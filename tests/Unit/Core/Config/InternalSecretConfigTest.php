<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Config\Config;
use PressSentinel\Tests\Stubs\WpStubState;

final class InternalSecretConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_reads_internal_secret_from_config(): void
    {
        $config = new Config();

        self::assertNotSame('', (string) $config->get('security.internal_secret'));
    }

    public function test_filter_can_override_internal_secret(): void
    {
        \add_filter(
            'presssentinel_internal_secret',
            static fn (): string => 'filter-secret-32bytes-min-length-ok!'
        );

        $config = new Config();

        self::assertSame('filter-secret-32bytes-min-length-ok!', $config->get('security.internal_secret'));
    }
}
