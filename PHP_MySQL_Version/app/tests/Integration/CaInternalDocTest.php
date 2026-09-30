<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CaController;
use App\Controllers\DocumentController;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * The CA internal-only "Financial Annexure" (RODTEP/export benefits +
 * expenses linked to an order, per CaOrderLinkingTest) must be:
 *   - off by default for every order,
 *   - toggleable/generatable only by someone holding ca_internal_doc_manage
 *     (not ca_module_view/inr_actual_edit),
 *   - structurally impossible to surface on the client portal (category =
 *     'internal', same mechanism as SUPPO/BLI/COOPREP/AMD),
 *   - excluded from the general staff dossier ZIP (which needs only the
 *     much broader manage_orders permission), and
 *   - gated by ca_module_view specifically on the generic document
 *     download route, so a staff member without any CA permission can't
 *     fetch it just by knowing/guessing its document id.
 */
final class CaInternalDocTest extends DbTestCase
{
    public function testCaInternalDocIsDisabledByDefaultForANewOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $order = OrderRepository::find($orderId);
        self::assertSame(0, (int) $order['ca_internal_doc_enabled']);
    }

    public function testSetCaInternalDocEnabledTogglesTheFlagBothWays(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        OrderRepository::setCaInternalDocEnabled($orderId, true);
        self::assertSame(1, (int) OrderRepository::find($orderId)['ca_internal_doc_enabled']);

        OrderRepository::setCaInternalDocEnabled($orderId, false);
        self::assertSame(0, (int) OrderRepository::find($orderId)['ca_internal_doc_enabled']);
    }

    public function testGenerateCaInternalAnnexureRefusesWhenNotEnabled(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not enabled');
        DocumentGenerationService::generateCaInternalAnnexure($orderId, $userId);
    }

    public function testGenerateCaInternalAnnexureProducesARealPdfAndAnInternalOnlyDocumentRow(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setCaInternalDocEnabled($orderId, true);

        CaExportBenefitRepository::record($orderId, 'RODTEP', 'SB-CAFIN-1', 15000, '2026-06-01', 'INR', null, $userId);
        $expenseId = CaExpenseRepository::insert('ZOHO-CAFIN-1', 'ECGC insurance', null, 'ECGC', 8800.0, 'INR', '2026-06-01');
        CaExpenseRepository::linkToOrder($expenseId, $orderId);

        $result = DocumentGenerationService::generateCaInternalAnnexure($orderId, $userId);

        self::assertNotEmpty($result['document_reference']);
        self::assertStringContainsString('CAFIN', $result['document_reference']);

        $document = DocumentRepository::find((int) $result['document_id']);
        self::assertSame('CAFIN', $document['document_type_code']);
        self::assertSame('internal', $document['document_type_category']);

        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        self::assertSame(1, (int) $file['internal_only']);
        self::assertTrue(is_file($file['server_path']), 'Generated CAFIN PDF must actually exist on disk.');
        $pdfBytes = (string) file_get_contents($file['server_path']);
        self::assertStringStartsWith('%PDF', $pdfBytes, 'File must be a real PDF.');

        @unlink($file['server_path']);
    }

    public function testCafinDocumentNeverAppearsInCustomerFacingForOrderEvenIfMarkedSent(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setCaInternalDocEnabled($orderId, true);

        $result = DocumentGenerationService::generateCaInternalAnnexure($orderId, $userId);
        $file = FileStoreRepository::find((int) $result['pdf_file_id']);

        // Force the document into the two statuses customerFacingForOrder()
        // would otherwise accept ('approved'/'sent') — the category filter
        // must still exclude it regardless of status, proving this is a
        // structural exclusion, not merely a status-based coincidence.
        Database::connection()->prepare("UPDATE documents SET status = 'sent' WHERE id = :id")
            ->execute(['id' => $result['document_id']]);

        $customerFacing = DocumentRepository::customerFacingForOrder($orderId);
        $codes = array_column($customerFacing, 'document_type_code');
        self::assertNotContains('CAFIN', $codes);

        @unlink($file['server_path']);
    }

    public function testForOrderExcludingInternalCaDocsExcludesCafinButKeepsOtherFiles(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $ordinaryFileId = $this->createTestFile($orderId);
        $cafinFileId = $this->createTestFile($orderId);
        $this->createTestDocument($orderId, 'CAFIN', 'draft', $cafinFileId);

        $all = FileStoreRepository::forOrder($orderId);
        $allIds = array_column($all, 'id');
        self::assertContains($ordinaryFileId, $allIds);
        self::assertContains($cafinFileId, $allIds, 'Sanity check: forOrder() (unfiltered) does include the CAFIN file.');

        $excluding = FileStoreRepository::forOrderExcludingInternalCaDocs($orderId);
        $excludingIds = array_column($excluding, 'id');
        self::assertContains($ordinaryFileId, $excludingIds);
        self::assertNotContains($cafinFileId, $excludingIds, 'The dossier-ZIP query must exclude the CAFIN file.');
    }

    public function testToggleInternalDocControllerEnablesAndAuditLogs(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['enabled' => '1'];
        $controller = new CaController();
        ob_start();
        $controller->toggleInternalDoc(['id' => (string) $orderId]);
        ob_get_clean();

        self::assertSame(1, (int) OrderRepository::find($orderId)['ca_internal_doc_enabled']);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'CA_INTERNAL_DOC_ENABLED' AND entity_id = {$orderId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testToggleInternalDocControllerDisablesAndAuditLogs(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setCaInternalDocEnabled($orderId, true);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = []; // no 'enabled' key -> unchecked checkbox -> disable
        $controller = new CaController();
        ob_start();
        $controller->toggleInternalDoc(['id' => (string) $orderId]);
        ob_get_clean();

        self::assertSame(0, (int) OrderRepository::find($orderId)['ca_internal_doc_enabled']);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'CA_INTERNAL_DOC_DISABLED' AND entity_id = {$orderId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testGenerateInternalDocControllerRefusesWhenNotEnabled(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new CaController();
        ob_start();
        $controller->generateInternalDoc(['id' => (string) $orderId]);
        ob_get_clean();

        self::assertNull(DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'CAFIN'));
    }

    public function testGenerateInternalDocControllerGeneratesAndAuditLogsWhenEnabled(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setCaInternalDocEnabled($orderId, true);

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new CaController();
        ob_start();
        $controller->generateInternalDoc(['id' => (string) $orderId]);
        ob_get_clean();

        $doc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'CAFIN');
        self::assertNotNull($doc);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'CA_INTERNAL_DOC_GENERATED' AND entity_id = {$orderId}")
            ->fetchColumn();
        self::assertSame(1, $count);

        $file = FileStoreRepository::find((int) $doc['pdf_file_id']);
        @unlink($file['server_path']);
    }

    public function testDocumentDownloadRefusesCafinWithoutCaModuleView(): void
    {
        // Export Executive holds download_pdf (the route-level permission
        // for /documents/{id}/download) but NOT ca_module_view — proving
        // the CAFIN-specific in-method check is what actually blocks this,
        // not the route's own broader gate.
        $userId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setCaInternalDocEnabled($orderId, true);
        $result = DocumentGenerationService::generateCaInternalAnnexure($orderId, $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new DocumentController();
        ob_start();
        $controller->download(['documentId' => (string) $result['document_id']]);
        $output = ob_get_clean();

        self::assertSame(403, http_response_code());
        self::assertStringContainsString('do not have permission', strtolower($output));

        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        @unlink($file['server_path']);
    }

    public function testDocumentDownloadAllowsCafinWithCaModuleView(): void
    {
        // Accounts Executive holds ca_module_view (but NOT
        // ca_internal_doc_manage) — proving download access only needs
        // the view permission, never the toggle/generate one.
        $adminId = $this->createTestUser('Admin');
        $viewerId = $this->createTestUser('Accounts Executive');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setCaInternalDocEnabled($orderId, true);
        $result = DocumentGenerationService::generateCaInternalAnnexure($orderId, $adminId);

        // download()'s success path never explicitly calls
        // http_response_code(200) (PHP's implicit default) — reset the
        // global here first, since a prior test in this same process may
        // have left it at 403/404 and that would otherwise pass this
        // assertion for the wrong reason (a leftover, not this call).
        http_response_code(200);

        $_SESSION['_auth_user_id'] = $viewerId;
        $controller = new DocumentController();
        ob_start();
        $controller->download(['documentId' => (string) $result['document_id']]);
        ob_get_clean();

        self::assertSame(200, http_response_code());

        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        @unlink($file['server_path']);
    }

    public function testOrderShowPageOffersToggleAndGenerateControlsToAPrivilegedUser(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new \App\Controllers\OrderController();
        ob_start();
        $controller->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Internal-Only Financial Annexure', $output);
        self::assertStringContainsString('Enable internal financial annexure for this order', $output);
    }

    /**
     * docs/schema.sql Section AP tightened this further: the whole Order
     * Financials panel (not just the CAFIN toggle inside it) now requires
     * manage_order_financials, which Accounts Executive does NOT get by
     * default (unlike the broader ca_module_view it does hold) — so this
     * viewer sees none of the panel at all, CAFIN sub-section included.
     */
    public function testOrderShowPageHidesTheWholePanelFromANonPrivilegedCaViewer(): void
    {
        $userId = $this->createTestUser('Accounts Executive');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new \App\Controllers\OrderController();
        ob_start();
        $controller->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('Internal-Only Financial Annexure', $output);
        self::assertStringNotContainsString('Enable internal financial annexure for this order', $output);
        self::assertStringNotContainsString('Order Financials', $output);
    }

    /**
     * Within the panel itself, the finer-grained distinction the original
     * version of this test protected still holds: someone who can see the
     * panel (granted manage_order_financials) but wasn't separately
     * granted ca_internal_doc_manage sees the CAFIN sub-section read-only,
     * not the toggle/generate controls.
     */
    public function testOrderShowPageHidesToggleFromAViewerWithoutCaInternalDocManage(): void
    {
        $userId = $this->createTestUser('Accounts Executive');
        $pdo = Database::connection();
        $permissionId = (int) $pdo->query("SELECT id FROM permissions WHERE permission_key = 'manage_order_financials'")->fetchColumn();
        $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:user_id, :permission_id, 1)')
            ->execute(['user_id' => $userId, 'permission_id' => $permissionId]);
        \App\Services\PermissionService::resetCache();
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new \App\Controllers\OrderController();
        ob_start();
        $controller->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Internal-Only Financial Annexure', $output);
        self::assertStringNotContainsString('Enable internal financial annexure for this order', $output);
        self::assertStringContainsString('only Admin/MD/ED', $output);
    }
}
