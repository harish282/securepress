<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Integrity\Heuristics;

use NiyiGuard\Core\Integrity\FindingSeverity;

/**
 * The classic webshell signature: `eval(base64_decode(...))` and its common variants.
 *
 * Patterns covered:
 *  - `eval(base64_decode($x))`
 *  - `eval(gzinflate(base64_decode($x)))`         (the standard PHP packer combo)
 *  - `eval(gzuncompress(base64_decode($x)))`
 *  - `eval(str_rot13($x))`                        (cheap ROT-13 obfuscation)
 *  - `assert($_REQUEST['x'])`-style assert-as-eval (deprecated in PHP 7.2+, still seen)
 *
 * These patterns are essentially never used in legitimate plugin code — they appear
 * almost exclusively in obfuscated payloads. False positives in real codebases are rare
 * enough that we mark every match as `critical` so they pop to the top of the admin UI.
 *
 * The regex deliberately uses a "decode-then-eval" composition pattern (rather than
 * matching just `eval(` alone) to keep false positives out: a defensive plugin that
 * passes a literal string to `eval` looks the same to PHP but is much less suspicious
 * than the dynamic decode-and-execute combo.
 */
final class EvalBase64Heuristic extends AbstractRegexHeuristic
{
    public function name(): string
    {
        return 'eval_base64';
    }

    protected function severity(): string
    {
        return FindingSeverity::CRITICAL;
    }

    protected function patterns(): array
    {
        return [
            'eval_base64' => '/\beval\s*\(\s*base64_decode\s*\(/i',
            'eval_gzinflate_base64' => '/\beval\s*\(\s*gzinflate\s*\(\s*base64_decode\s*\(/i',
            'eval_gzuncompress_base64' => '/\beval\s*\(\s*gzuncompress\s*\(\s*base64_decode\s*\(/i',
            'eval_str_rot13' => '/\beval\s*\(\s*str_rot13\s*\(/i',
            'assert_request' => '/\bassert\s*\(\s*\$(?:_REQUEST|_POST|_GET|_COOKIE)\b/i',
        ];
    }

    protected function messageFor(string $patternKey): string
    {
        return match ($patternKey) {
            'eval_base64' => 'Dynamic eval() of a base64-decoded payload.',
            'eval_gzinflate_base64' => 'Eval of gzinflate(base64_decode(...)) — classic webshell packer.',
            'eval_gzuncompress_base64' => 'Eval of gzuncompress(base64_decode(...)) — classic webshell packer.',
            'eval_str_rot13' => 'Eval of str_rot13() output — obfuscated payload.',
            'assert_request' => 'assert() with a superglobal — equivalent to eval(user input).',
            default => 'Suspicious eval-with-decode pattern.',
        };
    }
}
