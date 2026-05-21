<?php

declare(strict_types=1);

namespace PressSentinel\Core\Url;

/**
 * Outcome of a {@see UrlSigner::verify()} call.
 *
 * The `reason` field distinguishes failure modes so callers (typically middleware) can choose
 * an appropriate HTTP response — for example 410 Gone for `expired` so end users know to
 * request a fresh link, versus 403 Forbidden for `tampered`/`invalid-signature` which suggests
 * an attacker.
 *
 * Possible reasons:
 *  - `valid`               (success)
 *  - `missing-signature`   (no `signature` query param)
 *  - `invalid-signature`   (signature length wrong / not hex)
 *  - `tampered`            (signature does not match canonical payload)
 *  - `expired`             (signature was valid, but `expires` timestamp has passed)
 *  - `malformed`           (URL could not be parsed)
 */
final class SignedUrlResult
{
    /**
     * @param array<string, string> $params Decoded query parameters minus `signature`.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly string $reason,
        public readonly string $path,
        public readonly array $params,
        public readonly ?int $expiresAt,
    ) {
    }

    public static function success(string $path, array $params, ?int $expiresAt): self
    {
        return new self(true, 'valid', $path, $params, $expiresAt);
    }

    public static function failure(string $reason, string $path = '', array $params = [], ?int $expiresAt = null): self
    {
        return new self(false, $reason, $path, $params, $expiresAt);
    }

    public function isExpired(): bool
    {
        return $this->reason === 'expired';
    }

    public function isTampered(): bool
    {
        return in_array($this->reason, ['tampered', 'invalid-signature'], true);
    }
}
