<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStageRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-14: "Stage-1 documents are filed under a generic
 * `stage` folder — new orders have no current_stage_id until the first
 * gate passes (151 of 216 orders)." orders.current_stage_id — read by
 * the per-order/per-stage audit file_store folder layout — stayed NULL
 * until StageGateService::passAndUnlockNext() ran for the first time;
 * every brand-new order's own documents filed into a fallback folder in
 * the meantime. OrderStageRepository::initializeForOrder() — the one
 * chokepoint every "new order" path (OrderController::store(),
 * OrderDuplicationService, SampleDataService) already calls — now also
 * sets it to Stage 1 immediately.
 */
final class OrderCurrentStageSetOnCreateTest extends DbTestCase
{
    public function testInitializeForOrderSetsCurrentStageIdToStageOne(): void
    {
        // Deliberately not the createTestOrder() fixture: it already calls
        // initializeForOrder() itself (which is exactly this fix, now that
        // it's applied — every other test using that fixture is already
        // relying on it), so it can't demonstrate the "before" state. A
        // bare INSERT with no current_stage_id, mirroring exactly what
        // OrderRepository::insertRow() itself writes, is what actually
        // isolates the behavior under test here.
        $clientId = $this->createTestClient();
        $pdo = Database::connection();
        // company_legal_name is included (not left to its NULL default)
        // so this row renders safely through views/orders/index.php like
        // any other test order would — every other test in this suite
        // shares one disposable, un-rolled-back database for the whole
        // run, so a stray NULL-ish row here would otherwise surface as a
        // PHP deprecation in a completely unrelated test later in the run.
        $pdo->exec("INSERT INTO orders
            (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
             currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status, company_legal_name)
            SELECT CONCAT('PHPUNIT-L14-', UNIX_TIMESTAMP(), '-', FLOOR(RAND() * 1000000)), {$clientId}, 999, 'X',
                   (SELECT id FROM payment_presets LIMIT 1), (SELECT id FROM incoterms LIMIT 1),
                   (SELECT id FROM currencies LIMIT 1), 'TBC', 'NIL', CURDATE(), CURDATE(), 'active', 'PHPUnit L-14 Test Buyer Ltd'");
        $orderId = (int) $pdo->lastInsertId();
        self::assertNull(OrderRepository::find($orderId)['current_stage_id'], 'sanity check: a freshly-inserted order row must start with no current_stage_id, or this test proves nothing');

        OrderStageRepository::initializeForOrder($orderId);

        $stage1Id = (int) $pdo->query('SELECT id FROM stages_master WHERE stage_number = 1')->fetchColumn();
        $order = OrderRepository::find($orderId);
        self::assertNotNull($order['current_stage_id'], 'a brand-new order must never have a NULL current_stage_id');
        self::assertSame($stage1Id, (int) $order['current_stage_id']);
    }
}
