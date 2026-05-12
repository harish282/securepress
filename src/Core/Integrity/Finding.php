<?php

declare(strict_types=1);

namespace SecurePress\Core\Integrity;

/**
 * Immutable value object describing one piece of evidence emitted by a scanner.
 *
 * Findings are the single, canonical currency exchanged between the scanners and the
 * persistence / UI layers. Whether the evidence is "a core file was modified" or
 * "this PHP file looks like an eval/base64 backdoor", consumers don't have to know
 * which subsystem produced it — they just read the `type` + `severity` + `details`
 * triple and render or alert.
 *
 * The `details` array carries scanner-specific structured data:
 *  - manifest diffs include `old_hash` / `new_hash` / `old_size` / `new_size`;
 *  - heuristic findings include `heuristic`, `line`, `snippet`, `pattern`;
 *  - core-tamper findings include `expected_hash` / `actual_hash` / `wp_version`.
 *
 * `id` is `null` until persisted. The repository hands back a copy via
 * {@see withId()} so the original event is never mutated.
 */
final class Finding
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $scope,
        public readonly string $type,
        public readonly string $severity,
        public readonly string $path,
        public readonly string $message,
        /** @var array<string, mixed> */
        public readonly array $details,
        public readonly int $createdAt,
        public readonly ?int $reviewedAt,
    ) {
    }

    public static function make(
        string $scope,
        string $type,
        string $severity,
        string $path,
        string $message,
        array $details = [],
        ?int $createdAt = null,
    ): self {
        return new self(
            id: null,
            scope: $scope,
            type: $type,
            severity: $severity,
            path: $path,
            message: $message,
            details: $details,
            createdAt: $createdAt ?? time(),
            reviewedAt: null,
        );
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->scope,
            $this->type,
            $this->severity,
            $this->path,
            $this->message,
            $this->details,
            $this->createdAt,
            $this->reviewedAt,
        );
    }

    public function markReviewed(?int $now = null): self
    {
        return new self(
            $this->id,
            $this->scope,
            $this->type,
            $this->severity,
            $this->path,
            $this->message,
            $this->details,
            $this->createdAt,
            $now ?? time(),
        );
    }

    public function isReviewed(): bool
    {
        return $this->reviewedAt !== null;
    }
}
