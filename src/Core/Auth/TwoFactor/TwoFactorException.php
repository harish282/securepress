<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\TwoFactor;

use RuntimeException;

/**
 * Raised by 2FA flows when a precondition is violated — invalid configuration, missing
 * enrolment, exhausted recovery codes, etc.
 *
 * Distinct from a "wrong code" failure: invalid codes are handled by returning `false`
 * from the providers/service so the controller can render a friendly error. This exception
 * is reserved for genuine programming / configuration errors that should never occur in
 * normal use.
 */
final class TwoFactorException extends RuntimeException
{
}
