<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Stubs;

use RuntimeException;

/**
 * Thrown by the `wp_die` test stub so the calling code aborts the same way it
 * would under real WordPress (where wp_die exits the process). Tests can
 * `expectException(WpDieException::class)` to assert a guard branch was hit.
 */
final class WpDieException extends RuntimeException
{
}
