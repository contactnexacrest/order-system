<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;

/**
 * Owner decision D-359 (13 Nov 2026, "go with recommendation"): suppliers' offers of a
 * product, the product's CONFIGURED base price, and the simple "Edit price" form.
 *
 *  - An OFFER is one catalog_product_suppliers row. It is USABLE when the offer is switched
 *    on (offer_active = 1) and its directory supplier is active (suppliers.is_active = 1; a
 *    price typed before the directory link counts as active). An unusable offer is never used
 *    for pricing, matching or the lowest / highest supplier price.
 *  - The CONFIGURED base price (catalog_products.configured_base_*) is what the selling price
 *    is worked out from. While configured_base_follows_supplier = 1 (every product priced
 *    before D-359, and new ones until the Configure screen, D-361, fixes a price) it follows
 *    the usable supplier price "used for pricing" — exactly as before, so no price changes.
 *    Once fixed (follows = 0) it never changes by itself; it is turned into a price row with
 *    the same maths the engine uses for a supplier price (ThicknessPrices / PricingService).
 *  - "Edit price": one form for one offer — the new price at one thickness, the other
 *    thicknesses worked out (ThicknessPrices::calcFrom / addPrice), CHA + transport, packing,
 *    notes, the selling-price preview (PricingService::fobParts / buildFromFob), the price
 *    history (ThicknessPrices::history) and Undo. No maths of its own.
 *
 * Functions for D-360 / D-361: offers(), usable(), offerBase(), lowestHighest(),
 * configuredBase(), setConfiguredBase(), followSupplier(), pricingRow(), setOfferActive();
 * the levels and the resets are in PricingLevels. Node twin: src/services/supplierOffers.js.
 */
final class SupplierOffers
{
    // ------------------------------------------------------------------ offers

    /** An offer is used only when it is switched on and its directory supplier is active. */
    public static function usable(array $row): bool
    {
        $offer = !array_key_exists('offer_active', $row) || $row['offer_active'] === null || (int) $row['offer_active'] === 1;
        $supplier = !array_key_exists('supplier_is_active', $row) || $row['supplier_is_active'] === null || (int) $row['supplier_is_active'] === 1;
        return $offer && $supplier;
    }

