<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity\Heuristics;

use PressSentinel\Core\Integrity\FindingSeverity;

/**
 * Detects the `preg_replace('/pattern/e', ...)` form, where the `e` modifier silently
 * eval()'s the replacement string.
 *
 * The `e` modifier was deprecated in PHP 5.5 and removed in PHP 7.0, so its presence in
 * a modern WordPress install almost always indicates either:
 *  - a long-dead legacy plugin that has not been touched in a decade (still bad — it's
 *    dead code waiting for a PHP downgrade or a `preg_replace_callback` shim), or
 *  - an attacker-injected backdoor disguised as a "find/replace" helper.
 *
 * The regex matches `preg_replace('...e...'`, `preg_replace("...e..."`, and the
 * `mb_ereg_replace` cousin. Single character class modifiers, escaped slashes inside
 * the pattern, and trailing flags all play nicely with the non-greedy `.*?` body.
 */
final class PregReplaceEvalHeuristic extends AbstractRegexHeuristic
{
    public function name(): string
    {
        return 'preg_replace_e';
    }

    protected function severity(): string
    {
        return FindingSeverity::HIGH;
    }

    protected function patterns(): array
    {
        // Match `preg_replace(<quote><any-non-quote>*/<flags-with-e><quote>`.
        // The `e` modifier comes at the end of the regex pattern, between the closing
        // delimiter `/` and the closing quote of the PHP string. We allow other flag
        // letters before/after (e.g., `/foo/ie`, `/foo/se`).
        return [
            'preg_replace_e' => '/\bpreg_replace\s*\(\s*[\'"][^\'"\n]*\/[a-z]*e[a-z]*[\'"]/i',
            'mb_ereg_replace_e' => '/\bmb_ereg_replace\s*\(\s*[\'"][^\'"\n]*\/[a-z]*e[a-z]*[\'"]/i',
        ];
    }

    protected function messageFor(string $patternKey): string
    {
        return match ($patternKey) {
            'preg_replace_e' => 'preg_replace() with /e modifier — the replacement string is eval()ed.',
            'mb_ereg_replace_e' => 'mb_ereg_replace() with /e modifier — the replacement string is eval()ed.',
            default => 'preg_replace eval pattern.',
        };
    }
}
