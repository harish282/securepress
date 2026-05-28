<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use NiyiGuard\Admin\AuditLogPage;
use NiyiGuard\Admin\FileIntegrityPage;
use NiyiGuard\Admin\HealthDiagnosticsPage;
use NiyiGuard\Tests\Stubs\WpStubState;

/**
 * Regression coverage for the bug that left `Prune now` / `Clear all logs` /
 * `Re-scan`/ `Save license` etc. silently broken on `wp-admin/admin-post.php`.
 *
 * The bug: `Plugin::boot()` used to register these admin pages from inside an
 * `admin_menu` deferral callback. But `admin-post.php` doesn't fire
 * `admin_menu` — it fires `admin_init` followed by `admin_post_{action}`. As
 * a result none of the `admin_post_*` hooks were ever wired on form
 * submissions, and admins saw nothing happen.
 *
 * These contract tests pin down the page-level promise: when `register()` is
 * called, it MUST add every `admin_post_*` hook the page's form templates
 * point at. Combined with the deferral fix in `Plugin::boot()`
 * (admin_menu → admin_init), the action handlers are guaranteed to be live
 * by the time `admin-post.php` dispatches the action.
 *
 * We construct each page via `newInstanceWithoutConstructor()` because the
 * `register()` method is a pure hook-wiring concern — it never touches the
 * injected services. Skipping the constructor lets us avoid pulling in the
 * full DI graph (repositories, scheduler, license validator, etc.) for what
 * is fundamentally a routing assertion.
 */
final class AdminPageRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_audit_log_page_registers_clear_and_prune_admin_post_hooks(): void
    {
        $page = (new ReflectionClass(AuditLogPage::class))->newInstanceWithoutConstructor();
        $page->register();

        self::assertTrue(
            WpStubState::hasAction('admin_post_niyiguard_clear_audit_logs'),
            'AuditLogPage must register the admin_post_niyiguard_clear_audit_logs handler so the "Clear all logs" button works.'
        );
        self::assertTrue(
            WpStubState::hasAction('admin_post_niyiguard_prune_audit_logs'),
            'AuditLogPage must register the admin_post_niyiguard_prune_audit_logs handler so the "Run prune now" button works.'
        );
        self::assertTrue(
            WpStubState::hasAction('admin_menu'),
            'AuditLogPage must register an admin_menu callback to add its submenu.'
        );
    }

    public function test_file_integrity_page_registers_all_admin_post_hooks(): void
    {
        $page = (new ReflectionClass(FileIntegrityPage::class))->newInstanceWithoutConstructor();
        $page->register();

        $expected = [
            'admin_post_niyiguard_integrity_rescan',
            'admin_post_niyiguard_integrity_review',
            'admin_post_niyiguard_integrity_delete',
            'admin_post_niyiguard_integrity_clear',
            'admin_post_niyiguard_integrity_reset_baseline',
        ];
        foreach ($expected as $hook) {
            self::assertTrue(
                WpStubState::hasAction($hook),
                sprintf('FileIntegrityPage must register %s — without it the related toolbar button silently no-ops.', $hook)
            );
        }
    }

    public function test_health_diagnostics_page_registers_admin_menu(): void
    {
        $page = (new \ReflectionClass(HealthDiagnosticsPage::class))->newInstanceWithoutConstructor();
        $page->register();

        self::assertTrue(
            WpStubState::hasAction('admin_menu'),
            'HealthDiagnosticsPage must register admin_menu to add its submenu.'
        );
    }

    /**
     * Pin down the wp-admin/admin.php lifecycle that broke when registration
     * was hung off `admin_init`: WordPress fires `admin_menu` BEFORE
     * `admin_init` during a normal admin pageview, so anything registered
     * inside `admin_init` is registered too late to add a submenu.
     */
    public function test_init_hook_fires_before_admin_menu_so_pages_can_register_submenus(): void
    {
        $menuRegistered = false;

        // Simulate Plugin::boot() → registerAdminHooks() → outer init handler.
        \add_action('init', static function () use (&$menuRegistered): void {
            \add_action('admin_menu', static function () use (&$menuRegistered): void {
                $menuRegistered = true;
            });
        });

        // Simulate wp-admin/admin.php: init runs first (from wp-settings.php),
        // then menu.php is required which fires admin_menu.
        WpStubState::dispatchAction('init');
        WpStubState::dispatchAction('admin_menu');

        self::assertTrue(
            $menuRegistered,
            'Page registration must hook on `init` (not `admin_init`) so addMenu() callbacks are in place before WordPress fires admin_menu.'
        );
    }

    /**
     * Pin down the wp-admin/admin-post.php lifecycle: the entry point only
     * fires `init` (from wp-load.php) then `admin_init` then
     * `admin_post_{action}`, and never fires `admin_menu`. Anything registered
     * inside `admin_menu` would be missed entirely on form submissions.
     */
    public function test_dashboard_post_handler_registers_when_is_admin_false_at_wire_time(): void
    {
        WpStubState::$isAdmin = false;

        $page = (new ReflectionClass(\NiyiGuard\Admin\NiyiGuardMenuPage::class))->newInstanceWithoutConstructor();
        $page->registerPostHandler();

        self::assertTrue(
            WpStubState::hasAction('admin_post_niyiguard_features'),
            'Dashboard save must register admin_post_niyiguard_features even when is_admin() was false during MU/front-end bootstrap.'
        );
    }

    public function test_admin_post_handlers_registered_via_init_run_on_form_submission(): void
    {
        $invoked = false;

        \add_action('init', static function () use (&$invoked): void {
            \add_action('admin_post_niyiguard_test', static function () use (&$invoked): void {
                $invoked = true;
            });
        });

        // Simulate wp-admin/admin-post.php request lifecycle.
        WpStubState::dispatchAction('init');
        // admin_menu is intentionally NOT fired here — admin-post.php skips it.
        WpStubState::dispatchAction('admin_init');
        WpStubState::dispatchAction('admin_post_niyiguard_test');

        self::assertTrue(
            $invoked,
            'admin_post_* handlers registered on `init` must be live when admin-post.php dispatches the action — admin_menu never fires on that entry point.'
        );
    }
}
