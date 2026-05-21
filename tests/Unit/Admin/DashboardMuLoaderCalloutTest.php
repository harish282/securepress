<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PressSentinel\Admin\FeatureRegistry;
use PressSentinel\Admin\MuLoaderDownloadController;
use PressSentinel\Admin\MuLoaderStatus;
use PressSentinel\Admin\PressSentinelMenuPage;
use PressSentinel\Core\Audit\ArrayAuditLogRepository;
use PressSentinel\Core\Audit\AuditLogOptions;
use PressSentinel\Core\Auth\AuthHardeningOptions;
use PressSentinel\Core\Config\Config;
use PressSentinel\Core\Headers\SecurityHeadersOptions;
use PressSentinel\Core\Integrity\ArrayFindingRepository;
use PressSentinel\Core\Integrity\IntegrityOptions;
use PressSentinel\Core\Licensing\LicenseManager;
use PressSentinel\Core\Licensing\LicenseStatus;
use PressSentinel\Core\Licensing\LicenseValidatorInterface;
use PressSentinel\Core\RateLimit\RateLimitOptions;
use PressSentinel\Core\UrlDisguise\UrlDisguiseOptions;
use PressSentinel\Core\View\View;
use PressSentinel\Tests\Stubs\WpStubState;
use PressSentinel\WooCommerce\Admin\WooCommerceProtectionOptions;

/**
 * Verifies the dashboard renders the "MU loader not installed" callout when
 * appropriate, and hides it once the loader is in place.
 *
 * Together with MuLoaderStatusTest + MuLoaderDownloadControllerTest, this
 * closes the loop on the user-visible behaviour: detection → guidance →
 * download button → admin-post handler.
 */
final class DashboardMuLoaderCalloutTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        WpStubState::reset();
        WpStubState::$currentUserCapabilities = ['manage_options' => true];
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function test_dashboard_renders_setup_callout_with_download_button_when_loader_missing(): void
    {
        $page = $this->makePage(isInstalled: false);

        $html = $this->captureRender($page);

        self::assertStringContainsString('MU loader not installed', $html);
        self::assertStringContainsString('Why this matters', $html);
        self::assertStringContainsString('Download MU loader (.zip)', $html);

        // The download button must POST to admin-post.php with our
        // action + nonce — otherwise WordPress won't route it.
        self::assertStringContainsString('action="' . WpStubState::$siteUrl . '/wp-admin/admin-post.php"', $html);
        self::assertStringContainsString(
            'name="action" value="' . MuLoaderDownloadController::ACTION . '"',
            $html
        );
        self::assertStringContainsString('_wpnonce', $html);

        // Setup steps name the expected destination directory + filename so
        // admins know exactly where to drop the extracted file.
        self::assertStringContainsString('00-press-sentinel-loader.php', $html);
        self::assertStringContainsString('mu-plugins', $html);
    }

    public function test_dashboard_hides_callout_when_loader_is_installed(): void
    {
        $page = $this->makePage(isInstalled: true);

        $html = $this->captureRender($page);

        self::assertStringNotContainsString('MU loader not installed', $html);
        self::assertStringNotContainsString('Download MU loader (.zip)', $html);
    }

    private function captureRender(PressSentinelMenuPage $page): string
    {
        \ob_start();
        $page->render();
        $output = (string) \ob_get_clean();

        return $output;
    }

    /**
     * Builds a PressSentinelMenuPage with real dependencies. We need a real
     * View to render the template (the whole point of the test is the
     * template's output), and real Options/Registry instances so render()
     * doesn't blow up reaching for their methods.
     */
    private function makePage(bool $isInstalled): PressSentinelMenuPage
    {
        $config = new Config();

        $muDir = $this->makeFixtureDir();
        $template = $muDir . '/00-press-sentinel-loader.php';
        \file_put_contents($template, "<?php // test fixture\n");

        $muPluginsDir = $this->makeFixtureDir();
        if ($isInstalled) {
            \file_put_contents($muPluginsDir . '/00-press-sentinel-loader.php', "<?php // installed\n");
        }

        $status = new MuLoaderStatus($template, $muPluginsDir);

        $validator = new class () implements LicenseValidatorInterface {
            public function validate(string $key): LicenseStatus
            {
                return LicenseStatus::none();
            }
        };
        $license = new LicenseManager($validator, new Config());

        $features = new FeatureRegistry(
            new AuditLogOptions($config),
            new AuthHardeningOptions($config),
            new SecurityHeadersOptions($config),
            new IntegrityOptions($config),
            new WooCommerceProtectionOptions($config),
            new RateLimitOptions($config),
            new UrlDisguiseOptions($config),
            $license,
        );

        $viewsDir = \dirname(__DIR__, 3) . '/resources/views';

        return new PressSentinelMenuPage(
            $license,
            new AuthHardeningOptions($config),
            new SecurityHeadersOptions($config),
            new IntegrityOptions($config),
            new ArrayFindingRepository(),
            new ArrayAuditLogRepository(),
            $features,
            $status,
            new View($viewsDir),
        );
    }

    private function makeFixtureDir(): string
    {
        $dir = \sys_get_temp_dir() . '/presssentinel-dash-mu-' . \uniqid('', true);
        \mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
