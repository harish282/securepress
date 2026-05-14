<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use SecurePress\Admin\FeatureRegistry;
use SecurePress\Admin\MuLoaderDownloadController;
use SecurePress\Admin\MuLoaderStatus;
use SecurePress\Admin\SecurePressMenuPage;
use SecurePress\Core\Audit\ArrayAuditLogRepository;
use SecurePress\Core\Audit\AuditLogOptions;
use SecurePress\Core\Auth\AuthHardeningOptions;
use SecurePress\Core\Config\Config;
use SecurePress\Core\Headers\SecurityHeadersOptions;
use SecurePress\Core\Integrity\ArrayFindingRepository;
use SecurePress\Core\Integrity\IntegrityOptions;
use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Licensing\LicenseStatus;
use SecurePress\Core\Licensing\LicenseValidatorInterface;
use SecurePress\Core\RateLimit\RateLimitOptions;
use SecurePress\Core\UrlDisguise\UrlDisguiseOptions;
use SecurePress\Core\View\View;
use SecurePress\Tests\Stubs\WpStubState;
use SecurePress\WooCommerce\Admin\WooCommerceProtectionOptions;

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
        self::assertStringContainsString('00-securepress-loader.php', $html);
        self::assertStringContainsString('mu-plugins', $html);
    }

    public function test_dashboard_hides_callout_when_loader_is_installed(): void
    {
        $page = $this->makePage(isInstalled: true);

        $html = $this->captureRender($page);

        self::assertStringNotContainsString('MU loader not installed', $html);
        self::assertStringNotContainsString('Download MU loader (.zip)', $html);
    }

    private function captureRender(SecurePressMenuPage $page): string
    {
        \ob_start();
        $page->render();
        $output = (string) \ob_get_clean();

        return $output;
    }

    /**
     * Builds a SecurePressMenuPage with real dependencies. We need a real
     * View to render the template (the whole point of the test is the
     * template's output), and real Options/Registry instances so render()
     * doesn't blow up reaching for their methods.
     */
    private function makePage(bool $isInstalled): SecurePressMenuPage
    {
        $config = new Config();

        $muDir = $this->makeFixtureDir();
        $template = $muDir . '/00-securepress-loader.php';
        \file_put_contents($template, "<?php // test fixture\n");

        $muPluginsDir = $this->makeFixtureDir();
        if ($isInstalled) {
            \file_put_contents($muPluginsDir . '/00-securepress-loader.php', "<?php // installed\n");
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

        return new SecurePressMenuPage(
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
        $dir = \sys_get_temp_dir() . '/securepress-dash-mu-' . \uniqid('', true);
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
