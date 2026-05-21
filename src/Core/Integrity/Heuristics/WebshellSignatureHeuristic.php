<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity\Heuristics;

use PressSentinel\Core\Integrity\FindingSeverity;

/**
 * Looks for string fingerprints of well-known PHP webshells.
 *
 * These match author headers, common variable names, and login-prompt copy that ship
 * verbatim in publicly-distributed shells like c99, r57, WSO, b374k, FilesMan, MarijuanaShell.
 *
 * Match severity is `critical` — these strings have no business existing in any
 * legitimate WordPress install. Even when a researcher legitimately stores a sample for
 * analysis, the integrity scanner is the correct place for it to surface, so the
 * operator can review and whitelist via "mark reviewed".
 */
final class WebshellSignatureHeuristic extends AbstractRegexHeuristic
{
    public function name(): string
    {
        return 'webshell_signature';
    }

    protected function severity(): string
    {
        return FindingSeverity::CRITICAL;
    }

    protected function patterns(): array
    {
        return [
            'c99' => '/c99shell/i',
            'r57' => '/r57shell/i',
            'wso' => '/WSO\s*[0-9]+\.[0-9]+/',
            'b374k' => '/b374k/i',
            'filesman' => '/FilesMan/',
            'marijuana_shell' => '/Marijuana\s*Shell/i',
            'auth_pass' => '/\$auth_pass\s*=\s*[\'"][0-9a-f]{32}[\'"]/i',
            'shell_password_prompt' => '/PHP\s*Web\s*Shell\s*by/i',
        ];
    }

    protected function messageFor(string $patternKey): string
    {
        return match ($patternKey) {
            'c99' => 'c99 webshell signature.',
            'r57' => 'r57 webshell signature.',
            'wso' => 'WSO (Web Shell by oRb) signature.',
            'b374k' => 'b374k webshell signature.',
            'filesman' => 'FilesMan webshell signature.',
            'marijuana_shell' => 'Marijuana Shell signature.',
            'auth_pass' => 'Hard-coded MD5 password (`$auth_pass`) — common in webshells.',
            'shell_password_prompt' => 'Webshell-style password prompt header.',
            default => 'Known webshell signature.',
        };
    }
}
