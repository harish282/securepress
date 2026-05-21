<?php

declare(strict_types=1);

namespace PressSentinel\Core\Http;

/**
 * Collects URI patterns guarded by middleware or future route protection.
 *
 * Dispatcher logic will consume this in a later sprint.
 */
final class RouteGuardRegistry
{
    /** @var list<string> */
    private array $patterns = [];

    public function register(string $pathOrPattern): void
    {
        $pathOrPattern = trim($pathOrPattern);
        if ($pathOrPattern === '') {
            return;
        }
        $this->patterns[] = $pathOrPattern;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->patterns;
    }
}
