<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\FileStoreRepository;
use App\Tests\Support\DbTestCase;

/**
 * Batch 3 #13b — free-form extra documents attached to an order or a
 * client that don't fit any fixed document type. Reuses file_store
 * (Section AZ's is_additional_document marker column) rather than a new
 * table; delete is a soft-delete (is_active = 0) — the file on disk is
 * never touched, same "nothing is ever really deleted" rule as the rest
 * of the app.
 */
final class AdditionalDocumentsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
        http_response_code(200);
    }

    public function testMarkAsAdditionalDocumentSetsFlagAndNotes(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderId, null);

        FileStoreRepository::markAsAdditionalDocument($fileId, 'A note.');

        $file = FileStoreRepository::find($fileId);
        self::assertSame(1, (int) $file['is_additional_document']);
        self::assertSame('A note.', $file['notes']);
    }

    public function testAdditionalDocumentsForOrderExcludesOrdinaryFileStoreRows(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $ordinaryFileId = $this->createTestFile($orderId, null); // e.g. a buyer PO copy — never marked
        $additionalFileId = $this->createTestFile($orderId, null);
        FileStoreRepository::markAsAdditionalDocument($additionalFileId, null);

        $rows = FileStoreRepository::additionalDocumentsForOrder($orderId);
        $ids = array_column($rows, 'id');

        self::assertContains($additionalFileId, $ids);
        self::assertNotContains($ordinaryFileId, $ids);
    }

    public function testAdditionalDocumentsForClientExcludesOrdinaryFileStoreRows(): void
    {
        $clientId = $this->createTestClient();
        $ordinaryFileId = $this->createTestFile(null, $clientId);
        $additionalFileId = $this->createTestFile(null, $clientId);
        FileStoreRepository::markAsAdditionalDocument($additionalFileId, null);

        $rows = FileStoreRepository::additionalDocumentsForClient($clientId);
        $ids = array_column($rows, 'id');

        self::assertContains($additionalFileId, $ids);
        self::assertNotContains($ordinaryFileId, $ids);
    }

    public function testDeactivateSoftDeletesAndExcludesFromTheList(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderId, null);
        FileStoreRepository::markAsAdditionalDocument($fileId, null);
        self::assertContains($fileId, array_column(FileStoreRepository::additionalDocumentsForOrder($orderId), 'id'));

        FileStoreRepository::deactivate($fileId);

        $file = FileStoreRepository::find($fileId);
        self::assertSame(0, (int) $file['is_active'], 'the row itself is never hard-deleted, only soft-deleted');
        self::assertNotContains($fileId, array_column(FileStoreRepository::additionalDocumentsForOrder($orderId), 'id'));
    }

    public function testOrderUploadRequiresATitle(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_POST = ['title' => '  '];

        ob_start();
        (new OrderController())->uploadAdditionalDocument(['id' => $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('title is required', $flash[0]['message']);
        self::assertEmpty(FileStoreRepository::additionalDocumentsForOrder($orderId));
    }

    public function testOrderDeleteRefusesAFileBelongingToAnotherOrder(): void
    {
        $orderA = $this->createTestOrder($this->createTestClient());
        $orderB = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderA, null);
        FileStoreRepository::markAsAdditionalDocument($fileId, null);

        ob_start();
        (new OrderController())->deleteAdditionalDocument(['id' => $orderB, 'fileId' => $fileId]);
        ob_end_clean();

        self::assertSame(1, (int) FileStoreRepository::find($fileId)['is_active'], 'a file belonging to a different order must never be removable via this route');
    }

    public function testOrderDeleteRefusesAFileThatIsNotMarkedAsAnAdditionalDocument(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderId, null); // never marked — e.g. a generated document's own file

        ob_start();
        (new OrderController())->deleteAdditionalDocument(['id' => $orderId, 'fileId' => $fileId]);
        ob_end_clean();

        self::assertSame(1, (int) FileStoreRepository::find($fileId)['is_active'], 'only additional-document-marked rows may be removed via this route');
    }

    public function testOrderDeleteDeactivatesTheCorrectFile(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderId, null);
        FileStoreRepository::markAsAdditionalDocument($fileId, null);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['reason' => 'No longer required by the buyer.'];

        ob_start();
        (new OrderController())->deleteAdditionalDocument(['id' => $orderId, 'fileId' => $fileId]);
        ob_end_clean();

        self::assertSame(0, (int) FileStoreRepository::find($fileId)['is_active']);
    }

    /** External QA report L-18: "deleteAdditionalDocument (order) still takes no reason". */
    public function testOrderDeleteRefusesWithoutAReason(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderId, null);
        FileStoreRepository::markAsAdditionalDocument($fileId, null);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['reason' => 'too short'];

        ob_start();
        (new OrderController())->deleteAdditionalDocument(['id' => $orderId, 'fileId' => $fileId]);
        ob_end_clean();

        self::assertSame(1, (int) FileStoreRepository::find($fileId)['is_active'], 'a reason under the minimum length must never be accepted');
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }

    public function testOrderDeleteLogsTheReasonOnTheAuditEntryAndItAppearsOnTheRemovedView(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderId, null);
        FileStoreRepository::markAsAdditionalDocument($fileId, 'Special inspection note.');
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['reason' => 'Buyer withdrew the special inspection requirement.'];

        ob_start();
        (new OrderController())->deleteAdditionalDocument(['id' => $orderId, 'fileId' => $fileId]);
        ob_end_clean();

        $removed = FileStoreRepository::removedAdditionalDocumentsForOrder($orderId);
        self::assertCount(1, $removed);
        self::assertSame('Buyer withdrew the special inspection requirement.', $removed[0]['removal_reason']);
        self::assertNotNull($removed[0]['removed_at']);

        ob_start();
        (new OrderController())->showRemovedAdditionalDocuments(['id' => $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Buyer withdrew the special inspection requirement.', $html);
        self::assertStringContainsString('Special inspection note.', $html);
    }

    public function testClientUploadRequiresATitle(): void
    {
        $clientId = $this->createTestClient();
        $_POST = ['title' => ''];

        ob_start();
        (new ClientController())->uploadAdditionalDocument(['id' => $clientId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertEmpty(FileStoreRepository::additionalDocumentsForClient($clientId));
    }

    public function testClientDeleteRefusesAFileBelongingToAnotherClient(): void
    {
        $clientA = $this->createTestClient();
        $clientB = $this->createTestClient();
        $fileId = $this->createTestFile(null, $clientA);
        FileStoreRepository::markAsAdditionalDocument($fileId, null);

        ob_start();
        (new ClientController())->deleteAdditionalDocument(['id' => $clientB, 'fileId' => $fileId]);
        ob_end_clean();

        self::assertSame(1, (int) FileStoreRepository::find($fileId)['is_active']);
    }

    public function testClientDeleteDeactivatesTheCorrectFile(): void
    {
        $clientId = $this->createTestClient();
        $fileId = $this->createTestFile(null, $clientId);
        FileStoreRepository::markAsAdditionalDocument($fileId, null);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['reason' => 'No longer required by the buyer.'];

        ob_start();
        (new ClientController())->deleteAdditionalDocument(['id' => $clientId, 'fileId' => $fileId]);
        ob_end_clean();

        self::assertSame(0, (int) FileStoreRepository::find($fileId)['is_active']);
    }

    /** External QA report L-18: "deleteAdditionalDocument (client) still takes no reason". */
    public function testClientDeleteRefusesWithoutAReason(): void
    {
        $clientId = $this->createTestClient();
        $fileId = $this->createTestFile(null, $clientId);
        FileStoreRepository::markAsAdditionalDocument($fileId, null);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['reason' => 'too short'];

        ob_start();
        (new ClientController())->deleteAdditionalDocument(['id' => $clientId, 'fileId' => $fileId]);
        ob_end_clean();

        self::assertSame(1, (int) FileStoreRepository::find($fileId)['is_active'], 'a reason under the minimum length must never be accepted');
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }

    public function testClientDeleteLogsTheReasonOnTheAuditEntryAndItAppearsOnTheRemovedView(): void
    {
        $clientId = $this->createTestClient();
        $fileId = $this->createTestFile(null, $clientId);
        FileStoreRepository::markAsAdditionalDocument($fileId, 'Standing NDA.');
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['reason' => 'Superseded by a new NDA signed this quarter.'];

        ob_start();
        (new ClientController())->deleteAdditionalDocument(['id' => $clientId, 'fileId' => $fileId]);
        ob_end_clean();

        $removed = FileStoreRepository::removedAdditionalDocumentsForClient($clientId);
        self::assertCount(1, $removed);
        self::assertSame('Superseded by a new NDA signed this quarter.', $removed[0]['removal_reason']);
        self::assertNotNull($removed[0]['removed_at']);

        ob_start();
        (new ClientController())->showRemovedAdditionalDocuments(['id' => $clientId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Superseded by a new NDA signed this quarter.', $html);
        self::assertStringContainsString('Standing NDA.', $html);
    }

    public function testOrderShowPagePassesAdditionalDocumentsToTheView(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $fileId = $this->createTestFile($orderId, null);
        FileStoreRepository::markAsAdditionalDocument($fileId, 'Special inspection note.');

        $user = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $user;

        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Additional Documents', $html);
        self::assertStringContainsString('test-file.pdf', $html);
        self::assertStringContainsString('Special inspection note.', $html);
    }

    public function testClientShowPagePassesAdditionalDocumentsToTheView(): void
    {
        $clientId = $this->createTestClient();
        $fileId = $this->createTestFile(null, $clientId);
        FileStoreRepository::markAsAdditionalDocument($fileId, 'Standing NDA.');

        $user = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $user;

        ob_start();
        (new ClientController())->show(['id' => $clientId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Additional Documents', $html);
        self::assertStringContainsString('Standing NDA.', $html);
    }

    /** External QA report L-18: "no screen to see removed files" — the order/client show pages must link to it. */
    public function testOrderAndClientShowPagesLinkToTheRemovedAdditionalDocumentsView(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $orderHtml = (string) ob_get_clean();
        self::assertStringContainsString("/orders/{$orderId}/additional-documents/removed", $orderHtml);

        ob_start();
        (new ClientController())->show(['id' => $clientId]);
        $clientHtml = (string) ob_get_clean();
        self::assertStringContainsString("/clients/{$clientId}/additional-documents/removed", $clientHtml);
    }
}
