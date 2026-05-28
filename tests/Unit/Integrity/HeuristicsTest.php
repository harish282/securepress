<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Integrity\FindingSeverity;
use NiyiGuard\Core\Integrity\Heuristics\EvalBase64Heuristic;
use NiyiGuard\Core\Integrity\Heuristics\ObfuscatedCallableHeuristic;
use NiyiGuard\Core\Integrity\Heuristics\PregReplaceEvalHeuristic;
use NiyiGuard\Core\Integrity\Heuristics\ShellExecHeuristic;
use NiyiGuard\Core\Integrity\Heuristics\WebshellSignatureHeuristic;

/**
 * @see \NiyiGuard\Core\Integrity\Heuristics\AbstractRegexHeuristic
 */
final class HeuristicsTest extends TestCase
{
    public function test_eval_base64_matches_classic_payload(): void
    {
        $heuristic = new EvalBase64Heuristic();
        $payload = '<?php eval(base64_decode("ZWNobyAxOw==")); ?>';

        $matches = $heuristic->scan($payload);

        self::assertNotEmpty($matches);
        self::assertSame('eval_base64', $matches[0]->heuristic);
        self::assertSame('eval_base64', $matches[0]->pattern);
        self::assertSame(FindingSeverity::CRITICAL, $matches[0]->severity);
        self::assertSame(1, $matches[0]->line);
        self::assertStringContainsString('eval(base64_decode', $matches[0]->snippet);
    }

    public function test_eval_base64_matches_gzinflate_wrapper(): void
    {
        $heuristic = new EvalBase64Heuristic();
        $payload = "<?php\n@eval(gzinflate(base64_decode(\$x)));\n";

        $matches = $heuristic->scan($payload);

        self::assertSame('eval_gzinflate_base64', $matches[0]->pattern);
        self::assertSame(2, $matches[0]->line);
    }

    public function test_eval_base64_matches_assert_with_superglobal(): void
    {
        $heuristic = new EvalBase64Heuristic();
        $payload = '<?php assert($_REQUEST["cmd"]);';

        $matches = $heuristic->scan($payload);

        self::assertNotEmpty($matches);
        self::assertSame('assert_request', $matches[0]->pattern);
    }

    public function test_eval_base64_ignores_clean_code(): void
    {
        $heuristic = new EvalBase64Heuristic();
        $payload = '<?php $data = base64_decode($trusted); echo strtoupper($data);';

        self::assertSame([], $heuristic->scan($payload));
    }

    public function test_preg_replace_e_flag_is_detected(): void
    {
        $heuristic = new PregReplaceEvalHeuristic();
        $payload = "<?php preg_replace('/foo(.+)/e', 'strtoupper(\"\$1\")', \$x);";

        $matches = $heuristic->scan($payload);

        self::assertNotEmpty($matches);
        self::assertSame('preg_replace_e', $matches[0]->pattern);
        self::assertSame(FindingSeverity::HIGH, $matches[0]->severity);
    }

    public function test_preg_replace_without_e_flag_is_clean(): void
    {
        $heuristic = new PregReplaceEvalHeuristic();
        $payload = "<?php preg_replace('/foo/', 'bar', \$x);";

        self::assertSame([], $heuristic->scan($payload));
    }

    public function test_obfuscated_callable_detects_chr_chain(): void
    {
        $heuristic = new ObfuscatedCallableHeuristic();
        $payload = '<?php $f = chr(101).chr(118).chr(97).chr(108).chr(40);';

        $matches = $heuristic->scan($payload);

        self::assertNotEmpty($matches);
        self::assertSame('chr_chain', $matches[0]->pattern);
    }

    public function test_obfuscated_callable_detects_create_function(): void
    {
        $heuristic = new ObfuscatedCallableHeuristic();
        $payload = "<?php create_function('', 'echo 1;');";

        $matches = $heuristic->scan($payload);

        self::assertNotEmpty($matches);
        self::assertSame('create_function', $matches[0]->pattern);
    }

    public function test_obfuscated_callable_detects_variable_superglobal(): void
    {
        $heuristic = new ObfuscatedCallableHeuristic();
        $payload = '<?php $x = ${"_" . "POST"}["cmd"];';

        $matches = $heuristic->scan($payload);

        self::assertNotEmpty($matches);
        self::assertSame('variable_superglobal', $matches[0]->pattern);
    }

    public function test_webshell_signatures_match_known_strings(): void
    {
        $heuristic = new WebshellSignatureHeuristic();

        $samples = [
            'c99' => '<?php /* c99shell */ ?>',
            'r57' => '<?php /* r57shell v1.0 */ ?>',
            'b374k' => '<?php /* b374k */ ?>',
            'filesman' => '<?php /* FilesMan */ ?>',
            'auth_pass' => '<?php $auth_pass = "5f4dcc3b5aa765d61d8327deb882cf99";',
        ];
        foreach ($samples as $key => $payload) {
            $matches = $heuristic->scan($payload);
            self::assertNotEmpty($matches, "Should match {$key}");
            self::assertSame(FindingSeverity::CRITICAL, $matches[0]->severity);
        }
    }

    public function test_webshell_signatures_are_silent_on_clean_code(): void
    {
        $heuristic = new WebshellSignatureHeuristic();
        $payload = "<?php\nfunction hello() { return 'world'; }\n";

        self::assertSame([], $heuristic->scan($payload));
    }

    public function test_shell_exec_detects_superglobal_argument(): void
    {
        $heuristic = new ShellExecHeuristic();
        $payload = '<?php system($_GET["cmd"]);';

        $matches = $heuristic->scan($payload);

        self::assertNotEmpty($matches);
        self::assertSame('system_superglobal', $matches[0]->pattern);
    }

    public function test_shell_exec_detects_concatenated_dynamic_argument(): void
    {
        $heuristic = new ShellExecHeuristic();
        $payload = '<?php exec("ls " . $dir);';

        $matches = $heuristic->scan($payload);

        self::assertNotEmpty($matches);
        self::assertSame('system_concat', $matches[0]->pattern);
    }

    public function test_shell_exec_ignores_constant_argument(): void
    {
        $heuristic = new ShellExecHeuristic();
        $payload = '<?php exec("ls -la /tmp", $output);';

        self::assertSame([], $heuristic->scan($payload));
    }
}
