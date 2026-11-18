<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\SupplierRepository;
use App\Services\PricingService;
use App\Services\SupplierCha;
use App\Services\SupplierOffers;
use App\Services\ThicknessPrices;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-381 (18 Nov 2026): CHA + transport per container per supplier — the Edit Supplier
 * box (amount, port, reason when either changes, audit SUPPLIER_CHA_CHANGED old → new), the chain
 * row → supplier → product default with its source, and an older 'computed' price that never gets
 * the per-container CHA. D-382: a block price typed per tonne on Edit price is stored per CBM
 * (× the weight per CBM) with what was typed kept in entered_unit / entered_price.
 * Node twin: tests/integration/chaPerSupplierAndTonneD381D382.test.js.
 */
final class ChaPerSupplierAndTonneD381D382Test extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
    }

    private function supplier(): array
    {
        $id = SupplierRepository::create(['supplier_legal_name' => 'D381 Quarry ' . bin2hex(random_bytes(3)), 'types' => []], $this->admin);
        return SupplierRepository::find($id);
    }

    /** A block product sold per CBM (old forms "block", 2.9 t per CBM) with one price per CBM from this supplier. */
    private function offer(int $supplierId, ?string $ownCha, string $fobSource = 'direct'): array
    {
        $pid = ProductRepository::create(['name' => 'D381 CHM Black block ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite block', 'hs_code' => '25161100', 'reference_unit' => 'CBM'], $this->admin);
        Database::connection()->prepare("UPDATE catalog_products SET forms = 'block', weight_t_per_cbm = 2.9, cha_transport_per_container = 40000 WHERE id = :id")->execute(['id' => $pid]);
        $oid = ProductSupplierRepository::create(['product_id' => $pid, 'supplier_name' => 'D381 Quarry', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => $fobSource, 'fob_value' => $fobSource === 'direct' ? 80000 : null, 'factory_cost' => $fobSource === 'computed' ? 85000 : null, 'transportation_cost' => null,
            'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => 1, 'notes' => null], $this->admin);
        ProductSupplierRepository::setPriceDetails($oid, $supplierId, true, $ownCha !== null ? (float) $ownCha : null);
        return [ProductRepository::find($pid), ProductSupplierRepository::find($oid)];
    }

    public function testTheEditSupplierBoxNeedsAReasonAndIsAudited(): void
    {
        $s = $this->supplier();
        self::assertSame([null, null], SupplierCha::read(['cha_transport_per_container' => '', 'cha_port' => ''], $s), 'nothing changed: no reason needed');
        [$change, $error] = SupplierCha::read(['cha_transport_per_container' => '45000', 'cha_port' => 'Mundra', 'cha_reason' => ''], $s);
        self::assertNull($change);
        self::assertSame('CHA + transport — reason for the change: A reason is required — nothing was saved.', $error);
        [, $error] = SupplierCha::read(['cha_transport_per_container' => '45,000.555', 'cha_port' => '', 'cha_reason' => 'New transporter rate'], $s);
        self::assertSame('CHA + transport per container: enter ₹ (at most 2 decimals), or leave it empty.', $error);
        [, $error] = SupplierCha::read(['cha_transport_per_container' => '', 'cha_port' => 'Mundra', 'cha_reason' => 'New transporter rate'], $s);
        self::assertSame('Port this figure is for: enter the CHA + transport figure too, or leave both empty.', $error);

        [$change, $error] = SupplierCha::read(['cha_transport_per_container' => '45,000', 'cha_port' => ' Mundra ', 'cha_reason' => 'New transporter rate to Mundra'], $s);
        self::assertNull($error);
        SupplierCha::save((int) $s['id'], $s, $change, $this->admin);
        $fresh = SupplierRepository::find((int) $s['id']);
        self::assertSame(['45000.00', 'Mundra'], [(string) $fresh['cha_transport_per_container'], (string) $fresh['cha_port']]);
        $st = Database::connection()->prepare("SELECT old_value, new_value, reason FROM audit_log WHERE action_type = 'SUPPLIER_CHA_CHANGED' AND entity_id = :id ORDER BY id DESC LIMIT 1");
        $st->execute(['id' => (int) $s['id']]);
        self::assertSame(['old_value' => 'none', 'new_value' => '₹45,000.00 (Mundra)', 'reason' => 'New transporter rate to Mundra'], $st->fetch(\PDO::FETCH_ASSOC));
        self::assertSame([null, null], SupplierCha::read(['cha_transport_per_container' => '45000.00', 'cha_port' => 'Mundra'], $fresh), 'the same figure again: nothing to save');
    }

    public function testTheChainRowSupplierProductAndTheComputedRule(): void
    {
        $s = $this->supplier();
        Database::connection()->prepare("UPDATE suppliers SET cha_transport_per_container = 45000, cha_port = 'Mundra' WHERE id = :id")->execute(['id' => (int) $s['id']]);
        $settings = PricingService::settings();

        [$product, $row] = $this->offer((int) $s['id'], null);
        $parts = PricingService::fobParts($product, $row, 'cbm', null, $settings);
        self::assertSame(['supplier', 'supplier · Mundra'], [$parts['cha_source'], $parts['cha_source_tag']]);
        $rules = ThicknessPrices::rules($product, $settings);
        $expected = ThicknessPrices::chaPerCbm(45000.0, (float) $rules['container_cbm'], $rules['container_max_t'], $rules['t_per_cbm'])['per_cbm'];
        self::assertEqualsWithDelta($expected, $parts['cha'], 0.001);
        self::assertSame('Empty = supplier\'s ₹45,000.00 (Mundra)', ThicknessPrices::chaFallbackText($row, $product));
        // The same row read without the join still finds the supplier's figure.
        $bare = $row;
        unset($bare['supplier_cha_per_container'], $bare['supplier_cha_port']);
        self::assertSame(45000.0, ThicknessPrices::chaPerContainer($bare, $product));

        [$product, $row] = $this->offer((int) $s['id'], '60000');
        self::assertSame('this price', PricingService::fobParts($product, $row, 'cbm', null, $settings)['cha_source_tag']);

        $plain = SupplierRepository::create(['supplier_legal_name' => 'D381 Plain ' . bin2hex(random_bytes(3)), 'types' => []], $this->admin);
        [$product, $row] = $this->offer($plain, null);
        self::assertSame('product default', PricingService::fobParts($product, $row, 'cbm', null, $settings)['cha_source_tag']);

        [$product, $row] = $this->offer((int) $s['id'], '60000', 'computed');
        $parts = PricingService::fobParts($product, $row, 'cbm', null, $settings);
        self::assertEqualsWithDelta(0.0, $parts['cha'], 1e-9);
        self::assertSame('CHA already inside this older price', $parts['note']);
        self::assertEqualsWithDelta(85000.0, $parts['total'], 0.001);
    }

    public function testAPricePerTonneIsSavedPerCbmWithWhatWasTyped(): void
    {
        $s = $this->supplier();
        [$product, $row] = $this->offer((int) $s['id'], null);
        self::assertTrue(SupplierOffers::tonnePriced($product, false, (string) $row['price_basis']));
        self::assertContains('Tonne', array_column(SupplierOffers::priceShape($product)['units'], 'label'));
        [$summary, $error] = SupplierOffers::save($product, $row, ['price' => 30000.0, 'unit' => 'TON', 'from_mm' => null, 'own' => [], 'quoted_on' => date('Y-m-d'),
            'valid_until' => '', 'cha' => null, 'includes_packing_loading' => true, 'notes' => ''], $this->admin);
        self::assertNull($error);
        self::assertStringContainsString('(₹30,000.00 per tonne = ₹87,000.00 per CBM at 2.9 t/CBM)', (string) $summary);
        $fresh = ProductSupplierRepository::find((int) $row['id']);
        self::assertSame(['87000.00', 'TON', '30000.00'], [(string) $fresh['fob_value'], (string) $fresh['entered_unit'], (string) $fresh['entered_price']]);
        self::assertSame('₹30,000.00 per tonne = ₹87,000.00 per CBM at 2.9 t/CBM', SupplierOffers::enteredText($fresh, $product));

        // Typed per CBM again: the typed tonne price is cleared.
        [, $error] = SupplierOffers::save($product, $fresh, ['price' => 86000.0, 'unit' => 'CBM', 'from_mm' => null, 'own' => [], 'quoted_on' => date('Y-m-d'),
            'valid_until' => '', 'cha' => null, 'includes_packing_loading' => true, 'notes' => ''], $this->admin);
        self::assertNull($error);
        $fresh = ProductSupplierRepository::find((int) $row['id']);
        self::assertSame(['86000.00', null, null], [(string) $fresh['fob_value'], $fresh['entered_unit'], $fresh['entered_price']]);
        // (No weight per CBM: refused with the LineUnits weight message — TonnePriceD382Test.)
    }
}
