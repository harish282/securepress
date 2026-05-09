<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use SecurePress\Core\Url\ArrayNonceStore;
use SecurePress\Core\Url\ArraySecretProvider;
use SecurePress\Core\Url\UrlSigner;
use SecurePress\Middleware\SignedUrlMiddleware;
use SecurePress\Tests\Stubs\WpStubState;

final class SignedUrlMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    private int $now = 1_700_000_000;

    /** @var Closure(): int */
    private Closure $clock;

    private UrlSigner $signer;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->serverBackup = $_SERVER;
        $_SERVER = [];
        $this->now = 1_700_000_000;
        $this->clock = fn (): int => $this->now;
        $this->signer = new UrlSigner(new ArraySecretProvider('test-secret-32-bytes-of-entropy-XX'), $this->clock);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WpStubState::reset();
    }

    public function test_valid_signed_url_passes_and_annotates_context(): void
    {
        $signed = $this->signer->sign('/download', ['file' => 'manual.pdf'], expiresIn: 600);
        $middleware = new SignedUrlMiddleware($this->signer);

        $reached = false;
        $next = static function (array $context) use (&$reached): array {
            $reached = true;

            return $context + ['next' => true];
        };

        $result = $middleware->handle(['request' => ['url' => $signed]], $next);

        self::assertTrue($reached);
        self::assertTrue($result['next']);
        self::assertTrue($result['signed_url']['verified']);
        self::assertSame('valid', $result['signed_url']['reason']);
        self::assertSame('/download', $result['signed_url']['path']);
        self::assertSame('manual.pdf', $result['signed_url']['params']['file']);
        self::assertFalse($result['signed_url']['one_time']);
        self::assertFalse($result['signed_url']['consumed']);
    }

    public function test_falls_back_to_request_uri_when_context_lacks_url(): void
    {
        $signed = $this->signer->sign('/file', ['id' => 1], expiresIn: 60);
        $_SERVER['REQUEST_URI'] = $signed;

        $middleware = new SignedUrlMiddleware($this->signer);

        $result = $middleware->handle([], static fn (array $context): array => $context + ['next' => true]);

        self::assertTrue($result['next']);
        self::assertTrue($result['signed_url']['verified']);
    }

    public function test_expired_url_short_circuits_with_410(): void
    {
        $signed = $this->signer->sign('/secret', [], expiresIn: 30);
        $this->now += 31;

        $middleware = new SignedUrlMiddleware($this->signer);

        $result = $middleware->handle(
            ['request' => ['url' => $signed]],
            static function (): array {
                self::fail('Pipeline must short-circuit on expired URL.');
            }
        );

        self::assertTrue($result['halted']);
        self::assertFalse($result['signed_url']['verified']);
        self::assertSame('expired', $result['signed_url']['reason']);
        self::assertSame(410, $result['response']['status']);
        self::assertStringContainsString('expired', strtolower($result['response']['message']));
    }

    public function test_tampered_url_short_circuits_with_403(): void
    {
        $signed = $this->signer->sign('/secret', ['user' => 5], expiresIn: 60);
        $tampered = str_replace('user=5', 'user=999', $signed);

        $middleware = new SignedUrlMiddleware($this->signer);

        $result = $middleware->handle(
            ['request' => ['url' => $tampered]],
            static function (): array {
                self::fail('Pipeline must short-circuit on tampered URL.');
            }
        );

        self::assertTrue($result['halted']);
        self::assertSame('tampered', $result['signed_url']['reason']);
        self::assertSame(403, $result['response']['status']);
    }

    public function test_missing_signature_short_circuits_with_403(): void
    {
        $middleware = new SignedUrlMiddleware($this->signer);

        $result = $middleware->handle(
            ['request' => ['url' => '/no-sig?id=1']],
            static function (): array {
                self::fail('Pipeline must short-circuit when signature is missing.');
            }
        );

        self::assertSame('missing-signature', $result['signed_url']['reason']);
        self::assertSame(403, $result['response']['status']);
    }

    public function test_one_time_url_is_consumed_on_first_use(): void
    {
        $store = new ArrayNonceStore($this->clock);
        $nonce = bin2hex(random_bytes(8));
        $store->register($nonce, 600);

        $signed = $this->signer->sign('/reset', ['n' => $nonce, 'user' => 7], expiresIn: 600);
        $middleware = new SignedUrlMiddleware($this->signer, $store);

        $result = $middleware->handle(
            ['request' => ['url' => $signed]],
            static fn (array $context): array => $context + ['next' => true]
        );

        self::assertTrue($result['next']);
        self::assertTrue($result['signed_url']['verified']);
        self::assertTrue($result['signed_url']['one_time']);
        self::assertTrue($result['signed_url']['consumed']);
    }

    public function test_one_time_url_is_rejected_on_replay(): void
    {
        $store = new ArrayNonceStore($this->clock);
        $nonce = bin2hex(random_bytes(8));
        $store->register($nonce, 600);

        $signed = $this->signer->sign('/reset', ['n' => $nonce], expiresIn: 600);
        $middleware = new SignedUrlMiddleware($this->signer, $store);

        $first = $middleware->handle(
            ['request' => ['url' => $signed]],
            static fn (array $context): array => $context
        );
        self::assertTrue($first['signed_url']['verified']);

        $second = $middleware->handle(
            ['request' => ['url' => $signed]],
            static function (): array {
                self::fail('Replay of consumed one-time URL must short-circuit.');
            }
        );

        self::assertTrue($second['halted']);
        self::assertSame('already-used', $second['signed_url']['reason']);
        self::assertSame(403, $second['response']['status']);
    }

    public function test_one_time_url_with_unregistered_nonce_is_rejected(): void
    {
        $store = new ArrayNonceStore($this->clock);
        $signed = $this->signer->sign('/reset', ['n' => 'never-registered'], expiresIn: 600);
        $middleware = new SignedUrlMiddleware($this->signer, $store);

        $result = $middleware->handle(
            ['request' => ['url' => $signed]],
            static function (): array {
                self::fail('Pipeline must short-circuit when nonce was never registered.');
            }
        );

        self::assertSame('already-used', $result['signed_url']['reason']);
    }

    public function test_url_without_nonce_param_is_replayable_even_with_store(): void
    {
        $store = new ArrayNonceStore($this->clock);
        $signed = $this->signer->sign('/public', ['id' => 1], expiresIn: 600);
        $middleware = new SignedUrlMiddleware($this->signer, $store);

        $a = $middleware->handle(
            ['request' => ['url' => $signed]],
            static fn (array $context): array => $context + ['next' => true]
        );
        $b = $middleware->handle(
            ['request' => ['url' => $signed]],
            static fn (array $context): array => $context + ['next' => true]
        );

        self::assertTrue($a['signed_url']['verified']);
        self::assertTrue($b['signed_url']['verified']);
        self::assertFalse($a['signed_url']['one_time']);
        self::assertFalse($a['signed_url']['consumed']);
    }

    public function test_no_url_returns_malformed(): void
    {
        $middleware = new SignedUrlMiddleware($this->signer);

        $result = $middleware->handle([], static function (): array {
            self::fail('Pipeline must short-circuit when no URL can be resolved.');
        });

        self::assertSame('malformed', $result['signed_url']['reason']);
        self::assertSame(403, $result['response']['status']);
    }
}
