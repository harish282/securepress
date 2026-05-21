<?php

declare(strict_types=1);

namespace PressSentinel\Facades;

use LogicException;
use PressSentinel\Core\Audit\AuditEvent;
use PressSentinel\Core\Audit\AuditEventCategory;
use PressSentinel\Core\Audit\AuditEventLevel;
use PressSentinel\Core\Audit\AuditEventBuilder;
use PressSentinel\Core\Audit\AuditLoggerInterface;
use PressSentinel\Core\Container;

/**
 * Laravel-style facade for recording audit events.
 *
 * Three calling styles are supported, by ascending verbosity:
 *
 *  ```php
 *  // 1. Quick PSR-3 style
 *  AuditLog::info('user.profile.updated', ['user_id' => 5]);
 *  AuditLog::warning('options.changed', ['option' => 'siteurl']);
 *  AuditLog::critical('plugin.activated', ['slug' => 'evil']);
 *
 *  // 2. Fluent builder for actor/target/category
 *  AuditLog::for($user)
 *      ->category('auth')
 *      ->action('user.login.success')
 *      ->message('User signed in')
 *      ->context(['ip' => $ip])
 *      ->record();
 *
 *  // 3. Full-fidelity AuditEvent
 *  AuditLog::record(AuditEvent::make('order.refunded', 'woocommerce', 'warning')
 *      ->withTarget('order', (string) $orderId)
 *      ->withContext(['amount' => $amount])
 *  );
 *  ```
 *
 * The facade resolves its underlying {@see AuditLoggerInterface} from the DI container, so
 * tests can swap the implementation by binding a different one before booting the plugin.
 */
final class AuditLog
{
    private static ?Container $container = null;

    public static function bootstrap(Container $container): void
    {
        self::$container = $container;
    }

    public static function record(AuditEvent $event): ?AuditEvent
    {
        return self::logger()->record($event);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $action, array $context = []): ?AuditEvent
    {
        return self::logger()->log($level, $action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $action, array $context = []): ?AuditEvent
    {
        return self::logger()->info($action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function notice(string $action, array $context = []): ?AuditEvent
    {
        return self::logger()->notice($action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $action, array $context = []): ?AuditEvent
    {
        return self::logger()->warning($action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $action, array $context = []): ?AuditEvent
    {
        return self::logger()->error($action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function critical(string $action, array $context = []): ?AuditEvent
    {
        return self::logger()->critical($action, $context);
    }

    /**
     * Begins a fluent builder bound to the given actor.
     *
     * Accepts a `WP_User` object (uses `ID` + `display_name`/`user_login`), a numeric user id,
     * or `null` for system actions.
     */
    public static function for(mixed $actor): AuditEventBuilder
    {
        return AuditEventBuilder::create(self::logger())->for($actor);
    }

    public static function action(string $action): AuditEventBuilder
    {
        return AuditEventBuilder::create(self::logger())->action($action);
    }

    public static function category(string $category): AuditEventBuilder
    {
        return AuditEventBuilder::create(self::logger())->category($category);
    }

    private static function logger(): AuditLoggerInterface
    {
        if (self::$container === null) {
            throw new LogicException('PressSentinel AuditLog has not been bootstrapped. Call AuditLog::bootstrap() from the plugin.');
        }

        return self::$container->get(AuditLoggerInterface::class);
    }
}
