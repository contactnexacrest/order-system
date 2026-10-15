<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientPortalController;
use App\Controllers\OrderController;
use App\Controllers\ReorderRequestController;
use App\Repositories\LookupRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #43/#66: the product-line/Supplier-PO "Unit" field and
 * the order-level "Package Type" field were both plain free-text inputs
 * ("SQM/PCS" and "e.g. Wooden Crates" were only ever placeholder hints),
 * unlike every other admin-managed field on these same screens (COO Type,
 * Container Type, Supplier Type), which already render from
 * dropdown_options. Fixed by giving both the same treatment — same
 * pattern as ShippingContainerTypeDropdownTest (90-item backlog #36).
 */
final class UnitAndPackageTypeDropdownTest extends DbTestCase
{
    public function testNewOrderFormRendersUnitAndPackageTypeDropdowns(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->create([]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="product_unit[]"', $output);
        self::assertStringNotContainsString('<input type="text" name="product_unit[]"', $output, 'the bug: Unit must be a <select>, not a free-text input');
        self::assertStringContainsString('name="estimated_package_type"', $output);
        self::assertStringNotContainsString('<input type="text" name="estimated_package_type"', $output, 'the bug: Package Type must be a <select>, not a free-text input');

        $this->assertEveryOptionPresent('unit', $output);
        $this->assertEveryOptionPresent('package_type', $output);
    }

    public function testOrderDetailPageRendersUnitDropdownForProductLines(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="unit"', $output);
        self::assertStringNotContainsString('<input type="text" name="unit"', $output, 'the bug: Unit must be a <select>, not a free-text input, on both the product-line and Supplier PO forms');
        $this->assertEveryOptionPresent('unit', $output);
    }

    public function testEditOrderDetailsRendersPackageTypeDropdown(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->editDetails(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="estimated_package_type"', $output);
        self::assertStringNotContainsString('<input type="text" name="estimated_package_type"', $output, 'the bug: Package Type must be a <select>, not a free-text input');
        $this->assertEveryOptionPresent('package_type', $output);
    }

    public function testReorderRequestReviewScreenRendersUnitDropdown(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $requestId = \App\Repositories\OrderReorderRequestRepository::create($clientId, $orderId, null);
        \App\Repositories\OrderReorderRequestRepository::addProductLine($requestId, 1, 'Test Product', null, null, '10', false, 'PCS', null, null);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new ReorderRequestController())->show(['id' => (string) $requestId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="unit[0]"', $output);
        self::assertStringNotContainsString('<input type="text" name="unit[0]"', $output, 'the bug: Unit must be a <select>, not a free-text input');
        $this->assertEveryOptionPresent('unit', $output);
    }

    public function testClientPortalReorderFormRendersUnitDropdown(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showReorderForm(['id' => (string) $orderId]);
        $output = ob_get_clean();

        unset($_SESSION['_client_portal_client_id']);

        self::assertStringContainsString('name="product_unit[0]"', $output);
        self::assertStringNotContainsString('<input type="text" name="product_unit[0]"', $output, 'the bug: Unit must be a <select>, not a free-text input');
        $this->assertEveryOptionPresent('unit', $output);
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
