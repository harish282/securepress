<?php

declare(strict_types=1);

namespace SecurePress\Core\Integrity\Heuristics;

/**
 * A single signature/rule that can flag a PHP file as suspicious.
 *
 * Heuristics are intentionally simple, pure functions: given the file's text content,
 * return a list of {@see HeuristicMatch} hits (empty for clean files). They do NOT see
 * the file path, ownership, or any side-channel info — the {@see \SecurePress\Core\Integrity\Scanners\SuspiciousPhpScanner}
 * is responsible for putting them in context (e.g., suppressing matches in /vendor/ or
 * known-good plugin directories).
 *
 * Implementations should:
 *  - bail out early when the content clearly isn't PHP (no `<?` prefix);
 *  - keep regexes anchored / non-greedy to avoid catastrophic backtracking on giant
 *    minified files;
 *  - prefer multiple narrow matches over one wide one — the admin reviews per-line.
 *
 * Severity is the heuristic's call: an `eval(base64_decode($_POST...))` is `critical`,
 * a stray `system()` in plugin source code is `low` because plenty of legit code uses
 * shell-out helpers.
 */
interface HeuristicInterface
{
    /**
     * @return list<HeuristicMatch>
     */
    public function scan(string $content): array;

    public function name(): string;
}
