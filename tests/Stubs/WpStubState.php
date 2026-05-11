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

    /** @var array<string, mixed> */
    public static array $options = [];

    public static int $currentUserId = 0;

    /** @var array{ID:int,user_login:string,display_name:string}|null */
    public static ?array $currentUser = null;

    /** @var array<string, true> Capability names the current user holds. */
    public static array $currentUserCapabilities = [];

    /** @var array<string, array{timestamp:int,recurrence:string}> */
    public static array $scheduledEvents = [];

    /** @var array<int, array<string, mixed>> */
    public static array $userMeta = [];

    /** @var list<array{to:array|string,subject:string,message:string,headers:array}> */
    public static array $sentMail = [];

    /** @var array<string, object> */
    public static array $usersByLogin = [];

    /** @var array<int, object> */
    public static array $usersById = [];

    /** @var list<array{user_id:int,remember:bool}> */
    public static array $authCookies = [];

    public static string $blogName = 'Test Site';

    public static string $siteUrl = 'https://example.test';

    private static int $createCounter = 0;

    public static function reset(): void
    {
        self::$validNonces = [];
        self::$isDoingAjax = false;
        self::$isRestRequest = false;
        self::$transients = [];
        self::$now = time();
        self::$salts = [];
        self::$options = [];
        self::$currentUserId = 0;
        self::$currentUser = null;
        self::$currentUserCapabilities = [];
        self::$scheduledEvents = [];
        self::$userMeta = [];
        self::$sentMail = [];
        self::$usersByLogin = [];
        self::$usersById = [];
        self::$authCookies = [];
        self::$blogName = 'Test Site';
        self::$siteUrl = 'https://example.test';
        self::$createCounter = 0;
    }

    public static function setUserMeta(int $userId, string $key, mixed $value): void
    {
        self::$userMeta[$userId] ??= [];
        self::$userMeta[$userId][$key] = $value;
    }

    public static function getUserMeta(int $userId, string $key, mixed $default = ''): mixed
    {
        return self::$userMeta[$userId][$key] ?? $default;
    }

    public static function deleteUserMeta(int $userId, string $key): bool
    {
        if (!isset(self::$userMeta[$userId][$key])) {
            return false;
        }
        unset(self::$userMeta[$userId][$key]);

        return true;
    }

    public static function registerUser(int $id, string $login, string $email, string $displayName = ''): object
    {
        $user = (object) [
            'ID' => $id,
            'user_login' => $login,
            'user_email' => $email,
            'display_name' => $displayName !== '' ? $displayName : $login,
        ];
        self::$usersByLogin[$login] = $user;
        self::$usersById[$id] = $user;

        return $user;
    }

    public static function recordMail(array|string $to, string $subject, string $message, array $headers = []): void
    {
        self::$sentMail[] = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
        ];
    }

    /**
     * @return array{ID:int,user_login:string,display_name:string}
     */
    public static function setCurrentUser(int $id, string $login, string $displayName = ''): array
    {
        self::$currentUserId = $id;
        self::$currentUser = [
            'ID' => $id,
            'user_login' => $login,
            'display_name' => $displayName !== '' ? $displayName : $login,
        ];

        return self::$currentUser;
    }

    public static function getOption(string $name, mixed $default = false): mixed
    {
        return array_key_exists($name, self::$options) ? self::$options[$name] : $default;
    }

    public static function updateOption(string $name, mixed $value): bool
    {
        self::$options[$name] = $value;

        return true;
    }

    public static function deleteOption(string $name): bool
    {
        if (!array_key_exists($name, self::$options)) {
            return false;
        }
        unset(self::$options[$name]);

        return true;
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
