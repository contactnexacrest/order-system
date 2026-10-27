<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner report (2026-10-27): confirming the supplier signed (7d) passes
 * Stage 5's gate, which ticked all of Step 7, ticked Step 8 with nothing
 * entered, and opened Group C. Step 7 now waits for the supplier advance
 * (7e), Step 8 for a real production / shipment entry, Group C for Group B.
 */
final class SupplierAdvanceBeforeProductionTest extends DbTestCase
{
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        $this->orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Advance Quarry Co']);
        OrderSupplierPoRepository::create($this->orderId, $supplierId, 'ADV-SPO-' . $this->orderId, [
            'material_stone_type' => null, 'grade' => null, 'surface_finish' => null,
            'dimensions' => null, 'dimensional_tolerance' => null, 'quantity' => '100',
            'unit' => 'sqm', 'colour_reference' => null, 'special_requirements' => null,
            'unit_price_inr' => '450', 'basic_value_inr' => '45000', 'gst_rate_pct' => null,
            'gst_amount_inr' => null, 'total_payable_inr' => '45000', 'advance_pct' => '30',
            'advance_amount_inr' => '13500', 'balance_amount_inr' => '31500',
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], 'signed');
        // As right after 7d: stages 1-5 passed, Stage 6 (freight) opened.
        $this->setStages([1, 2, 3, 4, 5], 'gate_passed');
        $this->setStages([6], 'in_progress');
    }

    /** @param array<int,int> $numbers */
    private function setStages(array $numbers, string $status): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE order_stages SET status = :status WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)'
        );
        foreach ($numbers as $n) {
            $stmt->execute(['status' => $status, 'id' => $this->orderId, 'n' => $n]);
        }
    }

    private function page(): string
    {
        ob_start();
        (new OrderController())->show(['id' => (string) $this->orderId]);
        return (string) ob_get_clean();
    }

    private function icon(string $page, string $id): string
    {
        self::assertSame(1, preg_match('#id="' . preg_quote($id, '#') . '"[^>]*>\s*<summary>(?:<span class="step-card-icon (\w+)")#', $page, $m), "{$id} is on the page");
        return $m[1];
    }

    private function opens(string $page, string $id): bool
    {
        return (bool) preg_match('#<details[^>]*id="' . preg_quote($id, '#') . '"[^>]*\sopen[\s>]#', $page);
    }

    public function testAfter7dTheSupplierAdvanceIsNextAndNothingLaterIsTicked(): void
    {
        $page = $this->page();
        self::assertSame('current', $this->icon($page, 'step-group-stage-5'), 'Step 7 is not done while the supplier advance is unpaid');
        self::assertSame('current', $this->icon($page, 'step-7e-supplier-advance-paid'));
        self::assertTrue($this->opens($page, 'step-7e-supplier-advance-paid'), '7e opens');
        self::assertSame('locked', $this->icon($page, 'step-group-stage-production'), 'Step 8 waits for Step 7');
        self::assertTrue($this->opens($page, 'phase-production'));
        self::assertFalse($this->opens($page, 'phase-shipment'), 'Group C stays closed');
        self::assertStringContainsString('0 of 2 steps done', $page);
    }

    public function testOnceTheAdvanceIsPaidStep8IsNextUntilSomethingIsEntered(): void
    {
        Database::connection()->prepare('UPDATE order_supplier_po SET advance_paid_at = CURDATE() WHERE order_id = :id')->execute(['id' => $this->orderId]);
        $page = $this->page();
        self::assertSame('done', $this->icon($page, 'step-group-stage-5'));
        self::assertSame('current', $this->icon($page, 'step-group-stage-production'), "'Not yet commenced' is the default, not an entry");
        self::assertFalse($this->opens($page, 'phase-shipment'));

        Database::connection()->prepare("UPDATE orders SET production_status_text = 'Cutting in progress' WHERE id = :id")->execute(['id' => $this->orderId]);
        $page = $this->page();
        self::assertSame('done', $this->icon($page, 'step-group-stage-production'));
        self::assertTrue($this->opens($page, 'phase-shipment'), 'Group C opens once Group B is finished');
        self::assertStringContainsString('2 of 2 steps done', $page);
    }
}
