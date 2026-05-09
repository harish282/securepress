<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use SecurePress\Core\Url\ArraySecretProvider;
use SecurePress\Core\Url\SignedUrlException;
use SecurePress\Core\Url\UrlSigner;

final class UrlSignerTest extends TestCase
{
    private int $now = 1_700_000_000;

    /** @var Closure(): int */
    private Closure $clock;

    protected function setUp(): void
    {
        $this->now = 1_700_000_000;
        $this->clock = fn (): int => $this->now;
    }

    public function test_signs_and_verifies_a_simple_url(): void
    {
        $signer = $this->signer();

        $signed = $signer->sign('/download', ['file' => 'report.pdf'], expiresIn: 3600);
        $result = $signer->verify($signed);

        self::assertTrue($result->valid);
        self::assertSame('valid', $result->reason);
        self::assertSame('/download', $result->path);
        self::assertSame('report.pdf', $result->params['file']);
        self::assertSame($this->now + 3600, $result->expiresAt);
    }

    public function test_signs_url_without_expiry(): void
    {
        $signer = $this->signer();

        $signed = $signer->sign('/never-expires', ['x' => 1]);
        $result = $signer->verify($signed);

        self::assertTrue($result->valid);
        self::assertNull($result->expiresAt);
        self::assertStringNotContainsString('expires=', $signed);
    }

    public function test_signed_url_starts_with_path_and_carries_signature_param(): void
    {
        $signer = $this->signer();

        $signed = $signer->sign('/share', ['id' => 7], expiresIn: 60);

        self::assertStringStartsWith('/share?', $signed);
        self::assertStringContainsString('signature=', $signed);
        self::assertStringContainsString('expires=', $signed);
        self::assertStringContainsString('id=7', $signed);
    }

    public function test_path_is_normalized_for_canonical_form(): void
    {
        $signer = $this->signer();

        $a = $signer->sign('/Files/Report');
        $b = $signer->sign('/files/report/');
        $c = $signer->sign('//files//report');

        self::assertTrue($signer->verify($a)->valid);
        self::assertTrue($signer->verify($b)->valid);
        self::assertTrue($signer->verify($c)->valid);

        $stripA = explode('signature=', $a)[1];
        $stripB = explode('signature=', $b)[1];
        $stripC = explode('signature=', $c)[1];
        self::assertSame($stripA, $stripB);
        self::assertSame($stripA, $stripC);
    }

    public function test_param_order_does_not_affect_signature(): void
    {
        $signer = $this->signer();

        $a = $signer->sign('/a', ['x' => 1, 'y' => 2]);
        $b = $signer->sign('/a', ['y' => 2, 'x' => 1]);

        self::assertSame($a, $b);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $signer = $this->signer();
        $signed = $signer->sign('/secret', ['user' => 5], expiresIn: 60);

        $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('0', 64), $signed) ?? $signed;

        $result = $signer->verify($tampered);

