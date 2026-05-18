<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps `PRESS_SENTINEL_SAFE_MODE` from `.env` (loaded by {@see env.php}) to the
 * `PRESS_SENTINEL_SAFE_MODE` constant when wp-config.php did not define it already.
 */
if (!defined('PRESS_SENTINEL_SAFE_MODE')) {
    $raw = $_ENV['PRESS_SENTINEL_SAFE_MODE'] ?? $_SERVER['PRESS_SENTINEL_SAFE_MODE'] ?? getenv('PRESS_SENTINEL_SAFE_MODE');
    if ($raw !== false && $raw !== '' && filter_var((string) $raw, FILTER_VALIDATE_BOOL)) {
        define('PRESS_SENTINEL_SAFE_MODE', true);
    }
}
