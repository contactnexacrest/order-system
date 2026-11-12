<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\OrderBuyerDetailRequestRepository;
use App\Repositories\OrderRepository;
use App\Services\BuyerDetailsRefreshService;
use App\Services\SelfApprovalRules;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-337 (12 Nov 2026): the buyer details an order keeps (R25)
 * follow the client — at once while nothing is issued, by an approved request
 * after. Covers case 1 (automatic, flash wording, audit, FYI notice), the
 * "issued" rule, the request / approve / refuse / lapse paths, the one open
 * request rule and the self-approval rule (default MD and ED).
 * Node twin: tests/integration/buyerDetailsRefresh.test.js (same wording).
 */
final class BuyerDetailsRefreshTest extends DbTestCase
{
    private function newClient(): int
    {
        return $this->createTestClient('d337-' . bin2hex(random_bytes(4)) . '@buyer.test');
    }

    private function setClientEmail(int $clientId, string $email): void
    {
        Database::connection()->prepare('UPDATE clients SET email = :e WHERE id = :id')->execute(['e' => $email, 'id' => $clientId]);
    }

    private function orderEmail(int $orderId): ?string
    {
        $stmt = Database::connection()->prepare('SELECT client_email FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetchColumn() ?: null;
    }

    private function auditRows(int $orderId, string $action): array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM audit_log WHERE entity_type = 'orders' AND entity_id = :id AND action_type = :a ORDER BY id");
        $stmt->execute(['id' => $orderId, 'a' => $action]);
        return $stmt->fetchAll();
    }

    private function notifications(int $userId, int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT type, message FROM notifications WHERE user_id = :u AND related_order_id = :o ORDER BY id');
        $stmt->execute(['u' => $userId, 'o' => $orderId]);
        return $stmt->fetchAll();
    }

    /** An order of the client with an issued (sent) quotation, the client's e-mail then changed. */
    private function issuedOrderWithChangedEmail(int $clientId, string $newEmail): int
    {
        $orderId = $this->createTestOrder($clientId);
        $this->createTestDocument($orderId, 'QT', 'sent');
        $this->setClientEmail($clientId, $newEmail);
        return $orderId;
    }

    public function testCase1DraftOrderTakesTheClientsDetailsWithFlashAuditAndNotice(): void
    {
        $md = $this->createTestUser('Managing Director');
        $exec = $this->createTestUser('Export Executive');
        $clientId = $this->newClient();
        $draft = $this->createTestOrder($clientId);
        $this->createTestDocument($draft, 'QT', 'draft');
        $issued = $this->createTestOrder($clientId);
        $this->createTestDocument($issued, 'QT', 'sent');
        $old = $this->orderEmail($draft);
        $new = 'x' . bin2hex(random_bytes(3)) . '@buyer.test';
        $this->setClientEmail($clientId, $new);

        $result = BuyerDetailsRefreshService::afterClientSaved($clientId, $exec, 'client edit');
        $ref = (string) OrderRepository::find($draft)['order_reference'];

        self::assertSame([$ref], $result['refreshed']);
        self::assertSame($new, $this->orderEmail($draft));
        self::assertSame($old, $this->orderEmail($issued), 'an order with an issued document never changes on its own');
        self::assertSame(' Buyer details also updated on draft order(s): ' . $ref . '.', BuyerDetailsRefreshService::flashText($result));

        $audit = $this->auditRows($draft, 'ORDER_BUYER_DETAILS_REFRESHED');
        self::assertCount(1, $audit);
        self::assertSame('client_email', $audit[0]['field_name']);
        self::assertSame($old, $audit[0]['old_value']);
        self::assertSame($new, $audit[0]['new_value']);
        self::assertSame('Source: client edit', $audit[0]['reason']);

        $notes = $this->notifications($md, $draft);
        self::assertSame('buyer_details_refreshed', $notes[0]['type']);
        self::assertSame('Buyer details of PHPUnit Test Buyer Ltd changed by PHPUnit Test User — also updated on draft order ' . $ref . ': Email: ' . $old . ' → ' . $new, $notes[0]['message']);
        self::assertSame([], $this->notifications($exec, $draft), 'the person who saved the client is not told');

        // Saving again with nothing different changes nothing.
        self::assertSame(['refreshed' => [], 'lapsed' => []], BuyerDetailsRefreshService::afterClientSaved($clientId, $exec, 'client edit'));
        self::assertCount(1, $this->auditRows($draft, 'ORDER_BUYER_DETAILS_REFRESHED'));
    }

