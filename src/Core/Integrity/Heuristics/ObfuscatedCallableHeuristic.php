<?php

declare(strict_types=1);

namespace SecurePress\Core\Integrity\Heuristics;

use SecurePress\Core\Integrity\FindingSeverity;

/**
 * Catches the "let's hide which function we're calling" obfuscation tricks.
 *
 * Common shapes:
 *  - `${'_'.'POST'}[...]`            — string-concat'd superglobal name
 *  - `$f = "ev"."al"; $f($x)`        — split function name (regex catches the call site)
 *  - `chr(101).chr(118).chr(97)...`  — multi-`chr()` chain spelling something
 *  - `\x65\x76\x61\x6c`               — hex-escaped function name in a string literal
 *  - `create_function('', '...')`     — deprecated dynamic function constructor
 *
 * These don't always indicate malware on their own (a `chr()` chain might just be ASCII
 * art), so severity is `HIGH` rather than `CRITICAL` — high enough to be triaged early
 * but not so high that a legitimate plugin trips the immediate-email-alert threshold.
 */
final class ObfuscatedCallableHeuristic extends AbstractRegexHeuristic
{
    public function name(): string
    {
        return 'obfuscated_callable';
    }

    protected function severity(): string
    {
        return FindingSeverity::HIGH;
    }

    protected function patterns(): array
    {
        return [
            'variable_superglobal' => '/\$\{\s*[\'"][_a-zA-Z]+[\'"]\s*\.\s*[\'"][_a-zA-Z]+[\'"]\s*\}/',
            'chr_chain' => '/(?:chr\s*\(\s*\d+\s*\)\s*\.\s*){4,}chr\s*\(\s*\d+\s*\)/i',
            'hex_function_name' => '/\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*[\'"](?:\\\\x[0-9a-f]{2}){3,}/i',
            'create_function' => '/\bcreate_function\s*\(\s*[\'"][^\'"]*[\'"]\s*,/i',
        ];
    }

    protected function messageFor(string $patternKey): string
    {
        return match ($patternKey) {
            'variable_superglobal' => 'Variable-variable that builds a superglobal name from string concatenation.',
            'chr_chain' => 'Long chr() chain — typically used to spell a function name like "eval".',
            'hex_function_name' => 'Hex-encoded string assigned to a variable — usually a hidden function name.',
            'create_function' => 'create_function() — deprecated in PHP 7.2; almost always a dynamic-eval backdoor today.',
            default => 'Suspicious obfuscation of a callable name.',
        };
    }
}
