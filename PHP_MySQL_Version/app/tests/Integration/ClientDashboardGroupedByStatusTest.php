<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Tests\Support\DbTestCase;

/**
 * A lightweight "group by status" split on the client portal's My
 * Orders list — not a full Kanban board (the client portal stays the
 * simple list it is), just a quick visual split once a client has more
 * than a couple of orders on file. Grouped by the client-FACING label,
 * so shipped_docs_sent and complete share one "Complete" group — the
 * client was never meant to see that internal distinction as two
 * separate buckets.
 */
final class ClientDashboardGroupedByStatusTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    private function render(int $clientId): string
    {
        $_SESSION['_client_portal_client_id'] = $clientId;
        ob_start();
        (new ClientPortalController())->dashboard([]);
        return (string) ob_get_clean();
    }

    /**
     * createTestOrder() always inserts sequence_no = 1, so a second call
     * for the same client collides on uq_client_sequence — this mirrors
     * ClientOrderHistoryVisibilityTest's own local helper to create a
     * second (or third) order for one client with a distinct sequence.
     */
    private function createTestOrderWithSequence(int $clientId, int $sequenceNo): int
    {
        $pdo = Database::connection();
        $incoterm = $pdo->query("SELECT id, code FROM incoterms WHERE code = 'FOB'")->fetch();
        $currency = $pdo->query("SELECT id, code FROM currencies WHERE code = 'USD'")->fetch();
        $preset = $pdo->query(
            "SELECT id, preset_name, advance_pct, advance_trigger_text, balance_pct, balance_trigger_option,
                    balance_days, balance_trigger_wording, show_bl_instructions
             FROM payment_presets WHERE preset_name = 'Standard — New Buyer'"
        )->fetch();

        $clientStmt = $pdo->prepare('SELECT company_legal_name FROM clients WHERE id = :id');
        $clientStmt->execute(['id' => $clientId]);
        $client = $clientStmt->fetch() ?: [];

        $stmt = $pdo->prepare(
            'INSERT INTO orders
                (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
                 currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status,
                 incoterm_code, currency_code, preset_name, advance_pct, advance_trigger_text,
                 balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, show_bl_instructions,
                 company_legal_name)
             VALUES
                (:ref, :client_id, :seq, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, \'TBC\', \'NIL\',
                 CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), \'active\',
                 :incoterm_code, :currency_code, :preset_name, :advance_pct, :advance_trigger_text,
                 :balance_pct, :balance_trigger_option, :balance_days, :balance_trigger_wording, :show_bl_instructions,
                 :company_legal_name)'
        );
        $stmt->execute([
            'ref'         => 'PHPUNIT-TEST-' . bin2hex(random_bytes(4)),
            'client_id'   => $clientId,
            'seq'         => $sequenceNo,
            'inquiry_ref' => 'PHPUNIT-' . bin2hex(random_bytes(3)),
            'preset_id'   => $preset['id'],
            'incoterm_id' => $incoterm['id'],
            'currency_id' => $currency['id'],
            'incoterm_code'           => $incoterm['code'],
            'currency_code'           => $currency['code'],
            'preset_name'             => $preset['preset_name'],
            'advance_pct'             => $preset['advance_pct'],
            'advance_trigger_text'    => $preset['advance_trigger_text'],
            'balance_pct'             => $preset['balance_pct'],
            'balance_trigger_option'  => $preset['balance_trigger_option'],
            'balance_days'            => $preset['balance_days'],
            'balance_trigger_wording' => $preset['balance_trigger_wording'],
            'show_bl_instructions'    => $preset['show_bl_instructions'],
            'company_legal_name'      => $client['company_legal_name'] ?? null,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        \App\Repositories\OrderStageRepository::initializeForOrder($orderId);
        return $orderId;
    }

    public function testOrdersOfDifferentStatusesRenderUnderSeparateGroupHeadings(): void
    {
        $clientId = $this->createTestClient();
        $this->createTestOrder($clientId);
        $lostId = $this->createTestOrderWithSequence($clientId, 2);
        Database::connection()->prepare("UPDATE orders SET status = 'lost' WHERE id = :id")->execute(['id' => $lostId]);

        $output = $this->render($clientId);

        self::assertStringContainsString('Active (1)', $output);
        self::assertStringContainsString('Lost (1)', $output);
    }

    public function testGroupsRenderInTheFixedMostRelevantFirstOrder(): void
    {
        $clientId = $this->createTestClient();
        $cancelledId = $this->createTestOrder($clientId);
        Database::connection()->prepare("UPDATE orders SET status = 'cancelled' WHERE id = :id")->execute(['id' => $cancelledId]);
        $this->createTestOrderWithSequence($clientId, 2);

        $output = $this->render($clientId);
        $activePos = strpos($output, 'Active (1)');
        $cancelledPos = strpos($output, 'Cancelled (1)');

        self::assertNotFalse($activePos);
        self::assertNotFalse($cancelledPos);
        self::assertLessThan($cancelledPos, $activePos, 'Active must render before Cancelled regardless of creation order');
    }

    public function testAStatusWithNoOrdersShowsNoHeading(): void
    {
        $clientId = $this->createTestClient();
        $this->createTestOrder($clientId);

        $output = $this->render($clientId);

        self::assertStringNotContainsString('Lost (0)', $output);
        self::assertStringNotContainsString('Cancelled (0)', $output);
        self::assertStringNotContainsString('Disputed (0)', $output);
    }

    public function testShippedDocsSentAndTrueCompleteShareOneCompleteGroupNotTwo(): void
    {
        $clientId = $this->createTestClient();
        $shippedId = $this->createTestOrder($clientId);
        Database::connection()->prepare("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id")->execute(['id' => $shippedId]);
        $completeId = $this->createTestOrderWithSequence($clientId, 2);
        Database::connection()->prepare("UPDATE orders SET status = 'complete' WHERE id = :id")->execute(['id' => $completeId]);

        $output = $this->render($clientId);

        self::assertStringContainsString('Complete (2)', $output, 'shipped_docs_sent and complete must merge into one group, not render as two separate "Complete (1)" headings');
        self::assertSame(1, substr_count($output, '<h2>Complete'));
    }

    public function testNoOrdersShowsThePlainEmptyMessageNotAnyGroupHeading(): void
    {
        $clientId = $this->createTestClient();

        $output = $this->render($clientId);

        self::assertStringContainsString('No orders yet.', $output);
        self::assertStringNotContainsString('<h2>', $output);
    }
}
