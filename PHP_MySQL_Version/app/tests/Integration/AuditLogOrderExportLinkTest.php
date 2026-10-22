<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\AuditLogController;
use App\Tests\Support\DbTestCase;

/**
 * External QA report M-03: "One-click export of an order's audit log
 * (privileged only)." The export itself (AuditLogController::forOrder()'s
 * ?format=csv branch) reuses the page's existing view_audit_log
 * permission gate, so what this test actually guards is discoverability —
 * the "Export CSV" link must render right on the order's own Audit Log
 * page rather than only existing as an unlinked, undiscoverable route.
 */
final class AuditLogOrderExportLinkTest extends DbTestCase
{
    public function testTheOrderAuditLogPageOffersAnExportCsvLink(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new AuditLogController())->forOrder(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString("/orders/{$orderId}/audit-log?format=csv", $html);
        self::assertStringContainsString('Export CSV', $html);
    }
}
