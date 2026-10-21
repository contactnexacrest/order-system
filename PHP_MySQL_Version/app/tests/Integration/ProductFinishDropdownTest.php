<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientPortalController;
use App\Controllers\OrderController;
use App\Repositories\LookupRepository;
use App\Repositories\OrderProductRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "product Finish dropdown": the order product line's
 * Finish field was plain free text everywhere it appears (New Order form,
 * the order page's Add/Edit Product Line forms, the client reorder
 * request form), unlike the Supplier PO's Surface Finish field (90-item
 * backlog #51a), which already got the dropdown_options treatment. Same
 * fix, reusing the same 'surface_finish' list — the two are the same
 * physical attribute viewed from the sale side vs. the procurement side
 * of the same order.
 */
final class ProductFinishDropdownTest extends DbTestCase
{
    private function renderOrderShow(int $orderId): string
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testOrderPageAddAndEditProductLineFormsRenderFinishAsADropdown(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        OrderProductRepository::add($orderId, 1, 'Granite Slab', null, 'Polished', '10', false, 'PCS', '100', '68022310');

        $output = $this->renderOrderShow($orderId);

        self::assertStringContainsString('name="finish"', $output);
        self::assertStringNotContainsString('<input type="text" name="finish"', $output, 'the bug: Finish must be a <select>, not a free-text input');
        $this->assertEveryOptionPresent('surface_finish', $output);
    }

    public function testNewOrderFormRendersProductFinishAsADropdown(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new OrderController())->create([]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="product_finish[]"', $output);
        self::assertStringNotContainsString('<input type="text" name="product_finish[]"', $output, 'the bug: Finish must be a <select>, not a free-text input');
        $this->assertEveryOptionPresent('surface_finish', $output);
    }

    public function testClientReorderFormRendersProductFinishAsADropdown(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        OrderProductRepository::add($orderId, 1, 'Granite Slab', null, 'Polished', '10', false, 'PCS', '100', '68022310');

        $_SESSION['_client_portal_client_id'] = $clientId;
        ob_start();
        (new ClientPortalController())->showReorderForm(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="product_finish[0]"', $output);
        self::assertStringNotContainsString('<input type="text" name="product_finish[0]"', $output, 'the bug: Finish must be a <select>, not a free-text input');
        $this->assertEveryOptionPresent('surface_finish', $output);
    }

    public function testSelectedFinishValueIsSavedWhenAddingAProductLine(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        $_POST = [
            'description' => 'Granite Monument',
            'hs_code' => '68022310',
            'finish' => 'Honed',
        ];
        (new OrderController())->addProduct(['id' => (string) $orderId]);

        $products = OrderProductRepository::forOrder($orderId);
        self::assertSame('Honed', $products[0]['finish']);
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
