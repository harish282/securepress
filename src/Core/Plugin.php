<?php

declare(strict_types=1);

namespace PressSentinel\Core;

use PressSentinel\Admin\AuditLogPage;
use PressSentinel\Admin\AuditLogSettingsPage;
use PressSentinel\Admin\AuthHardeningSettingsPage;
use PressSentinel\Admin\Diagnostics\HealthDiagnosticsCollector;
use PressSentinel\Admin\FeatureRegistry;
use PressSentinel\Admin\FileIntegrityPage;
use PressSentinel\Admin\HealthDiagnosticsPage;
use PressSentinel\Admin\LicensePage;
use PressSentinel\Admin\MuLoaderDownloadController;
use PressSentinel\Admin\MuLoaderStatus;
use PressSentinel\Admin\RateLimitSettingsPage;
use PressSentinel\Admin\PressSentinelMenuPage;
use PressSentinel\Admin\UrlDisguiseSettingsPage;
use PressSentinel\Admin\SecurityHeadersSettingsPage;
use PressSentinel\Admin\UserSecurityProfilePage;
use PressSentinel\Auth\AuthenticationHardeningKernel;
use PressSentinel\Auth\TwoFactorChallengeController;
use PressSentinel\Core\Audit\AuditLogger;
use PressSentinel\Core\Audit\AuditLoggerInterface;
use PressSentinel\Core\Audit\AuditLogOptions;
use PressSentinel\Core\Audit\AuditLogPruner;
use PressSentinel\Core\Audit\AuditLogRepositoryInterface;
use PressSentinel\Core\Audit\AuditLogSchema;
use PressSentinel\Core\Audit\Listeners\AuthListener;
use PressSentinel\Core\Audit\Listeners\FileEditorListener;
use PressSentinel\Core\Audit\Listeners\ListenerInterface;
use PressSentinel\Core\Audit\Listeners\OptionsListener;
use PressSentinel\Core\Audit\Listeners\PluginListener;
use PressSentinel\Core\Audit\Listeners\UserRoleListener;
use PressSentinel\Core\Audit\Listeners\WooCommerceListener;
use PressSentinel\Core\Audit\WpdbAuditLogRepository;
use PressSentinel\Core\Auth\AuthHardeningOptions;
use PressSentinel\Core\Auth\Lockout\LockoutStoreInterface;
use PressSentinel\Core\Auth\Lockout\LoginLockoutPolicy;
use PressSentinel\Core\Auth\Lockout\LoginLockoutService;
use PressSentinel\Core\Auth\Lockout\TransientLockoutStore;
use PressSentinel\Core\Auth\Notifications\AuthNotifier;
use PressSentinel\Core\Auth\Notifications\MailerInterface;
use PressSentinel\Core\Auth\Notifications\WpMailer;
use PressSentinel\Core\Auth\Sessions\WpSessionDestroyer;
use PressSentinel\Core\Auth\Sessions\SessionDestroyerInterface;
use PressSentinel\Core\Auth\Sessions\SessionFingerprinter;
use PressSentinel\Core\Auth\Sessions\SessionPruner;
use PressSentinel\Core\Auth\Sessions\SessionRepositoryInterface;
use PressSentinel\Core\Auth\Sessions\SessionSchema;
use PressSentinel\Core\Auth\Sessions\SessionService;
use PressSentinel\Core\Auth\Sessions\WpdbSessionRepository;
use PressSentinel\Core\Auth\SuspiciousLogin\Rules\NewDeviceRule;
use PressSentinel\Core\Auth\SuspiciousLogin\SuspicionDetector;
use PressSentinel\Core\Auth\TwoFactor\ChallengeStoreInterface;
use PressSentinel\Core\Auth\TwoFactor\EmailOtpProvider;
use PressSentinel\Core\Auth\TwoFactor\RecoveryCodeService;
use PressSentinel\Core\Auth\TwoFactor\TotpProvider;
use PressSentinel\Core\Auth\TwoFactor\TransientChallengeStore;
use PressSentinel\Core\Auth\TwoFactor\TwoFactorService;
use PressSentinel\Core\Auth\TwoFactor\TwoFactorUserRepositoryInterface;
use PressSentinel\Core\Auth\TwoFactor\UserMetaTwoFactorRepository;
use PressSentinel\Core\Config\Config;
use PressSentinel\Core\Headers\HeaderRegistryFactory;
use PressSentinel\Core\Headers\SecurityHeadersDispatcher;
use PressSentinel\Core\Headers\SecurityHeadersOptions;
use PressSentinel\Core\Licensing\LicenseHmacSecretProvisioner;
use PressSentinel\Core\Licensing\LicenseManager;
use PressSentinel\Core\Licensing\LicenseValidatorInterface;
use PressSentinel\Core\Licensing\LocalLicenseValidator;
use PressSentinel\WooCommerce\Admin\WooCommerceProtectionOptions;
use PressSentinel\WooCommerce\Admin\WooCommerceProtectionPage;
use PressSentinel\WooCommerce\Middleware\Api\ApiRateLimitMiddleware;
use PressSentinel\WooCommerce\Middleware\Api\SuspiciousRequestMiddleware;
use PressSentinel\WooCommerce\Middleware\Cart\CartVelocityMiddleware;
use PressSentinel\WooCommerce\Middleware\Cart\CouponAbuseMiddleware;
use PressSentinel\WooCommerce\Middleware\Checkout\BotCheckoutMiddleware;
use PressSentinel\WooCommerce\Middleware\Checkout\CartSimilarityMiddleware;
use PressSentinel\WooCommerce\Middleware\Checkout\CheckoutBehaviorMiddleware;
use PressSentinel\WooCommerce\Middleware\Checkout\DisposableEmailMiddleware as CheckoutDisposableEmailMiddleware;
use PressSentinel\WooCommerce\Middleware\Checkout\FraudScoreMiddleware;
use PressSentinel\WooCommerce\Middleware\Checkout\VelocityDetectionMiddleware;
use PressSentinel\WooCommerce\Middleware\Registration\HoneypotMiddleware;
use PressSentinel\WooCommerce\Middleware\Registration\RegistrationDisposableEmailMiddleware;
use PressSentinel\WooCommerce\Middleware\Registration\RegistrationRateLimitMiddleware;
use PressSentinel\WooCommerce\Pipelines\ApiPipeline;
use PressSentinel\WooCommerce\Pipelines\CartPipeline;
use PressSentinel\WooCommerce\Pipelines\CheckoutPipeline;
use PressSentinel\WooCommerce\Pipelines\RegistrationPipeline;
use PressSentinel\WooCommerce\Services\BehaviorClock;
use PressSentinel\WooCommerce\Services\CartFingerprinter;
use PressSentinel\WooCommerce\Services\DisposableEmailRegistry;
use PressSentinel\WooCommerce\Services\FraudScoreService;
use PressSentinel\WooCommerce\Storage\AbuseCounterStoreInterface;
use PressSentinel\WooCommerce\Storage\TransientAbuseCounterStore;
use PressSentinel\WooCommerce\WooCommerceModule;
use PressSentinel\Core\Integrity\Checksums\ChecksumProviderInterface;
use PressSentinel\Core\Integrity\Checksums\WpOrgChecksumProvider;
use PressSentinel\Core\Integrity\FindingRepositoryInterface;
use PressSentinel\Core\Integrity\Heuristics\EvalBase64Heuristic;
use PressSentinel\Core\Integrity\Heuristics\HeuristicInterface;
use PressSentinel\Core\Integrity\Heuristics\ObfuscatedCallableHeuristic;
use PressSentinel\Core\Integrity\Heuristics\PregReplaceEvalHeuristic;
use PressSentinel\Core\Integrity\Heuristics\ShellExecHeuristic;
use PressSentinel\Core\Integrity\Heuristics\WebshellSignatureHeuristic;
use PressSentinel\Core\Integrity\IntegrityOptions;
use PressSentinel\Core\Integrity\IntegrityScheduler;
use PressSentinel\Core\Integrity\IntegritySchema;
use PressSentinel\Core\Integrity\IntegrityService;
use PressSentinel\Core\Integrity\ManifestBuilder;
use PressSentinel\Core\Integrity\ManifestRepositoryInterface;
use PressSentinel\Core\Integrity\Scanners\CoreFilesScanner;
use PressSentinel\Core\Integrity\Scanners\ManifestDiffScanner;
use PressSentinel\Core\Integrity\Scanners\SuspiciousPhpScanner;
use PressSentinel\Core\Integrity\WpdbFindingRepository;
use PressSentinel\Core\Integrity\WpdbManifestRepository;
use PressSentinel\Core\Logging\FileLogger;
use PressSentinel\Core\Logging\LoggerInterface;
use PressSentinel\Core\Logging\NullLogger;
use PressSentinel\Core\Http\RouteGuardRegistry;
use PressSentinel\Core\Middleware\MiddlewareManager;
use PressSentinel\Core\Middleware\MiddlewarePipeline;
use PressSentinel\Core\Middleware\MiddlewareRegistry;
use PressSentinel\Core\Middleware\MiddlewareStack;
use PressSentinel\Core\Recovery\SafeMode;
use PressSentinel\Core\RateLimit\GlobalRateLimitSubscriber;
use PressSentinel\Core\RateLimit\RateLimiter;
use PressSentinel\Core\RateLimit\RateLimitOptions;
use PressSentinel\Core\RateLimit\RateLimitStoreInterface;
use PressSentinel\Core\RateLimit\TransientStore;
use PressSentinel\Core\UrlDisguise\UrlDisguiseModule;
use PressSentinel\Core\UrlDisguise\UrlDisguiseOptions;
use PressSentinel\Core\Url\NonceStoreInterface;
use PressSentinel\Core\Url\SecretProviderInterface;
use PressSentinel\Core\Url\TransientNonceStore;
use PressSentinel\Core\Url\UrlSigner;
use PressSentinel\Core\Url\WpSaltSecretProvider;
use PressSentinel\Middleware\CsrfProtectionMiddleware;
use PressSentinel\Middleware\RateLimitMiddleware;
use PressSentinel\Middleware\SecurityHeadersMiddleware;
use PressSentinel\Middleware\SignedUrlMiddleware;
use PressSentinel\Sdk\Csrf\CsrfTokenManager;
use PressSentinel\Sdk\Events\EventDispatcher;
use PressSentinel\Core\Requirements\SystemRequirementsChecker;
use PressSentinel\Core\Support\RequestContext;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;
use PressSentinel\Facades\AuditLog;
use PressSentinel\Facades\Security;

