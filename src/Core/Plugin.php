<?php

declare(strict_types=1);

namespace SecurePress\Core;

use SecurePress\Core\Config\Config;
use SecurePress\Core\Logging\FileLogger;
use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Core\Requirements\SystemRequirementsChecker;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Core\View\ViewRenderer;

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

        $this->container->get(ViewRenderer::class)->render('admin.notices.requirements', [
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

        $this->container->get(ViewRenderer::class)->render('admin.notices.mu-loader-missing', [
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
            ViewRenderer::class,
            static fn (): ViewRenderer => new ViewRenderer(SECUREPRESS_VIEWS_PATH)
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
