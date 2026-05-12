<?php

declare(strict_types=1);

namespace SecurePress\Core;

use SecurePress\Admin\AuditLogPage;
use SecurePress\Admin\AuthHardeningSettingsPage;
use SecurePress\Admin\FileIntegrityPage;
use SecurePress\Admin\LicensePage;
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
use SecurePress\Core\Auth\AuthHardeningOptions;
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
use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Licensing\LicenseValidatorInterface;
use SecurePress\Core\Licensing\LocalLicenseValidator;
use SecurePress\WooCommerce\Admin\WooCommerceProtectionOptions;
use SecurePress\WooCommerce\Admin\WooCommerceProtectionPage;
use SecurePress\WooCommerce\Middleware\Api\ApiRateLimitMiddleware;
use SecurePress\WooCommerce\Middleware\Api\SuspiciousRequestMiddleware;
use SecurePress\WooCommerce\Middleware\Cart\CartVelocityMiddleware;
use SecurePress\WooCommerce\Middleware\Cart\CouponAbuseMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\CartSimilarityMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\CheckoutBehaviorMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\DisposableEmailMiddleware as CheckoutDisposableEmailMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\FraudScoreMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\VelocityDetectionMiddleware;
use SecurePress\WooCommerce\Middleware\Registration\HoneypotMiddleware;
use SecurePress\WooCommerce\Middleware\Registration\RegistrationDisposableEmailMiddleware;
use SecurePress\WooCommerce\Middleware\Registration\RegistrationRateLimitMiddleware;
use SecurePress\WooCommerce\Pipelines\ApiPipeline;
use SecurePress\WooCommerce\Pipelines\CartPipeline;
use SecurePress\WooCommerce\Pipelines\CheckoutPipeline;
use SecurePress\WooCommerce\Pipelines\RegistrationPipeline;
use SecurePress\WooCommerce\Services\BehaviorClock;
use SecurePress\WooCommerce\Services\CartFingerprinter;
use SecurePress\WooCommerce\Services\DisposableEmailRegistry;
use SecurePress\WooCommerce\Services\FraudScoreService;
use SecurePress\WooCommerce\Storage\AbuseCounterStoreInterface;
use SecurePress\WooCommerce\Storage\TransientAbuseCounterStore;
use SecurePress\WooCommerce\WooCommerceModule;
use SecurePress\Core\Integrity\Checksums\ChecksumProviderInterface;
use SecurePress\Core\Integrity\Checksums\WpOrgChecksumProvider;
use SecurePress\Core\Integrity\FindingRepositoryInterface;
use SecurePress\Core\Integrity\Heuristics\EvalBase64Heuristic;
use SecurePress\Core\Integrity\Heuristics\HeuristicInterface;
use SecurePress\Core\Integrity\Heuristics\ObfuscatedCallableHeuristic;
use SecurePress\Core\Integrity\Heuristics\PregReplaceEvalHeuristic;
use SecurePress\Core\Integrity\Heuristics\ShellExecHeuristic;
use SecurePress\Core\Integrity\Heuristics\WebshellSignatureHeuristic;
use SecurePress\Core\Integrity\IntegrityOptions;
use SecurePress\Core\Integrity\IntegrityScheduler;
use SecurePress\Core\Integrity\IntegritySchema;
use SecurePress\Core\Integrity\IntegrityService;
use SecurePress\Core\Integrity\ManifestBuilder;
use SecurePress\Core\Integrity\ManifestRepositoryInterface;
use SecurePress\Core\Integrity\Scanners\CoreFilesScanner;
use SecurePress\Core\Integrity\Scanners\ManifestDiffScanner;
use SecurePress\Core\Integrity\Scanners\SuspiciousPhpScanner;
use SecurePress\Core\Integrity\WpdbFindingRepository;
use SecurePress\Core\Integrity\WpdbManifestRepository;
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
use SecurePress\Sdk\Csrf\CsrfTokenManager;
use SecurePress\Sdk\Events\EventDispatcher;
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
                    (string) ($opts['two_factor']['issuer'] ?? 'SecurePress'),
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
                $container->get(LicenseValidatorInterface::class)
            )
        );
        $this->container->singleton(
            LicensePage::class,
            static fn (Container $container): LicensePage => new LicensePage(
                $container->get(LicenseManager::class)
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

                return new CheckoutPipeline([
                    new VelocityDetectionMiddleware(
                        $store,
                        (int) $c['velocity_soft'],
                        (int) $c['velocity_hard'],
                        (int) $c['velocity_window'],
                    ),
                    new CheckoutDisposableEmailMiddleware($container->get(DisposableEmailRegistry::class)),
                    new CartSimilarityMiddleware(
                        $container->get(CartFingerprinter::class),
                        $store,
                    ),
                    new CheckoutBehaviorMiddleware(
                        $container->get(BehaviorClock::class),
                        (int) $c['min_seconds_to_submit'],
                    ),
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
        WpHelper::addFilter('plugin_row_meta', [$this, 'addPluginRowMeta'], 10, 4);

        // Admin pages: lazy-resolved on the *first* `admin_menu` invocation rather
        // than eagerly on every admin pageview. WordPress fires `admin_menu` once
        // per admin request anyway, so this defers the construction (Options +
        // View + LicenseManager etc.) to the moment it's actually used.
        WpHelper::addAction('admin_menu', function (): void {
            $this->container->get(SecurityHeadersSettingsPage::class)->register();
            $this->container->get(AuditLogPage::class)->register();
            $this->container->get(FileIntegrityPage::class)->register();
            $this->container->get(LicensePage::class)->register();
            // The WC settings page is registered unconditionally so admins can
            // discover the feature even on Free. The page itself renders an
            // upgrade prompt when the license isn't active.
            $this->container->get(WooCommerceProtectionPage::class)->register();

            // The Authentication settings page is registered unconditionally —
            // admins need a way to re-enable hardening after toggling it off, so
            // the page must remain reachable even when the master switch is off.
            $this->container->get(AuthHardeningSettingsPage::class)->register();

            if ($this->container->get(AuthHardeningOptions::class)->isEnabled()) {
                $this->container->get(UserSecurityProfilePage::class)->register();
            }
        }, 1);
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
