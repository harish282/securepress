<?php

declare(strict_types=1);

namespace SecurePress\Core;

use SecurePress\Core\Config\Config;
use SecurePress\Core\Logging\FileLogger;
use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Core\Requirements\SystemRequirementsChecker;

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
            $this->wpAddAction('admin_notices', [$this, 'renderRequirementsNotice']);

            return;
        }

        $this->container->get(LoggerInterface::class)->info('SecurePress plugin booted.');
        $this->registerAdminHooks();
    }

    public function renderRequirementsNotice(): void
    {
        if (!$this->currentUserCan('manage_options')) {
            return;
        }

        $errors = $this->container->get(SystemRequirementsChecker::class)->errors();
        if ($errors === []) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>SecurePress:</strong></p><ul>';
        foreach ($errors as $error) {
            echo '<li>' . $this->escapeHtml($error) . '</li>';
        }
        echo '</ul></div>';
    }

    public function renderMuLoaderNotice(): void
    {
        if (!$this->currentUserCan('manage_options')) {
            return;
        }

        if ($this->isMuLoaderInstalled()) {
            return;
        }

        $screenId = $this->getCurrentScreenId();
        if ($screenId !== 'plugins') {
            return;
        }

        $expectedPath = $this->getMuLoaderPath();
        $templatePath = SECUREPRESS_MU_LOADER_TEMPLATE_PATH;
        $guidePath = SECUREPRESS_PATH . '/docs/MU_LOADER_INSTALL.md';

        echo '<div class="notice notice-warning"><p><strong>SecurePress:</strong> MU loader is not installed. ';
        echo 'For earliest request monitoring, copy the loader file now.</p>';
        echo '<p><strong>Copy from:</strong> <code>' . $this->escapeHtml($templatePath) . '</code><br />';
        echo '<strong>Copy to:</strong> <code>' . $this->escapeHtml($expectedPath) . '</code><br />';
        echo '<strong>Guide:</strong> <code>' . $this->escapeHtml($guidePath) . '</code></p></div>';
    }

    /**
     * @param array<int, string> $pluginMeta
     * @param array<string, mixed> $pluginData
     * @return array<int, string>
     */
    public function addPluginRowMeta(array $pluginMeta, string $pluginFile, array $pluginData = [], string $status = ''): array
    {
        unset($pluginData, $status);

        if ($pluginFile !== $this->pluginBasename(SECUREPRESS_FILE)) {
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
        if (!$this->isAdmin()) {
            return;
        }

        $this->wpAddAction('admin_notices', [$this, 'renderMuLoaderNotice']);
        $this->wpAddFilter('plugin_row_meta', [$this, 'addPluginRowMeta'], 10, 4);
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

    private function wpAddAction(string $hook, array $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (\function_exists('add_action')) {
            \call_user_func('add_action', $hook, $callback, $priority, $acceptedArgs);
        }
    }

    private function wpAddFilter(string $hook, array $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (\function_exists('add_filter')) {
            \call_user_func('add_filter', $hook, $callback, $priority, $acceptedArgs);
        }
    }

    private function currentUserCan(string $capability): bool
    {
        if (!\function_exists('current_user_can')) {
            return false;
        }

        return (bool) \call_user_func('current_user_can', $capability);
    }

    private function getCurrentScreenId(): ?string
    {
        if (!\function_exists('get_current_screen')) {
            return null;
        }

        $screen = \call_user_func('get_current_screen');
        if (!is_object($screen) || !isset($screen->id) || !is_string($screen->id)) {
            return null;
        }

        return $screen->id;
    }

    private function escapeHtml(string $value): string
    {
        if (\function_exists('esc_html')) {
            return (string) \call_user_func('esc_html', $value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function pluginBasename(string $file): string
    {
        if (\function_exists('plugin_basename')) {
            return (string) \call_user_func('plugin_basename', $file);
        }

        return basename($file);
    }

    private function isAdmin(): bool
    {
        if (!\function_exists('is_admin')) {
            return false;
        }

        return (bool) \call_user_func('is_admin');
    }
}
