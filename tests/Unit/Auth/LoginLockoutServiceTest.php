<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Auth\Lockout\ArrayLockoutStore;
use SecurePress\Core\Auth\Lockout\LoginLockoutPolicy;
use SecurePress\Core\Auth\Lockout\LoginLockoutService;

final class LoginLockoutServiceTest extends TestCase
{
    public function test_threshold_locks_username_and_can_clear(): void
    {
        $now = static fn (): int => 1_000_000;
        $store = new ArrayLockoutStore($now);
        $policy = new LoginLockoutPolicy(3, 3600, 600, true);
        $service = new LoginLockoutService($store, $policy);

        self::assertFalse($service->isLocked('alice', '203.0.113.10'));

        self::assertFalse($service->registerFailure('alice', '203.0.113.10'));
        self::assertFalse($service->registerFailure('alice', '203.0.113.10'));
        self::assertTrue($service->registerFailure('alice', '203.0.113.10'));

        self::assertTrue($service->isLocked('alice', '203.0.113.10'));

        $service->clear('alice', '203.0.113.10');
        self::assertFalse($service->isLocked('alice', '203.0.113.10'));
    }

    public function test_disabled_policy_never_locks(): void
    {
        $store = new ArrayLockoutStore();
        $policy = new LoginLockoutPolicy(1, 60, 60, false);
        $service = new LoginLockoutService($store, $policy);

        self::assertFalse($service->registerFailure('bob', '198.51.100.5'));
        self::assertFalse($service->isLocked('bob', '198.51.100.5'));
    }
}
