<?php

declare(strict_types=1);

namespace SecurePress\Core\Integrity\Heuristics;

/**
 * Single hit produced by a {@see HeuristicInterface} scan.
 *
 * Kept as a small struct so heuristics can return a *list* of matches per file — a
 * single backdoor often trips multiple checks (eval+base64 AND obfuscated callable),
 * and surfacing every one of them is more useful than collapsing into one finding.
 *
 * `line` is 1-indexed (`0` means "no specific line — applies to whole file"). The
 * `snippet` is the offending line trimmed to a sensible display length so the admin UI
 * can render it without truncation logic.
 */
final class HeuristicMatch
{
    public function __construct(
        public readonly string $heuristic,
        public readonly string $pattern,
        public readonly string $severity,
        public readonly string $message,
        public readonly int $line,
        public readonly string $snippet,
    ) {
    }
}
