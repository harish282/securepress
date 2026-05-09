<?php

declare(strict_types=1);

namespace SecurePress\Core;

use SecurePress\Core\Config\Config;
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
use SecurePress\Middleware\SignedUrlMiddleware;
use SecurePress\Core\Requirements\SystemRequirementsChecker;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Core\View\View;
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
    }

    private function registerAdminHooks(): void
    {
        if (!WpHelper::isAdmin()) {
            return;
        }

        WpHelper::addAction('admin_notices', [$this, 'renderMuLoaderNotice']);
        WpHelper::addFilter('plugin_row_meta', [$this, 'addPluginRowMeta'], 10, 4);
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
