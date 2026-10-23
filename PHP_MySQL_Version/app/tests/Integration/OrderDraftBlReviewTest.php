<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderDraftBlReviewRepository;
use App\Repositories\OrderShippingRepository;
use App\Services\FileUploadService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section BS — export process steps 9h-9j: the CHA
 * draft BL review loop (upload a draft received from the CHA, approve
 * it or request a revision, repeat until approved).
 *
 * Like BlCopyUploadTest, every success-path test builds state through
 * the repositories directly, against a pre-made test file
 * ($this->createTestFile()) — PHP's move_uploaded_file() requires a
 * genuine HTTP upload and always fails under a CLI test, so the real
 * upload-and-everything-together path can't run end to end here; the
 * controller's own upload action is instead exercised for its
 * validation (no file attached, a pending round blocking a second
 * upload) and for proving the 'draft_bl' context resolves.
 */
final class OrderDraftBlReviewTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
    }

    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function render(int $orderId): string
    {
        $this->unlockThrough($orderId, 6);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    // ---------------------------------------------------------------
    // OrderDraftBlReviewRepository + OrderShippingRepository
    // ---------------------------------------------------------------

    public function testCreateRoundAssignsSequentialRoundNumbers(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $round1Id = OrderDraftBlReviewRepository::createRound($orderId, $this->createTestFile($orderId), $userId);
        $round2Id = OrderDraftBlReviewRepository::createRound($orderId, $this->createTestFile($orderId), $userId);

        $rounds = OrderDraftBlReviewRepository::forOrder($orderId);
        self::assertCount(2, $rounds);
        self::assertSame(2, (int) $rounds[0]['round_number'], 'newest round first');
        self::assertSame(1, (int) $rounds[1]['round_number']);
        self::assertSame($round2Id, (int) $rounds[0]['id']);
        self::assertSame($round1Id, (int) $rounds[1]['id']);
        self::assertSame('pending', $rounds[0]['status'], 'a new round always starts pending');

        $latest = OrderDraftBlReviewRepository::latestForOrder($orderId);
        self::assertSame($round2Id, (int) $latest['id']);
    }

    public function testRoundNumbersAreScopedPerOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderA = $this->createTestOrder($this->createTestClient());
        $orderB = $this->createTestOrder($this->createTestClient());

        OrderDraftBlReviewRepository::createRound($orderA, $this->createTestFile($orderA), $userId);
        $firstRoundForB = OrderDraftBlReviewRepository::createRound($orderB, $this->createTestFile($orderB), $userId);

        $round = OrderDraftBlReviewRepository::find($firstRoundForB);
        self::assertSame(1, (int) $round['round_number'], "order B's first round must be round 1, independent of order A's history");
    }

    public function testAttachDraftBlCreatesAShippingRowWhenNoneExistsYetAndClearsAnyPriorApproval(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertNull(OrderShippingRepository::find($orderId), 'no shipping-details form has been saved yet');

        $fileId = $this->createTestFile($orderId);
        OrderShippingRepository::attachDraftBl($orderId, $fileId);

        $shipping = OrderShippingRepository::find($orderId);
        self::assertNotNull($shipping);
        self::assertSame($fileId, (int) $shipping['draft_bl_file_id']);
        self::assertNotNull($shipping['draft_bl_uploaded_at']);
        self::assertNull($shipping['draft_bl_approved_at']);
    }

    public function testAttachDraftBlClearsAPriorApprovalWhenACorrectedDraftArrives(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::attachDraftBl($orderId, $this->createTestFile($orderId));
        OrderShippingRepository::approveDraftBl($orderId, $userId);
        self::assertNotNull(OrderShippingRepository::find($orderId)['draft_bl_approved_at']);

        OrderShippingRepository::attachDraftBl($orderId, $this->createTestFile($orderId));

        self::assertNull(OrderShippingRepository::find($orderId)['draft_bl_approved_at'], 'a newly attached draft must not inherit an earlier round\'s approval');
    }

    // ---------------------------------------------------------------
    // OrderController::uploadDraftBl() — validation (no real upload needed)
    // ---------------------------------------------------------------

    public function testUploadDraftBlRejectsWhenNoFileAttached(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->uploadDraftBl(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertSame([], OrderDraftBlReviewRepository::forOrder($orderId));
    }

    public function testUploadDraftBlRefusesWhileTheCurrentRoundIsStillPendingEvenBeforeLookingAtTheFile(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderDraftBlReviewRepository::createRound($orderId, $this->createTestFile($orderId), $userId);
        $_SESSION['_auth_user_id'] = $userId;
        // Deliberately no $_FILES['draft_bl'] at all — the pending-round
        // guard must fire first, before the "no file attached" check.

        ob_start();
        (new OrderController())->uploadDraftBl(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('still awaiting your review', $flash[0]['message']);
        self::assertCount(1, OrderDraftBlReviewRepository::forOrder($orderId));
    }

    public function testUploadDraftBlReturns404ForAnUnknownOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->uploadDraftBl(['id' => '999999999']);
        $output = ob_get_clean();

        self::assertStringContainsString('not found', strtolower($output));
    }

    // ---------------------------------------------------------------
    // OrderController::reviewDraftBl()
    // ---------------------------------------------------------------

    public function testReviewDraftBlApprovesAndUpdatesShipping(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderId);
        $roundId = OrderDraftBlReviewRepository::createRound($orderId, $fileId, $userId);
        OrderShippingRepository::attachDraftBl($orderId, $fileId);
        $_SESSION['_auth_user_id'] = $userId;

        $_POST = ['decision' => 'approved'];
        ob_start();
        (new OrderController())->reviewDraftBl(['id' => (string) $orderId, 'roundId' => (string) $roundId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        $updated = OrderDraftBlReviewRepository::find($roundId);
        self::assertSame('approved', $updated['status']);
        self::assertNotNull($updated['reviewed_at']);
        self::assertSame($userId, (int) $updated['reviewed_by']);

        $shipping = OrderShippingRepository::find($orderId);
        self::assertNotNull($shipping['draft_bl_approved_at']);
        self::assertSame($userId, (int) $shipping['draft_bl_approved_by']);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'DRAFT_BL_REVIEWED' AND entity_id = {$roundId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testReviewDraftBlRequestingRevisionRequiresNotesAndDoesNotApprove(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $fileId = $this->createTestFile($orderId);
        $roundId = OrderDraftBlReviewRepository::createRound($orderId, $fileId, $userId);
        OrderShippingRepository::attachDraftBl($orderId, $fileId);
        $_SESSION['_auth_user_id'] = $userId;

        // No notes supplied — must be rejected, round stays pending.
        $_POST = ['decision' => 'revision_requested'];
        ob_start();
        (new OrderController())->reviewDraftBl(['id' => (string) $orderId, 'roundId' => (string) $roundId]);
        ob_end_clean();
        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertSame('pending', OrderDraftBlReviewRepository::find($roundId)['status']);

        $_POST = ['decision' => 'revision_requested', 'notes' => 'Consignee address line 2 is wrong'];
        ob_start();
        (new OrderController())->reviewDraftBl(['id' => (string) $orderId, 'roundId' => (string) $roundId]);
        ob_end_clean();
        self::assertSame('success', Flash::pull()[0]['type']);
        $updated = OrderDraftBlReviewRepository::find($roundId);
        self::assertSame('revision_requested', $updated['status']);
        self::assertSame('Consignee address line 2 is wrong', $updated['review_notes']);

        $shipping = OrderShippingRepository::find($orderId);
        self::assertNull($shipping['draft_bl_approved_at'], 'a revision request must never approve');

        // Now that the round is no longer pending, a corrected draft can be logged as round 2.
        $round2Id = OrderDraftBlReviewRepository::createRound($orderId, $this->createTestFile($orderId), $userId);
        $rounds = OrderDraftBlReviewRepository::forOrder($orderId);
        self::assertCount(2, $rounds);
        self::assertSame(2, (int) OrderDraftBlReviewRepository::find($round2Id)['round_number']);
    }

    public function testReviewDraftBlRejectsAMismatchedOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderA = $this->createTestOrder($this->createTestClient());
        $orderB = $this->createTestOrder($this->createTestClient());
        $roundId = OrderDraftBlReviewRepository::createRound($orderA, $this->createTestFile($orderA), $userId);
        $_SESSION['_auth_user_id'] = $userId;

        $_POST = ['decision' => 'approved'];
        ob_start();
        (new OrderController())->reviewDraftBl(['id' => (string) $orderB, 'roundId' => (string) $roundId]);
        $output = ob_get_clean();

        self::assertStringContainsString('not found', strtolower($output));
        self::assertSame('pending', OrderDraftBlReviewRepository::find($roundId)['status']);
    }

    public function testReviewDraftBlRejectsAnAlreadyReviewedRound(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $roundId = OrderDraftBlReviewRepository::createRound($orderId, $this->createTestFile($orderId), $userId);
        OrderDraftBlReviewRepository::review($roundId, 'approved', null, $userId);
        $_SESSION['_auth_user_id'] = $userId;

        $_POST = ['decision' => 'approved'];
        ob_start();
        (new OrderController())->reviewDraftBl(['id' => (string) $orderId, 'roundId' => (string) $roundId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testReviewDraftBlRejectsAnInvalidDecision(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $roundId = OrderDraftBlReviewRepository::createRound($orderId, $this->createTestFile($orderId), $userId);
        $_SESSION['_auth_user_id'] = $userId;

        $_POST = ['decision' => 'maybe-later'];
        ob_start();
        (new OrderController())->reviewDraftBl(['id' => (string) $orderId, 'roundId' => (string) $roundId]);
        ob_end_clean();

        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertSame('pending', OrderDraftBlReviewRepository::find($roundId)['status']);
    }

    // ---------------------------------------------------------------
    // View — the order page offers the upload form and round history
    // ---------------------------------------------------------------

    public function testOrderPageOffersTheDraftBlUploadForm(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->render($orderId);

        self::assertStringContainsString('name="draft_bl"', $output);
        self::assertStringContainsString('/draft-bl', $output);
    }

    public function testOrderPageShowsRoundHistoryAndHidesTheUploadFormWhileARoundIsPending(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderDraftBlReviewRepository::createRound($orderId, $this->createTestFile($orderId), $userId);

        $output = $this->render($orderId);

        self::assertStringContainsString('Pending Review', $output);
        self::assertStringContainsString('/review', $output);
        self::assertStringNotContainsString('name="draft_bl"', $output, 'the upload form must be hidden while a round is still awaiting review');
    }

    public function testOrderPageShowsRevisionNotesAndOffersTheUploadFormAgainOnceARevisionWasRequested(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $roundId = OrderDraftBlReviewRepository::createRound($orderId, $this->createTestFile($orderId), $userId);
        OrderDraftBlReviewRepository::review($roundId, 'revision_requested', 'Fix the consignee address', $userId);

        $output = $this->render($orderId);

        self::assertStringContainsString('Revision Requested', $output);
        self::assertStringContainsString('Fix the consignee address', $output);
        self::assertStringContainsString('name="draft_bl"', $output, 'the upload form must reappear once the pending round has been decided');
    }

    /**
     * External QA report L-22 pattern: prove the 'draft_bl' upload
     * context actually resolves (seeded in seed.sql — pre-existing, but
     * never reached by any real code path until this feature) rather
     * than failing "Unknown upload context" before FileUploadService
     * ever reaches move_uploaded_file() — which cannot succeed outside
     * a real HTTP request, so getting that far (and no further) is the
     * proof, same as BlCopyUploadTest's own equivalent check.
     */
    public function testHandleUploadResolvesTheDraftBlContext(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'draft-bl-test-');
        file_put_contents($tmpPath, '%PDF-1.4 fake draft bl content for test');
        $_FILES['draft_bl'] = [
            'name' => 'draft-bl.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpPath),
        ];

        try {
            FileUploadService::handleUpload('draft_bl', 'draft_bl', 'test/draft-bl', null, null, null);
            self::fail('move_uploaded_file() cannot succeed outside a real HTTP upload, so handleUpload() must throw here — the point is which error it throws');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('Unknown upload context', $e->getMessage(), "the 'draft_bl' context row must exist");
            self::assertStringContainsString('Failed to move the uploaded file', $e->getMessage());
        } finally {
            unset($_FILES['draft_bl']);
            @unlink($tmpPath);
        }
    }
}
