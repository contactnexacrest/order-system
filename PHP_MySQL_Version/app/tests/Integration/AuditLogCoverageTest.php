<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Controllers\OrderController;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-23: a full order journey used to log only 5 kinds
 * of entry (document approvals, portal provisioned, OC acknowledged) —
 * client creation, order creation, advance/balance recorded and cleared,
 * every stage gate, Supplier PO saved/signed, and BL issued were all
 * silently untraced (and, since every AuditLogRepository::log() call also
 * fires an action-notification email, also silently un-notified).
 *
 * passAndUnlockNext() is the single choke point every stage-advancing
 * controller action goes through, so one assertion there (plus the
 * dedicated-sequential-walk test) stands in for all 9 stages rather than
 * repeating the same check at each call site.
 */
final class AuditLogCoverageTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    private function latestLogFor(string $actionType): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM audit_log WHERE action_type = :action_type ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['action_type' => $actionType]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function testClientCreationIsAuditLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['company_legal_name' => 'L-23 Audit Test Buyer Ltd', 'billing_address' => '1 Test Street'];

        ob_start();
        (new ClientController())->store([]);
        ob_end_clean();

        $log = $this->latestLogFor('CLIENT_CREATED');
        self::assertNotNull($log, 'client creation must be audit-logged');
        self::assertSame($userId, (int) $log['user_id']);
        self::assertSame('L-23 Audit Test Buyer Ltd', $log['new_value']);
    }

    public function testOrderCreationIsAuditLogged(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $clientId = $this->createTestClient();
        $pdo = Database::connection();
        $_POST = [
            'client_id'           => (string) $clientId,
            'incoterm_id'         => (string) $pdo->query("SELECT id FROM incoterms WHERE code = 'FOB'")->fetchColumn(),
            'currency_id'         => (string) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn(),
            'payment_preset_id'   => (string) $pdo->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")->fetchColumn(),
            'product_description' => ['L-23 audit test granite slab'],
            'product_hs_code'     => ['680293'],
            'product_quantity'    => ['10'],
            'product_unit_price'  => ['5.00'],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();

        $orderId = (int) $pdo->query('SELECT MAX(id) FROM orders')->fetchColumn();
        $log = $this->latestLogFor('ORDER_CREATED');
        self::assertNotNull($log, 'order creation must be audit-logged');
        self::assertSame($userId, (int) $log['user_id']);
        self::assertSame('orders', $log['entity_type']);
        self::assertSame($orderId, (int) $log['entity_id']);
    }

    public function testEveryStageGatePassIsAuditLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF'); // CIF so Stage 6 is never auto-skipped
        $userId = $this->createTestUser('Admin');

        for ($stage = 1; $stage <= 9; $stage++) {
            self::assertTrue(StageGateService::passAndUnlockNext($orderId, $stage, $userId), "stage {$stage} should pass");
        }

        $stmt = Database::connection()->prepare(
            "SELECT new_value FROM audit_log WHERE action_type = 'ORDER_STAGE_GATE_PASSED' AND entity_type = 'orders' AND entity_id = :order_id ORDER BY id ASC"
        );
        $stmt->execute(['order_id' => $orderId]);
        $loggedStages = array_map(fn ($row) => $row['new_value'], $stmt->fetchAll());

        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9'], $loggedStages, 'every one of the 9 stage gates must be individually traced');
    }

    public function testFobFreightAutoSkipIsAuditLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $userId = $this->createTestUser('Admin');
        for ($stage = 1; $stage <= 5; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }

        StageGateService::maybeAutoSkipFreightStage($orderId, $userId);

        $log = $this->latestLogFor('ORDER_STAGE_GATE_SKIPPED');
        self::assertNotNull($log, 'the FOB freight-stage auto-skip must be audit-logged');
        self::assertSame($orderId, (int) $log['entity_id']);
        self::assertSame('6', $log['new_value']);
    }

    public function testAdvancePaymentRecordedAndClearedAreBothAuditLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        $userId = $this->createTestUser('Accounts Executive');
        $_SESSION['_auth_user_id'] = $userId;
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);

        $_POST = ['advance_amount' => '1000.00', 'advance_received_at' => '2026-01-10'];
        ob_start();
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);
        ob_end_clean();

        $recordedLog = $this->latestLogFor('ADVANCE_PAYMENT_RECORDED');
        self::assertNotNull($recordedLog, 'recording the advance must be audit-logged');
        self::assertSame($orderId, (int) $recordedLog['entity_id']);
        self::assertSame('1000', $recordedLog['new_value']);

        $_POST = ['advance_cleared_at' => '2026-01-15'];
        ob_start();
        (new OrderController())->clearAdvancePayment(['id' => (string) $orderId]);
        ob_end_clean();

        $clearedLog = $this->latestLogFor('ADVANCE_PAYMENT_CLEARED');
        self::assertNotNull($clearedLog, 'clearing the advance must be audit-logged');
        self::assertSame('2026-01-15', $clearedLog['new_value']);
    }

    public function testBalancePaymentRecordedAndClearedAreBothAuditLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        $userId = $this->createTestUser('Accounts Executive');
        $_SESSION['_auth_user_id'] = $userId;
        for ($stage = 1; $stage <= 7; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        $this->createTestDocument($orderId, 'CI', 'approved');

        $_POST = ['balance_amount' => '1500.00', 'balance_received_at' => '2026-03-01'];
        ob_start();
        (new OrderController())->recordBalancePayment(['id' => (string) $orderId]);
        ob_end_clean();

        $recordedLog = $this->latestLogFor('BALANCE_PAYMENT_RECORDED');
        self::assertNotNull($recordedLog, 'recording the balance must be audit-logged');
        self::assertSame('1500', $recordedLog['new_value']);

        $_POST = ['balance_cleared_at' => '2026-03-05'];
        ob_start();
        (new OrderController())->clearBalancePayment(['id' => (string) $orderId]);
        ob_end_clean();

        $clearedLog = $this->latestLogFor('BALANCE_PAYMENT_CLEARED');
        self::assertNotNull($clearedLog, 'clearing the balance must be audit-logged');
        self::assertSame('2026-03-05', $clearedLog['new_value']);
    }

    public function testSupplierPoSavedAndSignedAreBothAuditLogged(): void
    {
        CompanySettingsRepository::set('wet_signature_required_supplier_po', '0');
        try {
            $orderId = $this->createTestOrder($this->createTestClient());
            $userId = $this->createTestUser('Admin');
            $_SESSION['_auth_user_id'] = $userId;
            for ($stage = 1; $stage <= 4; $stage++) {
                StageGateService::passAndUnlockNext($orderId, $stage, null);
            }
            $supplierId = $this->createTestSupplier();

            $_POST = ['supplier_id' => (string) $supplierId];
            ob_start();
            (new OrderController())->saveSupplierPo(['id' => (string) $orderId]);
            ob_end_clean();

            $savedLog = $this->latestLogFor('SUPPLIER_PO_SAVED');
            self::assertNotNull($savedLog, 'saving Supplier PO terms must be audit-logged');
            self::assertSame($orderId, (int) $savedLog['entity_id']);

            $this->createTestDocument($orderId, 'SUPPO', 'approved');
            ob_start();
            (new OrderController())->confirmSupplierSigned(['id' => (string) $orderId]);
            ob_end_clean();

            $signedLog = $this->latestLogFor('SUPPLIER_PO_SIGNED');
            self::assertNotNull($signedLog, 'confirming Supplier PO signature must be audit-logged');
            self::assertSame('order_supplier_po', $signedLog['entity_type']);
        } finally {
            CompanySettingsRepository::set('wet_signature_required_supplier_po', '1');
        }
    }

    private function createTestSupplier(): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO suppliers (supplier_legal_name) VALUES (:name)');
        $stmt->execute(['name' => 'L-23 Audit Test Supplier Co']);
        return (int) $pdo->lastInsertId();
    }

    public function testBlIssuedIsAuditLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        for ($stage = 1; $stage <= 6; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }

        $_POST = ['bl_number' => 'L23MSCU0001', 'bl_date' => '2026-06-15'];
        ob_start();
        (new OrderController())->recordBlIssued(['id' => (string) $orderId]);
        ob_end_clean();

        $log = $this->latestLogFor('BL_ISSUED');
        self::assertNotNull($log, 'BL issuance must be audit-logged');
        self::assertSame($orderId, (int) $log['entity_id']);
        self::assertSame('L23MSCU0001', $log['new_value']);
    }

    /**
     * forOrder() is the order page's own audit trail — writing a log row
     * against entity_type 'order_payment_status'/'order_supplier_po' is
     * not enough on its own if the one place staff actually read the
     * trail from never looked at those entity types (this was true even
     * for the pre-existing CA_INR_ACTUAL_* entries before this fix).
     */
    public function testForOrderSurfacesPaymentStatusAndSupplierPoEntries(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        AuditLogRepository::log(null, 'ADVANCE_PAYMENT_RECORDED', 'order_payment_status', $orderId, 'advance_amount', null, '999');

        $supplierId = $this->createTestSupplier();
        $pdo = Database::connection();
        $stmt = $pdo->prepare("INSERT INTO order_supplier_po (order_id, supplier_id, supplier_po_reference) VALUES (:order_id, :supplier_id, 'FOR-ORDER-TEST-SPO')");
        $stmt->execute(['order_id' => $orderId, 'supplier_id' => $supplierId]);
        $supplierPoId = (int) $pdo->lastInsertId();
        AuditLogRepository::log(null, 'SUPPLIER_PO_SIGNED', 'order_supplier_po', $supplierPoId, 'supplier_po_reference', null, 'FOR-ORDER-TEST-SPO');

        $rows = AuditLogRepository::forOrder($orderId);
        $actionTypes = array_column($rows, 'action_type');

        self::assertContains('ADVANCE_PAYMENT_RECORDED', $actionTypes);
        self::assertContains('SUPPLIER_PO_SIGNED', $actionTypes);
    }
}
