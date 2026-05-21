<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Audit\AuditEventLevel;

final class AuditEventLevelTest extends TestCase
{
    public function test_is_at_least_compares_severity(): void
    {
        self::assertTrue(AuditEventLevel::isAtLeast(AuditEventLevel::ERROR, AuditEventLevel::WARNING));
        self::assertFalse(AuditEventLevel::isAtLeast(AuditEventLevel::INFO, AuditEventLevel::NOTICE));
        self::assertTrue(AuditEventLevel::isAtLeast(AuditEventLevel::NOTICE, AuditEventLevel::NOTICE));
    }
}
