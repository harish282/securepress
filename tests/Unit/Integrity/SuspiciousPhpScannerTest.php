<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Integrity\FindingType;
use NiyiGuard\Core\Integrity\Heuristics\EvalBase64Heuristic;
use NiyiGuard\Core\Integrity\Heuristics\WebshellSignatureHeuristic;
use NiyiGuard\Core\Integrity\Scanners\SuspiciousPhpScanner;

/**
 * @see \NiyiGuard\Core\Integrity\Scanners\SuspiciousPhpScanner
 */
final class SuspiciousPhpScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sp_sps_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function test_emits_suspicious_php_finding_for_eval_base64(): void
    {
        file_put_contents($this->root . '/clean.php', "<?php echo 'hello';");
        file_put_contents($this->root . '/bad.php', "<?php eval(base64_decode('ZWNobyAxOw=='));");

        $scanner = new SuspiciousPhpScanner('plugins', $this->root, [new EvalBase64Heuristic()]);

        $findings = $scanner->scan();

        self::assertNotEmpty($findings);
        $hit = $this->findOfType($findings, FindingType::SUSPICIOUS_PHP);
        self::assertNotNull($hit);
        self::assertSame('bad.php', $hit->path);
        self::assertSame('eval_base64', $hit->details['heuristic'] ?? '');
        self::assertSame('eval_base64', $hit->details['pattern'] ?? '');
        self::assertGreaterThan(0, (int) ($hit->details['line'] ?? 0));
    }

    public function test_webshell_match_uses_webshell_signature_type(): void
    {
        file_put_contents($this->root . '/wso.php', '<?php /* WSO 2.5 */ ?>');

        $scanner = new SuspiciousPhpScanner('plugins', $this->root, [new WebshellSignatureHeuristic()]);

        $findings = $scanner->scan();

        self::assertCount(1, $findings);
        self::assertSame(FindingType::WEBSHELL_SIGNATURE, $findings[0]->type);
    }

    public function test_uploads_scope_flags_any_php_file(): void
    {
        file_put_contents($this->root . '/payload.php', "<?php echo 'hi';");
        file_put_contents($this->root . '/image.jpg', 'JFIF');

        $scanner = new SuspiciousPhpScanner('uploads', $this->root, [], null, 2 * 1024 * 1024, true);

        $findings = $scanner->scan();
        $hit = $this->findOfType($findings, FindingType::PHP_IN_UPLOADS);

        self::assertNotNull($hit);
        self::assertSame('payload.php', $hit->path);
        self::assertSame('uploads', $hit->scope);
    }

    public function test_uploads_scope_off_does_not_flag_php_alone(): void
    {
        file_put_contents($this->root . '/legit.php', "<?php echo 'ok';");

        $scanner = new SuspiciousPhpScanner('plugins', $this->root, [], null, 2 * 1024 * 1024, false);

        self::assertSame([], $scanner->scan());
    }

    public function test_double_extension_is_detected(): void
    {
        file_put_contents($this->root . '/payload.php.jpg', '<?php echo 1;');

        $scanner = new SuspiciousPhpScanner('uploads', $this->root, [], null, 2 * 1024 * 1024, false);

        $findings = $scanner->scan();
        $hit = $this->findOfType($findings, FindingType::DOUBLE_EXTENSION);

        self::assertNotNull($hit);
        self::assertSame('payload.php.jpg', $hit->path);
    }

    public function test_skip_directories_are_honored(): void
    {
        mkdir($this->root . '/node_modules', 0777, true);
        file_put_contents($this->root . '/node_modules/x.php', "<?php eval(base64_decode('ZQ=='));");

        $scanner = new SuspiciousPhpScanner('plugins', $this->root, [new EvalBase64Heuristic()]);

        self::assertSame([], $scanner->scan());
    }

    private function findOfType(array $findings, string $type)
    {
        foreach ($findings as $finding) {
            if ($finding->type === $type) {
                return $finding;
            }
        }

        return null;
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