final class Plugin
{
    private Container $container;

    public function __construct()
    {
        $this->container = new Container();
        $this->registerServices();
    }

    public function boot(): void
    {
        $requirements = $this->container->get(SystemRequirementsChecker::class);
        if (!$requirements->passes()) {
            WpHelper::addAction('admin_notices', [$this, 'renderRequirementsNotice']);

            return;
        }

        $logger = $this->container->get(LoggerInterface::class);
        $logger->info('PressSentinel plugin booted.');
        if (SafeMode::isActive()) {
            $logger->warning(
                'PressSentinel safe mode is active — emergency bypasses: '
                . implode(', ', SafeMode::activeBypasses())
            );
            WpHelper::addAction('admin_notices', [$this, 'renderSafeModeNotice']);
        }
        Security::bootstrap($this->container);
        AuditLog::bootstrap($this->container);
        $this->container->get(GlobalRateLimitSubscriber::class)->register();
        $this->container->get(SecurityHeadersDispatcher::class)->register();

        $this->container->get(AuditLogSchema::class)->install();
        $this->registerAuditListeners();
        $auditPruner = $this->container->get(AuditLogPruner::class);
        $auditPruner->register();
        WpHelper::addAction(
            'update_option_' . AuditLogOptions::OPTION_NAME,
            static function () use ($auditPruner): void {
                $auditPruner->syncSchedule();
            },
            10,
            0
        );

        $this->container->get(SessionSchema::class)->install();
        $authOptions = $this->container->get(AuthHardeningOptions::class)->all();
        if (($authOptions['enabled'] ?? true) && ($authOptions['sessions']['enabled'] ?? true)) {
            $this->container->get(SessionPruner::class)->register();
        }

        if ($authOptions['enabled'] ?? true) {
            $this->container->get(AuthenticationHardeningKernel::class)->register();
        }

        $integrityOptions = $this->container->get(IntegrityOptions::class);
        if ($integrityOptions->isEnabled()) {
            $this->container->get(IntegritySchema::class)->install();
            $this->container->get(IntegrityScheduler::class)->register();
        }

        // WooCommerce Protection (Pro-only). Deferred to `init` so:
        //  - The kernel itself isn't instantiated on activation / wp-cron / etc.
        //  - WooCommerce has finished loading by the time we check `canRun()`.
        //  - The request context (REST / admin / ajax / frontend) is decidable.
        // The kernel internally short-circuits when there's no Pro license or
        // WooCommerce isn't active, so no commerce-specific work happens on
        // free or non-WC installs.
        WpHelper::addAction(
            'init',
            function (): void {
                $this->container->get(WooCommerceModule::class)->register();
            },
            5
        );

        $this->registerAdminHooks();

        if (!SafeMode::bypasses(SafeMode::BYPASS_LOGIN_DISGUISE)) {
            $this->container->get(UrlDisguiseModule::class)->register();

            WpHelper::addAction(
                'update_option_' . UrlDisguiseOptions::OPTION_NAME,
                function (): void {
                    $this->container->get(UrlDisguiseModule::class)->addRewriteRules();
                    WpHelper::flushRewriteRules();
                },
                10,
                0
            );
        }
    }

