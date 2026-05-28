<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Auth\TwoFactor\RecoveryCodeService;

final class RecoveryCodeServiceTest extends TestCase
{
    public function test_generates_eight_codes_by_default(): void
    {
        $service = new RecoveryCodeService();
        $bundle = $service->generate();

        self::assertCount(8, $bundle['plain']);
        self::assertCount(8, $bundle['hashes']);

        foreach ($bundle['plain'] as $code) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{5}-[a-f0-9]{5}$/', $code);
        }
    }

    public function test_codes_round_trip_through_consume(): void
    {
        $service = new RecoveryCodeService(3);
        $bundle = $service->generate();

        $remaining = $service->consume($bundle['plain'][0], $bundle['hashes']);
        self::assertNotNull($remaining);
        self::assertCount(2, $remaining);

        self::assertNull($service->consume($bundle['plain'][0], $remaining));

        $remaining = $service->consume($bundle['plain'][1], $remaining);
        self::assertNotNull($remaining);
        self::assertCount(1, $remaining);

        self::assertNull($service->consume($bundle['plain'][1], $remaining));
    }

    public function test_consume_accepts_case_and_spacing_variants(): void
    {
        $service = new RecoveryCodeService(1);

        $bundle = $service->generate();
        $plain = $bundle['plain'][0];

        self::assertNotNull($service->consume(strtoupper($plain), $bundle['hashes']));

        $bundle = $service->generate();
        $plain = $bundle['plain'][0];
        self::assertNotNull($service->consume(str_replace('-', '', $plain), $bundle['hashes']));

        $bundle = $service->generate();
        $plain = $bundle['plain'][0];
        self::assertNotNull($service->consume(' ' . $plain . ' ', $bundle['hashes']));
    }

    public function test_consume_returns_null_on_unknown_code(): void
    {
        $service = new RecoveryCodeService();
        $bundle = $service->generate();

        self::assertNull($service->consume('11111-22222', $bundle['hashes']));
    }
}
