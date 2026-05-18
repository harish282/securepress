<?php

declare(strict_types=1);

namespace PressSentinel\Core\Audit;

/**
 * Filter / pagination criteria for {@see AuditLogRepositoryInterface::paginate()}.
 *
 * Public, mutable struct-style fields keep this lightweight at call sites — the admin
 * controller hydrates one of these from `$_GET` and hands it straight to the repo. The
 * repository is responsible for sanitizing the values into safe SQL (or its in-memory
 * equivalent), the query just carries them.
 *
 * Defaults are tuned for the admin list view: most-recent first, 25 per page.
 */
final class AuditLogQuery
{
    public ?string $category = null;

    public ?string $level = null;

    public ?string $search = null;

    public ?int $actorId = null;

    public ?int $dateFrom = null;

    public ?int $dateTo = null;

    public int $perPage = 25;

    public int $page = 1;

    /** @var 'asc'|'desc' */
    public string $direction = 'desc';

    public function withDefaults(): self
    {
        $clone = clone $this;
        $clone->perPage = max(1, min(200, $clone->perPage));
        $clone->page = max(1, $clone->page);
        $clone->direction = $clone->direction === 'asc' ? 'asc' : 'desc';

        return $clone;
    }

    public function offset(): int
    {
        $page = max(1, $this->page);

        return ($page - 1) * max(1, $this->perPage);
    }
}