    public function renderSafeModeNotice(): void
    {
        if (!SafeMode::isActive() || !WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $this->container->get(View::class)->render('admin.notices.safe-mode', [
            'bypasses' => SafeMode::activeBypasses(),
        ]);
    }

    public function renderRequirementsNotice(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $errors = $this->container->get(SystemRequirementsChecker::class)->errors();
        if ($errors === []) {
            return;
        }

        $this->container->get(View::class)->render('admin.notices.requirements', [
            'errors' => $errors,
        ]);
    }

    /**
     * Surfaces a dismissible admin notice when the file logger can't write to
     * its configured path. We rely on the boot-time `info()` call in
     * {@see boot()} having already triggered the logger's lazy bootstrap, so
     * by the time `admin_notices` fires the error state is decided.
     *
     * Only `manage_options` users see this — log paths can hint at host
     * directory structure, which we don't want surfaced to lower-privileged
     * admins.
     */
    public function renderLoggerNotice(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        if (!$this->container->has(LoggerInterface::class)) {
            return;
        }
        $logger = $this->container->get(LoggerInterface::class);
        if (!$logger instanceof FileLogger) {
            return;
        }
        $error = $logger->lastError();
        if ($error === null || $error === '') {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>PressSentinel logging:</strong> '
            . WpHelper::escapeHtml($error)
            . '</p></div>';
    }

    /**
     * Warns when the offline license HMAC secret is still the shipped placeholder
     * or is too short — forged keys are trivial if the secret is known.
     */
    public function renderLicenseSecretNotice(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $secret = (string) $this->container->get(Config::class)->get('licensing.secret', '');
        if ($secret !== '' && $secret !== 'change-me-in-production' && strlen($secret) >= 24) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>PressSentinel licensing:</strong> '
            . 'The install could not establish a strong signing secret for offline license keys. '
            . 'Check that the database is writable and PHP can use <code>random_bytes()</code> or '
            . '<code>wp_generate_password()</code>. Optional overrides: '
            . '<code>define(\'PRESS_SENTINEL_LICENSE_SECRET\', \'…\');</code> in <code>wp-config.php</code> '
            . 'or <code>PRESS_SENTINEL_LICENSE_SECRET</code> in environment / <code>.env</code>.</p></div>';
    }

    public function renderMuLoaderNotice(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $status = $this->container->get(MuLoaderStatus::class);
        if ($status->isInstalled()) {
            return;
        }

        $screenId = WpHelper::getCurrentScreenId();
        if ($screenId !== 'plugins') {
            return;
        }

        $guidePath = PRESS_SENTINEL_PATH . '/docs/MU_LOADER_INSTALL.md';

        $this->container->get(View::class)->render('admin.notices.mu-loader-missing', [
            'templatePath' => $status->templatePath(),
            'expectedPath' => $status->expectedPath(),
            'guidePath' => $guidePath,
        ]);
    }

    /**
     * @param array<int, string> $pluginMeta
     * @param array<string, mixed> $pluginData
     * @return array<int, string>
     */
    public function addPluginRowMeta(array $pluginMeta, string $pluginFile, array $pluginData = [], string $status = ''): array
    {
        unset($pluginData, $status);

        if ($pluginFile !== WpHelper::pluginBasename(PRESS_SENTINEL_FILE)) {
            return $pluginMeta;
        }

        $pluginMeta[] = $this->container->get(MuLoaderStatus::class)->isInstalled()
            ? '<span style="color:#2e7d32;font-weight:600;">MU Loader: Installed</span>'
            : '<span style="color:#b45309;font-weight:600;">MU Loader: Missing</span>';

        return $pluginMeta;
    }

    private function registerServices(): void
    {
        LicenseHmacSecretProvisioner::ensure();

        $this->container->singleton(Config::class, static fn (): Config => new Config());
        $this->container->singleton(
            View::class,
            static fn (): View => new View(PRESS_SENTINEL_VIEWS_PATH)
        );

        $this->container->singleton(LoggerInterface::class, function (Container $container): LoggerInterface {
            $config = $container->get(Config::class);
            $channel = (string) $config->get('logging.channel', 'file');
            $filename = (string) $config->get('logging.file', 'presssentinel.log');
            $logPath = PRESS_SENTINEL_LOG_PATH . '/' . ltrim($filename, '/');

            return $channel === 'file' ? new FileLogger($logPath) : new NullLogger();
        });

        $this->container->singleton(
            SystemRequirementsChecker::class,
            static fn (Container $container): SystemRequirementsChecker => new SystemRequirementsChecker(
                $container->get(Config::class),
                $container->get(LoggerInterface::class)
            )
        );
        $this->container->singleton(
            MiddlewareRegistry::class,
            static fn (): MiddlewareRegistry => new MiddlewareRegistry()
        );
        $this->container->singleton(
            MiddlewareStack::class,
            static fn (): MiddlewareStack => new MiddlewareStack()
        );
        $this->container->singleton(
            RouteGuardRegistry::class,
            static fn (): RouteGuardRegistry => new RouteGuardRegistry()
        );
        $this->container->singleton(
            MiddlewarePipeline::class,
            static fn (): MiddlewarePipeline => new MiddlewarePipeline()
        );
        $this->container->singleton(
            MiddlewareManager::class,
            fn (Container $container): MiddlewareManager => new MiddlewareManager(
                $container->get(MiddlewareRegistry::class),
                $container->get(MiddlewarePipeline::class),
                $container
            )
        );
        $this->container->singleton(
            CsrfProtectionMiddleware::class,
            static fn (Container $container): CsrfProtectionMiddleware => new CsrfProtectionMiddleware(
                $container->get(LoggerInterface::class)
            )
        );
        $this->container->singleton(
            CsrfTokenManager::class,
            static fn (): CsrfTokenManager => new CsrfTokenManager()
        );
        $this->container->singleton(
            EventDispatcher::class,
            static fn (): EventDispatcher => new EventDispatcher()
        );
        $this->container->singleton(
            RateLimitStoreInterface::class,
            static fn (): RateLimitStoreInterface => new TransientStore()
        );
        $this->container->singleton(
            RateLimiter::class,
            static fn (Container $container): RateLimiter => new RateLimiter(
                $container->get(RateLimitStoreInterface::class)
            )
        );
        // RateLimitOptions merges config/plugin.php defaults with whatever
        // admins persisted via the dedicated Rate Limiting settings page or
        // the dashboard's master toggle. Routing the middleware factory
        // through it means a save on either UI takes effect on the next
        // request, with no plugin restart required.
        $this->container->singleton(
            RateLimitOptions::class,
            static fn (Container $container): RateLimitOptions => new RateLimitOptions(
                $container->get(Config::class)
            )
        );
        $this->container->singleton(
            UrlDisguiseOptions::class,
            static fn (Container $container): UrlDisguiseOptions => new UrlDisguiseOptions(
                $container->get(Config::class)
            )
        );
        $this->container->singleton(
            UrlDisguiseModule::class,
            static fn (Container $container): UrlDisguiseModule => new UrlDisguiseModule(
                $container->get(UrlDisguiseOptions::class)
            )
        );
        $this->container->singleton(
            RateLimitMiddleware::class,
            static function (Container $container): RateLimitMiddleware {
                $options = $container->get(RateLimitOptions::class);

                return new RateLimitMiddleware(
                    limiter: $container->get(RateLimiter::class),
                    logger: $container->get(LoggerInterface::class),
                    limit: $options->limit(),
                    window: $options->window(),
                    keyResolver: null,
                    enabled: $options->isEnabled() && !SafeMode::bypasses(SafeMode::BYPASS_RATE_LIMIT),
                );
            }
        );
        $this->container->singleton(
            GlobalRateLimitSubscriber::class,
            static fn (Container $container): GlobalRateLimitSubscriber => new GlobalRateLimitSubscriber(
                $container->get(RateLimitMiddleware::class),
                $container->get(RateLimitOptions::class),
            )
        );
        $this->container->singleton(
            SecretProviderInterface::class,
            static fn (): SecretProviderInterface => new WpSaltSecretProvider()
        );
        $this->container->singleton(
            UrlSigner::class,
            static fn (Container $container): UrlSigner => new UrlSigner(
                $container->get(SecretProviderInterface::class)
            )
        );
        $this->container->singleton(
            NonceStoreInterface::class,
            static fn (): NonceStoreInterface => new TransientNonceStore()
        );
        $this->container->singleton(
            SignedUrlMiddleware::class,
            static fn (Container $container): SignedUrlMiddleware => new SignedUrlMiddleware(
                $container->get(UrlSigner::class),
                $container->get(NonceStoreInterface::class),
                $container->get(LoggerInterface::class)
            )
        );
        $this->container->singleton(
            SecurityHeadersOptions::class,
            static fn (Container $container): SecurityHeadersOptions => new SecurityHeadersOptions(
                $container->get(Config::class)
            )
        );
        $this->container->singleton(
            HeaderRegistryFactory::class,
            static fn (Container $container): HeaderRegistryFactory => new HeaderRegistryFactory(
                $container->get(SecurityHeadersOptions::class)
            )
        );
        $this->container->singleton(
            SecurityHeadersDispatcher::class,
            static fn (Container $container): SecurityHeadersDispatcher => new SecurityHeadersDispatcher(
                $container->get(HeaderRegistryFactory::class)
            )
        );
        $this->container->singleton(
            SecurityHeadersMiddleware::class,
            static fn (Container $container): SecurityHeadersMiddleware => new SecurityHeadersMiddleware(
                $container->get(HeaderRegistryFactory::class)
            )
        );
        $this->container->singleton(
            SecurityHeadersSettingsPage::class,
            static fn (Container $container): SecurityHeadersSettingsPage => new SecurityHeadersSettingsPage(
                $container->get(SecurityHeadersOptions::class),
                $container->get(View::class)
            )
        );
        $this->container->singleton(
            RateLimitSettingsPage::class,
            static fn (Container $container): RateLimitSettingsPage => new RateLimitSettingsPage(
                $container->get(RateLimitOptions::class),
                $container->get(View::class)
            )
        );
        $this->container->singleton(
            UrlDisguiseSettingsPage::class,
            static fn (Container $container): UrlDisguiseSettingsPage => new UrlDisguiseSettingsPage(
                $container->get(UrlDisguiseOptions::class),
                $container->get(View::class)
            )
        );
        $this->container->singleton(
            AuditLogSchema::class,
            static fn (): AuditLogSchema => new AuditLogSchema()
        );
        $this->container->singleton(
            AuditLogRepositoryInterface::class,
            static fn (Container $container): AuditLogRepositoryInterface => new WpdbAuditLogRepository(
                $container->get(AuditLogSchema::class)
            )
        );
        $this->container->singleton(
            AuditLogOptions::class,
            static fn (Container $container): AuditLogOptions => new AuditLogOptions(
                $container->get(Config::class)
            )
        );
        // The audit logger and pruner now resolve their `enabled`/retention
        // values from AuditLogOptions, which overlays a wp_option on top of
        // config/plugin.php. That makes the PressSentinel dashboard toggle
        // (which writes only that option) effective immediately on the next
        // request without any cache flush or plugin reactivation.
        $this->container->singleton(
            AuditLoggerInterface::class,
            static function (Container $container): AuditLoggerInterface {
                $auditOptions = $container->get(AuditLogOptions::class);

                return new AuditLogger(
                    $container->get(AuditLogRepositoryInterface::class),
                    $container->get(LoggerInterface::class),
                    $auditOptions->isEnabled(),
                    $auditOptions->mirrorToFileLogger(),
                    $auditOptions->minStorageLevel(),
                );
            }
        );
        $this->container->singleton(
            AuditLogPruner::class,
            static fn (Container $container): AuditLogPruner => new AuditLogPruner(
                $container->get(AuditLogRepositoryInterface::class),
                $container->get(LoggerInterface::class),
                $container->get(AuditLogOptions::class),
            )
        );
        $this->container->singleton(
            AuditLogSettingsPage::class,
            static fn (Container $container): AuditLogSettingsPage => new AuditLogSettingsPage(
                $container->get(AuditLogOptions::class),
                $container->get(View::class),
            )
        );
        $this->registerIntegrityServices();
        $this->registerLicensingServices();
        $this->registerWooCommerceServices();
        $this->container->singleton(
            AuthListener::class,
            static fn (Container $container): AuthListener => new AuthListener(
                $container->get(AuditLoggerInterface::class)
            )
        );
        $this->container->singleton(
            PluginListener::class,
            static fn (Container $container): PluginListener => new PluginListener(
                $container->get(AuditLoggerInterface::class)
            )
        );
        $this->container->singleton(
            UserRoleListener::class,
            static fn (Container $container): UserRoleListener => new UserRoleListener(
                $container->get(AuditLoggerInterface::class)
            )
        );
        $this->container->singleton(
            OptionsListener::class,
            static function (Container $container): OptionsListener {
                $allowlist = $container->get(Config::class)->get('audit_log.option_allowlist', []);
                /** @var list<string> $list */
                $list = is_array($allowlist) ? array_values(array_filter($allowlist, 'is_string')) : [];

                return new OptionsListener(
                    $container->get(AuditLoggerInterface::class),
                    $list,
                );
            }
        );
        $this->container->singleton(
            FileEditorListener::class,
            static fn (Container $container): FileEditorListener => new FileEditorListener(
                $container->get(AuditLoggerInterface::class)
            )
        );
        $this->container->singleton(
            WooCommerceListener::class,
            static fn (Container $container): WooCommerceListener => new WooCommerceListener(
                $container->get(AuditLoggerInterface::class)
            )
        );
        $this->container->singleton(
            AuditLogPage::class,
            static fn (Container $container): AuditLogPage => new AuditLogPage(
                $container->get(AuditLogRepositoryInterface::class),
                $container->get(AuditLogPruner::class),
                $container->get(View::class),
            )
        );
        $this->registerAuthHardeningServices();
    }

    private function registerAuthHardeningServices(): void
    {
        $this->container->singleton(
            AuthHardeningOptions::class,
            static fn (Container $container): AuthHardeningOptions => new AuthHardeningOptions(
                $container->get(Config::class)
            )
        );
        $this->container->singleton(
            TotpProvider::class,
            static fn (): TotpProvider => new TotpProvider()
        );
        $this->container->singleton(
            EmailOtpProvider::class,
            static fn (): EmailOtpProvider => new EmailOtpProvider()
        );
        $this->container->singleton(
            RecoveryCodeService::class,
            static fn (): RecoveryCodeService => new RecoveryCodeService()
        );
        $this->container->singleton(
            TwoFactorUserRepositoryInterface::class,
            static fn (): TwoFactorUserRepositoryInterface => new UserMetaTwoFactorRepository()
        );
        $this->container->singleton(
            ChallengeStoreInterface::class,
            static fn (): ChallengeStoreInterface => new TransientChallengeStore()
        );
        $this->container->singleton(
            MailerInterface::class,
            static fn (): MailerInterface => new WpMailer()
        );
        $this->container->singleton(
            AuthNotifier::class,
            static function (Container $container): AuthNotifier {
                $opts = $container->get(AuthHardeningOptions::class)->all();

                return new AuthNotifier(
                    $container->get(MailerInterface::class),
                    $container->get(LoggerInterface::class),
                    WpHelper::blogName(),
                    WpHelper::siteUrl(),
                    (bool) ($opts['notifications']['enabled'] ?? true),
                );
            }
        );
        $this->container->singleton(
            TwoFactorService::class,
            static function (Container $container): TwoFactorService {
                $opts = $container->get(AuthHardeningOptions::class)->all();

                return new TwoFactorService(
                    $container->get(TwoFactorUserRepositoryInterface::class),
                    $container->get(ChallengeStoreInterface::class),
                    $container->get(TotpProvider::class),
                    $container->get(EmailOtpProvider::class),
                    $container->get(RecoveryCodeService::class),
                    $container->get(AuthNotifier::class),
                    $container->get(LoggerInterface::class),
                    (string) ($opts['two_factor']['issuer'] ?? 'PressSentinel'),
                    (int) ($opts['two_factor']['challenge_ttl_seconds'] ?? TwoFactorService::CHALLENGE_TTL_SECONDS),
                );
            }
        );
        $this->container->singleton(
            SessionSchema::class,
            static fn (): SessionSchema => new SessionSchema()
        );
        $this->container->singleton(
            SessionRepositoryInterface::class,
            static fn (Container $container): SessionRepositoryInterface => new WpdbSessionRepository(
                $container->get(SessionSchema::class)
            )
        );
        $this->container->singleton(
            SessionFingerprinter::class,
            static fn (): SessionFingerprinter => new SessionFingerprinter()
        );
        $this->container->singleton(
            SessionDestroyerInterface::class,
            static fn (): SessionDestroyerInterface => new WpSessionDestroyer()
        );
        $this->container->singleton(
            SessionService::class,
            static fn (Container $container): SessionService => new SessionService(
                $container->get(SessionRepositoryInterface::class),
                $container->get(SessionFingerprinter::class),
                $container->get(LoggerInterface::class),
                $container->get(SessionDestroyerInterface::class),
            )
        );
        $this->container->singleton(
            LockoutStoreInterface::class,
            static fn (): LockoutStoreInterface => new TransientLockoutStore()
        );
        $this->container->singleton(
            LoginLockoutService::class,
            static function (Container $container): LoginLockoutService {
                $opts = $container->get(AuthHardeningOptions::class)->all();
                $lockout = is_array($opts['lockout'] ?? null) ? $opts['lockout'] : [];

                return new LoginLockoutService(
                    $container->get(LockoutStoreInterface::class),
                    LoginLockoutPolicy::fromArray($lockout)
                );
            }
        );
        $this->container->singleton(
            SuspicionDetector::class,
            static function (Container $container): SuspicionDetector {
                $opts = $container->get(AuthHardeningOptions::class)->all();
                $rules = [];
                if ($opts['suspicion']['rules']['new_device'] ?? true) {
                    $rules[] = new NewDeviceRule(
                        $container->get(SessionRepositoryInterface::class),
                        60,
                    );
                }

                return new SuspicionDetector($rules);
            }
        );
        $this->container->singleton(
            TwoFactorChallengeController::class,
            static fn (Container $container): TwoFactorChallengeController => new TwoFactorChallengeController(
                $container->get(TwoFactorService::class),
                $container->get(View::class),
                $container->get(LoggerInterface::class),
            )
        );
        $this->container->singleton(
            AuthenticationHardeningKernel::class,
            static function (Container $container): AuthenticationHardeningKernel {
                $opts = $container->get(AuthHardeningOptions::class)->all();

                return new AuthenticationHardeningKernel(
                    $container->get(TwoFactorService::class),
                    $container->get(LoginLockoutService::class),
                    $container->get(SessionService::class),
                    $container->get(SuspicionDetector::class),
                    $container->get(SessionFingerprinter::class),
                    $container->get(AuthNotifier::class),
                    $container->get(TwoFactorChallengeController::class),
                    $container->get(LoggerInterface::class),
                    [
                        'enabled' => (bool) ($opts['enabled'] ?? true),
                        'lockout_enabled' => (bool) ($opts['lockout']['enabled'] ?? true),
                        'sessions_enabled' => (bool) ($opts['sessions']['enabled'] ?? true),
                        'suspicion_enabled' => (bool) ($opts['suspicion']['enabled'] ?? true),
                    ]
                );
            }
        );
        $this->container->singleton(
            SessionPruner::class,
            static function (Container $container): SessionPruner {
                $opts = $container->get(AuthHardeningOptions::class)->all();

                return new SessionPruner(
                    $container->get(SessionRepositoryInterface::class),
                    $container->get(LoggerInterface::class),
                    (int) ($opts['sessions']['retention_days'] ?? 90),
                );
            }
        );
        $this->container->singleton(
            UserSecurityProfilePage::class,
            static fn (Container $container): UserSecurityProfilePage => new UserSecurityProfilePage(
                $container->get(TwoFactorService::class),
                $container->get(SessionService::class),
                $container->get(View::class),
                $container->get(LoggerInterface::class),
            )
        );
        $this->container->singleton(
            AuthHardeningSettingsPage::class,
            static fn (Container $container): AuthHardeningSettingsPage => new AuthHardeningSettingsPage(
                $container->get(AuthHardeningOptions::class),
                $container->get(View::class),
            )
        );
    }

    private function registerIntegrityServices(): void
    {
        $this->container->singleton(
            IntegrityOptions::class,
            static fn (Container $container): IntegrityOptions => new IntegrityOptions(
                $container->get(Config::class)
            )
        );
        $this->container->singleton(
            IntegritySchema::class,
            static fn (): IntegritySchema => new IntegritySchema()
        );
        $this->container->singleton(
            ManifestRepositoryInterface::class,
            static fn (Container $container): ManifestRepositoryInterface => new WpdbManifestRepository(
                $container->get(IntegritySchema::class)
            )
        );
        $this->container->singleton(
            FindingRepositoryInterface::class,
            static fn (Container $container): FindingRepositoryInterface => new WpdbFindingRepository(
                $container->get(IntegritySchema::class)
            )
        );
        $this->container->singleton(
            ManifestBuilder::class,
            static fn (): ManifestBuilder => new ManifestBuilder()
        );
        $this->container->singleton(
            ChecksumProviderInterface::class,
            static fn (Container $container): ChecksumProviderInterface => new WpOrgChecksumProvider(
                $container->get(LoggerInterface::class)
            )
        );

        // Heuristics — bound individually so application code can swap one out for a
        // custom rule without touching the container wiring of the others.
        $this->container->singleton(EvalBase64Heuristic::class, static fn (): EvalBase64Heuristic => new EvalBase64Heuristic());
        $this->container->singleton(PregReplaceEvalHeuristic::class, static fn (): PregReplaceEvalHeuristic => new PregReplaceEvalHeuristic());
        $this->container->singleton(ObfuscatedCallableHeuristic::class, static fn (): ObfuscatedCallableHeuristic => new ObfuscatedCallableHeuristic());
        $this->container->singleton(WebshellSignatureHeuristic::class, static fn (): WebshellSignatureHeuristic => new WebshellSignatureHeuristic());
        $this->container->singleton(ShellExecHeuristic::class, static fn (): ShellExecHeuristic => new ShellExecHeuristic());

        $this->container->singleton(
            IntegrityService::class,
            function (Container $container): IntegrityService {
                $service = new IntegrityService(
                    $container->get(FindingRepositoryInterface::class),
                    $container->get(LoggerInterface::class)
                );

                $heuristics = [
                    $container->get(EvalBase64Heuristic::class),
                    $container->get(PregReplaceEvalHeuristic::class),
                    $container->get(ObfuscatedCallableHeuristic::class),
                    $container->get(WebshellSignatureHeuristic::class),
                    $container->get(ShellExecHeuristic::class),
                ];

                $options = $container->get(IntegrityOptions::class)->all();
                $builder = $container->get(ManifestBuilder::class);
                $manifests = $container->get(ManifestRepositoryInterface::class);

                // Core: WP.org checksum comparison.
                if ($options['scan_core'] ?? true) {
                    $service->registerScanner(new CoreFilesScanner(
                        $container->get(ChecksumProviderInterface::class),
                        \defined('ABSPATH') ? (string) \constant('ABSPATH') : '',
                        WpHelper::wpVersion(),
                        'en_US'
                    ));
                }

                // Plugins: live tree vs stored baseline + suspicious PHP heuristics.
                if (($options['scan_plugins'] ?? true) && \defined('WP_PLUGIN_DIR')) {
                    $pluginDir = (string) \constant('WP_PLUGIN_DIR');
                    $service->registerScanner(new ManifestDiffScanner('plugins', $pluginDir, $builder, $manifests));
                    $service->registerScanner(new SuspiciousPhpScanner('plugins', $pluginDir, $heuristics));
                }

                // Themes: opt-in because legitimate theme editing produces a lot of noise.
                if (($options['scan_themes'] ?? false) && \function_exists('get_theme_root')) {
                    $themesDir = (string) \call_user_func('get_theme_root');
                    $service->registerScanner(new ManifestDiffScanner('themes', $themesDir, $builder, $manifests));
                    $service->registerScanner(new SuspiciousPhpScanner('themes', $themesDir, $heuristics));
                }

                // Uploads: critical scope — PHP files in uploads are always suspicious.
                if ($options['scan_uploads'] ?? true) {
                    $uploads = WpHelper::uploadsDir();
                    if ($uploads !== '') {
                        $service->registerScanner(new SuspiciousPhpScanner(
                            'uploads',
                            $uploads,
                            $heuristics,
                            null,
                            2 * 1024 * 1024,
                            true
                        ));
                    }
                }

                return $service;
            }
        );

        $this->container->singleton(
            IntegrityScheduler::class,
            static fn (Container $container): IntegrityScheduler => new IntegrityScheduler(
                $container->get(IntegrityService::class),
                $container->get(IntegrityOptions::class),
                $container->get(LoggerInterface::class),
            )
        );

        $this->container->singleton(
            FileIntegrityPage::class,
            static fn (Container $container): FileIntegrityPage => new FileIntegrityPage(
                $container->get(FindingRepositoryInterface::class),
                $container->get(IntegrityScheduler::class),
                $container->get(IntegrityService::class),
                $container->get(ManifestRepositoryInterface::class),
                $container->get(View::class),
            )
        );
    }

    private function registerLicensingServices(): void
    {
        $this->container->singleton(
            LicenseValidatorInterface::class,
            static fn (Container $container): LicenseValidatorInterface => new LocalLicenseValidator(
                (string) $container->get(Config::class)->get('licensing.secret', 'change-me-in-production')
            )
        );
        $this->container->singleton(
            LicenseManager::class,
            static fn (Container $container): LicenseManager => new LicenseManager(
                $container->get(LicenseValidatorInterface::class),
                $container->get(Config::class)
            )
        );
        $this->container->singleton(
            LicensePage::class,
            static fn (Container $container): LicensePage => new LicensePage(
                $container->get(LicenseManager::class)
            )
        );
        $this->container->singleton(
            FeatureRegistry::class,
            static fn (Container $container): FeatureRegistry => new FeatureRegistry(
                $container->get(AuditLogOptions::class),
                $container->get(AuthHardeningOptions::class),
                $container->get(SecurityHeadersOptions::class),
                $container->get(IntegrityOptions::class),
                $container->get(WooCommerceProtectionOptions::class),
                $container->get(RateLimitOptions::class),
                $container->get(UrlDisguiseOptions::class),
                $container->get(LicenseManager::class),
            )
        );
        $this->container->singleton(
            PressSentinelMenuPage::class,
            static fn (Container $container): PressSentinelMenuPage => new PressSentinelMenuPage(
                $container->get(LicenseManager::class),
                $container->get(AuthHardeningOptions::class),
                $container->get(SecurityHeadersOptions::class),
                $container->get(IntegrityOptions::class),
                $container->get(FindingRepositoryInterface::class),
                $container->get(AuditLogRepositoryInterface::class),
                $container->get(FeatureRegistry::class),
                $container->get(MuLoaderStatus::class),
                $container->get(View::class),
            )
        );

        // MuLoaderStatus is a stateless value object — share one instance so
        // every consumer (the plugins-screen notice, the dashboard callout,
        // and the row-meta filter) reports identical state within a request.
        $this->container->singleton(
            MuLoaderStatus::class,
            static fn (): MuLoaderStatus => new MuLoaderStatus()
        );

        $this->container->singleton(
            MuLoaderDownloadController::class,
            static fn (Container $container): MuLoaderDownloadController => new MuLoaderDownloadController(
                $container->get(MuLoaderStatus::class)
            )
        );

        $this->container->singleton(
            HealthDiagnosticsCollector::class,
            static fn (Container $container): HealthDiagnosticsCollector => new HealthDiagnosticsCollector(
                $container->get(FeatureRegistry::class),
                $container->get(LicenseManager::class),
                $container->get(SecurityHeadersOptions::class),
                $container->get(UrlDisguiseOptions::class),
                $container->get(RateLimitOptions::class),
                $container->get(AuditLogOptions::class),
                $container->get(AuthHardeningOptions::class),
                $container->get(IntegrityOptions::class),
                $container->get(WooCommerceProtectionOptions::class),
                $container->get(AuditLogSchema::class),
                $container->get(SessionSchema::class),
                $container->get(IntegritySchema::class),
                $container->get(MuLoaderStatus::class),
                $container->get(Config::class),
            )
        );
        $this->container->singleton(
            HealthDiagnosticsPage::class,
            static fn (Container $container): HealthDiagnosticsPage => new HealthDiagnosticsPage(
                $container->get(HealthDiagnosticsCollector::class),
                $container->get(View::class),
            )
        );
    }

    private function registerWooCommerceServices(): void
    {
        $this->container->singleton(
            WooCommerceProtectionOptions::class,
            static fn (Container $container): WooCommerceProtectionOptions => new WooCommerceProtectionOptions(
                $container->get(Config::class)
            )
        );
        $this->container->singleton(
            AbuseCounterStoreInterface::class,
            static fn (Container $container): AbuseCounterStoreInterface => new TransientAbuseCounterStore(
                (string) $container->get(Config::class)->get('licensing.secret', 'change-me-in-production')
            )
        );
        $this->container->singleton(
            DisposableEmailRegistry::class,
            static fn (): DisposableEmailRegistry => new DisposableEmailRegistry()
        );
        $this->container->singleton(
            CartFingerprinter::class,
            static fn (): CartFingerprinter => new CartFingerprinter()
        );
        $this->container->singleton(
            BehaviorClock::class,
            static fn (Container $container): BehaviorClock => new BehaviorClock(
                (string) $container->get(Config::class)->get('licensing.secret', 'change-me-in-production')
            )
        );

        // Pipelines are built lazily so they read the latest options on each boot.
        $this->container->singleton(
            CheckoutPipeline::class,
            static function (Container $container): CheckoutPipeline {
                $values = $container->get(WooCommerceProtectionOptions::class)->all();
                $c = $values['checkout'];
                $store = $container->get(AbuseCounterStoreInterface::class);
                $scorer = new FraudScoreService(
                    (int) $c['fraud']['challenge_threshold'],
                    (int) $c['fraud']['deny_threshold'],
                );

                $bot = is_array($c['bot'] ?? null) ? $c['bot'] : [];

                return new CheckoutPipeline([
                    // 1. Velocity — cheapest, short-circuits highest-volume abuse first.
                    new VelocityDetectionMiddleware(
                        $store,
                        (int) $c['velocity_soft'],
                        (int) $c['velocity_hard'],
                        (int) $c['velocity_window'],
                    ),
                    // 2. Bot-shape — honeypot, scanner UA, impossible timing.
                    // Runs early so unambiguous bot tells short-circuit before we
                    // touch the disposable-email registry / cart fingerprinter.
                    new BotCheckoutMiddleware(
                        $container->get(BehaviorClock::class),
                        honeypotField: (string) ($c['honeypot_field_name'] ?? 'presssentinel_hp'),
                        minSecondsToSubmit: (int) ($c['min_seconds_to_submit'] ?? 0),
                        timingAction: (string) ($c['timing_action'] ?? BotCheckoutMiddleware::TIMING_REPORT),
                        extraScannerUas: is_array($bot['extra_scanner_uas'] ?? null) ? $bot['extra_scanner_uas'] : [],
                        weightHoneypot: (int) ($bot['weight_honeypot'] ?? 200),
                        weightScannerUa: (int) ($bot['weight_scanner_ua'] ?? 200),
                        weightImpossibleTiming: (int) ($bot['weight_impossible_timing'] ?? 30),
                        weightEmptyUa: (int) ($bot['weight_empty_ua'] ?? 35),
                        weightMissingReferer: (int) ($bot['weight_missing_referer'] ?? 15),
                    ),
                    // 3. Disposable email — single hash-set lookup.
                    new CheckoutDisposableEmailMiddleware($container->get(DisposableEmailRegistry::class)),
                    // 4. Cart similarity — transient round-trip.
                    new CartSimilarityMiddleware(
                        $container->get(CartFingerprinter::class),
                        $store,
                    ),
                    // 5. Behavioural — country mismatch and similar order-level signals.
                    new CheckoutBehaviorMiddleware(),
                    // 6. Fraud score — terminator; converts accumulated signals into a Decision.
                    new FraudScoreMiddleware($scorer),
                ]);
            }
        );

        $this->container->singleton(
            RegistrationPipeline::class,
            static function (Container $container): RegistrationPipeline {
                $values = $container->get(WooCommerceProtectionOptions::class)->all();
                $r = $values['registration'];
                $store = $container->get(AbuseCounterStoreInterface::class);

                return new RegistrationPipeline([
                    new HoneypotMiddleware(
                        (string) $r['honeypot_field_name'],
                        (int) $r['min_seconds_to_submit'],
                    ),
                    new RegistrationRateLimitMiddleware(
                        $store,
                        (int) $r['rate_limit'],
                        (int) $r['window'],
                    ),
                    new RegistrationDisposableEmailMiddleware(
                        $container->get(DisposableEmailRegistry::class),
                        (bool) $r['deny_disposable_emails'],
                    ),
                ]);
            }
        );

        $this->container->singleton(
            ApiPipeline::class,
            static function (Container $container): ApiPipeline {
                $values = $container->get(WooCommerceProtectionOptions::class)->all();
                $a = $values['api'];
                $store = $container->get(AbuseCounterStoreInterface::class);

                return new ApiPipeline([
                    new SuspiciousRequestMiddleware(
                        [],
                        40,
                        200,
                        (bool) $a['deny_on_scanner_ua'],
                        (bool) $a['pass_when_authenticated'],
                    ),
                    new ApiRateLimitMiddleware(
                        $store,
                        is_array($a['per_route'] ?? null) ? $a['per_route'] : [],
                        (int) $a['default_limit'],
                        (int) $a['default_window'],
                    ),
                ]);
            }
        );

        $this->container->singleton(
            CartPipeline::class,
            static function (Container $container): CartPipeline {
                $values = $container->get(WooCommerceProtectionOptions::class)->all();
                $c = $values['cart'];
                $store = $container->get(AbuseCounterStoreInterface::class);

                return new CartPipeline([
                    new CartVelocityMiddleware(
                        $store,
                        (int) $c['velocity_soft'],
                        (int) $c['velocity_hard'],
                        (int) $c['window'],
                    ),
                    new CouponAbuseMiddleware(
                        $store,
                        (int) $c['coupon_soft'],
                        (int) $c['coupon_hard'],
                    ),
                ]);
            }
        );

        $this->container->singleton(
            WooCommerceModule::class,
            // The kernel takes only the three cheap services it always needs.
            // Pipelines, clock, counter store, and logger are lazy-resolved
            // inside the hook callbacks via the container so a request that
            // never lands on a checkout / cart / REST hook builds none of them.
            static fn (Container $container): WooCommerceModule => new WooCommerceModule(
                $container->get(LicenseManager::class),
                $container->get(WooCommerceProtectionOptions::class),
                $container,
            )
        );

        $this->container->singleton(
            WooCommerceProtectionPage::class,
            static fn (Container $container): WooCommerceProtectionPage => new WooCommerceProtectionPage(
                $container->get(WooCommerceProtectionOptions::class),
                $container->get(LicenseManager::class),
            )
        );
    }

    private function registerAuditListeners(): void
    {
        $config = $this->container->get(Config::class);
        $listeners = $config->get('audit_log.listeners', []);
        if (!is_array($listeners)) {
            return;
        }

        $map = [
            'auth' => AuthListener::class,
            'plugin' => PluginListener::class,
            'user' => UserRoleListener::class,
            'options' => OptionsListener::class,
            'file_editor' => FileEditorListener::class,
            'woocommerce' => WooCommerceListener::class,
        ];

        foreach ($map as $key => $class) {
            if (!($listeners[$key] ?? false)) {
                continue;
            }
            $listener = $this->container->get($class);
            if ($listener instanceof ListenerInterface) {
                $listener->register();
            }
        }
    }

    private function registerAdminHooks(): void
    {
        if (!WpHelper::isAdmin()) {
            return;
        }

        WpHelper::addAction('admin_notices', [$this, 'renderMuLoaderNotice']);
        WpHelper::addAction('admin_notices', [$this, 'renderLoggerNotice']);
        WpHelper::addAction('admin_notices', [$this, 'renderLicenseSecretNotice']);
        WpHelper::addFilter('plugin_row_meta', [$this, 'addPluginRowMeta'], 10, 4);

        // The top-level "Secure Press" menu owns the parent slug every submenu
        // page below hangs off. It must be registered first — WP drops submenu
        // entries whose `parent_slug` doesn't yet exist — so we hook into
        // `admin_menu` outside of the priority-1 submenu callback. The page
        // itself does this internally at priority 0.
        $this->container->get(PressSentinelMenuPage::class)->register();

        // The MU loader zip-download controller registers an admin_post_*
        // hook only (no menu page), so it can live right next to the
        // dashboard menu registration — same lifecycle, same admin-post.php
        // entry point.
        $this->container->get(MuLoaderDownloadController::class)->register();

        // Admin pages are deferred to `init` — NOT `admin_menu`, NOT
        // `admin_init` — because of how WordPress's two admin entry points
        // sequence their hooks:
        //
        //   wp-admin/admin.php (every normal admin page view):
        //     1. require wp-admin/menu.php  → fires `admin_menu`
        //     2. do_action('admin_init')                 ← AFTER admin_menu
        //
        //   wp-admin/admin-post.php (form submissions for Prune/Clear/Save):
        //     1. do_action('admin_init')
        //     2. do_action("admin_post_{$action}")
        //     - NEVER fires `admin_menu`.
        //
        // So no single hook between admin_menu and admin_init works for both
        // entry points. The earliest hook that fires reliably BEFORE both is
        // `init` (from wp-settings.php, line ~742 in core), which runs as
        // part of wp-load.php and therefore precedes wp-admin/admin.php's
        // menu-rendering AND admin-post.php's action dispatch.
        //
        // The outer is_admin() gate above ensures this hook is only added
        // for admin requests; the inner RequestContext::isAjax() check skips
        // admin-ajax.php to preserve the lazy-loading intent (no page
        // construction on every AJAX call).
        WpHelper::addAction('init', function (): void {
            if (RequestContext::isAjax()) {
                return;
            }

            $this->container->get(AuthHardeningSettingsPage::class)->register();
            $this->container->get(SecurityHeadersSettingsPage::class)->register();
            $this->container->get(RateLimitSettingsPage::class)->register();
            $this->container->get(UrlDisguiseSettingsPage::class)->register();
            $this->container->get(FileIntegrityPage::class)->register();
            $this->container->get(AuditLogPage::class)->register();
            $this->container->get(AuditLogSettingsPage::class)->register();
            $this->container->get(HealthDiagnosticsPage::class)->register();
            // The WC settings page is registered unconditionally so admins can
            // discover the feature even on Free. The page itself renders an
            // upgrade prompt when the license isn't active.
            $this->container->get(WooCommerceProtectionPage::class)->register();
            // License lives at the bottom of the menu — admins rarely need it
            // after initial setup, and burying it reduces the chance of
            // accidentally clearing a working key.
            $this->container->get(LicensePage::class)->register();

            // Account Security stays as its own top-level menu (separate from
            // the PressSentinel parent menu above): it's gated by the `read`
            // capability so every logged-in user can manage their own 2FA, while
            // the PressSentinel parent menu requires `manage_options`.
            if ($this->container->get(AuthHardeningOptions::class)->isEnabled()) {
                $this->container->get(UserSecurityProfilePage::class)->register();
            }
        }, 1);
    }

}
