<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\PricingService;
use App\Services\ProductService;

/**
 * Owner decision D-245 (4 Nov 2026), P1: Admin → Pricing settings, associate
 * commission levels and ladder, a product's pricing overrides, and
 * Procurement's market prices. Node twin: src/controllers/pricingController.js.
 */
final class PricingController
{
    private static function user(): array
    {
        return AuthService::currentUser() ?? [];
    }

    private static function can(string $key): bool
    {
        $u = self::user();
        return $u !== [] && PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, $key);
    }

    // ---------------------------------------------------------------- settings

    public function settings(array $params): void
    {
        View::render('pricing/settings', [
            'settings' => PricingRepository::settings() + PricingService::DEFAULTS,
            'labels' => PricingService::LABELS,
            'canManage' => self::can('manage_pricing'),
            'programOn' => PricingService::programOn(),
        ], 'layout/base');
    }

    public function saveSettings(array $params): void
    {
        [$values, $errors] = PricingService::readSettingsForm($_POST);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header('Location: /pricing/settings');
            return;
        }
        $userId = (int) self::user()['id'];
        $before = PricingRepository::settings() + PricingService::DEFAULTS;
        $changed = 0;
        foreach ($values as $k => $v) {
            if ((string) $before[$k] !== $v) {
                PricingRepository::setSetting($k, $v, $userId);
                AuditLogRepository::log($userId, 'PRICING_SETTING_CHANGED', 'pricing_settings', null, $k, (string) $before[$k], $v);
                $changed++;
            }
        }
        Flash::set('success', $changed === 0 ? 'Nothing changed.' : 'Pricing settings saved. New quotes use them; orders with an issued Proforma Invoice keep their prices.');
        header('Location: /pricing/settings');
    }

    // ---------------------------------------------------- levels and the ladder

    public function levels(array $params): void
    {
        $levels = PricingRepository::levels();
        $use = PricingRepository::associatesPerLevel();
        $sample = null;
        foreach (ProductRepository::all() as $p) {
            $b = PricingService::forProduct($p, array_values(array_filter($levels, static fn (array $l): bool => (int) $l['is_active'] === 1)));
            if ($b['build'] !== null) {
                $sample = ['product' => $p, 'pricing' => $b];
                break;
            }
        }
        $floors = [];
        if ($sample !== null) {
            foreach ($levels as $l) {
                $floors[(int) $l['id']] = \App\Services\PricingEngine::associate($sample['pricing']['build']['raw'], (string) $l['kind'], (float) $l['value']);
            }
        }
        $ladder = PricingRepository::ladder();
        View::render('pricing/commission_levels', [
            'levels' => $levels,
            'use' => $use,
            'stepsPerLevel' => array_count_values(array_map(static fn (array $s): int => (int) $s['level_id'], $ladder)),
            'ladder' => $ladder,
            'sample' => $sample,
            'floors' => $floors,
            'canManage' => self::can('manage_pricing'),
        ], 'layout/base');
    }

    /** @return array{0:?array{name:string,kind:string,value:string},1:?string} */
    private static function readLevel(?int $exceptId): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $kind = (string) ($_POST['kind'] ?? '');
        $value = trim((string) ($_POST['value'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            return [null, 'Enter the level\'s name (up to 60 characters).'];
        }
        if (PricingRepository::levelNameTaken($name, $exceptId)) {
            return [null, "A level called \"{$name}\" already exists."];
        }
        if (!in_array($kind, ['pct', 'fixed_usd'], true)) {
            return [null, 'Choose whether the level is a % of FOB or a fixed USD amount per unit.'];
        }
        $max = $kind === 'pct' ? 50 : 100000;
        if (!is_numeric($value) || preg_match('/^\d+(\.\d{1,4})?$/', $value) !== 1 || (float) $value <= 0 || (float) $value > $max) {
            return [null, $kind === 'pct' ? 'Enter the commission as a % above 0 and up to 50, with at most 4 decimals.' : 'Enter the commission as a USD amount per unit above 0, with at most 4 decimals.'];
        }
        return [['name' => $name, 'kind' => $kind, 'value' => number_format((float) $value, 4, '.', '')], null];
    }

    public function addLevel(array $params): void
    {
        [$l, $error] = self::readLevel(null);
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: /pricing/commission-levels');
            return;
        }
        $userId = (int) self::user()['id'];
        $id = PricingRepository::createLevel($l['name'], $l['kind'], $l['value'], $userId);
        AuditLogRepository::log($userId, 'COMMISSION_LEVEL_ADDED', 'commission_levels', $id, null, null, $l['name'] . ' — ' . PricingService::levelLabel($l));
        Flash::set('success', "Level \"{$l['name']}\" added.");
        header('Location: /pricing/commission-levels');
    }

    public function updateLevel(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $existing = PricingRepository::findLevel($id);
        if ($existing === null) {
            Flash::set('error', 'That commission level no longer exists.');
            header('Location: /pricing/commission-levels');
            return;
        }
        [$l, $error] = self::readLevel($id);
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: /pricing/commission-levels');
            return;
        }
        $userId = (int) self::user()['id'];
        PricingRepository::updateLevel($id, $l['name'], $l['kind'], $l['value'], $userId);
        AuditLogRepository::log($userId, 'COMMISSION_LEVEL_CHANGED', 'commission_levels', $id, null,
            $existing['name'] . ' — ' . PricingService::levelLabel($existing), $l['name'] . ' — ' . PricingService::levelLabel($l));
        Flash::set('success', "Level \"{$l['name']}\" saved. New quotes use it; orders with an issued Proforma Invoice keep the commission they had.");
        header('Location: /pricing/commission-levels');
    }

    public function toggleLevel(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $existing = PricingRepository::findLevel($id);
        if ($existing === null) {
            Flash::set('error', 'That commission level no longer exists.');
            header('Location: /pricing/commission-levels');
            return;
        }
        $active = (int) $existing['is_active'] !== 1;
        $userId = (int) self::user()['id'];
        PricingRepository::setLevelActive($id, $active, $userId);
        AuditLogRepository::log($userId, $active ? 'COMMISSION_LEVEL_ACTIVATED' : 'COMMISSION_LEVEL_DEACTIVATED', 'commission_levels', $id, 'is_active', $active ? '0' : '1', $active ? '1' : '0');
        Flash::set('success', "Level \"{$existing['name']}\" " . ($active ? 'activated.' : 'deactivated. Associates already on it keep it until you change them.'));
        header('Location: /pricing/commission-levels');
    }

    public function deleteLevel(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $existing = PricingRepository::findLevel($id);
        if ($existing === null) {
            Flash::set('error', 'That commission level no longer exists.');
            header('Location: /pricing/commission-levels');
            return;
        }
        $use = PricingRepository::levelUse($id);
        if ($use['associates'] > 0 || $use['steps'] > 0) {
            Flash::set('error', "Level \"{$existing['name']}\" is in use (" . $use['associates'] . ' associate(s), ' . $use['steps'] . ' ladder step(s)) — deactivate it instead.');
            header('Location: /pricing/commission-levels');
            return;
        }
        $userId = (int) self::user()['id'];
        PricingRepository::deleteLevel($id);
        AuditLogRepository::log($userId, 'COMMISSION_LEVEL_DELETED', 'commission_levels', $id, null, $existing['name'] . ' — ' . PricingService::levelLabel($existing), null);
        Flash::set('success', "Level \"{$existing['name']}\" deleted.");
        header('Location: /pricing/commission-levels');
    }

    public function saveLadder(array $params): void
    {
        $levelsById = [];
        foreach (PricingRepository::levels() as $l) {
            $levelsById[(int) $l['id']] = $l;
        }
        [$steps, $errors] = PricingService::readLadderForm($_POST, $levelsById);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header('Location: /pricing/commission-levels#ladder');
            return;
        }
        $describe = static function (array $rows) use ($levelsById): string {
            return implode('; ', array_map(static fn (array $s): string => ($s['from'] ?? $s['from_order']) . '–' . (($s['to'] ?? $s['to_order'] ?? null) === null ? 'onward' : ($s['to'] ?? $s['to_order']))
                . ': ' . ($levelsById[(int) $s['level_id']]['name'] ?? '?'), $rows));
        };
        $before = $describe(PricingRepository::ladder());
        $after = $describe($steps);
        $userId = (int) self::user()['id'];
        if ($before !== $after) {
            PricingRepository::replaceLadder($steps, $userId);
            AuditLogRepository::log($userId, 'COMMISSION_LADDER_CHANGED', 'commission_ladder_steps', null, null, $before, $after);
        }
        Flash::set('success', $before === $after ? 'Nothing changed.' : 'Ladder saved. It counts orders from P3 on; associates on "Ladder" use it.');
        header('Location: /pricing/commission-levels#ladder');
    }

    // ------------------------------------------------------ product overrides

    public function saveProductOverrides(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($id);
        if ($product === null) {
            Flash::set('error', 'That product no longer exists.');
            header('Location: /products');
            return;
        }
        [$fields, $errors] = PricingService::readOverridesForm($_POST);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header("Location: /products/{$id}#pricing");
            return;
        }
        $userId = (int) self::user()['id'];
        $changed = 0;
        foreach ($fields as $k => $v) {
            $old = $product[$k] === null ? null : (string) $product[$k];
            $new = $v === null ? null : (string) $v;
            if ($old !== $new && !($old !== null && $new !== null && is_numeric($old) && (float) $old === (float) $new)) {
                AuditLogRepository::log($userId, 'PRODUCT_PRICING_CHANGED', 'products', $id, $k, $old ?? 'global', $new ?? 'global');
                $changed++;
            }
        }
        PricingRepository::saveProductOverrides($id, $fields);
        Flash::set('success', $changed === 0 ? 'Nothing changed.' : 'This product\'s pricing settings saved.');
        header("Location: /products/{$id}#pricing");
    }

    // ------------------------------------------------------------ market prices

    public function marketPrices(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($id);
        if ($product === null) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }
        $settings = PricingService::settings();
        $service = new ProductService();
        $rows = [];
        foreach (ProductSupplierRepository::forProduct($id) as $s) {
            $s['effective_fob'] = $service->effectiveFobForSupplier($s, $product);
            $s['staleness'] = PricingService::staleness($s, $settings['stale_days']);
            $s['price_date'] = PricingService::priceDate($s);
            $rows[] = $s;
        }
        // Newest first; the one used for pricing on top.
        usort($rows, static fn (array $a, array $b): int => [(int) $b['is_primary'], (string) $b['price_date']] <=> [(int) $a['is_primary'], (string) $a['price_date']]);
        View::render('products/market_prices', [
            'product' => $product,
            'rows' => $rows,
            'staleDays' => $settings['stale_days'],
            'quotes' => PricingRepository::purchaseQuotesForProduct($id),
            'canRecord' => self::can('record_market_prices'),
        ], 'layout/base');
    }

    /** @return array{0: array<string,mixed>, 1: ?string} */
    private static function readMarketPrice(): array
    {
        $num = static function (string $k): ?float {
            $v = trim((string) ($_POST[$k] ?? ''));
            return $v === '' || !is_numeric($v) ? null : (float) $v;
        };
        $date = static function (string $k): ?string {
            $v = trim((string) ($_POST[$k] ?? ''));
            $d = \DateTime::createFromFormat('!Y-m-d', $v);
            return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
        };
        $data = [
            'supplier_name' => trim((string) ($_POST['supplier_name'] ?? '')),
            'location' => trim((string) ($_POST['location'] ?? '')) ?: null,
            'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct',
            'fob_value' => $num('price_inr'),
            'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => !empty($_POST['used_for_pricing']) ? 1 : 0,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'quoted_on' => $date('quoted_on'),
            'valid_until' => $date('valid_until'),
        ];
        if ($data['supplier_name'] === '' || mb_strlen($data['supplier_name']) > 255) {
            return [$data, 'Enter the supplier\'s name.'];
        }
        $raw = trim((string) ($_POST['price_inr'] ?? ''));
        if ($data['fob_value'] === null || $data['fob_value'] <= 0 || $data['fob_value'] > 999999999999.99 || preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
            return [$data, 'Enter the supplier\'s price in ₹ (above 0, at most 2 decimals).'];
        }
        if ($data['quoted_on'] === null) {
            return [$data, 'Enter the date the supplier quoted this price.'];
        }
        if ($data['quoted_on'] > date('Y-m-d')) {
            return [$data, 'The quoted date cannot be in the future.'];
        }
        if (trim((string) ($_POST['valid_until'] ?? '')) !== '' && $data['valid_until'] === null) {
            return [$data, 'Enter a valid "valid until" date, or leave it empty.'];
        }
        if ($data['valid_until'] !== null && $data['valid_until'] < $data['quoted_on']) {
            return [$data, '"Valid until" cannot be before the quoted date.'];
        }
        return [$data, null];
    }

    public function addMarketPrice(array $params): void
    {
        $productId = (int) ($params['id'] ?? 0);
        if (ProductRepository::find($productId) === null) {
            Flash::set('error', 'That product no longer exists.');
            header('Location: /products');
            return;
        }
        $quoteId = null;
        if (($_POST['purchase_quote_id'] ?? '') !== '') {
            $q = PricingRepository::purchaseQuote((int) $_POST['purchase_quote_id'], $productId);
            if ($q === null) {
                Flash::set('error', 'That supplier quote was not found for this product.');
                header("Location: /products/{$productId}/market-prices");
                return;
            }
            $quoteId = (int) $q['id'];
            $_POST['supplier_name'] = $q['supplier_legal_name'];
            $_POST['price_inr'] = number_format((float) $q['unit_price_inr'], 2, '.', '');
            $_POST['quoted_on'] = substr((string) $q['recorded_at'], 0, 10);
            $_POST['valid_until'] = (string) ($q['valid_until'] ?? '');
            $_POST['notes'] = 'Copied from supplier quote' . (!empty($q['quote_reference']) ? ' ' . $q['quote_reference'] : '') . ' (Procurement).';
        }
        [$data, $error] = self::readMarketPrice();
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /products/{$productId}/market-prices");
            return;
        }
        $data['product_id'] = $productId;
        $userId = (int) self::user()['id'];
        $id = ProductSupplierRepository::create($data, $userId);
        PricingRepository::setSupplierDates($id, $data['quoted_on'], $data['valid_until'], $quoteId);
        if ($data['is_primary'] === 1) {
            (new ProductService())->setPrimarySupplier($productId, $id, $userId);
        }
        AuditLogRepository::log($userId, 'MARKET_PRICE_ADDED', 'product_suppliers', $id, null, null,
            $data['supplier_name'] . ': ₹' . number_format((float) $data['fob_value'], 2) . ' quoted ' . $data['quoted_on'] . ($data['is_primary'] ? ' (used for pricing)' : ''));
        Flash::set('success', 'Supplier price recorded' . ($data['is_primary'] ? ' and used for pricing.' : '.'));
        header("Location: /products/{$productId}/market-prices");
    }

    public function useForPricing(array $params): void
    {
        $supplierId = (int) ($params['supplierId'] ?? 0);
        $s = ProductSupplierRepository::find($supplierId);
        if ($s === null) {
            Flash::set('error', 'That supplier price no longer exists.');
            header('Location: /products');
            return;
        }
        $userId = (int) self::user()['id'];
        $productId = (int) $s['product_id'];
        $old = PricingService::pricingSupplier($productId);
        (new ProductService())->setPrimarySupplier($productId, $supplierId, $userId);
        AuditLogRepository::log($userId, 'MARKET_PRICE_USED_FOR_PRICING', 'product_suppliers', $supplierId, 'is_primary', $old !== null ? $old['supplier_name'] . ' #' . $old['id'] : null, $s['supplier_name'] . ' #' . $supplierId);
        Flash::set('success', "{$s['supplier_name']}'s price is now used for pricing. New quotes use it.");
        header("Location: /products/{$productId}/market-prices");
    }

    public function updateMarketDates(array $params): void
    {
        $supplierId = (int) ($params['supplierId'] ?? 0);
        $s = ProductSupplierRepository::find($supplierId);
        if ($s === null) {
            Flash::set('error', 'That supplier price no longer exists.');
            header('Location: /products');
            return;
        }
        $productId = (int) $s['product_id'];
        $_POST['supplier_name'] = $s['supplier_name'];
        $_POST['price_inr'] = $_POST['price_inr'] ?? '';
        [$data, $error] = self::readMarketPrice();
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /products/{$productId}/market-prices");
            return;
        }
        $userId = (int) self::user()['id'];
        $before = '₹' . number_format((float) (new ProductService())->effectiveFobForSupplier($s, ProductRepository::find($productId) ?? []), 2) . ' quoted ' . (PricingService::priceDate($s) ?? '—');
        $s['fob_source'] = 'direct';
        $s['fob_value'] = $data['fob_value'];
        $s['notes'] = $data['notes'];
        $s['location'] = $data['location'] ?? $s['location'];
        ProductSupplierRepository::update($supplierId, $s, $userId);
        PricingRepository::setSupplierDates($supplierId, $data['quoted_on'], $data['valid_until']);
        AuditLogRepository::log($userId, 'MARKET_PRICE_CHANGED', 'product_suppliers', $supplierId, null,
 $before, '₹' . number_format((float) $data['fob_value'], 2) . ' quoted ' . $data['quoted_on']);
        Flash::set('success', 'Supplier price updated.');
        header("Location: /products/{$productId}/market-prices");
    }
}
