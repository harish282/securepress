<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\WooCommerce\Services;

use PHPUnit\Framework\TestCase;
use PressSentinel\WooCommerce\Services\DisposableEmailRegistry;

/**
 * @see \PressSentinel\WooCommerce\Services\DisposableEmailRegistry
 */
final class DisposableEmailRegistryTest extends TestCase
{
    public function test_default_list_contains_well_known_domains(): void
    {
        $registry = new DisposableEmailRegistry();

        self::assertTrue($registry->isDisposable('alice@mailinator.com'));
        self::assertTrue($registry->isDisposable('bob@guerrillamail.com'));
        self::assertTrue($registry->isDisposable('eve@yopmail.com'));
    }

    public function test_legitimate_domains_are_not_flagged(): void
    {
        $registry = new DisposableEmailRegistry();

        self::assertFalse($registry->isDisposable('alice@gmail.com'));
        self::assertFalse($registry->isDisposable('contact@example.com'));
    }

    public function test_lookup_is_case_insensitive(): void
    {
        $registry = new DisposableEmailRegistry();

        self::assertTrue($registry->isDisposable('Person@MAILINATOR.com'));
    }

    public function test_add_domain_extends_registry(): void
    {
        $registry = new DisposableEmailRegistry();
        $registry->addDomain('private-disposable.test');

        self::assertTrue($registry->isDisposable('user@private-disposable.test'));
    }

    public function test_allow_unflags_a_domain(): void
    {
        $registry = new DisposableEmailRegistry();
        $registry->allow('mailinator.com');

        self::assertFalse($registry->isDisposable('user@mailinator.com'));
    }

    public function test_invalid_email_returns_false(): void
    {
        $registry = new DisposableEmailRegistry();

        self::assertFalse($registry->isDisposable('no-at-symbol'));
        self::assertFalse($registry->isDisposable(''));
    }

    public function test_count_returns_number_of_tracked_domains(): void
    {
        $registry = new DisposableEmailRegistry([
            'one.test',
            'two.test',
        ]);

        self::assertSame(2, $registry->count());
    }
}
