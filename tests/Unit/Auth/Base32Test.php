<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Auth\TwoFactor\Base32;

final class Base32Test extends TestCase
{
    public function test_round_trip_through_known_vectors(): void
    {
        // RFC 4648 §10 test vectors.
        self::assertSame('', Base32::encode(''));
        self::assertSame('MY', Base32::encode('f'));
        self::assertSame('MZXQ', Base32::encode('fo'));
        self::assertSame('MZXW6', Base32::encode('foo'));
        self::assertSame('MZXW6YQ', Base32::encode('foob'));
        self::assertSame('MZXW6YTB', Base32::encode('fooba'));
        self::assertSame('MZXW6YTBOI', Base32::encode('foobar'));
    }

    public function test_decode_inverts_encode_for_random_secrets(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $bytes = random_bytes(random_int(1, 64));
            self::assertSame($bytes, Base32::decode(Base32::encode($bytes)));
        }
    }

    public function test_decoder_is_case_and_padding_insensitive(): void
    {
        $padded = 'MZXW6YQ====';
        $lower = strtolower($padded);
        $spaced = "MZX  W6 YQ\n=";

        self::assertSame('foob', Base32::decode($padded));
        self::assertSame('foob', Base32::decode($lower));
        self::assertSame('foob', Base32::decode($spaced));
    }

    public function test_decoder_rejects_non_alphabet_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base32::decode('!!!!!!');
    }

    public function test_random_secret_has_expected_length_and_only_alphabet_chars(): void
    {
        $secret = Base32::randomSecret();

        self::assertSame(32, strlen($secret));
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_random_secret_rejects_short_request(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base32::randomSecret(5);
    }
}
