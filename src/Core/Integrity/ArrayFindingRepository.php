<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Integrity;

/**
 * In-memory finding store for tests.
 *
 * Maintains its own auto-increment id so persisted findings round-trip through
 * {@see Finding::withId()} the same way the wpdb implementation does. Order of
 * iteration is insertion order, which is what the tests assert against.
 */
final class ArrayFindingRepository implements FindingRepositoryInterface
{
    /** @var array<int, Finding> */
    private array $items = [];

    private int $autoIncrement = 0;

    public function record(Finding $finding): Finding
    {
        $this->autoIncrement++;
        $stored = $finding->withId($this->autoIncrement);
        $this->items[$stored->id] = $stored;

        return $stored;
    }

    public function markReviewed(int $id, ?int $now = null): bool
    {
        if (!isset($this->items[$id])) {
            return false;
        }
        $this->items[$id] = $this->items[$id]->markReviewed($now);

        return true;
    }

    public function delete(int $id): bool
    {
        if (!isset($this->items[$id])) {
            return false;
        }
        unset($this->items[$id]);

        return true;
    }

    public function deleteAll(): int
    {
        $count = count($this->items);
        $this->items = [];
        $this->autoIncrement = 0;

        return $count;
    }

    public function open(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (Finding $f): bool => !$f->isReviewed()
        ));
    }

    public function all(): array
    {
        return array_values($this->items);
    }

    public function countOpen(): int
    {
        $count = 0;
        foreach ($this->items as $finding) {
            if (!$finding->isReviewed()) {
                $count++;
            }
        }

        return $count;
    }
}
