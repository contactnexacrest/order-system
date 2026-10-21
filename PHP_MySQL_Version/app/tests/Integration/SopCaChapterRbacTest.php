<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SopController;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "SOP/Reference-Library RBAC": the Reference Library
 * already scopes custom entries to a required_permission per category
 * (ReferenceLibraryCategoriesTest), but the SOP viewer's CA / Accounting
 * chapters (ca-01-overview.md through ca-08-order-financials.md, among
 * others) — which document the same supplier-cost/profitability/
 * government-benefit detail the live CA module itself gates behind
 * ca_module_view — were visible to any logged-in staff member, same as
 * the main numbered chapters. Reading the live screens already needed
 * that permission; reading the SOP chapter describing them didn't.
 */
final class SopCaChapterRbacTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testIndexListsCaChaptersForAUserWithCaModuleView(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin'); // Admin holds every permission

        ob_start();
        (new SopController())->index([]);
        $html = ob_get_clean();

        self::assertStringContainsString('CA / Accounting', $html);
        self::assertStringContainsString('/sop/ca-01-overview', $html);
    }

    public function testIndexHidesCaChaptersFromAUserWithoutCaModuleView(): void
    {
        // Viewer / Auditor holds view_reports/view_audit_log/view_product_catalog/
        // view_orders/view_clients — not ca_module_view.
        $_SESSION['_auth_user_id'] = $this->createTestUser('Viewer / Auditor');

        ob_start();
        (new SopController())->index([]);
        $html = ob_get_clean();

        self::assertStringNotContainsString('CA / Accounting', $html);
        self::assertStringNotContainsString('/sop/ca-01-overview', $html);
    }

    public function testShowAllowsACaChapterForAUserWithCaModuleView(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        http_response_code(200);
        (new SopController())->show(['chapter' => 'ca-01-overview']);
        $html = ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertStringContainsString('ca-01-overview', $html);
    }

    public function testShowBlocksACaChapterForAUserWithoutCaModuleView(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Viewer / Auditor');

        ob_start();
        http_response_code(200);
        (new SopController())->show(['chapter' => 'ca-01-overview']);
        $html = ob_get_clean();

        self::assertSame(403, http_response_code());
        self::assertStringContainsString('Not permitted', $html);
    }

    public function testShowStillAllowsAMainChapterForAUserWithoutCaModuleView(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Viewer / Auditor');

        ob_start();
        http_response_code(200);
        (new SopController())->show(['chapter' => '01-stage1-enquiry-quotation']);
        $html = ob_get_clean();

        self::assertSame(200, http_response_code());
        self::assertStringContainsString('01-stage1-enquiry-quotation', $html);
    }
}
