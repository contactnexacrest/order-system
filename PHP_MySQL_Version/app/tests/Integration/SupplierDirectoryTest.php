<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\SupplierController;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierFactoryAddressRepository;
use App\Repositories\SupplierRepository;
use App\Services\PermissionService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Suppliers directory (docs/schema.sql Section BZ): CRUD with several
 * types per supplier, delete only for a never-used supplier, and the
 * order staying self-contained — order_supplier_po keeps its own copy of
 * the supplier's details (and the one type chosen for that order), so
 * editing or deleting in the directory never changes an existing order.
 */
final class SupplierDirectoryTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function uniqueName(string $prefix): string
    {
        return $prefix . ' ' . bin2hex(random_bytes(4));
    }

    private function lastFlash(): string
    {
        $flash = $_SESSION['_flash'] ?? [];
        $last = is_array($flash) ? end($flash) : null;
        return (string) ($last['message'] ?? '');
    }

    private function supplierIdByName(string $name): ?int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM suppliers WHERE supplier_legal_name = :n');
        $stmt->execute(['n' => $name]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
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

    private function orderAtStage5(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 4; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, $this->userId);
        }
        return $orderId;
    }

    public function testCreatesASupplierWithSeveralTypesAndContactDetails(): void
    {
        $name = $this->uniqueName('Directory Quarry');
        $_POST = [
            'supplier_legal_name' => $name,
            'types' => ['Quarry Owner', 'Factory Owner'],
            'city' => 'Udaipur',
            'email' => 'quarry@example.com',
            'whatsapp_number' => '+91 90000 00001',
            'contact_person_phone' => '+91 90000 00002',
        ];

        (new SupplierController())->create([]);

        $id = $this->supplierIdByName($name);
        self::assertNotNull($id);
        $supplier = SupplierRepository::find($id);
        self::assertSame(['Factory Owner', 'Quarry Owner'], $supplier['types']);
        self::assertSame('Udaipur', $supplier['city']);
        self::assertSame('quarry@example.com', $supplier['email']);
        self::assertSame($this->userId, (int) $supplier['created_by']);
        $audit = Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'SUPPLIER_CREATED' AND entity_id = {$id}")->fetchColumn();
        self::assertSame(1, (int) $audit);
    }

    public function testRequiresAtLeastOneValidType(): void
    {
        $name = $this->uniqueName('No Type Supplier');
        $_POST = ['supplier_legal_name' => $name, 'types' => []];
        (new SupplierController())->create([]);
        self::assertNull($this->supplierIdByName($name));
        self::assertStringContainsString('at least one supplier type', $this->lastFlash());

        $_POST = ['supplier_legal_name' => $name, 'types' => ['Made Up Type']];
        (new SupplierController())->create([]);
        self::assertNull($this->supplierIdByName($name));
        self::assertStringContainsString('not a valid supplier type', $this->lastFlash());
    }

    public function testListFiltersByTypeAndSearchesByName(): void
    {
        $trader = $this->uniqueName('Filter Trader');
        $quarry = $this->uniqueName('Filter Quarry');
        SupplierRepository::create(['supplier_legal_name' => $trader, 'types' => ['Trader']]);
        SupplierRepository::create(['supplier_legal_name' => $quarry, 'types' => ['Quarry Owner']]);

        $names = array_column(SupplierRepository::all(true, 'Trader'), 'supplier_legal_name');
        self::assertContains($trader, $names);
        self::assertNotContains($quarry, $names);

        $names = array_column(SupplierRepository::all(true, null, $quarry), 'supplier_legal_name');
        self::assertSame([$quarry], $names);
    }

    public function testEditingOrDeactivatingASupplierNeverChangesAnOrdersSupplierPo(): void
    {
        $id = SupplierRepository::create([
            'supplier_legal_name' => 'Snapshot Stone Co',
            'address' => 'Old Address', 'email' => 'old@example.com', 'phone' => '111',
            'types' => ['Quarry Owner', 'Trader'],
        ]);
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderSupplierPoRepository::create($orderId, $id, 'SUPPO-DIR-1', $this->poData(['supplier_type' => 'Trader']));

        $_POST = ['supplier_legal_name' => 'Renamed Stone Co', 'address' => 'New Address', 'email' => 'new@example.com', 'phone' => '222', 'types' => ['Factory Owner']];
        (new SupplierController())->update(['id' => (string) $id]);
        (new SupplierController())->toggleActive(['id' => (string) $id]);

        self::assertSame('Renamed Stone Co', SupplierRepository::find($id)['supplier_legal_name']);
        $po = OrderSupplierPoRepository::findLatestForOrder($orderId);
        self::assertSame('Snapshot Stone Co', $po['supplier_legal_name']);
        self::assertSame('Old Address', $po['supplier_address']);
        self::assertSame('old@example.com', $po['supplier_email']);
        self::assertSame('111', $po['supplier_phone']);
        self::assertSame('Trader', $po['supplier_type'], 'the type chosen for the order stays even after the supplier drops it');
    }

    public function testDeactivatedSuppliersDropOutOfTheSupplierPoList(): void
    {
        $id = SupplierRepository::create(['supplier_legal_name' => $this->uniqueName('Toggle Supplier'), 'types' => ['Trader']]);
        (new SupplierController())->toggleActive(['id' => (string) $id]);

        self::assertNotContains($id, array_map('intval', array_column(SupplierRepository::all(), 'id')));
        self::assertContains($id, array_map('intval', array_column(SupplierRepository::all(true), 'id')));
    }

    public function testDeletesAnUnusedSupplierWithItsFactoryAddressesButRefusesAUsedOne(): void
    {
        $unused = SupplierRepository::create(['supplier_legal_name' => $this->uniqueName('Unused Supplier'), 'types' => ['Trader']]);
        SupplierFactoryAddressRepository::create($unused, 'Plot 9, Test Area', 'Main');
        (new SupplierController())->delete(['id' => (string) $unused]);
        self::assertNull(SupplierRepository::find($unused));
        self::assertSame([], SupplierFactoryAddressRepository::forSupplier($unused));

        $used = SupplierRepository::create(['supplier_legal_name' => $this->uniqueName('Used Supplier'), 'types' => ['Trader']]);
        OrderSupplierPoRepository::create($this->createTestOrder($this->createTestClient()), $used, 'SUPPO-DIR-2', $this->poData());
        (new SupplierController())->delete(['id' => (string) $used]);
        self::assertNotNull(SupplierRepository::find($used));
        self::assertStringContainsString('deactivate it instead', $this->lastFlash());
    }

    public function testSupplierPoAsksWhichTypeAppliesWhenTheSupplierHasSeveral(): void
    {
        $orderId = $this->orderAtStage5();
        $id = SupplierRepository::create(['supplier_legal_name' => $this->uniqueName('Two Type Supplier'), 'types' => ['Quarry Owner', 'Factory Owner']]);

        $_POST = ['supplier_id' => (string) $id];
        (new OrderController())->saveSupplierPo(['id' => (string) $orderId]);
        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($orderId));
        self::assertStringContainsString('supplier types applies to this order', $this->lastFlash());

        $_POST = ['supplier_id' => (string) $id, 'supplier_type' => 'Factory Owner'];
        (new OrderController())->saveSupplierPo(['id' => (string) $orderId]);
        self::assertSame('Factory Owner', OrderSupplierPoRepository::findLatestForOrder($orderId)['supplier_type']);
    }

    /** Owner decision (2026-10-27): 0% advance is saved as 0 (not blank), so the whole amount is the balance. */
    public function testSupplierPoSavesAZeroPercentAdvance(): void
    {
        $orderId = $this->orderAtStage5();
        $id = SupplierRepository::create(['supplier_legal_name' => $this->uniqueName('No Advance Supplier'), 'types' => ['Trader']]);
        $_POST = ['supplier_id' => (string) $id, 'total_payable_inr' => '53100.00', 'advance_pct' => '0', 'advance_amount_inr' => '0.00', 'balance_amount_inr' => '53100.00'];
        (new OrderController())->saveSupplierPo(['id' => (string) $orderId]);

        $po = OrderSupplierPoRepository::findLatestForOrder($orderId);
        self::assertSame(0.0, (float) $po['advance_pct']);
        self::assertNotNull($po['advance_pct'], '0% is a real choice, not "not filled in"');
        self::assertSame(0.0, (float) $po['advance_amount_inr']);
        self::assertSame(53100.0, (float) $po['balance_amount_inr']);
    }

    public function testSupplierPoPicksASingleTypeAutomaticallyAndRefusesAnInactiveSupplier(): void
    {
        $orderId = $this->orderAtStage5();
        $id = SupplierRepository::create(['supplier_legal_name' => $this->uniqueName('One Type Supplier'), 'types' => ['Trader']]);
        $_POST = ['supplier_id' => (string) $id, 'supplier_type' => 'Quarry Owner'];
        (new OrderController())->saveSupplierPo(['id' => (string) $orderId]);
        self::assertSame('Trader', OrderSupplierPoRepository::findLatestForOrder($orderId)['supplier_type']);

        $otherOrder = $this->orderAtStage5();
        SupplierRepository::toggleActive($id);
        $_POST = ['supplier_id' => (string) $id];
        (new OrderController())->saveSupplierPo(['id' => (string) $otherOrder]);
        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($otherOrder));
        self::assertStringContainsString('active supplier', $this->lastFlash());
    }

    public function testManageSuppliersBelongsToAdminTierOnly(): void
    {
        foreach (['Admin' => true, 'Managing Director' => true, 'Executive Director' => true, 'Export Executive' => false, 'Logistics Executive' => false] as $role => $expected) {
            $roleId = (int) Database::connection()->query('SELECT id FROM roles WHERE name = ' . Database::connection()->quote($role))->fetchColumn();
            self::assertSame($expected, PermissionService::can($this->createTestUser($role), $roleId, 'manage_suppliers'), $role);
        }
    }

    public function testOrderPageOffersTheDirectoryInsteadOfAnInlineAddSupplierForm(): void
    {
        $orderId = $this->orderAtStage5();
        SupplierRepository::create(['supplier_legal_name' => $this->uniqueName('Page Supplier'), 'types' => ['Quarry Owner', 'Trader']]);

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString("action=\"/orders/{$orderId}/suppliers\"", $html, 'the inline Add Supplier form is gone');
        self::assertStringContainsString('href="/suppliers"', $html);
        self::assertStringContainsString('id="spo-supplier-type-select"', $html);
        self::assertStringContainsString('data-types="[&quot;Quarry Owner&quot;,&quot;Trader&quot;]"', $html);
    }
}
