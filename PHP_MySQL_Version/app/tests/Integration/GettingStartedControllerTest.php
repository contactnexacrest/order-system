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

    /** QA Round 7, R7-N16: below 900 px the chapter bar sits above the text and the reference table scrolls in its own box (Playwright 06-responsive checks the result at 390/768 px). */
    public function testThePhoneLayoutStacksTheChapterBarAndKeepsTablesInsideTheScreen(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new GettingStartedController())->show([]);
        $html = (string) ob_get_clean();
        self::assertMatchesRegularExpression('/@media \(max-width: 880px\)\{\s*\/\*[^*]*R7-N16[^*]*\*\/\s*\.gs-shell\{flex-direction:column;\}/', $html);
        self::assertStringContainsString('@media (max-width: 880px){ .gs-reftable{display:block; overflow-x:auto;}', $html);
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

    /**
     * UI-005 / D-266: an Export Executive sees no broken screenshot and no link
     * to an SOP chapter that answers 403 — every screenshot left on the page is
     * one /sop-assets serves to them, every chapter link one they may open.
     */
    public function testUi005ShowsOnlyTheScreenshotsAndChaptersTheRoleMayOpen(): void
    {
        foreach (['Export Executive', 'Logistics Executive', 'Investor (view only)', 'Viewer / Auditor'] as $role) {
            $userId = $this->createTestUser($role);
            $_SESSION['_auth_user_id'] = $userId;
            \App\Services\PermissionService::resetCache();
            $roleId = (int) \App\Config\Database::connection()->query('SELECT role_id FROM users WHERE id = ' . $userId)->fetchColumn();
            $visible = \App\Services\SopAccessService::visibleSlugs($userId, $roleId, false);

            ob_start();
            (new GettingStartedController())->show([]);
            $html = (string) ob_get_clean();

            preg_match_all('#src="/sop-assets/([^"]+)"#', $html, $images);
            foreach ($images[1] as $file) {
                self::assertTrue(\App\Services\SopAccessService::imageVisible($file, $userId, $roleId, $visible), "$role: $file");
            }
            self::assertContains('login_screen.png', $images[1], "$role: the Getting Started chapter's own screenshots stay");
            self::assertNotContains('staff_dashboard.png', $images[1], "$role: shown in no chapter they may open");
            self::assertStringNotContainsString('Dashboard — search, approvals', $html, "$role: its caption goes with it");

            preg_match_all('#href="/sop/([A-Za-z0-9_-]+)#', $html, $links);
            foreach ($links[1] as $slug) {
                self::assertContains($slug, $visible, "$role: link to /sop/$slug");
            }
            self::assertStringNotContainsString('href="/sop/15-admin-settings"', $html, $role);
            self::assertStringContainsString('Ch. 15', $html, "$role: the chapter number stays as plain text");
        }
    }
}
