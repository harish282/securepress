<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use SecurePress\Admin\LicensePage;
use SecurePress\Core\Licensing\LicenseStatus;

/**
 * @see \SecurePress\Admin\LicensePage::shouldShowAdminMenu()
 */
final class LicensePageMenuVisibilityTest extends TestCase
{
    public function test_menu_hidden_during_beta_trial(): void
    {
        $status = LicenseStatus::betaTrial(time() + 86400);

        self::assertFalse(LicensePage::shouldShowAdminMenu($status));
    }

    public function test_menu_visible_for_active_license(): void
    {
        self::assertTrue(LicensePage::shouldShowAdminMenu(LicenseStatus::active('pro', null)));
    }

    public function test_menu_visible_when_no_license(): void
    {
        self::assertTrue(LicensePage::shouldShowAdminMenu(LicenseStatus::none()));
    }
}
