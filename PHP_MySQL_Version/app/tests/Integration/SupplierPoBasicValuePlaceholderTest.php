<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "basic_value_inr placeholder": every other field on the
 * Supplier PO form shows an example value while empty (Quantity, Unit
 * Price, Advance %), but Basic Value — an auto-calculated field, same as
 * GST Amount/Total Payable/Advance & Balance Amount — was the one such
 * field the business specifically wants a placeholder on, for a staff
 * member who overrides it by hand before Quantity/Unit Price are both
 * filled in (the JS only fires once both are present).
 */
final class SupplierPoBasicValuePlaceholderTest extends DbTestCase
{
    public function testBasicValueFieldHasAPlaceholder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 4; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }

        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = (string) ob_get_clean();

        self::assertStringContainsString(
            'name="basic_value_inr" data-spo-calc="basic_value_inr" placeholder="e.g., 225000.00"',
            $output,
            'Basic Value must show an example placeholder, same as every other typed field on this form'
        );
    }
}
