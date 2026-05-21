<?php

declare(strict_types=1);

namespace PressSentinel\Admin\Diagnostics;

use PressSentinel\Admin\FeatureDescriptor;
use PressSentinel\Admin\FeatureRegistry;
use PressSentinel\Admin\MuLoaderStatus;
use PressSentinel\Core\Audit\AuditLogOptions;
use PressSentinel\Core\Audit\AuditLogPruner;
use PressSentinel\Core\Audit\AuditLogSchema;
use PressSentinel\Core\Auth\AuthHardeningOptions;
use PressSentinel\Core\Auth\Sessions\SessionPruner;
use PressSentinel\Core\Auth\Sessions\SessionSchema;
use PressSentinel\Core\Config\Config;
use PressSentinel\Core\Headers\SecurityHeadersDispatcher;
use PressSentinel\Core\Headers\SecurityHeadersOptions;
use PressSentinel\Core\Integrity\IntegrityOptions;
use PressSentinel\Core\Integrity\IntegrityScheduler;
use PressSentinel\Core\Integrity\IntegritySchema;
use PressSentinel\Core\Licensing\LicenseManager;
use PressSentinel\Core\RateLimit\RateLimitOptions;
use PressSentinel\Core\Recovery\SafeMode;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\UrlDisguise\UrlDisguiseOptions;
use PressSentinel\WooCommerce\Admin\WooCommerceProtectionOptions;
use PressSentinel\WooCommerce\WooCommerceModule;