    /**
     * Every supplier offer of a product (with 'usable'); only the usable ones when asked.
     *
     * @return list<array<string,mixed>>
     */
    public static function offers(int $productId, bool $usableOnly = false): array
    {
        $out = [];
        foreach (ProductSupplierRepository::forProduct($productId) as $r) {
            $r['usable'] = self::usable($r);
            if (!$usableOnly || $r['usable']) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** The row marked "used for pricing" (a base price or a price for every thickness), usable or not. */
    public static function markedForPricing(int $productId): ?array
    {
        foreach (ProductSupplierRepository::forProduct($productId) as $s) {
            if (!empty($s['is_primary']) && in_array($s['price_basis'] ?? 'unit', ['unit', 'grid'], true)) {
                return $s;
            }
        }
        return null;
    }

    /** D-360: switches one offer on or off (with the price history and the audit log). */
    public static function setOfferActive(int $offerId, bool $active, int $userId): void
    {
        $row = ProductSupplierRepository::find($offerId);
        if ($row === null || (int) ($row['offer_active'] ?? 1) === ($active ? 1 : 0)) {
            return;
        }
        Database::connection()->prepare('UPDATE catalog_product_suppliers SET offer_active = :a WHERE id = :id')->execute(['a' => $active ? 1 : 0, 'id' => $offerId]);
        ThicknessPrices::history($offerId, 'Offer active', $active ? 'off' : 'on', $active ? 'on' : 'off', $userId);
        AuditLogRepository::log($userId, 'SUPPLIER_OFFER_' . ($active ? 'ON' : 'OFF'), 'product_suppliers', $offerId, 'offer_active', $active ? '0' : '1', $active ? '1' : '0');
    }

    /**
     * An offer's BASE price as quoted: a price for every thickness → the price at its base
     * thickness (per m², or per CBM for 50 mm and above); otherwise its price per unit (per CBM
     * for a thick-stone row). Null when it has none (or for an exact price for a combination).
     *
     * @return array{inr: float, mm: ?int, unit: string, label: string}|null
     */
    public static function offerBase(array $product, array $row, ?array $rules = null, ?array $settings = null): ?array
    {
        $basis = (string) ($row['price_basis'] ?? 'unit');
        if ($basis === 'combo') {
            return null;
        }
        if ($basis === 'grid') {
            $rules ??= ThicknessPrices::rules($product, $settings);
            $done = ThicknessPrices::values($rules, $row['_stored'] ?? ThicknessPrices::storedFor((int) $row['id']), ThicknessPrices::autoFill($row));
            $mm = ThicknessPrices::baseOf($row, $rules);
            $v = $done['values'][$mm] ?? null;
            if ($v === null || (float) $v['price'] <= 0) {
                return null;
            }
            return ['inr' => (float) $v['price'], 'mm' => $mm, 'unit' => $mm === ThicknessPrices::CBM ? 'CBM' : 'm2',
                'label' => ($mm === ThicknessPrices::CBM ? 'per CBM' : 'per m²') . ' (' . ThicknessPrices::label($mm, (int) $rules['cbm_from']) . ')'];
        }
        $v = (new ProductService())->effectiveFobForSupplier($row, $product);
        if ($v === null || $v <= 0) {
            return null;
        }
        $unit = $basis === 'cbm' ? 'CBM' : ((string) ($product['reference_unit'] ?? '') ?: 'unit');
        return ['inr' => (float) $v, 'mm' => null, 'unit' => $unit, 'label' => 'per ' . (LineUnits::label($unit) ?: $unit)];
    }

    /**
     * D-360 / D-361: the usable offers with their base price, and the lowest and highest of
     * those quoted the same way as the product's price (same unit and thickness).
     *
     * @return array{offers: list<array<string,mixed>>, lowest: ?array<string,mixed>, highest: ?array<string,mixed>, count: int}
     */
    public static function lowestHighest(array $product, ?array $settings = null): array
    {
        $settings ??= PricingService::settings();
        $rules = ThicknessPrices::rules($product, $settings);
        $rows = [];
        foreach (self::offers((int) $product['id'], true) as $r) {
            if (!in_array($r['price_basis'] ?? 'unit', ['unit', 'grid'], true)) {
                continue;
            }
            $r['base'] = self::offerBase($product, $r, $rules, $settings);
            if ($r['base'] !== null) {
                $rows[] = $r;
            }
        }
        $cfg = self::configuredBase($product, $settings);
        $key = $cfg['inr'] !== null ? $cfg['unit'] . '|' . ($cfg['mm'] ?? '') : ($rows !== [] ? $rows[0]['base']['unit'] . '|' . ($rows[0]['base']['mm'] ?? '') : null);
        $low = null;
        $high = null;
        foreach ($rows as $r) {
            if ($r['base']['unit'] . '|' . ($r['base']['mm'] ?? '') !== $key) {
                continue;
            }
            if ($low === null || $r['base']['inr'] < $low['base']['inr']) {
                $low = $r;
            }
            if ($high === null || $r['base']['inr'] > $high['base']['inr']) {
                $high = $r;
            }
        }
        return ['offers' => $rows, 'lowest' => $low, 'highest' => $high, 'count' => count($rows)];
    }

    // ------------------------------------------------------------------ the configured base price

    /**
     * The product's configured base price: while it follows the supplier, the usable price
     * "used for pricing" (live); otherwise the stored one.
     *
     * @return array{inr: ?float, mm: ?int, unit: ?string, follows: bool, source: string, offer: ?array<string,mixed>, stored_inr: ?float}
     */
    public static function configuredBase(array $product, ?array $settings = null): array
    {
        $follows = (int) ($product['configured_base_follows_supplier'] ?? 1) === 1;
        $stored = ($product['configured_base_price_inr'] ?? null) !== null ? (float) $product['configured_base_price_inr'] : null;
        $offer = PricingService::pricingSupplier((int) $product['id']);
        if ($follows || $stored === null) {
            $b = $offer !== null ? self::offerBase($product, $offer, null, $settings) : null;
            return ['inr' => $b['inr'] ?? null, 'mm' => $b['mm'] ?? null, 'unit' => $b['unit'] ?? null, 'follows' => true,
                'source' => $b !== null ? 'supplier' : 'none', 'offer' => $offer, 'stored_inr' => $stored];
        }
        $mm = ($product['configured_base_thickness_mm'] ?? null) !== null ? (int) $product['configured_base_thickness_mm'] : null;
        return ['inr' => $stored, 'mm' => $mm, 'unit' => (string) ($product['configured_base_unit'] ?? ''), 'follows' => false,
            'source' => 'configured', 'offer' => $offer, 'stored_inr' => $stored];
    }

    /** D-361: staff fix the base price themselves — it no longer follows the supplier price. */
    public static function setConfiguredBase(int $productId, float $inr, ?int $mm, string $unit, int $userId): void
    {
        $before = ProductRepository::find($productId);
        Database::connection()->prepare('UPDATE catalog_products SET configured_base_price_inr = :p, configured_base_thickness_mm = :mm, configured_base_unit = :u,
            configured_base_follows_supplier = 0, configured_at = NOW(), configured_by = :by, updated_by = :by2 WHERE id = :id')
            ->execute(['p' => number_format($inr, 2, '.', ''), 'mm' => $mm, 'u' => mb_substr($unit, 0, 20), 'by' => $userId, 'by2' => $userId, 'id' => $productId]);
        AuditLogRepository::log($userId, 'PRODUCT_BASE_PRICE_SET', 'catalog_products', $productId, 'configured_base_price_inr',
            $before !== null && $before['configured_base_price_inr'] !== null ? (string) $before['configured_base_price_inr'] : null, number_format($inr, 2, '.', ''));
    }

    /** D-361: the base price follows the supplier price "used for pricing" again. */
    public static function followSupplier(int $productId, int $userId): void
    {
        Database::connection()->prepare('UPDATE catalog_products SET configured_base_follows_supplier = 1, configured_at = NOW(), configured_by = :by WHERE id = :id')
            ->execute(['by' => $userId, 'id' => $productId]);
        $p = ProductRepository::find($productId);
        if ($p !== null) {
            self::refreshFollowedBase($p, $userId);
        }
        AuditLogRepository::log($userId, 'PRODUCT_BASE_PRICE_SET', 'catalog_products', $productId, 'configured_base_follows_supplier', '0', '1');
    }

    /** Keeps the stored copy of a FOLLOWING base price the same as the supplier price it follows. */
    public static function refreshFollowedBase(array $product, ?int $userId = null): void
    {
        if ((int) ($product['configured_base_follows_supplier'] ?? 1) !== 1) {
            return;
        }
        $offer = PricingService::pricingSupplier((int) $product['id']);
        $b = $offer !== null ? self::offerBase($product, $offer) : null;
        if ($b === null) {
            return;
        }
        Database::connection()->prepare('UPDATE catalog_products SET configured_base_price_inr = :p, configured_base_thickness_mm = :mm, configured_base_unit = :u,
            configured_at = NOW(), configured_by = COALESCE(:by, configured_by) WHERE id = :id')
            ->execute(['p' => number_format($b['inr'], 2, '.', ''), 'mm' => $b['mm'], 'u' => $b['unit'] === 'm2' || $b['unit'] === 'CBM' ? $b['unit'] : mb_substr($b['unit'], 0, 20), 'by' => $userId, 'id' => (int) $product['id']]);
    }

    /**
     * The price row the engine works from. While the base price follows the supplier (or none
     * is fixed) it is the usable offer "used for pricing", unchanged. A fixed base price
     * becomes a price row: a price for every thickness at its base thickness (the other
     * thicknesses worked out by the price rules) or a price per unit — with the CHA,
     * packing and dates of the offer used for pricing when there is one.
     */
    public static function pricingRow(array $product, ?array $offer, ?array $settings = null): ?array
    {
        $follows = (int) ($product['configured_base_follows_supplier'] ?? 1) === 1;
        $inr = ($product['configured_base_price_inr'] ?? null) !== null ? (float) $product['configured_base_price_inr'] : null;
        if ($follows || $inr === null || $inr <= 0) {
            return $offer;
        }
        $mm = ($product['configured_base_thickness_mm'] ?? null) !== null ? (int) $product['configured_base_thickness_mm'] : null;
        $rules = ThicknessPrices::rules($product, $settings);
        if ($offer !== null) {
            $b = self::offerBase($product, $offer, $rules, $settings);
            if ($b !== null && abs($b['inr'] - $inr) < 0.005 && $b['mm'] === $mm) {
                return $offer;
            }
        }
        $at = (string) ($product['configured_at'] ?? '');
        $row = [
            'id' => 0, 'product_id' => (int) $product['id'], 'supplier_name' => 'Configured base price', 'supplier_id' => null,
            'fob_source' => 'direct', 'fob_value' => null, 'is_primary' => 1, 'is_cbm_primary' => 0, 'combo_in_use' => 0,
            'offer_active' => 1, 'supplier_is_active' => null, 'configured' => true,
            'includes_packing_loading' => $offer['includes_packing_loading'] ?? 1,
            'cha_transport_per_container' => $offer['cha_transport_per_container'] ?? null,
            'quoted_on' => $offer['quoted_on'] ?? ($at !== '' ? substr($at, 0, 10) : null),
            'valid_until' => $offer['valid_until'] ?? null, 'updated_at' => $at !== '' ? $at : null,
        ];
        if ($mm !== null && $rules['grid']) {
            return $row + ['price_basis' => 'grid', 'base_thickness_mm' => $mm, 'auto_fill' => 1,
                '_stored' => [$mm => ['price' => $inr, 'how' => 'typed', 'calc_from_mm' => null, 'quoted_on' => null, 'since' => null]]];
        }
        return array_merge($row, ['price_basis' => 'unit', 'fob_value' => $inr, 'base_thickness_mm' => null]);
    }

    // ------------------------------------------------------------------ "Edit price"

    /** The thicknesses the form offers for a price for every thickness, in display order. @return list<int> */
    public static function formThicknesses(array $rules, array $row): array
    {
        $mms = ThicknessPrices::thicknesses($rules);
        $base = ThicknessPrices::baseOf($row, $rules);
        if (!in_array($base, $mms, true)) {
            $mms[] = $base;
        }
        return $mms;
    }

    /**
     * What the form shows for an entered price: every other thickness worked out (or typed
     * by the user), the old prices, and the selling price before / after (only when this
     * offer is the one the selling price comes from).
     *
     * @param array<int,float> $own thickness => price the user typed ("Type it yourself")
     * @return array<string,mixed>
     */
    public static function preview(array $product, array $row, ?int $fromMm, float $price, array $own = [], ?array $settings = null): array
    {
        $settings ??= PricingService::settings();
        $rules = ThicknessPrices::rules($product, $settings);
        $grid = ($row['price_basis'] ?? '') === 'grid';
        $cbmFrom = (int) $rules['cbm_from'];
        $out = ['ok' => true, 'error' => null, 'lines' => [], 'selling' => null, 'grid' => $grid];
        $new = $row;
        if ($grid) {
            $fromMm ??= ThicknessPrices::baseOf($row, $rules);
            $was = ThicknessPrices::values($rules, ThicknessPrices::storedFor((int) $row['id']), ThicknessPrices::autoFill($row));
            $calc = $price > 0 ? ThicknessPrices::calcFrom($rules, $fromMm, $price) : ['ok' => false, 'error' => 'Enter the price (above 0).', 'values' => []];
            if (!$calc['ok']) {
                $out['ok'] = false;
                $out['error'] = $calc['error'];
            }
            $stored = [];
            foreach (self::formThicknesses($rules, $row) as $mm) {
                $isFrom = $mm === $fromMm;
                $p = $isFrom ? $price : ($own[$mm] ?? ($calc['values'][$mm]['price'] ?? null));
                $how = $isFrom ? 'entered' : (isset($own[$mm]) ? 'typed' : ($p !== null ? 'calculated' : null));
                $out['lines'][] = ['mm' => $mm, 'label' => ThicknessPrices::label($mm, $cbmFrom), 'unit' => $mm === ThicknessPrices::CBM ? 'CBM' : 'm²',
                    'price' => $p, 'was' => isset($was['values'][$mm]) ? (float) $was['values'][$mm]['price'] : null, 'how' => $how, 'from' => $isFrom,
                    'rule' => $calc['values'][$mm]['change'] ?? ''];
                if ($p !== null && $p > 0) {
                    $stored[$mm] = ['price' => $p, 'how' => $isFrom || isset($own[$mm]) ? 'typed' : 'calculated', 'calc_from_mm' => $isFrom || isset($own[$mm]) ? null : $fromMm, 'quoted_on' => null, 'since' => null];
                }
            }
            $new['_stored'] = $stored;
            $new['auto_fill'] = 0;
        } else {
            $new['fob_source'] = 'direct';
            $new['fob_value'] = $price;
            $old = (new ProductService())->effectiveFobForSupplier($row, $product);
            $out['lines'][] = ['mm' => null, 'label' => 'Price', 'unit' => ($row['price_basis'] ?? '') === 'cbm' ? 'CBM' : ((string) ($product['reference_unit'] ?? '') ?: 'unit'),
                'price' => $price > 0 ? $price : null, 'was' => $old, 'how' => 'entered', 'from' => true, 'rule' => ''];
            if ($price <= 0) {
                $out['ok'] = false;
                $out['error'] = 'Enter the price (above 0).';
            }
        }
        // The selling price moves only when this offer is the one the price comes from.
        $pricing = PricingService::pricingSupplier((int) $product['id']);
        $cfg = self::configuredBase($product, $settings);
        $applies = $pricing !== null && (int) $pricing['id'] === (int) $row['id'] && $cfg['follows'] && in_array($row['price_basis'] ?? 'unit', ['unit', 'grid'], true);
        $out['selling'] = ['applies' => $applies, 'reason' => $applies ? null
            : (!$cfg['follows'] ? 'The selling price comes from the product\'s configured base price, not from this supplier\'s price.'
                : ($pricing === null ? 'No supplier price is used for pricing yet.' : 'The selling price comes from ' . $pricing['supplier_name'] . '\'s price (used for pricing).'))];
        if ($applies) {
            $oldParts = PricingService::fobParts($product, $row, 'unit', null, $settings, $rules);
            $newParts = $out['ok'] ? PricingService::fobParts($product, $new, 'unit', null, $settings, $rules) : null;
            $oldB = $oldParts !== null ? PricingService::buildFromFob($product, (float) $oldParts['total'], $settings) : null;
            $newB = $newParts !== null ? PricingService::buildFromFob($product, (float) $newParts['total'], $settings) : null;
            $out['selling'] += ['was_min' => $oldB['minimum'] ?? null, 'was_quote' => $oldB['staff_quote'] ?? null,
                'min' => $newB['minimum'] ?? null, 'quote' => $newB['staff_quote'] ?? null, 'unit' => $newParts['unit'] ?? ($oldParts['unit'] ?? '')];
        }
        return $out;
    }

    /** Everything a save changes on one offer, to put it back with Undo. */
    public static function snapshot(int $offerId): ?array
    {
        $row = ProductSupplierRepository::find($offerId);
        if ($row === null) {
            return null;
        }
        $st = Database::connection()->prepare('SELECT thickness_mm, price_inr, how, calc_from_mm, quoted_on, updated_by FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id ORDER BY thickness_mm');
        $st->execute(['id' => $offerId]);
        $product = ProductRepository::find((int) $row['product_id']);
        $keep = ['fob_source', 'fob_value', 'auto_fill', 'base_thickness_mm', 'quoted_on', 'valid_until', 'includes_packing_loading', 'cha_transport_per_container', 'notes'];
        return ['offer_id' => $offerId, 'product_id' => (int) $row['product_id'], 'row' => array_intersect_key($row, array_flip($keep)), 'thickness' => $st->fetchAll(\PDO::FETCH_ASSOC),
            'product' => $product !== null ? array_intersect_key($product, array_flip(['configured_base_price_inr', 'configured_base_thickness_mm', 'configured_base_unit', 'configured_at'])) : []];
    }

    /**
     * Saves the "Edit price" form for one offer. $in: price, from_mm (grid), own [mm => price],
     * quoted_on, valid_until, cha (₹ per container or null), includes_packing_loading, notes.
     * Returns [summary text for the green message, error].
     *
     * @param array<string,mixed> $in
     * @return array{0: ?string, 1: ?string}
     */
    public static function save(array $product, array $row, array $in, int $userId): array
    {
        $rules = ThicknessPrices::rules($product);
        $id = (int) $row['id'];
        $grid = ($row['price_basis'] ?? '') === 'grid';
        $cbmFrom = (int) $rules['cbm_from'];
        $before = self::offerBase($product, $row, $rules);
        // ThicknessPrices::addPrice() runs its own transaction for the thickness prices.
        if ($grid) {
            $from = (int) $in['from_mm'];
            $own = (array) $in['own'];
            $ticked = array_values(array_filter(self::formThicknesses($rules, $row), static fn (int $mm): bool => $mm !== $from && !isset($own[$mm])));
            $error = ThicknessPrices::addPrice($row, $rules, $from, (float) $in['price'], (string) $in['quoted_on'], $ticked, false, $userId);
            if ($error !== null) {
                return [null, $error];
            }
            foreach ($own as $mm => $p) {
                $fresh = ProductSupplierRepository::find($id) ?? $row;
                $error = ThicknessPrices::addPrice($fresh, $rules, (int) $mm, (float) $p, (string) $in['quoted_on'], [], false, $userId);
                if ($error !== null) {
                    return [null, ThicknessPrices::label((int) $mm, $cbmFrom) . ': ' . $error];
                }
            }
        } else {
            $old = (new ProductService())->effectiveFobForSupplier($row, $product);
            $upd = ProductSupplierRepository::find($id) ?? $row;
            $upd['fob_source'] = 'direct';
            $upd['fob_value'] = (float) $in['price'];
            ProductSupplierRepository::update($id, $upd, $userId);
            if ($old === null || abs($old - (float) $in['price']) > 0.005) {
                ThicknessPrices::history($id, 'Price per ' . (($row['price_basis'] ?? '') === 'cbm' ? 'CBM' : ((string) ($product['reference_unit'] ?? '') ?: 'unit')),
                    $old !== null ? number_format($old, 2, '.', '') : null, number_format((float) $in['price'], 2, '.', ''), $userId);
            }
        }
        $fresh = ProductSupplierRepository::find($id) ?? $row;
        PricingRepository::setSupplierDates($id, (string) $in['quoted_on'], $in['valid_until'] !== '' ? $in['valid_until'] : null);
        $oldCha = $row['cha_transport_per_container'] !== null ? number_format((float) $row['cha_transport_per_container'], 2, '.', '') : null;
        $newCha = $in['cha'] !== null ? number_format((float) $in['cha'], 2, '.', '') : null;
        $oldIncl = (int) ($row['includes_packing_loading'] ?? 1) === 1;
        ProductSupplierRepository::setPriceDetails($id, $fresh['supplier_id'] !== null ? (int) $fresh['supplier_id'] : null, (bool) $in['includes_packing_loading'], $in['cha']);
        if ($oldCha !== $newCha) {
            ThicknessPrices::history($id, 'CHA + transport per container', $oldCha ?? 'product default', $newCha ?? 'product default', $userId);
        }
        if ($oldIncl !== (bool) $in['includes_packing_loading']) {
            ThicknessPrices::history($id, 'Price includes packing and loading', $oldIncl ? 'yes' : 'no', $in['includes_packing_loading'] ? 'yes' : 'no', $userId);
        }
        if ((string) ($fresh['notes'] ?? '') !== (string) ($in['notes'] ?? '')) {
            $fresh = ProductSupplierRepository::find($id) ?? $fresh;
            $fresh['notes'] = $in['notes'] !== '' ? $in['notes'] : null;
            ProductSupplierRepository::update($id, $fresh, $userId);
        }
        $after = self::offerBase($product, ProductSupplierRepository::find($id) ?? $row, $rules);
        self::refreshFollowedBase(ProductRepository::find((int) $product['id']) ?? $product, $userId);
        $what = $grid ? ((int) $in['from_mm'] === ThicknessPrices::CBM ? 'per CBM' : 'per m²') . ' (' . ThicknessPrices::label((int) $in['from_mm'], $cbmFrom) . ')'
            : 'per ' . (($row['price_basis'] ?? '') === 'cbm' ? 'CBM' : ((string) ($product['reference_unit'] ?? '') ?: 'unit'));
        $summary = $row['supplier_name'] . ' · ' . $product['name'] . ': '
            . ($before !== null ? '₹' . ThicknessPrices::amount($before['inr']) . ' → ' : '') . '₹' . ThicknessPrices::amount((float) $in['price']) . ' ' . $what
            . ', quoted ' . date('j M Y', (int) strtotime((string) $in['quoted_on'])) . '.'
            . ($grid && $after !== null && $before !== null && ((int) $in['from_mm'] !== ($after['mm'] ?? -1)) ? ' Base price now ₹' . ThicknessPrices::amount($after['inr']) . ' ' . $after['label'] . '.' : '')
            . ($grid ? ' Other thicknesses updated.' : '');
        AuditLogRepository::log($userId, 'MARKET_PRICE_CHANGED', 'product_suppliers', $id, null,
            $before !== null ? '₹' . number_format($before['inr'], 2) . ' ' . $before['label'] : null, '₹' . number_format((float) $in['price'], 2) . ' ' . $what . ' quoted ' . $in['quoted_on'] . ' (Edit price)');
        return [$summary, null];
    }

    /** Undo: puts one offer back as the snapshot taken before a save, with a history row. */
    public static function undo(array $snap, int $userId): ?string
    {
        $id = (int) $snap['offer_id'];
        $row = ProductSupplierRepository::find($id);
        if ($row === null) {
            return 'That supplier price no longer exists.';
        }
        $product = ProductRepository::find((int) $row['product_id']);
        $rules = $product !== null ? ThicknessPrices::rules($product) : null;
        $before = $product !== null ? self::offerBase($product, $row, $rules) : null;
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id')->execute(['id' => $id]);
            $ins = $pdo->prepare('INSERT INTO catalog_supplier_thickness_prices (product_supplier_id, thickness_mm, price_inr, how, calc_from_mm, quoted_on, updated_by) VALUES (:id, :mm, :p, :how, :cf, :q, :u)');
            foreach ($snap['thickness'] as $t) {
                $ins->execute(['id' => $id, 'mm' => (int) $t['thickness_mm'], 'p' => $t['price_inr'], 'how' => $t['how'], 'cf' => $t['calc_from_mm'], 'q' => $t['quoted_on'], 'u' => $t['updated_by']]);
            }
            $r = $snap['row'];
            $pdo->prepare('UPDATE catalog_product_suppliers SET fob_source = :fs, fob_value = :fv, auto_fill = :af, base_thickness_mm = :b, quoted_on = :q, valid_until = :v,
                includes_packing_loading = :i, cha_transport_per_container = :c, notes = :n, updated_by = :u WHERE id = :id')
                ->execute(['fs' => $r['fob_source'], 'fv' => $r['fob_value'], 'af' => $r['auto_fill'], 'b' => $r['base_thickness_mm'], 'q' => $r['quoted_on'], 'v' => $r['valid_until'],
                    'i' => $r['includes_packing_loading'], 'c' => $r['cha_transport_per_container'], 'n' => $r['notes'], 'u' => $userId, 'id' => $id]);
            if (($snap['product'] ?? []) !== []) {
                $p = $snap['product'];
                $pdo->prepare('UPDATE catalog_products SET configured_base_price_inr = :p, configured_base_thickness_mm = :mm, configured_base_unit = :un, configured_at = :at WHERE id = :id')
                    ->execute(['p' => $p['configured_base_price_inr'], 'mm' => $p['configured_base_thickness_mm'], 'un' => $p['configured_base_unit'], 'at' => $p['configured_at'], 'id' => (int) $snap['product_id']]);
            }
            $after = $product !== null ? self::offerBase($product, ProductSupplierRepository::find($id) ?? $row, $rules) : null;
            ThicknessPrices::history($id, 'Undo (Edit price)', $before !== null ? '₹' . number_format($before['inr'], 2) . ' ' . $before['label'] : '—',
                $after !== null ? '₹' . number_format($after['inr'], 2) . ' ' . $after['label'] . ' restored' : 'restored', $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditLogRepository::log($userId, 'MARKET_PRICE_CHANGED', 'product_suppliers', $id, null, 'Edit price', 'undone');
        return null;
    }

    // ------------------------------------------------------------------ D-360: the Suppliers pages

    /**
     * D-360: every offer linked to these directory suppliers, by supplier id, for the Suppliers
     * list and the supplier page: product, base price as quoted (offerBase), dates, the switch.
     *
     * @param list<int> $supplierIds
     * @return array<int, list<array<string,mixed>>>
     */
    public static function forSuppliers(array $supplierIds, ?array $settings = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $supplierIds)));
        if ($ids === []) {
            return [];
        }
        $settings ??= PricingService::settings();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::connection()->prepare("SELECT cps.*, sd.is_active AS supplier_is_active FROM catalog_product_suppliers cps JOIN suppliers sd ON sd.id = cps.supplier_id
            JOIN catalog_products p ON p.id = cps.product_id WHERE cps.supplier_id IN ({$in}) ORDER BY p.name, cps.id");
        $st->execute($ids);
        $products = [];
        $rules = [];
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $pid = (int) $r['product_id'];
            $products[$pid] ??= ProductRepository::find($pid);
            $product = $products[$pid];
            if ($product === null) {
                continue;
            }
            $rules[$pid] ??= ThicknessPrices::rules($product, $settings);
            $out[(int) $r['supplier_id']][] = self::listRow($product, $r, $rules[$pid], $settings);
        }
        return $out;
    }

    /** One offer as the Suppliers pages show it. @return array<string,mixed> */
    public static function listRow(array $product, array $r, array $rules, ?array $settings = null): array
    {
        $basis = (string) ($r['price_basis'] ?? 'unit');
        $base = self::offerBase($product, $r, $rules, $settings);
        $cbmFrom = (int) $rules['cbm_from'];
        if ($base !== null) {
            $short = $base['unit'] === 'CBM' ? 'CBM' : ($base['unit'] === 'm2' ? 'm²' : (LineUnits::label($base['unit']) ?: $base['unit']));
            $price = '₹' . ThicknessPrices::amount($base['inr']) . ' / ' . $short . ($base['mm'] !== null ? ' (' . ThicknessPrices::label($base['mm'], $cbmFrom) . ')' : '');
        } else {
            $price = $basis === 'combo' ? 'Exact price for a combination' : '—';
        }
        $thickness = $basis === 'grid' ? ThicknessPrices::label(ThicknessPrices::baseOf($r, $rules), $cbmFrom) : '—';
        return [
            'id' => (int) $r['id'], 'product_id' => (int) $product['id'], 'product_name' => (string) $product['name'],
            'product_active' => (int) ($product['is_active'] ?? 1) === 1,
            'price' => $price, 'thickness' => $thickness, 'basis' => $basis,
            'quoted_on' => !empty($r['quoted_on']) ? substr((string) $r['quoted_on'], 0, 10) : null,
            'valid_until' => !empty($r['valid_until']) ? substr((string) $r['valid_until'], 0, 10) : null,
            // "12 Nov 2026 · 31 Dec 2026" (quoted on · valid until)
            'dates' => (!empty($r['quoted_on']) ? date('j M Y', (int) strtotime(substr((string) $r['quoted_on'], 0, 10))) : '—') . ' · '
                . (!empty($r['valid_until']) ? date('j M Y', (int) strtotime(substr((string) $r['valid_until'], 0, 10))) : 'no end date'),
            'offer_active' => (int) ($r['offer_active'] ?? 1) === 1,
            'supplier_active' => (int) ($r['supplier_is_active'] ?? 1) === 1,
            // "Edit price" is the simple form for one typed price (not an exact price for a combination).
            'editable' => ($r['fob_source'] ?? 'direct') === 'direct' && $basis !== 'combo',
        ];
    }

    /** D-360: how many offers each directory supplier has (linked rows). @return array<int,int> */
    public static function countsBySupplier(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT supplier_id, COUNT(*) AS n FROM catalog_product_suppliers WHERE supplier_id IS NOT NULL GROUP BY supplier_id')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['supplier_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** D-360: this supplier's offer of this product, if it already has one. */
    public static function existingOffer(int $supplierId, int $productId): ?array
    {
        $st = Database::connection()->prepare('SELECT id FROM catalog_product_suppliers WHERE supplier_id = :s AND product_id = :p ORDER BY id LIMIT 1');
        $st->execute(['s' => $supplierId, 'p' => $productId]);
        $id = $st->fetchColumn();
        return $id !== false ? ProductSupplierRepository::find((int) $id) : null;
    }

    /**
     * D-360: what "+ Add product" can price for a product (or a new product of a category):
     * a price for every thickness (the thicknesses, the base, m² / CBM) or one price per unit.
     *
     * @return array{grid: bool, base_mm: ?int, thicknesses: list<array{mm:int,label:string,unit:string}>, units: list<array{value:string,label:string}>}
     */
    public static function priceShape(array $product, ?array $settings = null, bool $newProduct = false): array
    {
        $rules = ThicknessPrices::rules($product, $settings);
        if ($rules['grid']) {
            $cbmFrom = (int) $rules['cbm_from'];
            return ['grid' => true, 'base_mm' => (int) $rules['base_mm'],
                'thicknesses' => array_map(static fn (int $mm): array => ['mm' => $mm, 'label' => ThicknessPrices::label($mm, $cbmFrom), 'unit' => $mm === ThicknessPrices::CBM ? 'CBM' : 'm2'], ThicknessPrices::thicknesses($rules)),
                'units' => [['value' => 'm2', 'label' => 'm²'], ['value' => 'CBM', 'label' => 'CBM']]];
        }
        $units = [];
        if ($newProduct) {
            foreach (\App\Repositories\LookupRepository::dropdownOptions('unit') as $o) {
                $units[] = ['value' => (string) $o['option_value'], 'label' => LineUnits::label((string) $o['option_value']) ?: (string) $o['option_value']];
            }
        } else {
            $u = (string) ($product['reference_unit'] ?? '');
            $units[] = ['value' => $u !== '' ? $u : 'unit', 'label' => $u !== '' ? (LineUnits::label($u) ?: $u) : 'unit'];
        }
        if (!in_array('CBM', array_map(static fn (array $x): string => strtoupper($x['value']), $units), true)) {
            $units[] = ['value' => 'CBM', 'label' => 'CBM'];
        }
        return ['grid' => false, 'base_mm' => null, 'thicknesses' => [], 'units' => $units];
    }

    /**
     * D-360: the "+ Add product" choices — every product (those with no directory supplier yet
     * first, marked), with what this supplier already offers, and every active category.
     *
     * @return array{products: list<array<string,mixed>>, categories: list<array<string,mixed>>}
     */
    public static function addProductChoices(int $supplierId, ?array $settings = null): array
    {
        $settings ??= PricingService::settings();
        $pdo = Database::connection();
        $linked = [];
        foreach ($pdo->query('SELECT DISTINCT product_id FROM catalog_product_suppliers WHERE supplier_id IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN) as $pid) {
            $linked[(int) $pid] = true;
        }
        $st = $pdo->prepare('SELECT product_id, MIN(id) AS id FROM catalog_product_suppliers WHERE supplier_id = :s GROUP BY product_id');
        $st->execute(['s' => $supplierId]);
        $mine = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $mine[(int) $r['product_id']] = (int) $r['id'];
        }
        $products = [];
        foreach ($pdo->query('SELECT * FROM catalog_products ORDER BY name, id')->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $products[] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'active' => (int) $p['is_active'] === 1,
                'no_supplier' => !isset($linked[(int) $p['id']]), 'offer_id' => $mine[(int) $p['id']] ?? null,
                'shape' => self::priceShape($p, $settings)];
        }
        usort($products, static fn (array $a, array $b): int => [$a['no_supplier'] ? 0 : 1, mb_strtolower($a['name'])] <=> [$b['no_supplier'] ? 0 : 1, mb_strtolower($b['name'])]);
        $categories = [];
        foreach (\App\Repositories\ProductCategoryRepository::all(true) as $c) {
            $categories[] = ['id' => (int) $c['id'], 'name' => (string) $c['name'],
                'shape' => self::priceShape(['id' => 0, 'category_id' => (int) $c['id']], $settings, true)];
        }
        return ['products' => $products, 'categories' => $categories];
    }

    /**
     * D-360: a new product from a supplier's "+ Add product" — name (unique), category, HS code
     * (optional: the category's default HS code, else empty until Configure). It starts
     * Inactive and not configured (D-361 adds Configure). Returns the new product's id.
     */
    public static function createProduct(string $name, int $categoryId, string $hsCode, ?string $unit, int $userId): int
    {
        $category = \App\Repositories\ProductCategoryRepository::find($categoryId);
        $hs = $hsCode !== '' ? $hsCode : (string) ($category['default_hs_code'] ?? '');
        $id = ProductRepository::create(['name' => $name, 'specifications' => '', 'hs_code' => $hs, 'reference_unit' => $unit, 'is_active' => 0], $userId);
        // Not configured: configured_at stays empty until Configure (D-361).
        \App\Repositories\SpecRepository::setProductFields($id, $categoryId, null, null, null);
        AuditLogRepository::log($userId, 'PRODUCT_CREATED', 'products', $id, null, null, $name . ' (from a supplier, Inactive, not configured)');
        return $id;
    }

    /**
     * D-360: "+ Add product" — this directory supplier's offer of a product: one price at one
     * thickness (the other thicknesses worked out by the price rules, as Market prices does)
     * or one price per unit / per CBM, quoted on, valid until; the offer is switched on. It is
     * "used for pricing" only when the product has no supplier price used for pricing yet.
     * $in: price, mm (grid; null otherwise), unit, quoted_on, valid_until. Returns the offer id.
     *
     * @param array<string,mixed> $in
     */
    public static function addOffer(array $supplier, array $product, array $in, int $userId, bool $newProduct = false): int
    {
        $productId = (int) $product['id'];
        $rules = ThicknessPrices::rules($product);
        $grid = $in['mm'] !== null && $rules['grid'];
        $unitIsCbm = strtoupper((string) $in['unit']) === 'CBM';
        $basis = $grid ? 'grid' : ($unitIsCbm && LineUnits::canonical((string) ($product['reference_unit'] ?? '')) !== 'CBM' ? 'cbm' : 'unit');
        $primary = $basis !== 'cbm' && self::markedForPricing($productId) === null;
        $id = ProductSupplierRepository::create([
            'product_id' => $productId, 'supplier_name' => (string) $supplier['supplier_legal_name'],
            'location' => trim(implode(', ', array_filter([(string) ($supplier['city'] ?? ''), (string) ($supplier['state'] ?? '')]))) ?: null,
            'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => $grid ? null : (float) $in['price'],
            'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 0, 'notes' => null,
        ], $userId);
        PricingRepository::setSupplierDates($id, (string) $in['quoted_on'], $in['valid_until'] !== '' ? (string) $in['valid_until'] : null);
        ProductSupplierRepository::setPriceDetails($id, (int) $supplier['id'], true, null);
        PricingRepository::setSupplierBasis($id, $basis);
        if ($grid) {
            $row = ProductSupplierRepository::find($id) ?? ['id' => $id];
            $others = array_values(array_filter(ThicknessPrices::thicknesses($rules), static fn (int $mm): bool => $mm !== (int) $in['mm']));
            $error = ThicknessPrices::addPrice($row, $rules, (int) $in['mm'], (float) $in['price'], (string) $in['quoted_on'], $others, true, $userId);
            if ($error !== null) {
                ProductSupplierRepository::delete($id);
                throw new \RuntimeException($error);
            }
        }
        if ($primary) {
            (new ProductService())->setPrimarySupplier($productId, $id, $userId);
        }
        $fresh = ProductRepository::find($productId) ?? $product;
        if ($primary && !$newProduct) {
            // The product had no supplier price used for pricing: its base price now follows this one.
            self::refreshFollowedBase($fresh, $userId);
        }
        $after = self::offerBase($fresh, ProductSupplierRepository::find($id) ?? ['id' => $id], $rules);
        ThicknessPrices::history($id, 'Offer added (+ Add product)', null, $after !== null ? '₹' . number_format($after['inr'], 2) . ' ' . $after['label'] : 'added', $userId);
        AuditLogRepository::log($userId, 'MARKET_PRICE_ADDED', 'product_suppliers', $id, null, null,
            $supplier['supplier_legal_name'] . ': ' . $product['name'] . ' ₹' . number_format((float) $in['price'], 2) . ' '
            . ($grid ? ((int) $in['mm'] === ThicknessPrices::CBM ? 'per CBM' : 'per m²') . ' (' . ThicknessPrices::label((int) $in['mm'], (int) $rules['cbm_from']) . ')' : 'per ' . $in['unit'])
            . ' quoted ' . $in['quoted_on'] . ' (+ Add product)' . ($primary ? ' (used for pricing)' : ''));
        return $id;
    }
}
