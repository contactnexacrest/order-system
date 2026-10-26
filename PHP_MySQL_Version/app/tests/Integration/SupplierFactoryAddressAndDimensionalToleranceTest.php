<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\SupplierFactoryAddressRepository;
use App\Repositories\SupplierRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * User-reported batch item 12: "Dimensional Tolerance = must be text not
 * place holder - dropdown + Delivery Location = factory address
 * (supplier details must have factory address add option - one owner
 * may have multiple factory addresses - dropdown)".
 */
final class SupplierFactoryAddressAndDimensionalToleranceTest extends DbTestCase
{
    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function render(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testDimensionalToleranceIsADropdownWithARealDefaultNotJustAPlaceholder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 4);

        $output = $this->render($orderId);

        $pos = strpos($output, 'name="dimensional_tolerance"');
        self::assertNotFalse($pos);
        $fragment = substr($output, $pos, 600);
        self::assertStringContainsString('<select', substr($output, $pos - 20, 40));
        self::assertStringContainsString('±1 mm', $fragment);
        self::assertMatchesRegularExpression('/value="±1 mm"\s+selected/u', $fragment);
    }

    public function testFactoryAddressCanBeAddedAndAppearsOnTheSuppliersOwnDropdownOption(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 4);
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Udaipur Granite Works']);

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['address' => 'Plot 12, Industrial Area, Udaipur, Rajasthan', 'label' => 'Udaipur Factory'];
        ob_start();
        (new \App\Controllers\SupplierController())->addFactoryAddress(['id' => (string) $supplierId]);
        ob_end_clean();

        $addresses = SupplierFactoryAddressRepository::forSupplier($supplierId);
        self::assertCount(1, $addresses);
        self::assertSame('Plot 12, Industrial Area, Udaipur, Rajasthan', $addresses[0]['address']);
        self::assertSame('Udaipur Factory', $addresses[0]['label']);

        $output = $this->render($orderId);
        $optionPos = strpos($output, "value=\"{$supplierId}\" data-addresses=");
        self::assertNotFalse($optionPos, 'the supplier <option> must carry its own data-addresses attribute');
        $optionTag = substr($output, $optionPos, 400);
        self::assertStringContainsString('Plot 12, Industrial Area, Udaipur, Rajasthan', $optionTag);

        // Owner request (2026-10-27): addresses are managed only in the
        // Suppliers directory — the order page no longer edits them.
        self::assertStringNotContainsString('Manage factory addresses', $output);
        self::assertStringNotContainsString('/factory-addresses', $output);
    }

    public function testFactoryAddressCanBeRemoved(): void
    {
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Removable Supplier']);
        $addressId = SupplierFactoryAddressRepository::create($supplierId, '1 Test Road', null);
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new \App\Controllers\SupplierController())->deleteFactoryAddress(['id' => (string) $supplierId, 'addressId' => (string) $addressId]);
        ob_end_clean();

        self::assertNull(SupplierFactoryAddressRepository::find($addressId));
    }
}
