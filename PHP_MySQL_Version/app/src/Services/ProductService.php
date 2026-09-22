<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\ProductImageRepository;
use App\Repositories\ProductMiscChargeRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;

/**
 * Business logic for the Product Interface / internal product catalog.
 * The one rule that matters here is effectiveFobForSupplier() — see its
 * docblock — plus the "only one primary supplier per product" invariant,
 * which is centralized in setPrimarySupplier() so it can't be violated
 * from two different call sites.
 */
final class ProductService
{
    /**
     * Computes the effective FOB for one supplier row.
     *
     * - fob_source = 'direct'  -> the supplier's own typed fob_value
     *   verbatim (may be null if not yet entered — callers must render
     *   that as "TBC", never crash on it).
     * - fob_source = 'computed' -> factory + transportation + packing +
     *   loading + cha, where each component is the supplier's own
     *   override if set, else the parent product's default, else 0.
     *
     * This MUST stay a literal PHP `??` chain: a supplier value of literal
     * 0 is a real override and must NOT fall back to the product default
     * (only NULL falls back). Never persisted — this is a pure, stateless
     * calculation recomputed fresh every time it's needed (list view,
     * detail view); product_suppliers.fob_value stays NULL forever for
     * 'computed' rows.
     *
     * @param array<string,mixed> $supplier
     * @param array<string,mixed> $product
     */
    public function effectiveFobForSupplier(array $supplier, array $product): ?float
    {
        if (($supplier['fob_source'] ?? 'direct') === 'direct') {
            $value = $supplier['fob_value'] ?? null;
            return $value === null ? null : (float) $value;
        }

        $factory   = $supplier['factory_cost']        ?? $product['default_factory_cost']        ?? 0;
        $transport = $supplier['transportation_cost']  ?? $product['default_transportation_cost']  ?? 0;
        $packing   = $supplier['packing_cost']         ?? $product['default_packing_cost']         ?? 0;
        $loading   = $supplier['loading_cost']         ?? $product['default_loading_cost']         ?? 0;
        $cha       = $supplier['cha_cost']             ?? $product['default_cha_cost']             ?? 0;

        return (float) $factory + (float) $transport + (float) $packing + (float) $loading + (float) $cha;
    }

    /**
     * Product + its images + suppliers (each with an added 'effective_fob'
     * key computed fresh — not from the DB) + misc charges. Callers decide
     * whether to include pricing/suppliers/misc-charges in what's shown
     * (view_product_pricing gating happens in the controller, not here) —
     * this always assembles the full data so the controller can freely
     * unset what a role isn't allowed to see.
     *
     * @return array{product: ?array<string,mixed>, images: array, suppliers: array, misc_charges: array}
     */
    public function getProductWithDetails(int $id): array
    {
        $product = ProductRepository::find($id);
        if (!$product) {
            return ['product' => null, 'images' => [], 'suppliers' => [], 'misc_charges' => []];
        }

        $suppliers = ProductSupplierRepository::forProduct($id);
        foreach ($suppliers as &$supplier) {
            $supplier['effective_fob'] = $this->effectiveFobForSupplier($supplier, $product);
        }
        unset($supplier);

        return [
            'product'      => $product,
            'images'       => ProductImageRepository::forProduct($id),
            'suppliers'    => $suppliers,
            'misc_charges' => ProductMiscChargeRepository::forProduct($id),
        ];
    }

    /**
     * Sets a supplier as the product's primary (headline) FOB, clearing
     * every other supplier's is_primary flag for the same product first,
     * in one transaction — the only place this invariant is applied, so
     * it cannot be violated by calling clearPrimaryForProduct()/setPrimary()
     * separately from two different places.
     */
    public function setPrimarySupplier(int $productId, int $supplierId, int $userId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            ProductSupplierRepository::clearPrimaryForProduct($productId);
            ProductSupplierRepository::setPrimary($supplierId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
