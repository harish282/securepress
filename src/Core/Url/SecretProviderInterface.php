<?php

declare(strict_types=1);

namespace SecurePress\Core\Url;

/**
 * Supplies the HMAC secret used by {@see UrlSigner}.
 *
 * Implementations MUST return a non-empty, sufficiently entropic byte string. The signer
 * treats the value opaquely, so any binary-safe string of >= 32 bytes is acceptable.
 *
 * Implementations SHOULD throw {@see SignedUrlException} when no secret is available rather
 * than returning a default; silent fallback to a known value is a footgun.
 */
interface SecretProviderInterface
{
    /**
     * @throws SignedUrlException When no secret can be resolved.
     */
    public function secret(): string;
}
