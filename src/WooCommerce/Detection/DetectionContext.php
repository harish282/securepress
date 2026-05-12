<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Detection;

/**
 * Immutable request envelope passed through every WooCommerce protection pipeline.
 *
 * Replaces the generic `array<string, mixed>` payload used by the HTTP-level middleware
 * pipeline with a strongly-typed bag of signals every WooCommerce middleware actually
 * needs: who is doing this (IP / UA / user / email), where (route / origin / referer),
 * and what (data, fingerprint, kind).
 *
 * **Immutability matters here**: a single checkout might pass through 5–6 middleware
 * before a final decision is taken. Allowing middleware to mutate the context in place
 * makes side-effects impossible to trace ("which middleware blanked the email?"). The
 * {@see withData()} / {@see withSignal()} / {@see withScore()} helpers return new
 * copies so the pipeline can compose context without aliasing surprises.
 *
 * Signals are *accumulating* — every middleware appends what it learned. The final
 * {@see \SecurePress\WooCommerce\Services\FraudScoreService} reads them all and turns
 * them into a single {@see Decision}.
 */
final class DetectionContext
{
    public const KIND_CHECKOUT = 'checkout';
    public const KIND_REGISTRATION = 'registration';
    public const KIND_CART = 'cart';
    public const KIND_API = 'api';
    public const KIND_COUPON = 'coupon';

    /**
     * @param array<string, mixed> $data    Free-form payload for the kind: posted form
     *                                      values for checkout, REST query args for API, …
     * @param list<Signal>         $signals What previous middleware learned.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly ?string $email = null,
        public readonly ?int $userId = null,
        public readonly array $data = [],
        public readonly array $signals = [],
        public readonly int $score = 0,
        public readonly int $occurredAt = 0,
        public readonly string $route = '',
        public readonly string $referer = '',
        public readonly string $sessionId = '',
    ) {
    }

    public function withData(string $key, mixed $value): self
    {
        $data = $this->data;
        $data[$key] = $value;

        return $this->copyWith(['data' => $data]);
    }

    public function withSignal(Signal $signal): self
    {
        $signals = $this->signals;
        $signals[] = $signal;

        return $this->copyWith([
            'signals' => $signals,
            'score' => $this->score + $signal->weight,
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @return list<Signal>
     */
    public function signalsOfRule(string $ruleName): array
    {
        return array_values(array_filter(
            $this->signals,
            static fn (Signal $signal): bool => $signal->rule === $ruleName,
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copyWith(array $overrides): self
    {
        return new self(
            kind: $overrides['kind'] ?? $this->kind,
            ip: $overrides['ip'] ?? $this->ip,
            userAgent: $overrides['userAgent'] ?? $this->userAgent,
            email: array_key_exists('email', $overrides) ? $overrides['email'] : $this->email,
            userId: array_key_exists('userId', $overrides) ? $overrides['userId'] : $this->userId,
            data: $overrides['data'] ?? $this->data,
            signals: $overrides['signals'] ?? $this->signals,
            score: $overrides['score'] ?? $this->score,
            occurredAt: $overrides['occurredAt'] ?? $this->occurredAt,
            route: $overrides['route'] ?? $this->route,
            referer: $overrides['referer'] ?? $this->referer,
            sessionId: $overrides['sessionId'] ?? $this->sessionId,
        );
    }
}
