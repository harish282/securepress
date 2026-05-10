<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\SuspiciousLogin;

use SecurePress\Core\Auth\SuspiciousLogin\Rules\RuleInterface;

/**
 * Sums the contributions of every registered rule into a single {@see SuspicionResult}.
 *
 * Designed so callers can register additional rules at runtime via {@see addRule()} —
 * for instance, sites with WooCommerce subscriptions might add a rule that flags logins
 * from a country other than the customer's stored billing address. The detector itself
 * is a generic aggregator and has no opinion about which signals are worth scoring.
 */
final class SuspicionDetector
{
    /** @var list<RuleInterface> */
    private array $rules = [];

    /**
     * @param iterable<RuleInterface> $rules
     */
    public function __construct(iterable $rules = [])
    {
        foreach ($rules as $rule) {
            $this->addRule($rule);
        }
    }

    public function addRule(RuleInterface $rule): void
    {
        $this->rules[] = $rule;
    }

    public function evaluate(LoginContext $context): SuspicionResult
    {
        $score = 0;
        $reasons = [];

        foreach ($this->rules as $rule) {
            $contribution = max(0, $rule->evaluate($context));
            if ($contribution === 0) {
                continue;
            }
            $score += $contribution;
            $reasons[] = $rule->name();
        }

        return new SuspicionResult($score, $reasons);
    }
}
