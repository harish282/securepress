<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\SuspiciousLogin;

/**
 * Aggregated output of the rule engine — a numeric score and the list of rule names
 * that fired.
 *
 * Score interpretation is intentionally rough:
 *  - 0      — nothing notable, behave normally.
 *  - 1..49  — slight anomaly (e.g., new IP within same /16) — log it but don't act.
 *  - 50+    — significant anomaly — surface to the user (email alert, force step-up).
 *
 * Callers decide what to do with the score; the detector itself never blocks logins.
 */
final class SuspicionResult
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(public readonly int $score, public readonly array $reasons)
    {
    }

    public static function clean(): self
    {
        return new self(0, []);
    }

    public function isSuspicious(int $threshold = 50): bool
    {
        return $this->score >= $threshold;
    }
}
