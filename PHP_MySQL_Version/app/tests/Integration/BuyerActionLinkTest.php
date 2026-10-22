<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\BuyerActionController;
use App\Controllers\ClientPortalController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderRepository;
use App\Services\EncryptionService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #75: acknowledging the Order Confirmation and uploading
 * a signed Buyer PO copy (ClientPortalController::acknowledgeOc()/
 * uploadBuyerPo()) used to only be reachable by logging into the client
 * portal, which itself is only provisioned from Stage 3->4 onward and
 * still requires consuming a one-time set-password link first. This adds
 * a public, no-login counterpart (BuyerActionController, docs/schema.sql
 * Section BG) modeled on the existing /quotation-details and /pi-details
 * tokenized links, sharing its write logic with the session-gated portal
 * route via BuyerActionService so the two paths can never drift apart.
 */
final class BuyerActionLinkTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
        http_response_code(200);
    }

    private function unlockThroughStage3(int $orderId): void
    {
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        StageGateService::passAndUnlockNext($orderId, 3, null);
    }

    // ---------------------------------------------------------------
    // OrderRepository::generateBuyerActionToken / findByValidBuyerActionToken
    // ---------------------------------------------------------------

    public function testGeneratedTokenValidatesBackToTheSameOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $token = OrderRepository::generateBuyerActionToken($orderId);
        $found = OrderRepository::findByValidBuyerActionToken($token);

        self::assertNotNull($found);
        self::assertSame($orderId, (int) $found['id']);
    }

    public function testBogusTokenNeverValidates(): void
    {
        self::assertNull(OrderRepository::findByValidBuyerActionToken('not-a-real-token'));
    }

    public function testExpiredTokenNoLongerValidates(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = OrderRepository::generateBuyerActionToken($orderId, -1);

        self::assertNull(OrderRepository::findByValidBuyerActionToken($token));
    }

    public function testRegeneratingInvalidatesThePreviousToken(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $oldToken = OrderRepository::generateBuyerActionToken($orderId);
        $newToken = OrderRepository::generateBuyerActionToken($orderId);

        self::assertNull(OrderRepository::findByValidBuyerActionToken($oldToken));
        self::assertNotNull(OrderRepository::findByValidBuyerActionToken($newToken));
    }

    // ---------------------------------------------------------------
    // BuyerActionController::show
    // ---------------------------------------------------------------

    public function testShowRendersLinkExpiredForAnInvalidToken(): void
    {
        ob_start();
        (new BuyerActionController())->show(['token' => 'not-a-real-token']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Link No Longer Valid', $html);
    }

    public function testShowRendersOcAckBannerWhenAcknowledgmentIsPending(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage3($orderId);
        $documentId = $this->createTestDocument($orderId, 'OC', 'sent');
        OrderOcAcknowledgmentRepository::recordSent($orderId, $documentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));
        $token = OrderRepository::generateBuyerActionToken($orderId);

        ob_start();
        (new BuyerActionController())->show(['token' => $token]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Your Acknowledgement Needed', $html);
        self::assertStringContainsString('Buyer PO', $html);
    }

    public function testShowOmitsOcAckBannerWhenNothingIsPending(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = OrderRepository::generateBuyerActionToken($orderId);

        ob_start();
        (new BuyerActionController())->show(['token' => $token]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('Your Acknowledgement Needed', $html);
        self::assertStringContainsString('Buyer PO', $html);
    }

    /**
     * User-reported batch item 10: the acknowledgement banner used to
     * depend on an order_oc_acknowledgments row already existing — but
     * that row is only ever created by EmailDispatchService::dispatch()
     * when the OC email actually goes out. Staff generating the Buyer
     * Action Link before that email was sent (still awaiting Level-2
     * approval, or mail sending misconfigured entirely) got a page with
     * nothing to acknowledge, even though an approved OC document
     * genuinely existed. The page must lazily create that row itself,
     * the same way the staff-side "Record Buyer's Acknowledgement"
     * fallback already does.
     */
    public function testShowRendersOcAckBannerWhenOcIsApprovedButNeverEmailed(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage3($orderId);
        $this->createTestDocument($orderId, 'OC', 'approved');
        $token = OrderRepository::generateBuyerActionToken($orderId);

        ob_start();
        (new BuyerActionController())->show(['token' => $token]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Your Acknowledgement Needed', $html);
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertNotNull($ack, 'visiting the page must lazily create the acknowledgment row so there is something real to acknowledge');
        self::assertNull($ack['acknowledged_at']);
    }

    public function testAcknowledgeOcSucceedsWhenOcWasApprovedButNeverEmailed(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage3($orderId);
        $this->createTestDocument($orderId, 'OC', 'approved');
        $token = OrderRepository::generateBuyerActionToken($orderId);

        (new BuyerActionController())->acknowledgeOc(['token' => $token]);

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertNotNull($ack['acknowledged_at']);
        self::assertTrue(StageGateService::isUnlocked($orderId, 5));
    }

    // ---------------------------------------------------------------
    // BuyerActionController::acknowledgeOc
    // ---------------------------------------------------------------

    public function testAcknowledgeOcViaTokenRecordsItAndUnlocksStage5(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage3($orderId);
        $documentId = $this->createTestDocument($orderId, 'OC', 'sent');
        OrderOcAcknowledgmentRepository::recordSent($orderId, $documentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));
        $token = OrderRepository::generateBuyerActionToken($orderId);

        (new BuyerActionController())->acknowledgeOc(['token' => $token]);

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertNotNull($ack['acknowledged_at']);
        self::assertTrue(StageGateService::isUnlocked($orderId, 5));
    }

    public function testAcknowledgeOcWithAnInvalidTokenNeverTouchesAnyOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage3($orderId);
        $documentId = $this->createTestDocument($orderId, 'OC', 'sent');
        OrderOcAcknowledgmentRepository::recordSent($orderId, $documentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));

        ob_start();
        (new BuyerActionController())->acknowledgeOc(['token' => 'not-a-real-token']);
        ob_end_clean();

        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertNull($ack['acknowledged_at'], 'a bogus token must never be able to acknowledge any order');
    }

    // ---------------------------------------------------------------
    // BuyerActionController::uploadBuyerPo
    // ---------------------------------------------------------------

    public function testUploadBuyerPoViaTokenReachesTheSameUploadPathAsThePortal(): void
    {
        // No $_FILES set — same as ClientBuyerPoUploadTest, this exercises
        // FileUploadService's own "No file was uploaded" guard. The point
        // here is solely that a valid token reaches that code path with no
        // session at all, proving the no-login route actually works.
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = OrderRepository::generateBuyerActionToken($orderId);

        (new BuyerActionController())->uploadBuyerPo(['token' => $token]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('No file was uploaded', $flash[0]['message']);
    }

    public function testUploadBuyerPoWithAnInvalidTokenIsRefused(): void
    {
        ob_start();
        (new BuyerActionController())->uploadBuyerPo(['token' => 'not-a-real-token']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Link No Longer Valid', $html);
    }

    // ---------------------------------------------------------------
    // Regression: the session-gated client-portal path must still behave
    // identically now that it delegates to the shared BuyerActionService.
    // ---------------------------------------------------------------

    /**
     * User-reported batch item 10 — same lazy-create fix applies to the
     * session-gated client-portal order page, not just the no-login
     * Buyer Action Link: a client who logs in before the OC email went
     * out must also see something real to acknowledge.
     */
    public function testClientPortalShowOrderRendersOcAckBannerWhenOcIsApprovedButNeverEmailed(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->unlockThroughStage3($orderId);
        $this->createTestDocument($orderId, 'OC', 'approved');
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Your Acknowledgement Needed', $html);
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertNotNull($ack);
        self::assertNull($ack['acknowledged_at']);
    }

    public function testClientPortalAcknowledgeOcStillWorksAfterTheRefactor(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->unlockThroughStage3($orderId);
        $documentId = $this->createTestDocument($orderId, 'OC', 'sent');
        OrderOcAcknowledgmentRepository::recordSent($orderId, $documentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));
        $_SESSION['_client_portal_client_id'] = $clientId;

        (new ClientPortalController())->acknowledgeOc(['id' => $orderId]);

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        self::assertStringContainsString('Thank you', $flash[0]['message']);
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertNotNull($ack['acknowledged_at']);
    }

    // ---------------------------------------------------------------
    // OrderController::generateBuyerActionLink (staff-initiated)
    // ---------------------------------------------------------------

    public function testGenerateBuyerActionLinkPersistsAWorkingToken(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        (new OrderController())->generateBuyerActionLink(['id' => (string) $orderId]);

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        self::assertStringContainsString('/buyer-action/', $flash[0]['message']);

        $order = OrderRepository::find($orderId);
        self::assertNotEmpty($order['buyer_action_token_plain']);
        // QA-5 L-24: the column holds ciphertext now (EncryptionService),
        // never the raw token — decrypt it first, exactly as
        // views/orders/show.php does before ever using it.
        $decryptedToken = EncryptionService::decrypt($order['buyer_action_token_plain']);
        self::assertNotNull($decryptedToken);
        self::assertNotSame($decryptedToken, $order['buyer_action_token_plain'], 'the stored value must not equal the raw token — it must actually be encrypted');
        self::assertNotNull(OrderRepository::findByValidBuyerActionToken($decryptedToken));
    }
}
