<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\SuspiciousLogin\Rules;

use PressSentinel\Core\Auth\SuspiciousLogin\LoginContext;

/**
 * Contract for individual suspicion rules.
 *
 * Each rule examines the {@see LoginContext} and returns either a positive integer score
 * (the rule fired, with this contribution to the overall result) or `0` (no signal).
 * Rules MUST be deterministic and side-effect-free except for read-only repository
 * lookups so they're safe to invoke repeatedly during testing.
 */
interface RuleInterface
{
    public function name(): string;

    public function evaluate(LoginContext $context): int;
}
