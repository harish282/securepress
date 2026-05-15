<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps `SECUREPRESS_SAFE_MODE` from `.env` (loaded by {@see env.php}) to the
 * `SECUREPRESS_SAFE_MODE` constant when wp-config.php did not define it already.
 */
if (!defined('SECUREPRESS_SAFE_MODE')) {
    $raw = $_ENV['SECUREPRESS_SAFE_MODE'] ?? $_SERVER['SECUREPRESS_SAFE_MODE'] ?? getenv('SECUREPRESS_SAFE_MODE');
    if ($raw !== false && $raw !== '' && filter_var((string) $raw, FILTER_VALIDATE_BOOL)) {
        define('SECUREPRESS_SAFE_MODE', true);
    }
}