        self::assertFalse($result->valid);
        self::assertSame('tampered', $result->reason);
        self::assertTrue($result->isTampered());
    }

    public function test_tampered_query_value_is_rejected(): void
    {
        $signer = $this->signer();
        $signed = $signer->sign('/secret', ['user' => 5], expiresIn: 60);

        $tampered = str_replace('user=5', 'user=999', $signed);

        $result = $signer->verify($tampered);

        self::assertFalse($result->valid);
        self::assertSame('tampered', $result->reason);
    }

    public function test_extending_expires_is_detected_as_tampering(): void
    {
        $signer = $this->signer();
        $signed = $signer->sign('/secret', ['user' => 5], expiresIn: 60);

        $tampered = preg_replace('/expires=\d+/', 'expires=' . ($this->now + 999_999), $signed) ?? $signed;

        $result = $signer->verify($tampered);

        self::assertFalse($result->valid);
        self::assertSame('tampered', $result->reason);
    }

    public function test_expired_url_is_rejected_with_expired_reason(): void
    {
        $signer = $this->signer();
        $signed = $signer->sign('/secret', ['user' => 5], expiresIn: 30);

        $this->now += 31;

        $result = $signer->verify($signed);

        self::assertFalse($result->valid);
        self::assertSame('expired', $result->reason);
        self::assertTrue($result->isExpired());
        self::assertSame($this->now - 1, $result->expiresAt);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $signer = $this->signer();

        $result = $signer->verify('/no-sig?id=42');

        self::assertFalse($result->valid);
        self::assertSame('missing-signature', $result->reason);
    }

    public function test_invalid_signature_format_is_rejected(): void
    {
        $signer = $this->signer();

        $result = $signer->verify('/x?signature=not-hex');

        self::assertFalse($result->valid);
        self::assertSame('invalid-signature', $result->reason);
    }

    public function test_full_url_with_scheme_and_host_is_accepted(): void
    {
        $signer = $this->signer();
        $signed = $signer->sign('/file', ['id' => 1], expiresIn: 60);

        $absolute = 'https://example.test' . $signed;
        $result = $signer->verify($absolute);

        self::assertTrue($result->valid);
    }

    public function test_signed_url_survives_scheme_change(): void
    {
        $signer = $this->signer();
        $signed = $signer->sign('/file', ['id' => 1], expiresIn: 60);

        $http = 'http://example.test' . $signed;
        $https = 'https://example.test' . $signed;

        self::assertTrue($signer->verify($http)->valid);
        self::assertTrue($signer->verify($https)->valid);
    }

    public function test_different_secrets_invalidate_signature(): void
    {
        $signer = new UrlSigner(new ArraySecretProvider('secret-A'), $this->clock);
        $other = new UrlSigner(new ArraySecretProvider('secret-B'), $this->clock);

        $signed = $signer->sign('/file', ['id' => 1], expiresIn: 60);

        self::assertFalse($other->verify($signed)->valid);
        self::assertSame('tampered', $other->verify($signed)->reason);
    }

    public function test_negative_or_zero_expiry_is_rejected_at_sign_time(): void
    {
        $signer = $this->signer();

        $this->expectException(SignedUrlException::class);
        $signer->sign('/x', [], expiresIn: 0);
    }

    public function test_signature_param_cannot_be_supplied_by_caller(): void
    {
        $signer = $this->signer();

        $this->expectException(SignedUrlException::class);
        $signer->sign('/x', ['signature' => 'fake'], expiresIn: 60);
    }

    public function test_non_scalar_params_are_rejected(): void
    {
        $signer = $this->signer();

        $this->expectException(SignedUrlException::class);
        /** @phpstan-ignore-next-line */
        $signer->sign('/x', ['a' => ['nested' => 'array']], expiresIn: 60);
    }

    public function test_boolean_params_are_normalized_to_strings(): void
    {
        $signer = $this->signer();

        $signed = $signer->sign('/admin', ['active' => true, 'banned' => false], expiresIn: 60);
        $result = $signer->verify($signed);

        self::assertTrue($result->valid);
        self::assertSame('1', $result->params['active']);
        self::assertSame('0', $result->params['banned']);
    }

    public function test_null_params_are_dropped(): void
    {
        $signer = $this->signer();

        $signed = $signer->sign('/x', ['a' => 1, 'b' => null], expiresIn: 60);
        $result = $signer->verify($signed);

        self::assertTrue($result->valid);
        self::assertArrayNotHasKey('b', $result->params);
    }

    public function test_special_characters_in_values_round_trip_correctly(): void
    {
        $signer = $this->signer();

        $signed = $signer->sign('/share', [
            'subject' => 'hello world & friends',
            'email' => 'test+tag@example.com',
        ], expiresIn: 60);
        $result = $signer->verify($signed);

        self::assertTrue($result->valid);
        self::assertSame('hello world & friends', $result->params['subject']);
        self::assertSame('test+tag@example.com', $result->params['email']);
    }

    public function test_malformed_url_returns_malformed_result(): void
    {
        $signer = $this->signer();

        $result = $signer->verify('http://:80');

        self::assertFalse($result->valid);
        self::assertSame('malformed', $result->reason);
    }

    private function signer(): UrlSigner
    {
        return new UrlSigner(new ArraySecretProvider('test-secret-32-bytes-of-entropy-XX'), $this->clock);
    }
}
