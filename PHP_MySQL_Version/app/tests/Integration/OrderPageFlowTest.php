<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderComplianceTaskRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner requests (2026-10-26) for the order page flow: a remittance is
 * recorded together with the day's exchange rate; each Compliance Checklist
 * row saves on its own (JSON, no page reload) and "Mark All N/A" works per
 * checklist part; the supplier's balance can still be paid long after
 * Stage 5 passed; the document section flags anything waiting on someone
 * and every Generate button says when its document already exists.
 */
final class OrderPageFlowTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        parent::tearDown();
    }

    private function lastFlash(): string
    {
        $flash = $_SESSION['_flash'] ?? [];
        $last = is_array($flash) ? end($flash) : null;
        return (string) ($last['message'] ?? '');
    }

    private function render(int $orderId): string
    {
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    /** @return array<string,mixed> a complete Supplier PO terms array, as the controller builds it */
    private function poData(array $overrides = []): array
    {
        $keys = ['material_stone_type', 'grade', 'surface_finish', 'dimensions', 'dimensional_tolerance', 'quantity', 'unit',
            'colour_reference', 'special_requirements', 'unit_price_inr', 'basic_value_inr', 'gst_rate_pct', 'gst_amount_inr',
            'total_payable_inr', 'advance_pct', 'advance_amount_inr', 'balance_amount_inr', 'delivery_location',
            'required_delivery_date', 'packing_requirement'];
        return array_merge(array_fill_keys($keys, null), $overrides);
    }

    public function testAdvanceRemittanceIsRecordedTogetherWithTheDaysExchangeRate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $_POST = ['advance_amount' => '1800', 'advance_received_at' => '2026-10-26'];
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);
        self::assertStringContainsString("today's exchange rate", $this->lastFlash());
        self::assertNull(OrderPaymentStatusRepository::find($orderId)['advance_amount'], 'nothing is recorded without the rate');

        $_POST = ['advance_amount' => '1800', 'advance_received_at' => '2026-10-26', 'advance_exchange_rate' => '83.25'];
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);
        $payment = OrderPaymentStatusRepository::find($orderId);
        self::assertEquals(1800, (float) $payment['advance_amount']);
        self::assertEquals(83.25, (float) $payment['advance_exchange_rate']);
        self::assertSame($this->userId, (int) $payment['advance_exchange_rate_set_by']);
        self::assertStringContainsString("Received USD 1,800.00 at today's exchange rate of ₹83.2500 (= ₹149,850.00).", $this->lastFlash());
    }

    public function testEachComplianceRowSavesOnItsOwnAsJson(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderComplianceTaskRepository::initializeForOrder($orderId);
        $tasks = OrderComplianceTaskRepository::forOrder($orderId);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $_POST = ['task_type_id' => (string) $tasks[0]['task_type_id'], 'status' => 'skipped', 'skip_reason' => ''];
        ob_start();
        (new OrderController())->updateComplianceTask(['id' => (string) $orderId]);
        $refused = json_decode((string) ob_get_clean(), true);
        self::assertFalse($refused['ok']);
        self::assertStringContainsString('reason is required', $refused['message']);

        $_POST = ['task_type_id' => (string) $tasks[0]['task_type_id'], 'status' => 'approved'];
        ob_start();
        (new OrderController())->updateComplianceTask(['id' => (string) $orderId]);
        $saved = json_decode((string) ob_get_clean(), true);
        self::assertTrue($saved['ok']);
        self::assertSame(count($tasks) - 1, $saved['outstanding']);
        self::assertArrayNotHasKey('_flash', $_SESSION, 'a JSON save leaves no flash behind for the next page');
    }

    public function testMarkAllNotApplicableOnlyTouchesItsOwnChecklistPart(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderComplianceTaskRepository::initializeForOrder($orderId);

        $_POST = ['skip_reason' => 'Repeat order — checklist does not apply', 'phase' => 'order'];
        (new OrderController())->markAllComplianceTasksNotApplicable(['id' => (string) $orderId]);

        foreach (OrderComplianceTaskRepository::forOrder($orderId) as $task) {
            self::assertSame($task['phase'] === 'order' ? 'skipped' : 'not_started', $task['status'], $task['task_type_name']);
        }
    }

    public function testSupplierBalanceCanBePaidAfterStage5HasPassed(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Flow Test Quarry ' . bin2hex(random_bytes(3)), 'types' => ['Trader']]);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-FLOW-1', $this->poData(['balance_amount_inr' => '50000']));
        for ($stage = 1; $stage <= 6; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, $this->userId);
        }

        $_POST = ['paid_date' => date('Y-m-d'), 'amount_paid_inr' => '50000'];
        (new OrderController())->markSupplierBalancePaid(['id' => (string) $orderId]);

        self::assertNotNull(OrderSupplierPoRepository::findLatestForOrder($orderId)['balance_paid_at'], $this->lastFlash());
    }

    public function testDocumentSectionFlagsPendingActionsAndGenerateButtonsSayWhenADocumentExists(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $html = $this->render($orderId);
        self::assertStringContainsString('id="document-review-approval">', $html, 'no documents yet — collapsed');
        self::assertStringNotContainsString('doc-already-note', $html);

        $this->createTestDocument($orderId, 'QT', 'draft');
        $html = $this->render($orderId);
        self::assertStringContainsString('id="document-review-approval" open', $html);
        self::assertStringContainsString('1 needs action', $html);
        self::assertStringContainsString('generated, no reviewer assigned yet', $html);
        self::assertStringContainsString('Already generated:', $html);
        self::assertStringContainsString('before generating a new one', $html);
    }
}
