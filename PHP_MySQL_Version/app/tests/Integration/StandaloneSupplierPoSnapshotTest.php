<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\OrderDuplicationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "standalone order" follow-on: order_supplier_po.
 * supplier_id was the same live-FK pattern the orders table itself had —
 * OrderSupplierPoRepository::findLatestForOrder() joined `suppliers` on
 * every read, so a change to a supplier's own name/address/GSTIN/PAN/
 * contact details would retroactively rewrite what an already-issued,
 * signed, or closed Supplier PO displays and prints. Suppliers have no
 * dedicated edit screen in the app today, but the same architectural risk
 * applies the moment one is added (or to a direct DB edit) — so this
 * closes it the same way the orders table was: snapshot the supplier's
 * details once, at Supplier PO creation, onto the row itself.
 */
final class StandaloneSupplierPoSnapshotTest extends DbTestCase
{
    private function fullSupplierPoData(array $overrides = []): array
    {
        return array_merge([
            'material_stone_type' => null, 'grade' => null, 'surface_finish' => null,
            'dimensions' => null, 'dimensional_tolerance' => null, 'quantity' => null,
            'unit' => null, 'colour_reference' => null, 'special_requirements' => null,
            'unit_price_inr' => null, 'basic_value_inr' => null, 'gst_rate_pct' => null,
            'gst_amount_inr' => null, 'total_payable_inr' => null, 'advance_pct' => null,
            'advance_amount_inr' => null, 'balance_amount_inr' => null,
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], $overrides);
    }

    public function testSupplierPoSnapshotsSupplierDetailsAtCreation(): void
    {
        $supplierId = SupplierRepository::create([
            'supplier_legal_name' => 'Original Stone Works',
            'address' => 'Plot 1, Udaipur',
            'gstin' => 'GSTIN-ORIG',
            'pan' => 'PAN-ORIG',
            'contact_person' => 'Original Contact',
            'phone' => '9000000000',
            'email' => 'orig@example.com',
            'whatsapp_number' => '9111111111',
            'city' => 'Udaipur',
            'state' => 'Rajasthan',
            'contact_person_phone' => '9222222222',
            'contact_person_whatsapp' => '9333333333',
            'types' => ['Quarry Owner', 'Factory Owner'],
        ]);
        $orderId = $this->createTestOrder($this->createTestClient());
        // The supplier holds two types; the order records the one chosen for it.
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-SNAP-1', $this->fullSupplierPoData(['supplier_type' => 'Factory Owner']));

        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);

        self::assertSame('Original Stone Works', $supplierPo['supplier_legal_name']);
        self::assertSame('Plot 1, Udaipur', $supplierPo['supplier_address']);
        self::assertSame('GSTIN-ORIG', $supplierPo['supplier_gstin']);
        self::assertSame('PAN-ORIG', $supplierPo['supplier_pan']);
        self::assertSame('Original Contact', $supplierPo['supplier_contact_person']);
        self::assertSame('9000000000', $supplierPo['supplier_phone']);
        self::assertSame('orig@example.com', $supplierPo['supplier_email']);
        self::assertSame('9111111111', $supplierPo['supplier_whatsapp_number']);
        self::assertSame('Udaipur', $supplierPo['supplier_city']);
        self::assertSame('Rajasthan', $supplierPo['supplier_state']);
        self::assertSame('9222222222', $supplierPo['supplier_contact_person_phone']);
        self::assertSame('9333333333', $supplierPo['supplier_contact_person_whatsapp']);
        self::assertSame('Factory Owner', $supplierPo['supplier_type']);
    }

    public function testEditingTheSuppliersTableDoesNotChangeAnExistingSupplierPo(): void
    {
        $supplierId = SupplierRepository::create([
            'supplier_legal_name' => 'Stable Stone Co',
            'address' => 'Original Address',
        ]);
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-SNAP-2', $this->fullSupplierPoData());

        Database::connection()
            ->prepare('UPDATE suppliers SET supplier_legal_name = :name, address = :address WHERE id = :id')
            ->execute(['name' => 'Renamed Stone Co', 'address' => 'Renamed Address', 'id' => $supplierId]);

        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        self::assertSame('Stable Stone Co', $supplierPo['supplier_legal_name'], 'an existing Supplier PO must keep its own snapshot even after the supplier is renamed');
        self::assertSame('Original Address', $supplierPo['supplier_address']);
    }

    public function testDuplicatingAnOrderReResolvesTodaysCurrentSupplierDetails(): void
    {
        $supplierId = SupplierRepository::create([
            'supplier_legal_name' => 'Pre-Change Supplier Name',
        ]);
        $clientId = $this->createTestClient();
        $sourceOrderId = $this->createTestOrder($clientId);
        OrderSupplierPoRepository::create($sourceOrderId, $supplierId, 'SUPPO-SNAP-3', $this->fullSupplierPoData());

        // "Later" the supplier's own name changes.
        Database::connection()
            ->prepare('UPDATE suppliers SET supplier_legal_name = :name WHERE id = :id')
            ->execute(['name' => 'Post-Change Supplier Name', 'id' => $supplierId]);

        $duplicateOrderId = OrderDuplicationService::duplicate($sourceOrderId, $this->createTestUser('Admin'));

        $source = OrderSupplierPoRepository::findLatestForOrder($sourceOrderId);
        $duplicate = OrderSupplierPoRepository::findLatestForOrder($duplicateOrderId);

        self::assertSame('Pre-Change Supplier Name', $source['supplier_legal_name'], 'the original Supplier PO must still reflect what was true when IT was created');
        self::assertSame('Post-Change Supplier Name', $duplicate['supplier_legal_name'], 'a duplicate re-resolves the supplier\'s CURRENT details, not a freeze-copy of the source\'s old snapshot');
    }
}
