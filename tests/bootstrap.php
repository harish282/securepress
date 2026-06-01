<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once dirname(__DIR__) . '/bootstrap/constants.php';
require_once NIYIGUARD_SRC_PATH . '/Core/Support/Autoloader.php';

\NiyiGuard\Core\Support\Autoloader::register();

// Signals admin_post handlers / page redirectors that they're running inside
// a unit test, so they record the redirect target on {@see \NiyiGuard\Tests\Stubs\WpStubState}
// instead of exit()-ing the process.
if (!defined('NIYIGUARD_TESTING')) {
    define('NIYIGUARD_TESTING', true);
}

require_once __DIR__ . '/Stubs/wp-functions.php';
