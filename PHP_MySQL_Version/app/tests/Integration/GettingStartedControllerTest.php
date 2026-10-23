<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\GettingStartedController;
use App\Tests\Support\DbTestCase;

/**
 * The first-day onboarding page — same visibility as SopController's main
 * chapters (logged in is the only requirement, no permission gate), but
 * rendered with no layout (layout/base.php never wraps it, since this
 * page carries its own standalone navigation rail).
 */
final class GettingStartedControllerTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testShowRendersForAnyLoggedInUserRegardlessOfRole(): void
    {
        // Viewer / Auditor holds no special permissions at all — this page
        // still has to render for them, same as the SOP viewer's main chapters.
        $_SESSION['_auth_user_id'] = $this->createTestUser('Viewer / Auditor');

        ob_start();
        (new GettingStartedController())->show([]);
        $html = ob_get_clean();

        self::assertStringContainsString('Getting Started with the Order System', $html);
        self::assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function testShowIsNotWrappedInTheNormalAppLayout(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new GettingStartedController())->show([]);
        $html = ob_get_clean();

        // layout/base.php's own sidebar markup (a structurally distinct
        // class from this page's own "gs-rail" nav) — its absence confirms
        // View::render() was called with layout => null, not the usual shell.
        self::assertStringNotContainsString('class="sidebar-nav"', $html);
        self::assertStringNotContainsString('id="nav-toggle"', $html);
    }

    public function testShowGreetsTheSignedInUserByName(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new GettingStartedController())->show([]);
        $html = ob_get_clean();

        self::assertStringContainsString('Signed in as', $html);
    }

    public function testShowReferencesTheRealScreenshotsServedByTheSopAssetRoute(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new GettingStartedController())->show([]);
        $html = ob_get_clean();

        self::assertStringContainsString('/sop-assets/login_screen.png', $html);
        self::assertStringContainsString('/sop-assets/staff_dashboard.png', $html);
        self::assertStringContainsString('/sop-assets/kanban_orders.png', $html);
    }
}
