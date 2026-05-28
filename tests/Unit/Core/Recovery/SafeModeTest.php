<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Core\Recovery;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Recovery\SafeMode;

final class SafeModeTest extends TestCase
{
    protected function tearDown(): void
    {
        if (\function_exists('remove_all_filters')) {
            \remove_all_filters('niyiguard_safe_mode');
            \remove_all_filters('niyiguard_safe_mode_bypasses');
        }
    }

    public function test_is_inactive_by_default(): void
    {
        self::assertFalse(SafeMode::isActive());
        self::assertFalse(SafeMode::bypasses(SafeMode::BYPASS_LOCKOUT));
        self::assertSame([], SafeMode::activeBypasses());
    }

    public function test_filter_can_enable_safe_mode(): void
    {
        if (!\function_exists('add_filter')) {
            self::markTestSkipped('WordPress filter API not available.');
        }

        \add_filter('niyiguard_safe_mode', static fn (): bool => true);

        self::assertTrue(SafeMode::isActive());
        self::assertTrue(SafeMode::bypasses(SafeMode::BYPASS_LOGIN_DISGUISE));
        self::assertNotEmpty(SafeMode::activeBypasses());
    }

    public function test_filter_can_limit_bypasses(): void
    {
        if (!\function_exists('add_filter')) {
            self::markTestSkipped('WordPress filter API not available.');
        }

        \add_filter('niyiguard_safe_mode', static fn (): bool => true);
        \add_filter(
            'niyiguard_safe_mode_bypasses',
            static fn (): array => [SafeMode::BYPASS_LOCKOUT]
        );

        self::assertTrue(SafeMode::bypasses(SafeMode::BYPASS_LOCKOUT));
        self::assertFalse(SafeMode::bypasses(SafeMode::BYPASS_RATE_LIMIT));
    }
}
