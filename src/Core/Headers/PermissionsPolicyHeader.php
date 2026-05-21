<?php

declare(strict_types=1);

namespace PressSentinel\Core\Headers;

/**
 * Browser feature opt-out via `Permissions-Policy` (the successor to `Feature-Policy`).
 *
 * The policy string is emitted verbatim. Format is `feature=(allowlist), feature=(allowlist)`
 * where `()` means "deny everywhere". A safe default for content sites that don't use these
 * APIs:
 *
 *     geolocation=(), camera=(), microphone=(), payment=(), usb=(), accelerometer=(),
 *     gyroscope=(), magnetometer=(), interest-cohort=()
 *
 * Note `interest-cohort=()` opts the site out of Google FLoC / Topics tracking — keep it
 * unless you have a reason not to.
 */
final class PermissionsPolicyHeader implements HeaderInterface
{
    public const DEFAULT_POLICY = 'geolocation=(), camera=(), microphone=(), payment=(), usb=(), accelerometer=(), gyroscope=(), magnetometer=(), interest-cohort=()';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $policy = self::DEFAULT_POLICY,
    ) {
    }

    public function name(): string
    {
        return 'Permissions-Policy';
    }

    public function value(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $trimmed = trim($this->policy);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }
}
