<?php

declare(strict_types=1);

namespace PressSentinel\Core\Edition;

/**
 * Whether the current install may use premium-tier features.
 *
 * The public WordPress.org build always returns true. A separate licensing
 * package can replace this binding in a commercial fork.
 */
interface EditionAccess
{
    public function isPro(): bool;

    public function status(): EditionStatus;
}
