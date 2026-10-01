<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientPortalController;
use App\Helpers\Flash;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Tests\Support\DbTestCase;

/**
 * "there must be an option on the client side, so he can upload the buyer
 * PO after signing" — the client portal now offers a self-service Buyer PO
 * upload, sharing order_buyer_po_documents (the same table the internal
 * staff upload writes to, see OrderController::uploadBuyerPoDocument) so
 * every copy — staff- or client-uploaded — shows up in one place with
 * version history. Cross-tenant blocking for this action is covered in
 * ClientPortalCrossTenantTest; this suite covers the legitimate-owner path:
 * the ownership check must let the real owner through (never 404 them),
 * and whatever is already on file must reach the view.
 */
final class ClientBuyerPoUploadTest extends DbTestCase
{
    private ClientPortalController $controller;
    private int $clientId;
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ClientPortalController();
        $this->clientId = $this->createTestClient();
        $this->orderId = $this->createTestOrder($this->clientId);
        $_SESSION['_client_portal_client_id'] = $this->clientId;
        $_POST = [];
        $_FILES = [];
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_client_portal_client_id']);
        parent::tearDown();
    }

    public function testOwnerIsNeverBlockedByTheOwnershipCheck(): void
    {
        // No $_FILES set — this exercises FileUploadService's own "No file
        // was uploaded" guard, which the controller catches and reports via
        // Flash, rather than ever reaching move_uploaded_file() (which only
        // ever succeeds for a genuine HTTP upload, not under PHPUnit). The
        // point of this test is solely that the real owner's request is
        // never turned away by the 404 ownership check that blocks other
        // clients' orders.
        ob_start();
        $this->controller->uploadBuyerPo(['id' => $this->orderId]);
        ob_end_clean();

        self::assertNotSame(404, http_response_code(), 'the real owner must never be 404d by the ownership check');
        $flash = Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('No file was uploaded', $flash[0]['message']);
    }

    public function testCreatesNoDocumentWhenNoFileIsSelected(): void
    {
        $before = count(OrderBuyerPoDocumentRepository::forOrder($this->orderId));

        ob_start();
        $this->controller->uploadBuyerPo(['id' => $this->orderId]);
        ob_end_clean();

        self::assertCount($before, OrderBuyerPoDocumentRepository::forOrder($this->orderId));
    }

    public function testShowOrderPassesExistingBuyerPoDocumentsToTheView(): void
    {
        $fileId = $this->createTestFile($this->orderId, $this->clientId);
        OrderBuyerPoDocumentRepository::attach($this->orderId, $fileId);

        ob_start();
        $this->controller->showOrder(['id' => $this->orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Buyer PO', $html);
    }
}
