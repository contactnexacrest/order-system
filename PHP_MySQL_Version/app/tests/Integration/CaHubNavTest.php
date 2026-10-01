<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CaController;
use App\Tests\Support\DbTestCase;

/**
 * Point 3 (2026-10-01): the CA / Accounting hub page used to be a single
 * paragraph of inline middot-separated links — this covers its replacement
 * with card sections (same pattern as the Reports hub), and specifically
 * that the Zoho Books / Financial Year Lock card — gated on the stricter
 * ca_module_manage, not the plain ca_module_view every other card needs —
 * is only shown to a user who actually holds it.
 */
final class CaHubNavTest extends DbTestCase
{
    public function testHubShowsCardSectionsNotTheOldInlineLinkParagraph(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        $controller = new CaController();
        ob_start();
        $controller->index([]);
        $html = ob_get_clean();

        self::assertStringContainsString('Revenue &amp; Benefits', $html);
        self::assertStringContainsString('Expenses &amp; TDS', $html);
        self::assertStringContainsString('Bank &amp; Reconciliation', $html);
        self::assertStringContainsString('href="/ca/reports"', $html);
        self::assertStringContainsString('href="/ca/export-benefits"', $html);
        self::assertStringContainsString('href="/ca/expenses"', $html);
        self::assertStringContainsString('href="/ca/tds-summary"', $html);
        self::assertStringContainsString('href="/ca/bank-statement"', $html);
        self::assertStringContainsString('href="/ca/reconciliation"', $html);
    }

    public function testHubShowsZohoAndFyLockCardForAUserWithCaModuleManage(): void
    {
        // Admin holds ca_module_manage per seed role_permissions.
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        $controller = new CaController();
        ob_start();
        $controller->index([]);
        $html = ob_get_clean();

        self::assertStringContainsString('Zoho Books &amp; Financial Year Lock', $html);
        self::assertStringContainsString('href="/ca/zoho-sync"', $html);
        self::assertStringContainsString('href="/ca/fy-locks"', $html);
    }

    public function testHubHidesZohoAndFyLockCardForAViewOnlyCaUser(): void
    {
        // 'CA / Chartered Accountant' is seeded with ca_module_view only,
        // deliberately not ca_module_manage (docs/SOP/ca-01-overview.md).
        $userId = $this->createTestUser('CA / Chartered Accountant');
        $_SESSION['_auth_user_id'] = $userId;

        $controller = new CaController();
        ob_start();
        $controller->index([]);
        $html = ob_get_clean();

        self::assertStringNotContainsString('Zoho Books &amp; Financial Year Lock', $html);
        self::assertStringNotContainsString('href="/ca/zoho-sync"', $html);
        self::assertStringNotContainsString('href="/ca/fy-locks"', $html);
        // The rest of the hub must still render normally for this user.
        self::assertStringContainsString('Revenue &amp; Benefits', $html);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_auth_user_id']);
        parent::tearDown();
    }
}
