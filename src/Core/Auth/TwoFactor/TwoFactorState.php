<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\TwoFactor;

/**
 * Immutable snapshot of a user's 2FA configuration as persisted by the repository.
 *
 * Stored in user_meta as a single JSON blob (one row per user) — the repository serialises
 * to/from this object. The `recoveryCodeHashes` array contains *hashes* only; plain text
 * codes are shown to the user once at generation time and never persisted.
 */
final class TwoFactorState
{
    /**
     * @param list<string> $recoveryCodeHashes
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly ?string $method,
        public readonly ?string $secret,
        public readonly array $recoveryCodeHashes,
        public readonly ?int $enabledAt,
        public readonly ?int $lastUsedAt = null,
    ) {
    }

    public static function disabled(): self
    {
        return new self(false, null, null, [], null, null);
    }

    /**
     * @param list<string> $recoveryHashes
     */
    public static function enabled(string $method, ?string $secret, array $recoveryHashes, int $enabledAt): self
    {
        return new self(true, $method, $secret, $recoveryHashes, $enabledAt, null);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->method !== null;
    }

    public function withRecoveryCodeHashes(array $hashes): self
    {
        return new self($this->enabled, $this->method, $this->secret, array_values($hashes), $this->enabledAt, $this->lastUsedAt);
    }

    public function withLastUsedAt(int $timestamp): self
    {
        return new self($this->enabled, $this->method, $this->secret, $this->recoveryCodeHashes, $this->enabledAt, $timestamp);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'method' => $this->method,
            'secret' => $this->secret,
            'recovery_code_hashes' => $this->recoveryCodeHashes,
            'enabled_at' => $this->enabledAt,
            'last_used_at' => $this->lastUsedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $hashes = $row['recovery_code_hashes'] ?? [];

        return new self(
            (bool) ($row['enabled'] ?? false),
            isset($row['method']) && is_string($row['method']) && $row['method'] !== '' ? $row['method'] : null,
            isset($row['secret']) && is_string($row['secret']) && $row['secret'] !== '' ? $row['secret'] : null,
            is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [],
            isset($row['enabled_at']) ? (int) $row['enabled_at'] : null,
            isset($row['last_used_at']) ? (int) $row['last_used_at'] : null,
        );
    }
}
