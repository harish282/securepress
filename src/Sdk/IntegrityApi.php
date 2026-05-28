<?php

declare(strict_types=1);

namespace NiyiGuard\Sdk;

use NiyiGuard\Core\Container;
use NiyiGuard\Core\Integrity\Finding;
use NiyiGuard\Core\Integrity\FindingRepositoryInterface;
use NiyiGuard\Core\Integrity\IntegrityScanResult;
use NiyiGuard\Core\Integrity\IntegrityScheduler;
use NiyiGuard\Core\Integrity\IntegrityService;
use NiyiGuard\Core\Integrity\ManifestRepositoryInterface;

/**
 * Public surface for the file-integrity subsystem.
 *
 * Exposes the otherwise-internal {@see IntegrityService}, {@see IntegrityScheduler},
 * and the finding / manifest repositories through a single stable API. Callers don't
 * have to remember which DI binding lives where — `Security::integrity()->scan()`,
 * `Security::integrity()->openFindings()`, `Security::integrity()->resetBaseline(...)`,
 * etc. all live on the same instance.
 *
 * Typical operator workflow from code (custom dashboard, WP-CLI, REST endpoint):
 *
 * ```php
 * $api = Security::integrity();
 * $result = $api->scan();                 // ad-hoc trigger
 * foreach ($api->openFindings() as $f) {  // list outstanding issues
 *     // ...
 * }
 * $api->markReviewed($findingId);
 * ```
 */
final class IntegrityApi
{
    public function __construct(private readonly Container $container)
    {
    }

    public function scan(): IntegrityScanResult
    {
        return $this->service()->scan();
    }

    /**
     * @return list<Finding>
     */
    public function openFindings(): array
    {
        return $this->findings()->open();
    }

    /**
     * @return list<Finding>
     */
    public function allFindings(): array
    {
        return $this->findings()->all();
    }

    public function countOpen(): int
    {
        return $this->findings()->countOpen();
    }

    public function markReviewed(int $findingId): bool
    {
        return $this->findings()->markReviewed($findingId);
    }

    public function deleteFinding(int $findingId): bool
    {
        return $this->findings()->delete($findingId);
    }

    public function clearFindings(): int
    {
        return $this->findings()->deleteAll();
    }

    public function resetBaseline(string $scope = ''): void
    {
        $repo = $this->manifests();
        if ($scope === '') {
            foreach ($repo->scopes() as $known) {
                $repo->delete($known);
            }

            return;
        }
        $repo->delete($scope);
    }

    public function scheduler(): IntegrityScheduler
    {
        return $this->container->get(IntegrityScheduler::class);
    }

    private function service(): IntegrityService
    {
        return $this->container->get(IntegrityService::class);
    }

    private function findings(): FindingRepositoryInterface
    {
        return $this->container->get(FindingRepositoryInterface::class);
    }

    private function manifests(): ManifestRepositoryInterface
    {
        return $this->container->get(ManifestRepositoryInterface::class);
    }
}
