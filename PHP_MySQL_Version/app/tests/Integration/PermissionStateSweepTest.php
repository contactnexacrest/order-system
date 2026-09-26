<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\DisputeController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\AmendmentRepository;
use App\Repositories\DisputeRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\AmendmentService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * QA-4 P0.5: a systematic sweep for manage_*-permission-vs-resource-state
 * gaps — write paths that only ever checked "does this user hold the
 * permission" and never "is the resource actually in a state where this
 * action makes sense." Each of these three is a real, fixed gap (not the
 * Stage-9 closeOrder() finding investigated alongside these, which was
 * reverted: docs/SOP/09-stage9-despatch-closure.md documents that specific
 * behavior as an intentional staff-trust decision, not a bug).
 */
final class PermissionStateSweepTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    // ---------------------------------------------------------------
    // Gap 1: DisputeController::updateStatus() took $_POST['status']
    // straight into the DB with no check against the dispute_status
    // dropdown (a bare VARCHAR(30), not a DB-level enum).
    // ---------------------------------------------------------------

    public function testUpdateStatusRefusesAValueNotInTheDisputeStatusDropdown(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Export Executive');
        $disputeId = DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Test dispute', null, null);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['status' => 'NotARealStatus'];

        ob_start();
        (new DisputeController())->updateStatus(['disputeId' => $disputeId]);
        ob_end_clean();

        self::assertSame('Open', DisputeRepository::find($disputeId)['status'], 'the bogus value must never reach the DB');
        $flash = Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
    }

    public function testUpdateStatusAcceptsAValueFromTheDisputeStatusDropdown(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Export Executive');
        $disputeId = DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Test dispute', null, null);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['status' => 'Under Review'];

        ob_start();
        (new DisputeController())->updateStatus(['disputeId' => $disputeId]);
        ob_end_clean();

        self::assertSame('Under Review', DisputeRepository::find($disputeId)['status']);
    }

    // ---------------------------------------------------------------
    // Gap 2: OrderController::saveSupplierPo() never checked Stage 5 was
    // unlocked, unlike confirmSupplierSigned() right below it — holding
    // manage_orders alone was enough to save/generate a Supplier PO for an
    // order still at Stage 1.
    // ---------------------------------------------------------------

    public function testSaveSupplierPoRefusesAnOrderStillAtStageOne(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        $_POST = ['supplier_id' => (string) $supplierId, 'grade' => 'Grade A'];

        ob_start();
        (new OrderController())->saveSupplierPo(['id' => $orderId]);
        ob_end_clean();

        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($orderId), 'no order_supplier_po row must be created while Stage 5 is locked');
        $flash = Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('Stage 5', $flash[0]['message']);
    }

    public function testSaveSupplierPoSucceedsOnceStageFiveIsUnlocked(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        StageGateService::passAndUnlockNext($orderId, 3, null);
        StageGateService::passAndUnlockNext($orderId, 4, null);
        self::assertTrue(StageGateService::isUnlocked($orderId, 5));
        $_POST = ['supplier_id' => (string) $supplierId, 'grade' => 'Grade A'];

        ob_start();
        (new OrderController())->saveSupplierPo(['id' => $orderId]);
        ob_end_clean();

        self::assertNotNull(OrderSupplierPoRepository::findLatestForOrder($orderId));
    }

    // ---------------------------------------------------------------
    // Gap 3: AmendmentService::rejectAmendment() never re-checked the
    // amendment's current status, unlike every sibling transition
    // (approveByMd, generateDocument, attachSignedCopyAndActivate) —
    // AmendmentRepository::reject() unconditionally overwrites status to
    // 'rejected' with no WHERE-clause guard of its own.
    // ---------------------------------------------------------------

    public function testRejectAmendmentRefusesAnAlreadyActiveAmendment(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $amendmentId = AmendmentRepository::create(
            'AMD-TEST-' . bin2hex(random_bytes(4)),
            $orderId,
            'PHPUnit test amendment',
            'importer',
            ['note' => 'snapshot'],
            null, null, null, null, null, null, null
        );
        AmendmentService::approveByMd($amendmentId, 1);
        $documentId = $this->createTestDocument($orderId, 'QT');
        AmendmentRepository::attachDocument($amendmentId, $documentId);
        $fileId = $this->createTestFile($orderId);
        AmendmentService::attachSignedCopyAndActivate($amendmentId, $fileId, 1);
        self::assertSame('active', AmendmentRepository::find($amendmentId)['status']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only a pending amendment can be rejected.');
        AmendmentService::rejectAmendment($amendmentId, 1);
    }

    public function testRejectAmendmentSucceedsOnAPendingAmendment(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $amendmentId = AmendmentRepository::create(
            'AMD-TEST-' . bin2hex(random_bytes(4)),
            $orderId,
            'PHPUnit test amendment',
            'importer',
            ['note' => 'snapshot'],
            null, null, null, null, null, null, null
        );

        AmendmentService::rejectAmendment($amendmentId, 1);

        self::assertSame('rejected', AmendmentRepository::find($amendmentId)['status']);
    }
}
