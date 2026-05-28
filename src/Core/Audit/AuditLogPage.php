<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Audit;

/**
 * One page of audit log results — the items + pagination metadata for the admin UI.
 */
final class AuditLogPage
{
    /**
     * @param list<AuditEvent> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function totalPages(): int
    {
        if ($this->perPage <= 0 || $this->total <= 0) {
            return 1;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
