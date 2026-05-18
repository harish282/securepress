<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\Sessions;

/**
 * One row in the session log — represents a single (user, device, timeframe) entry.
 *
 * Distinct from `wp_user_meta['session_tokens']`, which is WordPress's own session table:
 * we keep our own copy because (a) we want richer metadata (IP, fingerprint, last_seen,
 * created_at, label) and (b) we want to expose revocation independent of WP's lifecycle.
 *
 * The `revokedAt` field is optional. When set, the session is considered logged-out from
 * PressSentinel's perspective; the matching entry in WP's session_tokens is also expunged
 * by {@see SessionService::revoke()} so the user is forced back to the login screen.
 */
final class SessionRecord
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $userId,
        public readonly string $token,
        public readonly string $fingerprintHash,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly int $createdAt,
        public readonly int $lastSeenAt,
        public readonly ?int $revokedAt = null,
        public readonly ?string $label = null,
    ) {
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->userId,
            $this->token,
            $this->fingerprintHash,
            $this->ip,
            $this->userAgent,
            $this->createdAt,
            $this->lastSeenAt,
            $this->revokedAt,
            $this->label,
        );
    }

    public function withLastSeen(int $timestamp): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->token,
            $this->fingerprintHash,
            $this->ip,
            $this->userAgent,
            $this->createdAt,
            $timestamp,
            $this->revokedAt,
            $this->label,
        );
    }

    public function revoke(int $timestamp): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->token,
            $this->fingerprintHash,
            $this->ip,
            $this->userAgent,
            $this->createdAt,
            $this->lastSeenAt,
            $timestamp,
            $this->label,
        );
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'token' => $this->token,
            'fingerprint_hash' => $this->fingerprintHash,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'created_at' => $this->createdAt,
            'last_seen_at' => $this->lastSeenAt,
            'revoked_at' => $this->revokedAt,
            'label' => $this->label,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (int) ($row['user_id'] ?? 0),
            (string) ($row['token'] ?? ''),
            (string) ($row['fingerprint_hash'] ?? ''),
            isset($row['ip']) && is_string($row['ip']) ? $row['ip'] : null,
            isset($row['user_agent']) && is_string($row['user_agent']) ? $row['user_agent'] : null,
            (int) ($row['created_at'] ?? 0),
            (int) ($row['last_seen_at'] ?? 0),
            isset($row['revoked_at']) && $row['revoked_at'] !== null ? (int) $row['revoked_at'] : null,
            isset($row['label']) && is_string($row['label']) && $row['label'] !== '' ? $row['label'] : null,
        );
    }
}
