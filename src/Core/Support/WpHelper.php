<?php

declare(strict_types=1);

namespace SecurePress\Core\Support;

final class WpHelper
{
    public static function addAction(string $hook, array $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (\function_exists('add_action')) {
            \call_user_func('add_action', $hook, $callback, $priority, $acceptedArgs);
        }
    }

    public static function addFilter(string $hook, array $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (\function_exists('add_filter')) {
            \call_user_func('add_filter', $hook, $callback, $priority, $acceptedArgs);
        }
    }

    public static function currentUserCan(string $capability): bool
    {
        if (!\function_exists('current_user_can')) {
            return false;
        }

        return (bool) \call_user_func('current_user_can', $capability);
    }

    public static function getCurrentScreenId(): ?string
    {
        if (!\function_exists('get_current_screen')) {
            return null;
        }

        $screen = \call_user_func('get_current_screen');
        if (!is_object($screen) || !isset($screen->id) || !is_string($screen->id)) {
            return null;
        }

        return $screen->id;
    }

    public static function escapeHtml(string $value): string
    {
        if (\function_exists('esc_html')) {
            return (string) \call_user_func('esc_html', $value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function pluginBasename(string $file): string
    {
        if (\function_exists('plugin_basename')) {
            return (string) \call_user_func('plugin_basename', $file);
        }

        return basename($file);
    }

    public static function isAdmin(): bool
    {
        if (!\function_exists('is_admin')) {
            return false;
        }

        return (bool) \call_user_func('is_admin');
    }
}
