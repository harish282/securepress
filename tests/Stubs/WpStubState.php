<?php

declare(strict_types=1);

namespace SecurePress\Tests\Stubs;

/**
 * Mutable state backing the WordPress function stubs in tests/Stubs/wp-functions.php.
 *
 * Tests configure this between cases (typically in setUp) instead of monkey-patching
 * global functions directly.
 */
final class WpStubState
{
    /** @var array<string, int> action|nonce => tick (1 = fresh, 2 = stale) */
    public static array $validNonces = [];

    public static bool $isDoingAjax = false;

    public static bool $isRestRequest = false;

    /** @var array<string, array{value: mixed, expires: int}> */
    public static array $transients = [];

    public static int $now = 0;

    /** @var array<string, string> */
    public static array $salts = [];

    private static int $createCounter = 0;

    public static function reset(): void
    {
        self::$validNonces = [];
        self::$isDoingAjax = false;
        self::$isRestRequest = false;
        self::$transients = [];
        self::$now = time();
        self::$salts = [];
        self::$createCounter = 0;
    }

    public static function saltFor(string $scheme): string
    {
        return self::$salts[$scheme] ?? '';
    }

    public static function getTransient(string $name): mixed
    {
        $entry = self::$transients[$name] ?? null;
        if ($entry === null) {
            return false;
        }

        if ($entry['expires'] <= self::currentTime()) {
            unset(self::$transients[$name]);

            return false;
        }

        return $entry['value'];
    }

    public static function setTransient(string $name, mixed $value, int $expiration): bool
    {
        self::$transients[$name] = [
            'value' => $value,
            'expires' => self::currentTime() + max(0, $expiration),
        ];

        return true;
    }

    public static function deleteTransient(string $name): bool
    {
        if (!isset(self::$transients[$name])) {
            return false;
        }

        unset(self::$transients[$name]);

        return true;
    }

    public static function advance(int $seconds): void
    {
        self::$now += $seconds;
    }

    public static function currentTime(): int
    {
        return self::$now > 0 ? self::$now : time();
    }

    public static function registerNonce(string $action, string $nonce, int $tick = 1): void
    {
        self::$validNonces[self::key($action, $nonce)] = $tick;
    }

    public static function tickFor(string $action, string $nonce): int
    {
        return self::$validNonces[self::key($action, $nonce)] ?? 0;
    }

    public static function nextNonce(string $action): string
    {
        self::$createCounter++;
        $token = sprintf('nonce_%s_%d', $action, self::$createCounter);
        self::registerNonce($action, $token, 1);

        return $token;
    }

    private static function key(string $action, string $nonce): string
    {
        return $action . '|' . $nonce;
    }
}
