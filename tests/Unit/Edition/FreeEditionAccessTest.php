<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Edition;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Edition\EditionStatus;
use PressSentinel\Core\Edition\FreeEditionAccess;
use PressSentinel\Tests\Stubs\WpStubState;

final class FreeEditionAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_is_pro_by_default(): void
    {
        $access = new FreeEditionAccess();

        self::assertTrue($access->isPro());
    }

    public function test_status_is_free(): void
    {
        $access = new FreeEditionAccess();
        $status = $access->status();

        self::assertSame(EditionStatus::STATE_FREE, $status->state);
        self::assertTrue($status->hasProAccess());
    }

    public function test_is_pro_filter_can_override(): void
    {
        \add_filter(FreeEditionAccess::FILTER_IS_PRO, static fn (): bool => false);

        self::assertFalse((new FreeEditionAccess())->isPro());
    }
}
