<?php

declare(strict_types=1);

namespace NiyiGuard\Admin;

use Closure;

/**
 * Immutable descriptor for one toggleable NiyiGuard feature.
 *
 * Closures are used for `isEnabled` / `setEnabled` instead of an interface so
 * the FeatureRegistry can wire up existing Options classes (which were not
 * designed to share an interface — they have wildly different sub-schemas)
 * with one line each.
 *
 * @see FeatureRegistry
 */
final class FeatureDescriptor
{
    /**
     * @param Closure(): bool       $isEnabled
     * @param Closure(bool): void   $setEnabled
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        private readonly Closure $isEnabled,
        private readonly Closure $setEnabled,
        public readonly bool $isPro = false,
    ) {
    }

    public function isEnabled(): bool
    {
        return ($this->isEnabled)();
    }

    public function setEnabled(bool $enabled): void
    {
        ($this->setEnabled)($enabled);
    }
}