    public function testIssuedMeansApprovedSentOrSupersededAfterBeingIssued(): void
    {
        $clientId = $this->newClient();
        foreach (['draft' => false, 'in_review' => false, 'approved' => true, 'sent' => true] as $status => $issued) {
            $orderId = $this->createTestOrder($clientId);
            $this->createTestDocument($orderId, 'QT', $status);
            self::assertSame($issued, BuyerDetailsRefreshService::hasIssuedDocument($orderId), $status);
        }
        $orderId = $this->createTestOrder($clientId);
        self::assertFalse(BuyerDetailsRefreshService::hasIssuedDocument($orderId), 'no documents at all');

        // Superseded straight from a draft: not issued. Superseded after being sent (or unknown): issued.
        $fromDraft = $this->createTestDocument($orderId, 'QT', 'superseded');
        \App\Repositories\AuditLogRepository::log(null, 'DOCUMENT_SUPERSEDED', 'documents', $fromDraft, 'status', 'draft', 'superseded');
        self::assertFalse(BuyerDetailsRefreshService::hasIssuedDocument($orderId));
        $fromSent = $this->createTestDocument($orderId, 'PI', 'superseded');
        \App\Repositories\AuditLogRepository::log(null, 'DOCUMENT_SUPERSEDED', 'documents', $fromSent, 'status', 'sent', 'superseded');
        self::assertTrue(BuyerDetailsRefreshService::hasIssuedDocument($orderId));
    }

