<?php

declare(strict_types=1);

namespace SecurePress\Tests\Stubs;

use SecurePress\Tests\Stubs\WpDieException;

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

    public static bool $isAdmin = false;

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

    /**
     * Registered action callbacks, keyed by hook name. Each entry is a list of
     * `{callback, priority, accepted_args}` triples in registration order.
     *
     * @var array<string, list<array{callback: mixed, priority: int, accepted_args: int}>>
     */
    public static array $registeredActions = [];

    /**
     * Registered filter callbacks, keyed by hook name. Same shape as
     * {@see $registeredActions} — filters and actions share the WordPress
     * internal hook table, and tests sometimes need to confirm both.
     *
     * @var array<string, list<array{callback: mixed, priority: int, accepted_args: int}>>
     */
    public static array $registeredFilters = [];

    /**
     * URLs passed to {@see \SecurePress\Core\Support\WpHelper::safeRedirect()}
     * during the current test scope. We capture instead of redirecting so
     * admin_post handler tests can assert the destination.
     *
     * @var list<string>
     */
    public static array $redirects = [];

    /**
     * Calls captured from `wp_die()` so admin_post handlers' guard branches
     * (insufficient capability / failed nonce) can be unit-tested without the
     * process terminating.
     *
     * @var list<array{message: string, title: string, args: array}>
     */
    public static array $wpDieCalls = [];

    /**
     * Sections registered via `add_settings_section()`. Keyed by section id so
     * tests can assert that a specific section (e.g. the new master toggle
     * section) was registered against the expected page.
     *
     * @var array<string, array{title: string, callback: callable, page: string}>
     */
    public static array $settingsSections = [];

    /**
     * Fields registered via `add_settings_field()`. Same shape as
     * {@see $settingsSections}, plus the section the field belongs to.
     *
     * @var array<string, array{title: string, callback: callable, page: string, section: string}>
     */
    public static array $settingsFields = [];

    /**
     * Options registered via `register_setting()`. Keyed by option name so
     * tests can confirm a single canonical name backs both the dashboard and
     * the dedicated settings page.
     *
     * @var array<string, array{group: string, args: array}>
     */
    public static array $registeredOptions = [];

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

    /** When set, {@see home_url()} uses this base instead of {@see $siteUrl}. */
    public static ?string $homeUrl = null;

    /** @var array<string, mixed> Simulated main query vars for {@see get_query_var()} stubs. */
    public static array $queryVars = [];

    /** @var list<array{pattern:string, query:string, after:string}> */
    public static array $rewriteRulesAdded = [];

    public static int $flushRewriteRulesCalls = 0;

    private static int $createCounter = 0;

    public static function reset(): void
    {
        self::$validNonces = [];
        self::$isDoingAjax = false;
        self::$isRestRequest = false;
        self::$isAdmin = false;
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
        self::$homeUrl = null;
        self::$queryVars = [];
        self::$rewriteRulesAdded = [];
        self::$flushRewriteRulesCalls = 0;
        self::$registeredActions = [];
        self::$registeredFilters = [];
        self::$redirects = [];
        self::$wpDieCalls = [];
        self::$settingsSections = [];
        self::$settingsFields = [];
        self::$registeredOptions = [];
        self::$createCounter = 0;
    }

    /**
     * Throwable raised by the `wp_die` stub. Captured in
     * {@see $wpDieCalls} too, but throwing means the calling handler aborts
     * the same way it would in production (where wp_die exits the process).
     */
    public static function recordWpDie(string $message, string $title, array $args): void
    {
        self::$wpDieCalls[] = [
            'message' => $message,
            'title' => $title,
            'args' => $args,
        ];

        throw new WpDieException($message);
    }

    public static function recordRedirect(string $url): void
    {
        self::$redirects[] = $url;
    }

    /**
     * Records that `add_action()` was called. Used by the wp-functions.php
     * stub so tests can inspect which callbacks would have been registered in
     * a real WordPress runtime.
     */
    public static function recordAction(string $hook, mixed $callback, int $priority, int $acceptedArgs): void
    {
        self::$registeredActions[$hook] ??= [];
        self::$registeredActions[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        ];
    }

    public static function recordFilter(string $hook, mixed $callback, int $priority, int $acceptedArgs): void
    {
        self::$registeredFilters[$hook] ??= [];
        self::$registeredFilters[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        ];
    }

    /**
     * True when at least one callback has been registered against the given
     * action hook in the current test scope.
     */
    public static function hasAction(string $hook): bool
    {
        return !empty(self::$registeredActions[$hook]);
    }

    public static function hasFilter(string $hook): bool
    {
        return !empty(self::$registeredFilters[$hook]);
    }

    /**
     * Fires every callback registered against `$hook` in priority order, the
     * same way WordPress's `do_action()` would. Tests use this to simulate
     * the WordPress lifecycle without needing a real wp-load.php.
     */
    public static function dispatchAction(string $hook, mixed ...$args): void
    {
        $callbacks = self::$registeredActions[$hook] ?? [];
        if ($callbacks === []) {
            return;
        }
        usort(
            $callbacks,
            static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']
        );
        foreach ($callbacks as $entry) {
            $sliced = array_slice($args, 0, max(0, $entry['accepted_args']));
            \call_user_func_array($entry['callback'], $sliced);
        }
    }

    /**
     * Runs filter callbacks in priority order and returns the transformed value.
     */
    public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $callbacks = self::$registeredFilters[$hook] ?? [];
        if ($callbacks === []) {
            return $value;
        }
        usort(
            $callbacks,
            static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']
        );
        foreach ($callbacks as $entry) {
            $passed = array_merge([$value], $args);
            $sliced = array_slice($passed, 0, max(1, $entry['accepted_args']));
            $value = \call_user_func_array($entry['callback'], $sliced);
        }

        return $value;
    }

    public static function removeAllFilters(string $hook): void
    {
        unset(self::$registeredFilters[$hook]);
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
