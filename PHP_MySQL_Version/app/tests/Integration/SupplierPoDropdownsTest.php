<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\LookupRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #51a: the Supplier PO form's Surface Finish and GST
 * Rate fields were plain free-text inputs, unlike every other
 * admin-managed field on the same screen (Supplier Type, Unit), which
 * already render from dropdown_options. Also covers the Supplier Type
 * list additions: a new "Factory Owner" option, and renaming the
 * ambiguous "Processor" option to "Processor (Job-Work Basis)" so it no
 * longer overlaps with "Factory Owner". The auto-calculation wiring
 * (Unit Price x Quantity -> Basic Value -> GST Amount -> Total Payable
 * -> Advance/Balance) is pure client-side JS with no PHP-visible output
 * beyond the data-spo-calc hooks each field carries — asserted here as a
 * markup-presence regression guard, same approach as FormValueRetentionTest.
 */
final class SupplierPoDropdownsTest extends DbTestCase
{
    private function unlockThroughStage4(int $orderId): void
    {
        for ($stage = 1; $stage <= 4; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function renderOrderShow(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testSupplierPoFormRendersSurfaceFinishAndGstRateDropdowns(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage4($orderId);
        self::assertTrue(StageGateService::isUnlocked($orderId, 5));

        $output = $this->renderOrderShow($orderId);

        self::assertStringContainsString('name="surface_finish"', $output);
        self::assertStringNotContainsString('<input type="text" name="surface_finish"', $output, 'the bug: Surface Finish must be a <select>, not a free-text input');
        $this->assertEveryOptionPresent('surface_finish', $output);

        self::assertStringContainsString('name="gst_rate_pct"', $output);
        self::assertStringNotContainsString('<input type="text" name="gst_rate_pct"', $output, 'the bug: GST Rate must be a <select>, not a free-text input');
        foreach (LookupRepository::dropdownOptions('gst_rate') as $option) {
            self::assertStringContainsString(
                '>' . htmlspecialchars($option['option_value']) . '%<',
                $output,
                "GST Rate option {$option['option_value']}% must appear in the dropdown"
            );
        }
    }

    public function testSupplierPoFormRendersClarifiedSupplierTypeOptions(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage4($orderId);

        $output = $this->renderOrderShow($orderId);

        self::assertStringContainsString('>Factory Owner<', $output, 'new Factory Owner supplier type must be offered');
        self::assertStringContainsString('>Processor (Job-Work Basis)<', $output, 'Processor must be clarified, not left as the ambiguous bare label');
        self::assertStringNotContainsString('>Processor</option>', $output, 'the old ambiguous bare "Processor" label must no longer appear — it was renamed for clarity');
    }

    public function testSupplierPoFormWiresAutoCalculationHooks(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage4($orderId);

        $output = $this->renderOrderShow($orderId);

        foreach (['quantity', 'unit_price_inr', 'basic_value_inr', 'gst_rate_pct', 'gst_amount_inr', 'total_payable_inr', 'advance_pct', 'advance_amount_inr', 'balance_amount_inr'] as $field) {
            self::assertStringContainsString("data-spo-calc=\"{$field}\"", $output, "auto-calc hook missing for {$field}");
        }
        self::assertStringContainsString("getElementById('supplier-po-form')", $output, 'auto-calc script must target the Supplier PO form by id');
    }

    private function assertEveryOptionPresent(string $listKey, string $output): void
    {
        $options = LookupRepository::dropdownOptions($listKey);
        self::assertNotEmpty($options, "admin must have at least one '{$listKey}' option seeded to assert against");
        foreach ($options as $option) {
            self::assertStringContainsString(
                htmlspecialchars($option['option_value']),
                $output,
                "every admin-managed '{$listKey}' option must appear as a choice in the dropdown"
            );
        }
    }
}
