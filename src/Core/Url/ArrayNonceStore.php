<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Url;

use Closure;

/**
 * In-memory {@see NonceStoreInterface} for tests and as a non-persistent fallback.
 */
final class ArrayNonceStore implements NonceStoreInterface
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @var array<string, int> nonce => expiry timestamp */
    private array $entries = [];

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function register(string $nonce, int $ttl): void
    {
        if ($nonce === '' || $ttl < 1) {
            return;
        }

        $this->entries[$nonce] = ($this->clock)() + $ttl;
    }

    public function consume(string $nonce): bool
    {
        if ($nonce === '' || !isset($this->entries[$nonce])) {
            return false;
        }

        $expires = $this->entries[$nonce];
        unset($this->entries[$nonce]);

        return $expires > ($this->clock)();
    }
}
