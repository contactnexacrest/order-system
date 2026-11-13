<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\PricingLevels;
use App\Services\PricingService;
use App\Services\ProductSpecs;
use App\Services\SupplierOffers;
use App\Services\ThicknessPrices;

/**
 * Owner decision D-361 (13 Nov 2026, "go with recommendation"; mockups p4 and
 * configure_three_levels_mockup.png): Products › Configure / Edit — the price part of one product.
 *
 *  - Base price: default = the highest active supplier's base price; "See all supplier prices";
 *    a warning below the lowest; it never changes by itself (owner decision 1) — when supplier
 *    prices change the screen says "Supplier prices changed: highest is now ₹X" with "Use ₹X".
 *  - Three levels: Global | Category | This product | Comes from, a reset per row, "Reset all to
 *    category values" and "Reset all to global values" with a confirm box listing the changes
 *    (PricingLevels).
 *  - Active: only when the product is configured with at least one active supplier offer, or it
 *    is a product from before 13b with no supplier yet.
 *  Only users with manage_product_catalog (owner decision 2).
 *
 * Also (owner decisions 1 and 3): "Link a supplier" for a product with no supplier yet, and
 * Products › "Link these prices" for legacy supplier prices with no supplier record.
 * Node twin: src/controllers/productConfigureController.js.
 */
final class ProductConfigureController
{
    /** The rows of the three-level table: key => [label, product column, decimals, min, max]. */
    private const ROWS = [
        'margin_pct' => ['Margin %', 'pricing_margin_pct', 2, 0, 89.99],
        'other_cost' => ['Other costs', 'pricing_other_cost_value', 4, 0, 100],
        'buffer_pct' => ['Buffer %', 'pricing_buffer_pct', 2, 0, 100],
        'weight_t_per_cbm' => ['Weight t / CBM', 'weight_t_per_cbm', 3, 0.1, 20],
        'container_cbm' => ['Container CBM', 'container_cbm', 2, 0.5, 100],
        'container_max_t' => ['Container max tonnes', 'container_max_t', 2, 1, 100],
        'thick_k_factor' => ['Thickness factor', 'thick_k_factor', 3, 1, 1000],
    ];

