<?php

declare(strict_types=1);

namespace SecurePress\Core;

use SecurePress\Admin\AuditLogPage;
use SecurePress\Admin\SecurityHeadersSettingsPage;
use SecurePress\Admin\UserSecurityProfilePage;
use SecurePress\Auth\AuthenticationHardeningKernel;
use SecurePress\Auth\TwoFactorChallengeController;
use SecurePress\Core\Audit\AuditLogger;
use SecurePress\Core\Audit\AuditLoggerInterface;
use SecurePress\Core\Audit\AuditLogPruner;
use SecurePress\Core\Audit\AuditLogRepositoryInterface;
use SecurePress\Core\Audit\AuditLogSchema;
use SecurePress\Core\Audit\Listeners\AuthListener;
use SecurePress\Core\Audit\Listeners\FileEditorListener;
use SecurePress\Core\Audit\Listeners\ListenerInterface;
use SecurePress\Core\Audit\Listeners\OptionsListener;
use SecurePress\Core\Audit\Listeners\PluginListener;
use SecurePress\Core\Audit\Listeners\UserRoleListener;
use SecurePress\Core\Audit\Listeners\WooCommerceListener;
use SecurePress\Core\Audit\WpdbAuditLogRepository;
use SecurePress\Core\Auth\Lockout\LockoutStoreInterface;
use SecurePress\Core\Auth\Lockout\LoginLockoutPolicy;
use SecurePress\Core\Auth\Lockout\LoginLockoutService;
use SecurePress\Core\Auth\Lockout\TransientLockoutStore;
use SecurePress\Core\Auth\Notifications\AuthNotifier;
use SecurePress\Core\Auth\Notifications\MailerInterface;
use SecurePress\Core\Auth\Notifications\WpMailer;
use SecurePress\Core\Auth\Sessions\WpSessionDestroyer;
use SecurePress\Core\Auth\Sessions\SessionDestroyerInterface;
use SecurePress\Core\Auth\Sessions\SessionFingerprinter;
use SecurePress\Core\Auth\Sessions\SessionPruner;
use SecurePress\Core\Auth\Sessions\SessionRepositoryInterface;
use SecurePress\Core\Auth\Sessions\SessionSchema;
use SecurePress\Core\Auth\Sessions\SessionService;
use SecurePress\Core\Auth\Sessions\WpdbSessionRepository;
use SecurePress\Core\Auth\SuspiciousLogin\Rules\NewDeviceRule;
use SecurePress\Core\Auth\SuspiciousLogin\SuspicionDetector;
use SecurePress\Core\Auth\TwoFactor\ChallengeStoreInterface;
use SecurePress\Core\Auth\TwoFactor\EmailOtpProvider;
use SecurePress\Core\Auth\TwoFactor\RecoveryCodeService;
use SecurePress\Core\Auth\TwoFactor\TotpProvider;
use SecurePress\Core\Auth\TwoFactor\TransientChallengeStore;
use SecurePress\Core\Auth\TwoFactor\TwoFactorService;
use SecurePress\Core\Auth\TwoFactor\TwoFactorUserRepositoryInterface;
use SecurePress\Core\Auth\TwoFactor\UserMetaTwoFactorRepository;
use SecurePress\Core\Config\Config;
use SecurePress\Core\Headers\HeaderRegistryFactory;
use SecurePress\Core\Headers\SecurityHeadersDispatcher;
use SecurePress\Core\Headers\SecurityHeadersOptions;
use SecurePress\Core\Logging\FileLogger;
use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Core\Http\RouteGuardRegistry;
use SecurePress\Core\Middleware\MiddlewareManager;
use SecurePress\Core\Middleware\MiddlewarePipeline;
use SecurePress\Core\Middleware\MiddlewareRegistry;
use SecurePress\Core\Middleware\MiddlewareStack;
use SecurePress\Core\RateLimit\RateLimiter;
use SecurePress\Core\RateLimit\RateLimitStoreInterface;
use SecurePress\Core\RateLimit\TransientStore;
use SecurePress\Core\Url\NonceStoreInterface;
use SecurePress\Core\Url\SecretProviderInterface;
use SecurePress\Core\Url\TransientNonceStore;
use SecurePress\Core\Url\UrlSigner;
use SecurePress\Core\Url\WpSaltSecretProvider;
use SecurePress\Middleware\CsrfProtectionMiddleware;
use SecurePress\Middleware\RateLimitMiddleware;
use SecurePress\Middleware\SecurityHeadersMiddleware;
use SecurePress\Middleware\SignedUrlMiddleware;
use SecurePress\Core\Requirements\SystemRequirementsChecker;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Core\View\View;
use SecurePress\Facades\AuditLog;
use SecurePress\Facades\Security;

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

        $this->container->get(LoggerInterface::class)->info('SecurePress plugin booted.');
        Security::bootstrap($this->container);
        AuditLog::bootstrap($this->container);
        $this->container->get(SecurityHeadersDispatcher::class)->register();

        $this->container->get(AuditLogSchema::class)->install();
        $this->registerAuditListeners();
        $this->container->get(AuditLogPruner::class)->register();

        $this->container->get(SessionSchema::class)->install();
        if ((bool) $this->container->get(Config::class)->get('auth_hardening.enabled', true)
            && (bool) $this->container->get(Config::class)->get('auth_hardening.sessions.enabled', true)) {
            $this->container->get(SessionPruner::class)->register();
        }

        if ((bool) $this->container->get(Config::class)->get('auth_hardening.enabled', true)) {
            $this->container->get(AuthenticationHardeningKernel::class)->register();
        }

        $this->registerAdminHooks();
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

    public function renderMuLoaderNotice(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        if ($this->isMuLoaderInstalled()) {
            return;
        }

        $screenId = WpHelper::getCurrentScreenId();
        if ($screenId !== 'plugins') {
            return;
        }

        $expectedPath = $this->getMuLoaderPath();
        $templatePath = SECUREPRESS_MU_LOADER_TEMPLATE_PATH;
        $guidePath = SECUREPRESS_PATH . '/docs/MU_LOADER_INSTALL.md';

        $this->container->get(View::class)->render('admin.notices.mu-loader-missing', [
            'templatePath' => $templatePath,
            'expectedPath' => $expectedPath,
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

        if ($pluginFile !== WpHelper::pluginBasename(SECUREPRESS_FILE)) {
            return $pluginMeta;
        }

        $pluginMeta[] = $this->isMuLoaderInstalled()
            ? '<span style="color:#2e7d32;font-weight:600;">MU Loader: Installed</span>'
            : '<span style="color:#b45309;font-weight:600;">MU Loader: Missing</span>';

        return $pluginMeta;
    }

    private function registerServices(): void
    {
        $this->container->singleton(Config::class, static fn (): Config => new Config());
        $this->container->singleton(
            View::class,
            static fn (): View => new View(SECUREPRESS_VIEWS_PATH)
        );

        $this->container->singleton(LoggerInterface::class, function (Container $container): LoggerInterface {
            $config = $container->get(Config::class);
            $channel = (string) $config->get('logging.channel', 'file');
            $filename = (string) $config->get('logging.file', 'securepress.log');
            $logPath = SECUREPRESS_LOG_PATH . '/' . ltrim($filename, '/');

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
            RateLimitStoreInterface::class,
            static fn (): RateLimitStoreInterface => new TransientStore()
        );
        $this->container->singleton(
            RateLimiter::class,
            static fn (Container $container): RateLimiter => new RateLimiter(
                $container->get(RateLimitStoreInterface::class)
            )
        );
        $this->container->singleton(
            RateLimitMiddleware::class,
            static fn (Container $container): RateLimitMiddleware => new RateLimitMiddleware(
                $container->get(RateLimiter::class),
                $container->get(LoggerInterface::class),
                (int) $container->get(Config::class)->get('rate_limit.limit', RateLimitMiddleware::DEFAULT_LIMIT),
                (int) $container->get(Config::class)->get('rate_limit.window', RateLimitMiddleware::DEFAULT_WINDOW)
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
            AuditLoggerInterface::class,
            static fn (Container $container): AuditLoggerInterface => new AuditLogger(
                $container->get(AuditLogRepositoryInterface::class),
                $container->get(LoggerInterface::class),
                (bool) $container->get(Config::class)->get('audit_log.enabled', true),
                (bool) $container->get(Config::class)->get('audit_log.mirror_to_file_logger', false),
            )
        );
        $this->container->singleton(
            AuditLogPruner::class,
            static fn (Container $container): AuditLogPruner => new AuditLogPruner(
                $container->get(AuditLogRepositoryInterface::class),
                $container->get(LoggerInterface::class),
                (int) $container->get(Config::class)->get('audit_log.retention_days', 90),
            )
        );
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
            static fn (Container $container): AuthNotifier => new AuthNotifier(
                $container->get(MailerInterface::class),
                $container->get(LoggerInterface::class),
                WpHelper::blogName(),
                WpHelper::siteUrl(),
            )
        );
        $this->container->singleton(
            TwoFactorService::class,
            static fn (Container $container): TwoFactorService => new TwoFactorService(
                $container->get(TwoFactorUserRepositoryInterface::class),
                $container->get(ChallengeStoreInterface::class),
                $container->get(TotpProvider::class),
                $container->get(EmailOtpProvider::class),
                $container->get(RecoveryCodeService::class),
                $container->get(AuthNotifier::class),
                $container->get(LoggerInterface::class),
                (string) $container->get(Config::class)->get('auth_hardening.two_factor.issuer', 'SecurePress'),
                (int) $container->get(Config::class)->get('auth_hardening.two_factor.challenge_ttl_seconds', TwoFactorService::CHALLENGE_TTL_SECONDS),
            )
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
                $config = $container->get(Config::class);
                $policy = LoginLockoutPolicy::fromArray([
                    'enabled' => (bool) $config->get('auth_hardening.lockout.enabled', true),
                    'max_attempts' => (int) $config->get('auth_hardening.lockout.max_attempts', 5),
                    'window_seconds' => (int) $config->get('auth_hardening.lockout.window_seconds', 900),
                    'lock_seconds' => (int) $config->get('auth_hardening.lockout.lock_seconds', 900),
                ]);

                return new LoginLockoutService(
                    $container->get(LockoutStoreInterface::class),
                    $policy
                );
            }
        );
        $this->container->singleton(
            SuspicionDetector::class,
            static function (Container $container): SuspicionDetector {
                $config = $container->get(Config::class);
                $rules = [];
                if ((bool) $config->get('auth_hardening.suspicion.rules.new_device', true)) {
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
                $config = $container->get(Config::class);

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
                        'enabled' => (bool) $config->get('auth_hardening.enabled', true),
                        'lockout_enabled' => (bool) $config->get('auth_hardening.lockout.enabled', true),
                        'sessions_enabled' => (bool) $config->get('auth_hardening.sessions.enabled', true),
                        'suspicion_enabled' => (bool) $config->get('auth_hardening.suspicion.enabled', true),
                    ]
                );
            }
        );
        $this->container->singleton(
            SessionPruner::class,
            static fn (Container $container): SessionPruner => new SessionPruner(
                $container->get(SessionRepositoryInterface::class),
                $container->get(LoggerInterface::class),
                (int) $container->get(Config::class)->get('auth_hardening.sessions.retention_days', 90),
            )
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
        WpHelper::addFilter('plugin_row_meta', [$this, 'addPluginRowMeta'], 10, 4);
        $this->container->get(SecurityHeadersSettingsPage::class)->register();
        $this->container->get(AuditLogPage::class)->register();
        if ((bool) $this->container->get(Config::class)->get('auth_hardening.enabled', true)) {
            $this->container->get(UserSecurityProfilePage::class)->register();
        }
    }

    private function isMuLoaderInstalled(): bool
    {
        return is_readable($this->getMuLoaderPath());
    }

    private function getMuLoaderPath(): string
    {
        $muDirectory = \defined('WPMU_PLUGIN_DIR')
            ? (string) \constant('WPMU_PLUGIN_DIR')
            : \dirname(SECUREPRESS_PATH) . '/mu-plugins';

        return rtrim($muDirectory, '/') . '/' . SECUREPRESS_MU_LOADER_FILENAME;
    }

}
