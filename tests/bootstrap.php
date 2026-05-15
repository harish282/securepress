<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Keep the public-beta trial programme and early-access Pro unlock off during PHPUnit
// so "no license key" cases stay strictly Free unless a test explicitly enables the env vars.
putenv('SECUREPRESS_BETA_TRIAL_ENABLED=false');
putenv('SECUREPRESS_EARLY_ACCESS=false');

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once dirname(__DIR__) . '/bootstrap/constants.php';
require_once SECUREPRESS_SRC_PATH . '/Core/Support/Autoloader.php';

\SecurePress\Core\Support\Autoloader::register();

// Signals admin_post handlers / page redirectors that they're running inside
// a unit test, so they record the redirect target on {@see \SecurePress\Tests\Stubs\WpStubState}
// instead of exit()-ing the process.
if (!defined('SECUREPRESS_TESTING')) {
    define('SECUREPRESS_TESTING', true);
}

require_once __DIR__ . '/Stubs/wp-functions.php';
