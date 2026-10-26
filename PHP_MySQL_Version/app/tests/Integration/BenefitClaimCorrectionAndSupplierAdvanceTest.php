<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner requests (2026-10-27): a wrongly typed government-benefit claim
 * can be corrected or deleted (reason required, audit-logged); and the
 * supplier balance (10a) waits for the supplier advance (7e) whenever the
 * Supplier PO terms plan one.
 */
final class BenefitClaimCorrectionAndSupplierAdvanceTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        Flash::pull();
    }

    private function lastFlash(): array
    {
        $flash = Flash::pull();
        return end($flash) ?: ['type' => '', 'message' => ''];
    }

    private function lastAudit(): array
    {
        return Database::connection()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
    }

    public function testAWrongClaimCanBeCorrectedWithAReason(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $id = CaExportBenefitRepository::record($orderId, 'RODTEP', '443432432', 50000, '2026-10-27', 'INR', null, $this->userId);
        CaExportBenefitRepository::markReceived($id, 5000, '2026-10-27');
        $params = ['id' => (string) $orderId, 'benefitId' => (string) $id];

        $_POST = ['claimed_amount' => '5000', 'claimed_at' => '2026-10-27', 'received_amount' => '5000', 'received_at' => '2026-10-27', 'reason' => ''];
        (new OrderFinancialsController())->correctExportBenefit($params);
        self::assertSame('error', $this->lastFlash()['type']);
        self::assertEqualsWithDelta(50000, (float) CaExportBenefitRepository::find($id)['claimed_amount'], 0.001);

        $_POST['reason'] = 'Typed 50,000 instead of 5,000';
        (new OrderFinancialsController())->correctExportBenefit($params);
        self::assertSame('success', $this->lastFlash()['type']);
        $row = CaExportBenefitRepository::find($id);
        self::assertEqualsWithDelta(5000, (float) $row['claimed_amount'], 0.001);
        self::assertEqualsWithDelta(5000, (float) $row['received_amount'], 0.001);
        $audit = $this->lastAudit();
        self::assertSame('CA_EXPORT_BENEFIT_CORRECTED', $audit['action_type']);
        self::assertStringContainsString('claimed 50000.00', $audit['old_value']);
        self::assertStringContainsString('claimed 5000.00', $audit['new_value']);

        $_POST = ['claimed_amount' => '5000', 'claimed_at' => '2026-10-27', 'received_amount' => '', 'received_at' => '', 'reason' => 'Money has not actually arrived yet'];
        (new OrderFinancialsController())->correctExportBenefit($params);
        self::assertNull(CaExportBenefitRepository::find($id)['received_amount'], 'emptying the received amount clears the receipt');
    }

    public function testADuplicateClaimCanBeDeletedWithAReason(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $id = CaExportBenefitRepository::record($orderId, 'RODTEP', 'ewrwrew', 50000, '2026-10-27', 'INR', null, $this->userId);
        $_POST = ['reason' => 'Recorded twice by mistake'];
        (new OrderFinancialsController())->deleteExportBenefit(['id' => (string) $orderId, 'benefitId' => (string) $id]);
        self::assertNull(CaExportBenefitRepository::find($id));
        self::assertSame('CA_EXPORT_BENEFIT_DELETED', $this->lastAudit()['action_type']);

        $otherOrder = $this->createTestOrder($this->createTestClient());
        $kept = CaExportBenefitRepository::record($otherOrder, 'RODTEP', null, 100, '2026-10-27', 'INR', null, $this->userId);
        ob_start();
        (new OrderFinancialsController())->deleteExportBenefit(['id' => (string) $orderId, 'benefitId' => (string) $kept]);
        ob_end_clean();
        self::assertNotNull(CaExportBenefitRepository::find($kept), 'a claim of another order is never touched');
    }

    public function testTheSupplierBalanceWaitsForThePlannedAdvance(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 5; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'heera granites']);
        $data = array_fill_keys(['material_stone_type', 'grade', 'surface_finish', 'dimensions', 'dimensional_tolerance', 'quantity', 'unit', 'colour_reference', 'special_requirements', 'unit_price_inr', 'basic_value_inr', 'gst_rate_pct', 'gst_amount_inr', 'total_payable_inr', 'advance_pct', 'balance_amount_inr', 'delivery_location', 'required_delivery_date', 'packing_requirement'], null);
        $supplierPoId = OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-ADV-1', $data + ['advance_amount_inr' => '20000'], 'issued');

        $_POST = ['paid_date' => date('Y-m-d'), 'amount_paid_inr' => '30000'];
        (new OrderController())->markSupplierBalancePaid(['id' => (string) $orderId]);
        self::assertStringContainsString('Record the supplier advance (Step 7e) first', $this->lastFlash()['message']);
        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($orderId)['balance_paid_at']);

        $_POST = ['paid_date' => date('Y-m-d'), 'amount_paid_inr' => '20000'];
        (new OrderController())->markSupplierAdvancePaid(['id' => (string) $orderId]);
        $_POST = ['paid_date' => date('Y-m-d'), 'amount_paid_inr' => '30000'];
        (new OrderController())->markSupplierBalancePaid(['id' => (string) $orderId]);
        self::assertNotNull(OrderSupplierPoRepository::findLatestForOrder($orderId)['balance_paid_at']);
        self::assertSame($supplierPoId, (int) OrderSupplierPoRepository::findLatestForOrder($orderId)['id']);
    }
}
