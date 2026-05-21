<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity\Heuristics;

/**
 * Base class for heuristics that look for one or more regex patterns in the file body.
 *
 * Each match becomes a {@see HeuristicMatch} carrying the line number (1-indexed) and a
 * trimmed snippet of the offending line. Long lines (typical of obfuscated payloads
 * which are minified to single-line) are truncated to {@see SNIPPET_LIMIT} characters
 * with an ellipsis — the snippet exists to help the admin recognise the pattern, not
 * to be a forensic record of the payload.
 *
 * Concrete heuristics supply their own `patterns()`, `name()`, `severity()`, and
 * `messageFor()` so each rule can carry its own narrative for the admin UI. The base
 * handles the boring scanning bits identically across every heuristic.
 */
abstract class AbstractRegexHeuristic implements HeuristicInterface
{
    private const SNIPPET_LIMIT = 200;

    public function scan(string $content): array
    {
        $matches = [];

        foreach ($this->patterns() as $patternKey => $pattern) {
            if (!preg_match_all($pattern, $content, $hits, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $hitRows = $hits[0] ?? [];
            if (!is_array($hitRows)) {
                continue;
            }
            foreach ($hitRows as $hit) {
                if (!is_array($hit) || !isset($hit[0], $hit[1])) {
                    continue;
                }
                $offset = (int) $hit[1];
                $matches[] = new HeuristicMatch(
                    heuristic: $this->name(),
                    pattern: (string) $patternKey,
                    severity: $this->severity(),
                    message: $this->messageFor((string) $patternKey),
                    line: $this->lineFromOffset($content, $offset),
                    snippet: $this->snippetAround($content, $offset),
                );
            }
        }

        return $matches;
    }

    /**
     * @return array<string, string> Keyed regex patterns. The key is opaque to the base
     *                               class — it's passed back to `messageFor()` so each
     *                               pattern can produce a tailored explanation.
     */
    abstract protected function patterns(): array;

    abstract protected function severity(): string;

    abstract protected function messageFor(string $patternKey): string;

    private function lineFromOffset(string $content, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    private function snippetAround(string $content, int $offset): string
    {
        $start = strrpos(substr($content, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($content, "\n", $offset);
        $end = $end === false ? strlen($content) : $end;
        $line = trim(substr($content, $start, $end - $start));
        if (strlen($line) > self::SNIPPET_LIMIT) {
            $line = substr($line, 0, self::SNIPPET_LIMIT) . '…';
        }

        return $line;
    }
}