/**
 * Read-only snapshot of plugin health for the admin diagnostics screen.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Diagnostics probes; table names from schema helpers only.
final class HealthDiagnosticsCollector
{
    private const TRANSIENT_PROBE_PREFIX = 'presssentinel_health_probe_';

    public function __construct(
        private readonly FeatureRegistry $features,
        private readonly LicenseManager $license,
        private readonly SecurityHeadersOptions $headersOptions,
        private readonly UrlDisguiseOptions $urlDisguiseOptions,
        private readonly RateLimitOptions $rateLimitOptions,
        private readonly AuditLogOptions $auditLogOptions,
        private readonly AuthHardeningOptions $authOptions,
        private readonly IntegrityOptions $integrityOptions,
        private readonly WooCommerceProtectionOptions $wcOptions,
        private readonly AuditLogSchema $auditSchema,
        private readonly SessionSchema $sessionSchema,
        private readonly IntegritySchema $integritySchema,
        private readonly MuLoaderStatus $muLoader,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array{
     *     generated_at: int,
     *     safe_mode: array{active: bool, bypasses: list<string>},
     *     protections: list<array{key: string, label: string, state: string, detail: string}>,
     *     hooks: list<array{label: string, hook: string, kind: string, expected: bool, registered: bool, status: string, note: string}>,
     *     storage: list<array{label: string, table: string, expected: bool, exists: bool, version: int|null, row_count: int|null, status: string}>,
     *     transients: array{functions_available: bool, read_write_ok: bool, object_cache_active: bool, note: string}
     * }
     */
    public function collect(): array
    {
        return [
            'generated_at' => time(),
            'safe_mode' => [
                'active' => SafeMode::isActive(),
                'bypasses' => SafeMode::activeBypasses(),
            ],
            'protections' => $this->collectProtections(),
            'hooks' => $this->collectHooks(),
            'storage' => $this->collectStorage(),
            'transients' => $this->collectTransientHealth(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, state: string, detail: string}>
     */
    private function collectProtections(): array
    {
        $rows = [];

        foreach ($this->features->all() as $feature) {
            $rows[] = $this->protectionRow($feature);
        }

        $rows[] = [
            'key' => 'mu_loader',
            'label' => 'MU loader (early bootstrap)',
            'state' => $this->muLoader->isInstalled() ? 'active' : 'inactive',
            'detail' => $this->muLoader->isInstalled()
                ? 'Installed at ' . $this->muLoader->expectedPath()
                : 'Not installed — optional early-load shim.',
        ];

        $rows[] = [
            'key' => 'url_disguise_runtime',
            'label' => 'URL disguise (runtime)',
            'state' => $this->urlDisguiseOptions->isActive() ? 'active' : 'inactive',
            'detail' => $this->urlDisguiseOptions->isActive()
                ? 'Custom login path: /' . $this->urlDisguiseOptions->loginSlug() . '/'
                : ($this->urlDisguiseOptions->isEnabled()
                    ? 'Enabled in settings but inactive (empty slug or safe mode).'
                    : 'Disabled.'),
        ];

        $secret = (string) $this->config->get('licensing.secret', '');
        $weakSecret = $secret === '' || $secret === 'change-me-in-production' || strlen($secret) < 24;
        $rows[] = [
            'key' => 'license_hmac_secret',
            'label' => 'License HMAC secret',
            'state' => $weakSecret ? 'blocked' : 'active',
            'detail' => $weakSecret
                ? 'Ensure the database option presssentinel_license_hmac_secret is writable, or set PRESS_SENTINEL_LICENSE_SECRET (24+ chars) in wp-config.php or licensing.secret in config/plugin.php.'
                : 'Strong secret resolved (auto-generated option, constant, or environment).',
        ];

        $rows[] = [
            'key' => 'rate_limit_http',
            'label' => 'Global rate limiting (HTTP)',
            'state' => $this->rateLimitOptions->isEnabled() ? 'active' : 'inactive',
            'detail' => $this->rateLimitOptions->isEnabled()
                ? 'Enforced on REST, front-end, AJAX, and wp-login; wp-admin dashboard loads are excluded to avoid editor lockouts.'
                : 'Disabled — enable from the dashboard or Rate Limiting settings after tuning limit/window.',
        ];

        if ($this->license->isPro() && $this->wcOptions->isEnabled()) {
            $wc = $this->wcOptions->all();
            $checkout = is_array($wc['checkout'] ?? null) ? $wc['checkout'] : [];
            $parts = ['checkout', 'registration', 'cart', 'api'];
            $on = [];
            foreach ($parts as $part) {
                if ((bool) (is_array($wc[$part] ?? null) ? ($wc[$part]['enabled'] ?? false) : false)) {
                    $on[] = $part;
                }
            }
            $timingFloor = (int) ($checkout['min_seconds_to_submit'] ?? 0);
            $rows[] = [
                'key' => 'wc_subsystems',
                'label' => 'WooCommerce sub-protections',
                'state' => $on !== [] ? 'active' : 'inactive',
                'detail' => $on !== []
                    ? 'Enabled: ' . implode(', ', $on)
                    . ($timingFloor > 0 ? sprintf('; checkout timing floor %ds', $timingFloor) : '')
                    : 'Master on but all subsystems disabled.',
            ];
        }

        return $rows;
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string}
     */
    private function protectionRow(FeatureDescriptor $feature): array
    {
        $enabled = $feature->isEnabled();
        $state = 'inactive';
        $detail = 'Disabled in settings.';

        if ($enabled && $feature->isPro && !$this->license->isPro()) {
            $state = 'blocked';
            $detail = 'Enabled in settings but requires an active Pro license to run.';
        } elseif ($enabled) {
            $state = 'active';
            $detail = 'Enabled and eligible to run.';
        }

        if ($feature->key === 'rate_limit' && SafeMode::bypasses(SafeMode::BYPASS_RATE_LIMIT)) {
            $state = 'bypassed';
            $detail = 'Enabled but bypassed while safe mode is active.';
        }

        if ($feature->key === 'auth_hardening' && SafeMode::bypasses(SafeMode::BYPASS_LOCKOUT)) {
            $state = 'bypassed';
            $detail = 'Enabled; lockout bypassed while safe mode is active.';
        }

        if ($feature->key === 'url_disguise' && SafeMode::bypasses(SafeMode::BYPASS_LOGIN_DISGUISE)) {
            $state = 'bypassed';
            $detail = 'Enabled but login disguise bypassed while safe mode is active.';
        }

        return [
            'key' => $feature->key,
            'label' => $feature->label,
            'state' => $state,
            'detail' => $detail,
        ];
    }

    /**
     * @return list<array{label: string, hook: string, kind: string, expected: bool, registered: bool, status: string, note: string}>
     */
    private function collectHooks(): array
    {
        $listeners = $this->config->get('audit_log.listeners', []);
        if (!is_array($listeners)) {
            $listeners = [];
        }

        $authOpts = $this->authOptions->all();
        $authEnabled = (bool) ($authOpts['enabled'] ?? true);
        $sessionsOn = $authEnabled && (bool) ($authOpts['sessions']['enabled'] ?? true);

        $wcCanRun = $this->license->isPro()
            && $this->wcOptions->isEnabled()
            && (class_exists('WooCommerce', false) || class_exists('WC_Cart', false));

        $integrityConfig = $this->integrityOptions->all();
        $integrityCron = is_array($integrityConfig['cron'] ?? null) ? $integrityConfig['cron'] : [];

        $checks = [
            [
                'label' => 'Security headers',
                'hook' => 'send_headers',
                'kind' => 'action',
                'expected' => $this->headersOptions->isEnabled(),
                'note' => 'Fires ' . SecurityHeadersDispatcher::class . '::send',
            ],
            [
                'label' => 'Audit log auto-prune',
                'hook' => AuditLogPruner::HOOK,
                'kind' => 'action',
                'expected' => $this->auditLogOptions->isEnabled()
                    && $this->auditLogOptions->isAutoPruneEnabled()
                    && $this->auditLogOptions->retentionDays() > 0,
                'note' => 'Daily cron retention pruner.',
            ],
            [
                'label' => 'Audit: login success',
                'hook' => 'wp_login',
                'kind' => 'action',
                'expected' => $this->auditLogOptions->isEnabled() && (bool) ($listeners['auth'] ?? false),
                'note' => 'Shared hook — other plugins may also register callbacks.',
            ],
            [
                'label' => 'Auth hardening (2FA challenge route)',
                'hook' => 'login_form_sp_2fa',
                'kind' => 'action',
                'expected' => $authEnabled,
                'note' => 'PressSentinel-specific login form action.',
            ],
            [
                'label' => 'Auth hardening (lockout pre-check)',
                'hook' => 'authenticate',
                'kind' => 'filter',
                'expected' => $authEnabled && (bool) ($authOpts['lockout']['enabled'] ?? true),
                'note' => 'Shared filter — WordPress core also uses authenticate.',
            ],
            [
                'label' => 'Session table prune',
                'hook' => SessionPruner::CRON_HOOK,
                'kind' => 'action',
                'expected' => $sessionsOn,
                'note' => 'Daily cron for presssentinel_sessions rows.',
            ],
            [
                'label' => 'File integrity scan',
                'hook' => IntegrityScheduler::HOOK,
                'kind' => 'action',
                'expected' => $this->integrityOptions->isEnabled()
                    && (bool) ($integrityCron['enabled'] ?? true),
                'note' => 'Scheduled integrity scan dispatcher.',
            ],
            [
                'label' => 'URL disguise (template handler)',
                'hook' => 'template_redirect',
                'kind' => 'action',
                'expected' => $this->urlDisguiseOptions->isActive(),
                'note' => 'Custom login path handler; shared hook when active.',
            ],
            [
                'label' => 'WooCommerce checkout guard',
                'hook' => WooCommerceModule::HOOK_CHECKOUT_PROCESS,
                'kind' => 'action',
                'expected' => $wcCanRun && $this->wcOptions->isCheckoutEnabled(),
                'note' => 'Runs checkout protection pipeline.',
            ],
            [
                'label' => 'WooCommerce REST guard',
                'hook' => WooCommerceModule::HOOK_REST_API_INIT,
                'kind' => 'action',
                'expected' => $wcCanRun && $this->wcOptions->isApiEnabled(),
                'note' => 'Registers rest_pre_dispatch when REST API initializes.',
            ],
        ];

        $rows = [];
        foreach ($checks as $check) {
            $registered = $check['kind'] === 'filter'
                ? WpHelper::hasFilter($check['hook'])
                : WpHelper::hasAction($check['hook']);

            $expected = $check['expected'];
            $status = 'ok';
            if ($expected && !$registered) {
                $status = 'missing';
            } elseif (!$expected && $registered) {
                $status = 'extra';
            } elseif (!$expected) {
                $status = 'idle';
            }

            $rows[] = [
                'label' => $check['label'],
                'hook' => $check['hook'],
                'kind' => $check['kind'],
                'expected' => $expected,
                'registered' => $registered,
                'status' => $status,
                'note' => $check['note'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, table: string, expected: bool, exists: bool, version: int|null, row_count: int|null, status: string}>
     */
    private function collectStorage(): array
    {
        $authOpts = $this->authOptions->all();
        $sessionsExpected = ($authOpts['enabled'] ?? true) && ($authOpts['sessions']['enabled'] ?? true);

        $definitions = [
            [
                'label' => 'Audit log',
                'table' => $this->auditSchema->tableName(),
                'version_option' => AuditLogSchema::VERSION_OPTION,
                'expected' => $this->auditLogOptions->isEnabled(),
            ],
            [
                'label' => 'Auth sessions',
                'table' => $this->sessionSchema->tableName(),
                'version_option' => SessionSchema::VERSION_OPTION,
                'expected' => $sessionsExpected,
            ],
            [
                'label' => 'Integrity baselines',
                'table' => $this->integritySchema->baselineTable(),
                'version_option' => IntegritySchema::VERSION_OPTION,
                'expected' => $this->integrityOptions->isEnabled(),
            ],
            [
                'label' => 'Integrity findings',
                'table' => $this->integritySchema->findingTable(),
                'version_option' => IntegritySchema::VERSION_OPTION,
                'expected' => $this->integrityOptions->isEnabled(),
            ],
        ];

        $rows = [];
        foreach ($definitions as $def) {
            $exists = $this->tableExists($def['table']);
            $version = $this->storedDbVersion($def['version_option']);
            $rowCount = $exists ? $this->tableRowCount($def['table']) : null;

            $status = 'ok';
            if ($def['expected'] && !$exists) {
                $status = 'missing';
            } elseif ($def['expected'] && $exists && $version !== null && $version < 1) {
                $status = 'stale';
            } elseif (!$def['expected'] && $exists) {
                $status = 'present';
            } elseif (!$def['expected']) {
                $status = 'idle';
            }

            $rows[] = [
                'label' => $def['label'],
                'table' => $def['table'],
                'expected' => $def['expected'],
                'exists' => $exists,
                'version' => $version,
                'row_count' => $rowCount,
                'status' => $status,
            ];
        }

        return $rows;
    }

    /**
     * @return array{functions_available: bool, read_write_ok: bool, object_cache_active: bool, note: string}
     */
    private function collectTransientHealth(): array
    {
        $functionsAvailable = \function_exists('set_transient')
            && \function_exists('get_transient')
            && \function_exists('delete_transient');

        $readWriteOk = false;
        if ($functionsAvailable) {
            $key = self::TRANSIENT_PROBE_PREFIX . bin2hex(random_bytes(8));
            $written = WpHelper::setTransient($key, 'probe-ok', 60);
            $read = WpHelper::getTransient($key);
            WpHelper::deleteTransient($key);
            $readWriteOk = $written && $read === 'probe-ok';
        }

        $objectCache = \defined('WP_CACHE') && WP_CACHE
            && (\defined('WP_EXTERNAL_OBJECT_CACHE') && WP_EXTERNAL_OBJECT_CACHE);

        $note = 'Rate limits, lockouts, WooCommerce abuse counters, and signed URL nonces use transients.';
        if ($objectCache) {
            $note .= ' A persistent object cache is active — ensure it does not drop transient keys prematurely.';
        }
        if (!$readWriteOk && $functionsAvailable) {
            $note .= ' Read/write probe failed; counters and throttles may not persist.';
        }
        if (!$functionsAvailable) {
            $note .= ' Transient API unavailable in this context (CLI without WordPress loaded?).';
        }

        return [
            'functions_available' => $functionsAvailable,
            'read_write_ok' => $readWriteOk,
            'object_cache_active' => $objectCache,
            'note' => $note,
        ];
    }

    private function tableExists(string $table): bool
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics probe; table name from schema helpers.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return is_string($found) && $found === $table;
    }

    private function tableRowCount(string $table): ?int
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return null;
        }

        // Table name comes from our schema helpers only — not user input.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $raw = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if (!is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function storedDbVersion(string $option): ?int
    {
        if (!\function_exists('get_option')) {
            return null;
        }

        $value = \call_user_func('get_option', $option, 0);

        return is_numeric($value) ? (int) $value : null;
    }
}
