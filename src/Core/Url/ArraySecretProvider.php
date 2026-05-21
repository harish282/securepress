<?php

declare(strict_types=1);

namespace PressSentinel\Core\Url;

/**
 * In-memory {@see SecretProviderInterface} for tests and bootstrap fixtures.
 */
final class ArraySecretProvider implements SecretProviderInterface
{
    public function __construct(private readonly string $secret)
    {
        if ($this->secret === '') {
            throw new SignedUrlException('ArraySecretProvider requires a non-empty secret.');
        }
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
