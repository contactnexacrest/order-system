<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\LookupRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #36: Container Type is an admin-managed dropdown list
 * (Settings > Dropdown Options) everywhere else it's captured — New Order
 * creation, Edit Order Details — but Stage 7's "Shipping / Vessel Details"
 * form on the order page rendered it as a free-text <input>, letting staff
 * type anything ("40ft", "40'HC", etc.) instead of picking from the same
 * admin-managed list. This left order_shipping.container_type able to
 * drift out of sync with the values the admin screen actually defines,
 * which is the mismatch the user reported. Fixed by rendering the same
 * dropdown (LookupRepository::dropdownOptions('container_type')) here too.
 */
final class ShippingContainerTypeDropdownTest extends DbTestCase
{
    public function testStage7ShippingFormRendersTheAdminManagedContainerTypeDropdown(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        // Stage 7's "Shipping / Vessel Details" form only renders once
        // Stage 7 itself is unlocked — walk the gates forward (FOB order,
        // so Stage 6/Freight Payment auto-skips, same as other tests that
        // need to reach Stage 7, e.g. PerLegExchangeRateTest).
        for ($stage = 1; $stage <= 5; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, $userId);
        }
        StageGateService::maybeAutoSkipFreightStage($orderId, $userId);

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="ship_container_type"', $output);
        self::assertStringNotContainsString(
            '<input type="text" name="ship_container_type"',
            $output,
            'the bug: this must be a <select> sourced from the admin dropdown list, not a free-text input'
        );

        $containerTypes = LookupRepository::dropdownOptions('container_type');
        self::assertNotEmpty($containerTypes, 'admin must have at least one container_type option seeded to assert against');
        foreach ($containerTypes as $option) {
            self::assertStringContainsString(
                htmlspecialchars($option['option_value']),
                $output,
                "every admin-managed container type option must appear as a choice in the shipping form's dropdown"
            );
        }
    }
}
