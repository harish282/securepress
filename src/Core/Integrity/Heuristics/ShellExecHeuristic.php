<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity\Heuristics;

use PressSentinel\Core\Integrity\FindingSeverity;

/**
 * Flags use of OS command-execution functions with dynamic (variable) arguments.
 *
 * Pure shell-out from a constant string can be legitimate ("call this packaged binary
 * with these fixed args"). The dangerous form is:
 *
 *     system($_GET['cmd']);
 *     shell_exec("ls " . $input);
 *
 * The regex therefore requires the first argument to involve `$_GET` / `$_POST` /
 * `$_REQUEST` / `$_COOKIE` or `$_FILES`, OR be a concatenated expression starting with
 * a string. Plugins that legitimately wrap a binary call almost always use a constant
 * literal as the first arg.
 *
 * Severity defaults to `medium` — the heuristic flags risk but cannot tell legit shell
 * helpers from real attacks without context.
 */
final class ShellExecHeuristic extends AbstractRegexHeuristic
{
    public function name(): string
    {
        return 'shell_exec';
    }

    protected function severity(): string
    {
        return FindingSeverity::MEDIUM;
    }

    protected function patterns(): array
    {
        $superglobal = '\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER)';

        return [
            'system_superglobal' => '/\b(?:system|exec|passthru|shell_exec|popen|proc_open)\s*\(\s*' . $superglobal . '/i',
            'backtick_superglobal' => '/`[^`]*' . $superglobal . '[^`]*`/i',
            'system_concat' => '/\b(?:system|exec|passthru|shell_exec)\s*\(\s*[\'"][^\'"]*[\'"]\s*\.\s*\$/i',
        ];
    }

    protected function messageFor(string $patternKey): string
    {
        return match ($patternKey) {
            'system_superglobal' => 'OS command function called with a request superglobal — command injection vector.',
            'backtick_superglobal' => 'Backtick exec containing a request superglobal — command injection vector.',
            'system_concat' => 'OS command function called with a concatenated dynamic argument.',
            default => 'Suspicious OS command execution.',
        };
    }
}