    private static function can(string $key): bool
    {
        $u = AuthService::currentUser();
        return $u !== null && PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, $key);
    }

    private static function product(array $params): ?array
    {
        $p = ProductRepository::find((int) ($params['id'] ?? 0));
        if ($p === null) {
            http_response_code(404);
            echo 'Product not found.';
        }
        return $p;
    }

    /** A number as the inputs show it: "15", "2.7", "5.5". */
    private static function fmt(mixed $v): string
    {
        return $v === null || $v === '' ? '' : ThicknessPrices::fmt((float) $v);
    }

    /** The base price's unit and thickness for a product that is not configured yet. @return array{0: ?int, 1: string} */
    private static function defaultShape(array $product, array $lh, array $settings): array
    {
        if (SupplierOffers::isConfigured($product)) {
            return [$product['configured_base_thickness_mm'] !== null ? (int) $product['configured_base_thickness_mm'] : null, (string) $product['configured_base_unit']];
        }
        if ($lh['highest'] !== null) {
            return [$lh['highest']['base']['mm'], (string) $lh['highest']['base']['unit']];
        }
        $rules = ThicknessPrices::rules($product, $settings);
        if ($rules['grid']) {
            return [(int) $rules['base_mm'], (int) $rules['base_mm'] === ThicknessPrices::CBM ? 'CBM' : 'm2'];
        }
        return [null, (string) ($product['reference_unit'] ?? '') ?: 'unit'];
    }

    /** "₹ / CBM, 50 mm and above", "₹ / m², 20 mm", "₹ / Sq.Ft". */
    private static function shapeLabel(?int $mm, string $unit, array $product, array $settings): string
    {
        if ($mm !== null) {
            $rules = ThicknessPrices::rules($product, $settings);
            return '₹ / ' . ($mm === ThicknessPrices::CBM ? 'CBM' : 'm²') . ', ' . ThicknessPrices::label($mm, (int) $rules['cbm_from']);
        }
        return '₹ / ' . (\App\Services\LineUnits::label($unit) ?: $unit);
    }

    public function form(array $params): void
    {
        $product = self::product($params);
        if ($product === null) {
            return;
        }
        $settings = PricingService::settings();
        $catId = ProductSpecs::categoryIdFor($product);
        $category = $catId !== null ? \App\Repositories\ProductCategoryRepository::find($catId) : null;
        $now = PricingLevels::resolve($product, $settings);
        $bare = $product;
        foreach (PricingLevels::PRODUCT_OVERRIDES as $c) {
            $bare[$c] = null;
        }
        $inherit = PricingLevels::resolve($bare, $settings);
        $rows = [];
        foreach (self::ROWS as $key => [$label, $col, $dec]) {
            $isOther = $key === 'other_cost';
            $g = $isOther ? $now[$key]['global']['value'] : $now[$key]['global'];
            $catVal = $category !== null ? ($isOther ? ($category['other_cost_pct'] ?? null) : ($category[$key] ?? null)) : null;
            $own = $isOther ? ($product['pricing_other_cost_mode'] !== null) : ($product[$col] !== null);
            $inh = $isOther ? $inherit[$key]['value']['value'] : $inherit[$key]['value'];
            $value = $isOther ? $now[$key]['value']['value'] : $now[$key]['value'];
            $rows[] = ['key' => $key, 'label' => $label, 'global' => self::fmt($g), 'category' => self::fmt($catVal), 'value' => self::fmt($value),
                'inherit' => self::fmt($inh), 'inherit_from' => $inherit[$key]['from'], 'own' => $own, 'from' => $now[$key]['from'],
                'mode' => $isOther ? (string) $now[$key]['value']['mode'] : null, 'decimals' => $dec];
        }
        $lh = SupplierOffers::lowestHighest($product, $settings);
        [$mm, $unit] = self::defaultShape($product, $lh, $settings);
        $configured = SupplierOffers::isConfigured($product);
        $old = Flash::pullOld();
        $offers = [];
        foreach (SupplierOffers::offers((int) $product['id']) as $o) {
            $b = SupplierOffers::offerBase($product, $o, null, $settings);
            $offers[] = ['id' => (int) $o['id'], 'supplier' => (string) $o['supplier_name'], 'usable' => (bool) $o['usable'], 'linked' => $o['supplier_id'] !== null,
                'price' => $b !== null ? '₹' . ThicknessPrices::amount($b['inr']) . ' ' . $b['label'] : '—',
                'quoted_on' => !empty($o['quoted_on']) ? substr((string) $o['quoted_on'], 0, 10) : '—', 'valid_until' => !empty($o['valid_until']) ? substr((string) $o['valid_until'], 0, 10) : '—',
                'editable' => ($o['fob_source'] ?? 'direct') === 'direct' && ($o['price_basis'] ?? '') !== 'combo'];
        }
        View::render('products/configure', [
            'product' => $product, 'category' => $category, 'rows' => $rows, 'followGlobal' => (int) $product['pricing_follow_global'] === 1,
            'configured' => $configured, 'status' => SupplierOffers::status($product),
            'basePrice' => $old['base_price'] ?? ($configured ? number_format((float) $product['configured_base_price_inr'], 2, '.', '') : ($lh['highest'] !== null ? number_format((float) $lh['highest']['base']['inr'], 2, '.', '') : '')),
            'baseMm' => $mm, 'baseUnit' => $unit, 'baseLabel' => self::shapeLabel($mm, $unit, $product, $settings),
            'lowest' => $lh['lowest'], 'highest' => $lh['highest'], 'offers' => $offers,
            'change' => SupplierOffers::priceChange($product, $lh),
            'previewCategory' => self::figureChanges(PricingLevels::resetPreview($product, 'category', $settings)),
            'previewGlobal' => self::figureChanges(PricingLevels::resetPreview($product, 'global', $settings)),
            'canEditPrice' => self::can('record_market_prices'),
            'old' => $old,
        ], 'layout/base');
    }

    /**
     * The confirm box lists the values whose figure changes; values that only change where they
     * come from (same figure) are named in one line.
     *
     * @param list<array<string,string>> $lines
     * @return array{changes: list<array<string,string>>, same: list<string>}
     */
    private static function figureChanges(array $lines): array
    {
        $out = ['changes' => [], 'same' => []];
        foreach ($lines as $l) {
            if ($l['now'] !== $l['after']) {
                $out['changes'][] = $l;
            } else {
                $out['same'][] = $l['label'];
            }
        }
        return $out;
    }

    /** "190,000.00" / "190000" → 190000.0; null when not a ₹ amount above 0 with at most 2 decimals. */
    private static function money(string $v): ?float
    {
        $v = str_replace([',', ' '], '', trim($v));
        return preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v) === 1 && (float) $v > 0 ? (float) $v : null;
    }

    public function save(array $params): void
    {
        $product = self::product($params);
        if ($product === null) {
            return;
        }
        $id = (int) $product['id'];
        $back = "/products/{$id}/configure";
        $userId = (int) AuthService::currentUserId();
        $settings = PricingService::settings();
        $price = self::money((string) ($_POST['base_price'] ?? ''));
        if ($price === null) {
            Flash::set('error', 'Base price: enter the price in ₹ (above 0, at most 2 decimals), e.g. 190000.');
            Flash::setOld($_POST);
            header("Location: {$back}");
            return;
        }
        $lh = SupplierOffers::lowestHighest($product, $settings);
        [$mm, $unit] = self::defaultShape($product, $lh, $settings);
        // The values of the three-level table: a value equal to the inherited one is not the product's own.
        $bare = $product;
        foreach (PricingLevels::PRODUCT_OVERRIDES as $c) {
            $bare[$c] = null;
        }
        $inherit = PricingLevels::resolve($bare, $settings);
        $vals = is_array($_POST['level'] ?? null) ? $_POST['level'] : [];
        $set = [];
        foreach (self::ROWS as $key => [$label, $col, $dec, $min, $max]) {
            $raw = trim((string) ($vals[$key] ?? ''));
            $mode = $key === 'other_cost' ? ((string) ($_POST['other_cost_mode'] ?? 'pct') === 'usd' ? 'usd' : 'pct') : null;
            $inh = $key === 'other_cost' ? (float) $inherit[$key]['value']['value'] : (float) $inherit[$key]['value'];
            if ($raw === '') {
                $new = null;
            } else {
                $maxV = $mode === 'usd' ? 1000000 : $max;
                if (preg_match('/^\d{1,7}(\.\d{1,' . $dec . '})?$/', $raw) !== 1 || (float) $raw < $min || (float) $raw > $maxV) {
                    Flash::set('error', "{$label}: enter a number from " . ThicknessPrices::fmt((float) $min) . ' to ' . ThicknessPrices::fmt((float) $maxV) . " with at most {$dec} decimals, or reset it.");
                    Flash::setOld($_POST);
                    header("Location: {$back}");
                    return;
                }
                $new = ($mode === null || $mode === 'pct') && abs((float) $raw - $inh) < 1e-9 ? null : (float) $raw;
            }
            if ($key === 'other_cost') {
                $set['pricing_other_cost_mode'] = $new === null ? null : $mode;
                $set['pricing_other_cost_value'] = $new === null ? null : number_format($new, 4, '.', '');
            } else {
                $set[$col] = $new === null ? null : number_format($new, $dec, '.', '');
            }
        }
        $wantActive = !empty($_POST['is_active']);
        $pdo = Database::connection();
        $changes = 0;
        $pdo->beginTransaction();
        try {
            if (!SupplierOffers::isConfigured($product) || abs((float) $product['configured_base_price_inr'] - $price) > 0.004
                || (string) ($product['configured_base_thickness_mm'] ?? '') !== (string) ($mm ?? '') || (string) $product['configured_base_unit'] !== $unit) {
                SupplierOffers::setConfiguredBase($id, $price, $mm, $unit, $userId);
                $changes++;
            }
            foreach ($set as $col => $v) {
                $old = $product[$col] === null ? null : (string) $product[$col];
                if ($old === $v || ($old !== null && $v !== null && is_numeric($old) && is_numeric($v) && abs((float) $old - (float) $v) < 1e-9)) {
                    continue;
                }
                $pdo->prepare("UPDATE catalog_products SET {$col} = :v, updated_by = :u WHERE id = :id")->execute(['v' => $v, 'u' => $userId, 'id' => $id]);
                AuditLogRepository::log($userId, 'PRODUCT_PRICING_CHANGED', 'products', $id, $col, $old ?? 'category / global', $v ?? 'category / global');
                $changes++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $fresh = ProductRepository::find($id) ?? $product;
        $status = SupplierOffers::status($fresh);
        $note = '';
        if ($wantActive !== ((int) $fresh['is_active'] === 1)) {
            if ($wantActive && !$status['can_activate']) {
                $note = ' It stays Inactive: ' . $status['reason'];
            } else {
                ProductRepository::setActive($id, $wantActive, $userId);
                AuditLogRepository::log($userId, $wantActive ? 'PRODUCT_REACTIVATED' : 'PRODUCT_DEACTIVATED', 'products', $id, 'is_active', $wantActive ? '0' : '1', $wantActive ? '1' : '0');
                $note = $wantActive ? ' The product is Active — it can be picked on order lines.' : ' The product is Inactive — it cannot be picked on new order lines.';
                $changes++;
            }
        }
        Flash::set($note !== '' && str_starts_with($note, ' It stays') ? 'error' : 'success', ($changes === 0 ? 'Nothing changed.' : 'Saved: ' . $product['name'] . ' — base price ₹' . ThicknessPrices::amount($price) . ' ' . SupplierOffers::baseLabel($fresh, $settings) . '. Quotations and Proforma Invoices already issued keep their prices.') . $note);
        header("Location: {$back}");
    }

    /** "Reset all to category values" / "Reset all to global values" (after the confirm box). */
    public function reset(array $params): void
    {
        $product = self::product($params);
        if ($product === null) {
            return;
        }
        $to = (string) ($_POST['to'] ?? '') === 'global' ? 'global' : 'category';
        $userId = (int) AuthService::currentUserId();
        $lines = self::figureChanges(PricingLevels::resetPreview($product, $to))['changes'];
        $to === 'global' ? PricingLevels::resetToGlobal((int) $product['id'], $userId) : PricingLevels::resetToCategory((int) $product['id'], $userId);
        Flash::set('success', ($to === 'global' ? 'Reset to the global values' : 'Reset to the category values') . ($lines === [] ? ' — nothing changed in the figures.' : ': '
            . implode(' · ', array_map(static fn (array $l): string => $l['label'] . ' ' . $l['now'] . ' → ' . $l['after'], $lines)) . '.'));
        header('Location: /products/' . (int) $product['id'] . '/configure');
    }

    /** "Use ₹X": the base price becomes the highest active supplier price now. */
    public function usePrice(array $params): void
    {
        $product = self::product($params);
        if ($product === null) {
            return;
        }
        $change = SupplierOffers::priceChange($product);
        if ($change === null) {
            Flash::set('success', 'The base price already matches the supplier prices — nothing changed.');
        } else {
            SupplierOffers::setConfiguredBase((int) $product['id'], $change['inr'], $change['mm'], $change['unit'], (int) AuthService::currentUserId());
            Flash::set('success', 'Base price now ₹' . ThicknessPrices::amount($change['inr']) . ' ' . $change['label'] . ' (' . $change['supplier'] . '). Quotations and Proforma Invoices already issued keep their prices.');
        }
        header('Location: /products/' . (int) $product['id'] . '/configure');
    }

    // ------------------------------------------------------------------ "Link a supplier" (decision 1, part A)

    public function linkSupplierForm(array $params): void
    {
        $product = self::product($params);
        if ($product === null) {
            return;
        }
        $settings = PricingService::settings();
        $old = Flash::pullOld();
        View::render('products/link_supplier', [
            'product' => $product, 'shape' => SupplierOffers::priceShape($product, $settings),
            'pickList' => SupplierRepository::pickList(), 'supplierTypes' => array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('supplier_type')),
            'pickedId' => (int) ($old['supplier_id'] ?? ($_GET['supplier_id'] ?? 0)),
            'configured' => SupplierOffers::isConfigured($product), 'baseText' => SupplierOffers::isConfigured($product) ? '₹' . ThicknessPrices::amount((float) $product['configured_base_price_inr']) . ' ' . SupplierOffers::baseLabel($product, $settings) : null,
            'old' => $old, 'today' => date('Y-m-d'),
        ], 'layout/base');
    }

    public function linkSupplier(array $params): void
    {
        $product = self::product($params);
        if ($product === null) {
            return;
        }
        $id = (int) $product['id'];
        $back = "/products/{$id}/link-supplier";
        $supplier = SupplierRepository::find((int) ($_POST['supplier_id'] ?? 0));
        $shape = SupplierOffers::priceShape($product);
        $price = self::money((string) ($_POST['price'] ?? ''));
        $q = trim((string) ($_POST['quoted_on'] ?? ''));
        $v = trim((string) ($_POST['valid_until'] ?? ''));
        $mmRaw = trim((string) ($_POST['mm'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $error = null;
        if ($supplier === null || empty($supplier['is_active'])) {
            $error = 'Supplier: choose an active supplier from the list (or add one).';
        } elseif (SupplierOffers::existingOffer((int) $supplier['id'], $id) !== null) {
            $error = $supplier['supplier_legal_name'] . ' already has a price for this product — change it with Edit price.';
        } elseif ($price === null) {
            $error = 'Their price: enter the price in ₹ (above 0, at most 2 decimals).';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $q) || $q > date('Y-m-d')) {
            $error = 'Quoted on: the date the supplier gave the price (today or earlier).';
        } elseif ($v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || $v < $q)) {
            $error = 'Valid until: a date on or after the quoted date, or leave it empty.';
        }
        $mm = null;
        if ($error === null && $shape['grid']) {
            $mm = ctype_digit($mmRaw) ? (int) $mmRaw : -1;
            if (!in_array($mm, array_column($shape['thicknesses'], 'mm'), true)) {
                $error = 'For: choose the thickness the price is for.';
            }
            $unit = $mm === ThicknessPrices::CBM ? 'CBM' : 'm2';
        } elseif ($error === null && !in_array($unit, array_column($shape['units'], 'value'), true)) {
            $error = 'Per: choose the unit the price is for.';
        }
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
            header("Location: {$back}");
            return;
        }
        $userId = (int) AuthService::currentUserId();
        try {
            SupplierOffers::addOffer($supplier, $product, ['price' => $price, 'mm' => $mm, 'unit' => $unit, 'quoted_on' => $q, 'valid_until' => $v], $userId);
        } catch (\RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            Flash::setOld($_POST);
            header("Location: {$back}");
            return;
        }
        $fresh = ProductRepository::find($id) ?? $product;
        $st = SupplierOffers::status($fresh);
        Flash::set('success', 'Linked. ' . $product['name'] . ' now has ' . $st['usable'] . ' active supplier' . ($st['usable'] === 1 ? '' : 's') . ' (' . $supplier['supplier_legal_name'] . ', ₹' . ThicknessPrices::amount($price) . ').'
            . ' The red "No supplier yet" badge is gone; from now on the normal rule applies — if every supplier is switched off, the product is not offered on new orders.'
            . (SupplierOffers::isConfigured($fresh) ? ' The configured base price stays as it is.' : ' Configure the product to set its selling price.'));
        header('Location: /products?status=all');
    }

    // ------------------------------------------------------------------ "Link these prices" (decision 3)

    public function linkPricesForm(array $params): void
    {
        View::render('products/link_prices', [
            'rows' => SupplierOffers::unlinkedPrices(),
            'pickList' => SupplierRepository::pickList(),
            'supplierTypes' => array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('supplier_type')),
            'pickedId' => (int) ($_GET['supplier_id'] ?? 0),
        ], 'layout/base');
    }

    public function linkPrices(array $params): void
    {
        $error = SupplierOffers::linkPrice((int) ($_POST['offer_id'] ?? 0), (int) ($_POST['supplier_id'] ?? 0), (int) AuthService::currentUserId());
        $left = SupplierOffers::unlinkedCount();
        Flash::set($error === null ? 'success' : 'error', $error ?? ('Linked.' . ($left > 0 ? " {$left} still to link." : ' Every supplier price is now linked to a supplier.')));
        header('Location: ' . ($left > 0 || $error !== null ? '/products/link-prices' : '/products?status=all'));
    }
}
