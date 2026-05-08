<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once dirname(__DIR__) . '/bootstrap/constants.php';
require_once SECUREPRESS_SRC_PATH . '/Core/Support/Autoloader.php';

\SecurePress\Core\Support\Autoloader::register();