    public function testCancelledLostAndCompleteOrdersAreNotChanged(): void
    {
        $clientId = $this->newClient();
        $ids = [];
        foreach (['cancelled', 'lost', 'complete'] as $status) {
            $ids[$status] = $this->createTestOrder($clientId);
            Database::connection()->prepare('UPDATE orders SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $ids[$status]]);
        }
        $before = $this->orderEmail($ids['lost']);
        $this->setClientEmail($clientId, 'closed' . bin2hex(random_bytes(3)) . '@buyer.test');
        self::assertSame([], BuyerDetailsRefreshService::afterClientSaved($clientId, null, 'client edit')['refreshed']);
        self::assertSame($before, $this->orderEmail($ids['lost']));
    }

    public function testRequestNeedsAReasonAndOnlyOneMayWait(): void
    {
        $exec = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        $clientId = $this->newClient();
        $orderId = $this->issuedOrderWithChangedEmail($clientId, 'req' . bin2hex(random_bytes(3)) . '@buyer.test');

        self::assertSame('Give the reason (10 to 500 characters).', BuyerDetailsRefreshService::request($orderId, $exec, 'too short'));
        self::assertSame('Give the reason (10 to 500 characters).', BuyerDetailsRefreshService::request($orderId, $exec, str_repeat('a', 501)));
        self::assertNull(BuyerDetailsRefreshService::request($orderId, $exec, 'The buyer changed their e-mail'));
        self::assertSame('A request to update the buyer details is already waiting for approval.',
            BuyerDetailsRefreshService::request($orderId, $exec, 'Asking a second time for this order'));

        $pending = OrderBuyerDetailRequestRepository::pendingForOrder($orderId);
        self::assertNotNull($pending);
        self::assertSame('PENDING', $pending['status']);
        $changes = json_decode((string) $pending['changes_json'], true);
        self::assertSame('client_email', $changes[0]['field']);
        self::assertCount(1, $this->auditRows($orderId, 'BUYER_DETAILS_CHANGE_REQUESTED'));
        self::assertSame('buyer_details_change_requested', $this->notifications($md, $orderId)[0]['type']);

        // The notice on the order page.
        $notice = BuyerDetailsRefreshService::noticeFor(OrderRepository::find($orderId));
        self::assertTrue($notice['issued']);
        self::assertSame((int) $pending['id'], (int) $notice['pending']['id']);
    }

    public function testNothingIssuedMeansNoRequestButADirectUpdate(): void
    {
        $exec = $this->createTestUser('Export Executive');
        $clientId = $this->newClient();
        $orderId = $this->createTestOrder($clientId);
        $new = 'direct' . bin2hex(random_bytes(3)) . '@buyer.test';
        $this->setClientEmail($clientId, $new);
        self::assertSame('Nothing has been issued on this order yet — use "Update buyer details from the client" (no approval needed).',
            BuyerDetailsRefreshService::request($orderId, $exec, 'The buyer changed their e-mail'));
        $result = BuyerDetailsRefreshService::refreshOrder($orderId, $exec);
        self::assertNull($result['error']);
        self::assertSame($new, $this->orderEmail($orderId));
        self::assertSame('The order already shows the client\'s current details — nothing to update.', BuyerDetailsRefreshService::refreshOrder($orderId, $exec)['error']);
    }

    public function testApproveAppliesTheDetailsAndTellsTheRequester(): void
    {
        $exec = $this->createTestUser('Export Executive');
        $ed = $this->createTestUser('Executive Director');
        $clientId = $this->newClient();
        $new = 'ok' . bin2hex(random_bytes(3)) . '@buyer.test';
        $orderId = $this->issuedOrderWithChangedEmail($clientId, $new);
        BuyerDetailsRefreshService::request($orderId, $exec, 'The buyer changed their e-mail');
        $req = OrderBuyerDetailRequestRepository::pendingForOrder($orderId);

        self::assertNull(BuyerDetailsRefreshService::approve((int) $req['id'], $orderId, $ed, 'Checked with the buyer'));
        self::assertSame($new, $this->orderEmail($orderId));
        $after = OrderBuyerDetailRequestRepository::find((int) $req['id']);
        self::assertSame('APPROVED', $after['status']);
        self::assertSame($ed, (int) $after['decided_by']);
        self::assertSame('Source: approved request #' . $req['id'], $this->auditRows($orderId, 'ORDER_BUYER_DETAILS_REFRESHED')[0]['reason']);
        self::assertCount(1, $this->auditRows($orderId, 'BUYER_DETAILS_CHANGE_APPROVED'));
        $ref = (string) OrderRepository::find($orderId)['order_reference'];
        self::assertSame('Your request to update the buyer details of order ' . $ref . ' was approved by PHPUnit Test User — documents generated from now on show the new details.',
            $this->notifications($exec, $orderId)[0]['message']);
        self::assertSame('This request is no longer waiting for approval.', BuyerDetailsRefreshService::approve((int) $req['id'], $orderId, $ed, ''));
        self::assertNull(BuyerDetailsRefreshService::noticeFor(OrderRepository::find($orderId)), 'nothing left to show');
    }

    public function testRefuseNeedsANoteAndKeepsTheOrder(): void
    {
        $exec = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        $clientId = $this->newClient();
        $orderId = $this->issuedOrderWithChangedEmail($clientId, 'no' . bin2hex(random_bytes(3)) . '@buyer.test');
        $before = $this->orderEmail($orderId);
        BuyerDetailsRefreshService::request($orderId, $exec, 'The buyer changed their e-mail');
        $req = OrderBuyerDetailRequestRepository::pendingForOrder($orderId);

        self::assertSame('Give the note saying why it is refused (10 to 500 characters).', BuyerDetailsRefreshService::refuse((int) $req['id'], $orderId, $md, 'no'));
        self::assertNull(BuyerDetailsRefreshService::refuse((int) $req['id'], $orderId, $md, 'The buyer has not confirmed it'));
        self::assertSame('REFUSED', OrderBuyerDetailRequestRepository::find((int) $req['id'])['status']);
        self::assertSame($before, $this->orderEmail($orderId));
        $ref = (string) OrderRepository::find($orderId)['order_reference'];
        self::assertSame('Your request to update the buyer details of order ' . $ref . ' was refused by PHPUnit Test User: The buyer has not confirmed it',
            $this->notifications($exec, $orderId)[0]['message']);
        self::assertCount(1, $this->auditRows($orderId, 'BUYER_DETAILS_CHANGE_REFUSED'));
    }

    public function testRequestLapsesWhenTheClientChangesBeforeApproval(): void
    {
        $exec = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        $clientId = $this->newClient();
        $orderId = $this->issuedOrderWithChangedEmail($clientId, 'first' . bin2hex(random_bytes(3)) . '@buyer.test');
        $before = $this->orderEmail($orderId);
        BuyerDetailsRefreshService::request($orderId, $exec, 'The buyer changed their e-mail');
        $req = OrderBuyerDetailRequestRepository::pendingForOrder($orderId);
        $this->setClientEmail($clientId, 'second' . bin2hex(random_bytes(3)) . '@buyer.test');

        self::assertSame('The client\'s details changed again after this request — ask again so the approver sees the current details.',
            BuyerDetailsRefreshService::approve((int) $req['id'], $orderId, $md, ''));
        self::assertSame('LAPSED', OrderBuyerDetailRequestRepository::find((int) $req['id'])['status']);
        self::assertSame($before, $this->orderEmail($orderId), 'nothing applied');
        self::assertCount(1, $this->auditRows($orderId, 'BUYER_DETAILS_CHANGE_LAPSED'));
    }

    public function testSavingTheClientAgainLapsesAWaitingRequest(): void
    {
        $exec = $this->createTestUser('Export Executive');
        $clientId = $this->newClient();
        $orderId = $this->issuedOrderWithChangedEmail($clientId, 'first' . bin2hex(random_bytes(3)) . '@buyer.test');
        BuyerDetailsRefreshService::request($orderId, $exec, 'The buyer changed their e-mail');
        $req = OrderBuyerDetailRequestRepository::pendingForOrder($orderId);
        $this->setClientEmail($clientId, 'again' . bin2hex(random_bytes(3)) . '@buyer.test');

        $result = BuyerDetailsRefreshService::afterClientSaved($clientId, $exec, 'client edit');
        $ref = (string) OrderRepository::find($orderId)['order_reference'];
        self::assertSame([$ref], $result['lapsed']);
        self::assertSame(' A waiting request to update the buyer details lapsed on order(s) ' . $ref . ' — ask again so the approver sees the current details.',
            BuyerDetailsRefreshService::flashText($result));
        $after = OrderBuyerDetailRequestRepository::find((int) $req['id']);
        self::assertSame('LAPSED', $after['status']);
        self::assertSame('Lapsed — the client was saved again (client edit)', $after['decision_note']);
    }

    public function testNobodyDecidesTheirOwnRequestUnlessTheRuleAllowsIt(): void
    {
        self::assertArrayHasKey('buyer_details_change', SelfApprovalRules::RULES);
        $ed = $this->createTestUser('Executive Director');
        $md = $this->createTestUser('Managing Director');
        // Owner, 12 Nov 2026: the default is the MD and the ED, like every other rule.
        self::assertTrue(SelfApprovalRules::allowed('buyer_details_change', $ed), 'default: the ED');
        self::assertTrue(SelfApprovalRules::allowed('buyer_details_change', $md), 'default: the MD');

        // Admin unticks the ED: the ED can no longer decide their own request.
        $pdo = Database::connection();
        $edRoleId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'Executive Director'")->fetchColumn();
        $pdo->prepare("DELETE FROM self_approval_rules WHERE rule_key = 'buyer_details_change' AND role_id = :r")->execute(['r' => $edRoleId]);
        try {
            self::assertFalse(SelfApprovalRules::allowed('buyer_details_change', $ed), 'ED unticked');
            $clientId = $this->newClient();
            $orderId = $this->issuedOrderWithChangedEmail($clientId, 'self' . bin2hex(random_bytes(3)) . '@buyer.test');
            BuyerDetailsRefreshService::request($orderId, $ed, 'The buyer changed their e-mail');
            $req = OrderBuyerDetailRequestRepository::pendingForOrder($orderId);
            $msg = 'You asked for this change — someone else must approve it (Admin › Self-approval rules).';
            self::assertSame($msg, BuyerDetailsRefreshService::approve((int) $req['id'], $orderId, $ed, ''));
            self::assertSame($msg, BuyerDetailsRefreshService::refuse((int) $req['id'], $orderId, $ed, 'Refusing my own request'));
            self::assertSame('PENDING', OrderBuyerDetailRequestRepository::find((int) $req['id'])['status']);
        } finally {
            $pdo->prepare("INSERT INTO self_approval_rules (rule_key, role_id) SELECT 'buyer_details_change', :r FROM DUAL
                WHERE NOT EXISTS (SELECT 1 FROM self_approval_rules WHERE rule_key = 'buyer_details_change' AND role_id = :r2)")->execute(['r' => $edRoleId, 'r2' => $edRoleId]);
        }

        // The MD's own request: allowed by the default rule.
        $clientId = $this->newClient();
        $orderId2 = $this->issuedOrderWithChangedEmail($clientId, 'self2' . bin2hex(random_bytes(3)) . '@buyer.test');
        BuyerDetailsRefreshService::request($orderId2, $md, 'The buyer changed their e-mail');
        $req2 = OrderBuyerDetailRequestRepository::pendingForOrder($orderId2);
        self::assertNull(BuyerDetailsRefreshService::approve((int) $req2['id'], $orderId2, $md, ''));
    }

    public function testPermissionIsSeededForMdAndEdAndTheClientSavePathsCallTheRefresh(): void
    {
        $roles = Database::connection()->query(
            "SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
              WHERE p.permission_key = 'approve_buyer_details_change' ORDER BY r.name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['Executive Director', 'Managing Director'], $roles);
        $src = dirname(__DIR__, 2) . '/src/Controllers/';
        self::assertStringContainsString("BuyerDetailsRefreshService::afterClientSaved(\$clientId, (int) \$user['id'], 'client edit')", (string) file_get_contents($src . 'ClientController.php'));
        self::assertStringContainsString('BuyerDetailsRefreshService::afterClientSaved(', (string) file_get_contents($src . 'PiIntakeReviewController.php'));
    }
}
