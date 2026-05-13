<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use SecurePress\Admin\MuLoaderDownloadController;
use SecurePress\Admin\MuLoaderStatus;
use SecurePress\Tests\Stubs\WpDieException;
use SecurePress\Tests\Stubs\WpStubState;
use ZipArchive;

/**
 * Tests for the dashboard's "Download MU loader (.zip)" admin-post handler.
 *
 * Coverage:
 *  - Guards (capability + nonce) reject unauthenticated/cross-site requests.
 *  - Happy path produces a real zip whose contents match the template file
 *    plus a self-documenting INSTALL.txt.
 *  - The `register()` hook wires admin_post_* under the public ACTION
 *    constant so the dashboard form's `<input name="action">` value lines up
 *    with what WordPress dispatches.
 */
final class MuLoaderDownloadControllerTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /** @var list<string> ad-hoc files outside our tempDirs, removed individually */
    private array $tempFiles = [];

    private string $templatePath = '';

    protected function setUp(): void
    {
        WpStubState::reset();
        if (!\defined('SECUREPRESS_TESTING')) {
            \define('SECUREPRESS_TESTING', true);
        }

        $dir = \sys_get_temp_dir() . '/securepress-mu-controller-' . \uniqid('', true);
        \mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;
        $this->templatePath = $dir . '/00-securepress-loader.php';
        \file_put_contents(
            $this->templatePath,
            "<?php\n// SecurePress MU loader (test fixture)\nrequire_once __DIR__ . '/securepress.php';\n"
        );
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
        $_POST = $_GET = $_REQUEST = [];
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @\unlink($file);
            }
        }
        $this->tempFiles = [];
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function test_register_hooks_into_admin_post_using_the_public_action_constant(): void
    {
        $controller = $this->makeController();
        $controller->register();

        self::assertTrue(
            WpStubState::hasAction('admin_post_' . MuLoaderDownloadController::ACTION),
            'controller must register admin_post_securepress_mu_loader_download'
        );
    }

    public function test_handle_aborts_without_manage_options_capability(): void
    {
        $controller = $this->makeController();
        // No capability granted on purpose.
        WpStubState::registerNonce(MuLoaderDownloadController::ACTION, 'tk');
        $_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = 'tk';

        try {
            $controller->handle();
            self::fail('handle() must wp_die without manage_options.');
        } catch (WpDieException) {
            // expected
        }

        self::assertSame(403, WpStubState::$wpDieCalls[0]['args']['response']);
    }

    public function test_handle_aborts_on_invalid_nonce(): void
    {
        $controller = $this->makeController();
        WpStubState::$currentUserCapabilities = ['manage_options' => true];
        $_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = 'forged-token';

        $this->expectException(WpDieException::class);
        $controller->handle();
    }

    public function test_build_payload_returns_zip_with_loader_and_install_notes(): void
    {
        $controller = $this->makeController();

        $payload = $controller->buildPayload();

        self::assertSame('zip', $payload['format']);
        self::assertSame('application/zip', $payload['contentType']);
        self::assertSame('securepress-mu-loader.zip', $payload['filename']);
        self::assertNotSame('', $payload['body']);

        // Round-trip the zip back through ZipArchive to confirm the
        // contents an admin would actually extract — not just that we sent
        // *some* bytes.
        $zipPath = $this->writeTempFile($payload['body'], '.zip');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true, 'response body must be a valid zip');

        try {
            self::assertSame(2, $zip->numFiles, 'expected loader + INSTALL.txt');

            $loader = $zip->getFromName('00-securepress-loader.php');
            self::assertIsString($loader);
            self::assertStringContainsString('SecurePress MU loader', $loader);

            $readme = $zip->getFromName('INSTALL.txt');
            self::assertIsString($readme);
            self::assertStringContainsString('wp-content/mu-plugins', $readme);
            self::assertStringContainsString('00-securepress-loader.php', $readme);
        } finally {
            $zip->close();
        }
    }

    public function test_handle_does_not_emit_headers_or_exit_in_test_mode(): void
    {
        $controller = $this->makeController();
        WpStubState::$currentUserCapabilities = ['manage_options' => true];
        WpStubState::registerNonce(MuLoaderDownloadController::ACTION, 'tk');
        $_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = 'tk';

        // The SECUREPRESS_TESTING short-circuit means handle() returns
        // cleanly without sending headers or calling exit. If it didn't,
        // we'd never reach the assertion line.
        $controller->handle();

        self::assertSame([], WpStubState::$wpDieCalls, 'guards must pass');
    }

    private function makeController(): MuLoaderDownloadController
    {
        $status = new MuLoaderStatus($this->templatePath, \sys_get_temp_dir(), '00-securepress-loader.php');

        return new MuLoaderDownloadController($status);
    }

    private function writeTempFile(string $contents, string $suffix): string
    {
        // Write inside one of our scoped fixture dirs so cleanup never
        // sweeps outside the tests' own scratch area.
        $dir = $this->tempDirs[0] ?? \sys_get_temp_dir();
        $path = $dir . '/sp-zip-' . \uniqid('', true) . $suffix;
        \file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
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
