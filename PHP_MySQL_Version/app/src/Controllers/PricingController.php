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
use App\Services\ThicknessPrices;
use App\Repositories\SupplierRepository;

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
            // D-301 (point 4): the exchange rate in use, its source and date, and the history.
            // D-340: with what the "Rate used for prices right now" box and the field badges say.
            'fx' => \App\Services\FxRateService::status(),
            'fxHistory' => \App\Services\FxRateService::history(30),
            'fxSources' => \App\Services\FxRateService::SOURCES,
        ], 'layout/base');
    }

    /** D-301: the MD's OK (or not) for a market rate that moved more than 2 % in a day. */
    public function decideFxRate(array $params): void
    {
        $approve = (string) ($_POST['decision'] ?? '') === 'approve';
        $error = \App\Services\FxRateService::decide((int) ($params['id'] ?? 0), $approve, trim((string) ($_POST['reason'] ?? '')), (int) self::user()['id']);
        Flash::set($error === null ? 'success' : 'error', $error ?? ($approve ? 'Rate approved — new quotes use it from now on. Issued Quotations and Proforma Invoices keep their prices.' : 'Rate not used — prices keep the rate in use.'));
        header('Location: /pricing/settings#exchange-rate');
    }

    /** D-301: fetch today's rate now (the same as the daily job). */
    public function fetchFxRate(array $params): void
    {
        $r = \App\Services\FxRateService::fetchDaily();
        AuditLogRepository::log((int) self::user()['id'], 'FX_RATE_FETCHED', 'fx_rates', null, null, null, $r['message']);
        Flash::set($r['status'] === 'failed' ? 'error' : 'success', $r['message']);
        header('Location: /pricing/settings#exchange-rate');
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
        Flash::set('success', $changed === 0 ? 'Nothing changed.' : 'Global pricing settings saved. New quotes use them; orders with an issued Proforma Invoice keep their prices.');
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
        $rules = ThicknessPrices::rules($product, $settings);
        $rows = [];
        foreach (ProductSupplierRepository::forProduct($id) as $s) {
            $basis = ($s['price_basis'] ?? 'unit') === 'cbm' || ($s['combo_per'] ?? '') === 'cbm' ? 'cbm' : 'unit';
            $s['fob'] = PricingService::fobParts($product, $s, $basis, null, $settings, $rules);
            $s['effective_fob'] = ($s['price_basis'] ?? '') === 'grid' ? ($s['fob']['supplier'] ?? null) : (new ProductService())->effectiveFobForSupplier($s, $product);
            // D-335: what is stored (typed / calculated), the base, and one price card per supplier row.
            $s['grid'] = ($s['price_basis'] ?? '') === 'grid' ? ThicknessPrices::values($rules, ThicknessPrices::storedFor((int) $s['id']), ThicknessPrices::autoFill($s)) : null;
            $s['card'] = $s['grid'] !== null ? self::priceCard($product, $s, $rules, $settings, $s['grid']) : null;
            $s['staleness'] = PricingService::staleness($s, $settings['stale_days']);
            $s['price_date'] = PricingService::priceDate($s);
            $rows[] = $s;
        }
        // Newest first; the ones used for pricing on top.
        $used = static fn (array $r): int => max((int) $r['is_primary'], (int) ($r['is_cbm_primary'] ?? 0), (int) ($r['combo_in_use'] ?? 0));
        usort($rows, static fn (array $a, array $b): int => [$used($b), (string) $b['price_date']] <=> [$used($a), (string) $a['price_date']]);
        // D-256: exact prices name a combination of options.
        $offered = \App\Services\ProductSpecs::offered($product);
        $named = PricingRepository::comboOptions(array_map(static fn (array $r): int => (int) $r['id'], array_filter($rows, static fn (array $r): bool => ($r['price_basis'] ?? '') === 'combo')));
        foreach ($rows as &$r) {
            $r['combo_text'] = ($r['price_basis'] ?? '') === 'combo' ? PricingService::comboText($offered, $named[(int) $r['id']] ?? []) : null;
        }
        unset($r);
        $prefill = null;
        if (($_GET['from_quote'] ?? '') !== '' && ($q = PricingRepository::purchaseQuote((int) $_GET['from_quote'], $id)) !== null) {
            $prefill = ['purchase_quote_id' => (int) $q['id'], 'supplier_id' => (int) $q['supplier_id'], 'supplier_name' => (string) $q['supplier_legal_name'],
                'price_inr' => number_format((float) $q['unit_price_inr'], 2, '.', ''),
                'quoted_on' => substr((string) $q['recorded_at'], 0, 10), 'valid_until' => (string) ($q['valid_until'] ?? '')];
        }
        // D-303: a supplier just added quickly is picked already.
        if ($prefill === null && ($_GET['supplier_id'] ?? '') !== '' && ($sup = SupplierRepository::find((int) $_GET['supplier_id'])) !== null) {
            $prefill = ['supplier_id' => (int) $sup['id'], 'supplier_name' => (string) $sup['supplier_legal_name']];
        }
        View::render('products/market_prices', [
            'product' => $product,
            'rows' => $rows,
            'staleDays' => $settings['stale_days'],
            'cbmFrom' => $settings['cbm_from_mm'],
            'quotes' => PricingRepository::purchaseQuotesForProduct($id),
            'canRecord' => self::can('record_market_prices'),
            'offered' => $offered,
            'prefill' => $prefill,
            'rules' => $rules,
            'pickList' => SupplierRepository::pickList(),
            'supplierTypes' => array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('supplier_type')),
            'canQuickAdd' => self::can('record_market_prices') || self::can('manage_suppliers'),
            'canChangeRules' => self::can('manage_pricing'),
            'canSeeBuild' => self::can('view_price_buildup'),
        ], 'layout/base');
    }

    /**
     * D-335: one supplier price for every thickness as a card — every thickness with its price,
     * how it was set (TYPED / CALCULATED from … / no price), since when, the FOB cost incl. CHA
     * + transport in its own unit (per m², or per CBM for 50 mm and above), the BASE, and the
     * product price worked out from the base.
     *
     * @param array{ok: bool, values: array<int, array<string,mixed>>} $grid ThicknessPrices::values()
     * @return array<string,mixed>
     */
    private static function priceCard(array $product, array $row, array $rules, array $settings, array $grid): array
    {
        $cbmFrom = (int) $rules['cbm_from'];
        $baseMm = ThicknessPrices::baseOf($row, $rules);
        $cha = ThicknessPrices::chaPerCbm(ThicknessPrices::chaPerContainer($row, $product), (float) $rules['container_cbm'], $rules['container_max_t'], $rules['t_per_cbm']);
        $mms = ThicknessPrices::thicknesses($rules);
        foreach (array_keys($grid['values']) as $mm) {
            if (!in_array((int) $mm, $mms, true)) {
                $mms[] = (int) $mm;
            }
        }
        usort($mms, static fn (int $a, int $b): int => [$a === ThicknessPrices::CBM ? 1 : 0, $a] <=> [$b === ThicknessPrices::CBM ? 1 : 0, $b]);
        $pct = [];
        foreach ($rules['rows'] as $r) {
            $pct[(int) $r['mm']] = (float) $r['pct'];
        }
        $lines = [];
        $basePrice = null;
        foreach ($mms as $mm) {
            $v = $grid['values'][$mm] ?? null;
            $isCbm = $mm === ThicknessPrices::CBM;
            $chaU = $cha['per_cbm'] !== null ? ($isCbm ? $cha['per_cbm'] : $cha['per_cbm'] * $mm / 1000) : 0.0;
            $note = '';
            if ($v !== null && $v['how'] === 'calculated') {
                $from = $v['calc_from_mm'] ?? null;
                $fromLabel = $from !== null ? ThicknessPrices::label((int) $from, $cbmFrom) : ThicknessPrices::label((int) $rules['base_mm'], $cbmFrom);
                $change = $isCbm ? '× ' . ThicknessPrices::fmt((float) $rules['k'])
                    : (isset($pct[$mm]) && $mm !== (int) $rules['base_mm'] ? (($pct[$mm] < 0 ? '− ' : '+ ') . ThicknessPrices::fmt(abs($pct[$mm])) . ' %') : '');
                $note = 'from ' . $fromLabel . ($change !== '' && ($from === null || $from === (int) $rules['base_mm']) ? ' ' . $change : '') . (!empty($v['auto']) ? ' (worked out each time — older price)' : '');
            }
            $since = $v === null ? null : ($v['how'] === 'typed' ? ($v['quoted_on'] ?? $v['since'] ?? null) : ($v['since'] ?? null));
            $lines[] = [
                'mm' => $mm, 'label' => ThicknessPrices::label($mm, $cbmFrom), 'unit' => $isCbm ? 'per CBM' : 'per m²',
                'price' => $v !== null ? (float) $v['price'] : null, 'how' => $v['how'] ?? null, 'note' => $note, 'since' => $since,
                'cha' => $chaU, 'fob' => $v !== null ? (float) $v['price'] + $chaU : null, 'is_base' => $mm === $baseMm,
            ];
            if ($mm === $baseMm && $v !== null) {
                $basePrice = (float) $v['price'];
            }
        }
        $parts = PricingService::fobParts($product, $row, 'unit', null, $settings, $rules);
        $build = $parts !== null && self::can('view_price_buildup') ? PricingService::buildFromFob($product, (float) $parts['total'], $settings) : null;
        return [
            'lines' => $lines, 'base_mm' => $baseMm, 'base_label' => ThicknessPrices::label($baseMm, $cbmFrom), 'base_price' => $basePrice,
            'base_unit' => $baseMm === ThicknessPrices::CBM ? 'CBM' : 'm²', 'base_chosen' => ThicknessPrices::rowBase($row) !== null,
            'cha_per_cbm' => $cha['per_cbm'], 'fits_cbm' => $cha['fits_cbm'], 'cha_per_container' => ThicknessPrices::chaPerContainer($row, $product),
            'parts' => $parts, 'build' => $build, 'auto' => ThicknessPrices::autoFill($row),
            'unit_label' => $parts !== null ? (\App\Services\LineUnits::label((string) $parts['unit']) ?: (string) $parts['unit']) : '',
        ];
    }

    /**
     * D-335: reads "Add / update a price" — the thickness, its price, the date and the ticked
     * thicknesses to work out. Returns [mm, price, quoted_on, ticked, error].
     *
     * @return array{0: int, 1: float, 2: string, 3: list<int>, 4: ?string}
     */
    private static function readThicknessPrice(array $rules, string $dateField): array
    {
        $allowed = ThicknessPrices::thicknesses($rules);
        $rawMm = trim((string) ($_POST['thickness_mm'] ?? ''));
        $mm = $rawMm !== '' && ctype_digit($rawMm) ? (int) $rawMm : -1;
        if (!in_array($mm, $allowed, true)) {
            return [0, 0.0, '', [], 'Choose the thickness of the price.'];
        }
        $raw = trim(str_replace(',', '', (string) ($_POST['thickness_price'] ?? '')));
        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $raw) !== 1 || (float) $raw <= 0) {
            return [$mm, 0.0, '', [], 'Enter the price in ₹ (above 0, at most 2 decimals).'];
        }
        $q = trim((string) ($_POST[$dateField] ?? ''));
        $d = \DateTime::createFromFormat('!Y-m-d', $q);
        if ($d === false || $d->format('Y-m-d') !== $q) {
            return [$mm, (float) $raw, '', [], 'Enter the date the supplier quoted this price.'];
        }
        if ($q > date('Y-m-d')) {
            return [$mm, (float) $raw, '', [], 'The quoted date cannot be in the future.'];
        }
        $ticked = [];
        foreach ((array) ($_POST['also'] ?? []) as $a) {
            $a = (string) $a;
            if (ctype_digit($a) && in_array((int) $a, $allowed, true) && (int) $a !== $mm) {
                $ticked[] = (int) $a;
            }
        }
        return [$mm, (float) $raw, $q, array_values(array_unique($ticked)), null];
    }

    /** D-335: "20 mm ₹4,600.00 per m² typed; worked out: 15 mm, 18 mm" for the audit log. */
    private static function addText(array $rules, int $mm, float $price, array $ticked): string
    {
        $cbmFrom = (int) $rules['cbm_from'];
        return ThicknessPrices::label($mm, $cbmFrom) . ' ₹' . number_format($price, 2) . ($mm === ThicknessPrices::CBM ? ' per CBM' : ' per m²') . ' typed'
            . ($ticked !== [] ? '; worked out: ' . implode(', ', array_map(static fn (int $m): string => ThicknessPrices::label($m, $cbmFrom), $ticked)) : '');
    }

    /** D-335: "Add / update a price" on a supplier price for every thickness. */
    public function addThicknessPrice(array $params): void
    {
        $supplierId = (int) ($params['supplierId'] ?? 0);
        $s = ProductSupplierRepository::find($supplierId);
        $product = $s !== null ? ProductRepository::find((int) $s['product_id']) : null;
        if ($s === null || $product === null || ($s['price_basis'] ?? '') !== 'grid') {
            Flash::set('error', 'That supplier price no longer exists.');
            header('Location: /products');
            return;
        }
        $back = ($_POST['return_to'] ?? '') === 'edit' ? "/products/suppliers/{$supplierId}/price" : "/products/{$s['product_id']}/market-prices#price-card-{$supplierId}";
        $rules = ThicknessPrices::rules($product);
        [$mm, $price, $quotedOn, $ticked, $error] = self::readThicknessPrice($rules, 'price_quoted_on');
        if ($error === null) {
            $error = ThicknessPrices::addPrice($s, $rules, $mm, $price, $quotedOn, $ticked, ($_POST['base'] ?? 'keep') === 'this', (int) self::user()['id']);
        }
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: {$back}");
            return;
        }
        AuditLogRepository::log((int) self::user()['id'], 'MARKET_PRICE_CHANGED', 'product_suppliers', $supplierId, null, null,
            $s['supplier_name'] . ': ' . self::addText($rules, $mm, $price, $ticked) . ' quoted ' . $quotedOn . (($_POST['base'] ?? 'keep') === 'this' ? ' (made the base)' : ''));
        Flash::set('success', 'Price saved' . ($ticked !== [] ? ' and ' . count($ticked) . ' worked out from it' : '') . '.');
        header("Location: {$back}");
    }

    /** D-335: make one thickness of a supplier price the BASE for orders. */
    public function setBase(array $params): void
    {
        $supplierId = (int) ($params['supplierId'] ?? 0);
        $s = ProductSupplierRepository::find($supplierId);
        $product = $s !== null ? ProductRepository::find((int) $s['product_id']) : null;
        if ($s === null || $product === null || ($s['price_basis'] ?? '') !== 'grid') {
            Flash::set('error', 'That supplier price no longer exists.');
            header('Location: /products');
            return;
        }
        $rules = ThicknessPrices::rules($product);
        $raw = (string) ($_POST['mm'] ?? '');
        $mm = ctype_digit($raw) ? (int) $raw : -1;
        $error = $mm < 0 ? 'Choose the thickness to make the base.' : ThicknessPrices::setBase($s, $rules, $mm, (int) self::user()['id']);
        $back = ($_POST['return_to'] ?? '') === 'edit' ? "/products/suppliers/{$supplierId}/price" : "/products/{$s['product_id']}/market-prices#price-card-{$supplierId}";
        if ($error !== null) {
            Flash::set('error', $error);
        } else {
            AuditLogRepository::log((int) self::user()['id'], 'MARKET_PRICE_CHANGED', 'product_suppliers', $supplierId, 'base_thickness_mm', null, ThicknessPrices::label($mm, (int) $rules['cbm_from']));
            Flash::set('success', 'The base for orders is now ' . ThicknessPrices::label($mm, (int) $rules['cbm_from']) . '.');
        }
        header("Location: {$back}");
    }

    /**
     * D-303 (point 15): the supplier of a price is a record of the Suppliers directory —
     * picked by its id (the search-pick fills it), or by its exact name.
     *
     * @return array{0: ?array, 1: ?string}
     */
    private static function pickedSupplier(): array
    {
        $id = (int) ($_POST['supplier_id'] ?? 0);
        $s = $id > 0 ? SupplierRepository::find($id) : null;
        if ($s === null && trim((string) ($_POST['supplier_name'] ?? '')) !== '') {
            $s = SupplierRepository::findByExactName((string) $_POST['supplier_name']);
        }
        if ($s === null || (int) $s['is_active'] !== 1) {
            return [null, 'Pick the supplier from the Suppliers directory — or add it with "+ Add a new supplier (quick)".'];
        }
        return [$s, null];
    }

    /** @return array{0: array<string,mixed>, 1: ?string} */
    private static function readMarketPrice(bool $grid = false): array
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
            'fob_value' => $grid ? null : $num('price_inr'),
            'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => !empty($_POST['used_for_pricing']) ? 1 : 0,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'quoted_on' => $date('quoted_on'),
            'valid_until' => $date('valid_until'),
            // D-255: per unit, or per CBM for thick stone; D-256 an exact combination; D-300 every thickness.
            'price_basis' => in_array($_POST['price_basis'] ?? 'unit', ['cbm', 'combo', 'grid'], true) ? (string) $_POST['price_basis'] : 'unit',
            // D-300: packing and loading included (ticked by default); CHA + transport per container.
            'includes_packing_loading' => (string) ($_POST['includes_packing_loading'] ?? '1') === '1',
            'cha_transport_per_container' => $num('cha_transport_per_container'),
        ];
        if ($data['supplier_name'] === '' || mb_strlen($data['supplier_name']) > 255) {
            return [$data, 'Pick the supplier from the Suppliers directory.'];
        }
        if (!$grid) {
            $raw = trim((string) ($_POST['price_inr'] ?? ''));
            if ($data['fob_value'] === null || $data['fob_value'] <= 0 || $data['fob_value'] > 999999999999.99 || preg_match('/^\d+(\.\d{1,2})?$/', $raw) !== 1) {
                return [$data, 'Enter the supplier\'s price in ₹ (above 0, at most 2 decimals).'];
            }
        }
        $cha = trim((string) ($_POST['cha_transport_per_container'] ?? ''));
        if ($cha !== '' && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $cha) !== 1) {
            return [$data, 'CHA + transport per container: enter ₹ (at most 2 decimals), or leave it empty for the product\'s default.'];
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

    /**
     * D-256: the options an exact price names (combo[spec id] = option id; empty = any).
     *
     * @return array{0: array<int,int>, 1: string, 2: ?string} [named, per (unit|cbm), error]
     */
    private static function readCombo(array $product): array
    {
        $offered = \App\Services\ProductSpecs::offered($product);
        $raw = is_array($_POST['combo'] ?? null) ? $_POST['combo'] : [];
        $named = [];
        $per = 'unit';
        $settings = PricingService::settings();
        $cbmFrom = (int) ($product['cbm_from_mm'] ?? 0) > 0 ? (int) $product['cbm_from_mm'] : $settings['cbm_from_mm'];
        foreach ($offered as $sp) {
            $v = (string) ($raw[$sp['id']] ?? '');
            if ($v === '') {
                continue;
            }
            $found = null;
            foreach ($sp['options'] as $o) {
                if ((string) $o['id'] === $v) {
                    $found = $o;
                }
            }
            if ($found === null) {
                return [[], 'unit', "{$sp['name']}: that option is not offered for this product."];
            }
            $named[(int) $sp['id']] = (int) $found['id'];
            if ($sp['kind'] === 'thickness' && ($found['change_type'] === 'cbm' || ($found['thickness_mm'] !== null && $found['thickness_mm'] >= $cbmFrom))) {
                $per = 'cbm';
            }
        }
        if ($named === []) {
            return [[], 'unit', 'Choose at least one option the exact price is for (e.g. thickness and finish) — or record it as the base price.'];
        }
        return [$named, $per, null];
    }

    public function addMarketPrice(array $params): void
    {
        $productId = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($productId);
        if ($product === null) {
            Flash::set('error', 'That product no longer exists.');
            header('Location: /products');
            return;
        }
        $back = "/products/{$productId}/market-prices";
        $quoteId = null;
        if (($_POST['purchase_quote_id'] ?? '') !== '') {
            $q = PricingRepository::purchaseQuote((int) $_POST['purchase_quote_id'], $productId);
            if ($q === null) {
                Flash::set('error', 'That supplier quote was not found for this product.');
                header("Location: {$back}");
                return;
            }
            $quoteId = (int) $q['id'];
            $_POST['supplier_id'] = (string) $q['supplier_id'];
            $_POST['supplier_name'] = $q['supplier_legal_name'];
            $_POST['quoted_on'] = substr((string) $q['recorded_at'], 0, 10);
            $_POST['valid_until'] = (string) ($q['valid_until'] ?? '');
            $_POST['notes'] = 'Copied from supplier quote' . (!empty($q['quote_reference']) ? ' ' . $q['quote_reference'] : '') . ' (Procurement).';
            if (!isset($_POST['price']) && !isset($_POST['price_inr'])) {
                $_POST['price_inr'] = number_format((float) $q['unit_price_inr'], 2, '.', '');
            }
        }
        $settings = PricingService::settings();
        $rules = ThicknessPrices::rules($product, $settings);
        // D-300: for a category with thickness price rules the ordinary price is one row for every thickness.
        if (($_POST['price_basis'] ?? '') === '') {
            $_POST['price_basis'] = $rules['grid'] && !isset($_POST['price_inr']) ? 'grid' : 'unit';
        }
        $grid = $_POST['price_basis'] === 'grid';
        if ($grid && !$rules['grid']) {
            Flash::set('error', 'This product\'s category has no thickness price rules — enter one price.');
            header("Location: {$back}");
            return;
        }
        [$picked, $pickError] = self::pickedSupplier();
        if ($pickError !== null) {
            Flash::set('error', $pickError);
            header("Location: {$back}#add-price");
            return;
        }
        $_POST['supplier_name'] = (string) $picked['supplier_legal_name'];
        [$data, $error] = self::readMarketPrice($grid);
        $typed = [];
        // D-335: one thickness typed (+ the ticked ones worked out); the older grid form (price[mm]) still works.
        $newGrid = $grid && isset($_POST['thickness_mm']);
        $add = null;
        if ($error === null && $newGrid) {
            [$aMm, $aPrice, , $aTicked, $error] = self::readThicknessPrice($rules, 'quoted_on');
            $add = ['mm' => $aMm, 'price' => $aPrice, 'ticked' => $aTicked];
        } elseif ($error === null && $grid) {
            [$typed, $error] = ThicknessPrices::readForm($_POST, $rules);
        }
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: {$back}#add-price");
            return;
        }
        $data['product_id'] = $productId;
        $userId = (int) self::user()['id'];
        $usedForPricing = $data['is_primary'] === 1;
        // D-256: an exact price for a specific combination names one option for some specifications.
        $named = [];
        $per = 'unit';
        if ($data['price_basis'] === 'combo') {
            [$named, $per, $comboError] = self::readCombo($product);
            if ($comboError !== null) {
                Flash::set('error', $comboError);
                header("Location: {$back}");
                return;
            }
        }
        if (!in_array($data['price_basis'], ['unit', 'grid'], true)) {
            $data['is_primary'] = 0;
        }
        $id = ProductSupplierRepository::create($data, $userId);
        PricingRepository::setSupplierDates($id, $data['quoted_on'], $data['valid_until'], $quoteId);
        ProductSupplierRepository::setPriceDetails($id, (int) $picked['id'], $data['includes_packing_loading'], $data['cha_transport_per_container']);
        if ($data['price_basis'] === 'combo') {
            // An exact price is in use (or not) by itself: it never replaces the ordinary price.
            PricingRepository::setCombo($id, $named, $per, $usedForPricing);
        } else {
            PricingRepository::setSupplierBasis($id, $data['price_basis']);
            if ($add !== null) {
                $row = ProductSupplierRepository::find($id) ?? ['id' => $id];
                ThicknessPrices::addPrice($row, $rules, $add['mm'], $add['price'], (string) $data['quoted_on'], $add['ticked'], true, $userId);
            } elseif ($grid) {
                ThicknessPrices::saveTyped($id, $typed, $rules, $userId);
            }
            if ($usedForPricing && $data['price_basis'] === 'cbm') {
                PricingRepository::setCbmPrimary($productId, $id);
            } elseif ($usedForPricing) {
                (new ProductService())->setPrimarySupplier($productId, $id, $userId);
            }
        }
        $data['is_primary'] = $usedForPricing ? 1 : 0;
        $comboText = $data['price_basis'] === 'combo' ? PricingService::comboText(\App\Services\ProductSpecs::offered($product), $named) : null;
        $priceText = $add !== null ? self::addText($rules, $add['mm'], $add['price'], $add['ticked']) : ($grid ? self::gridText($typed, $rules) : '₹' . number_format((float) $data['fob_value'], 2));
        AuditLogRepository::log($userId, 'MARKET_PRICE_ADDED', 'product_suppliers', $id, null, null,
            $data['supplier_name'] . ': ' . $priceText . ($comboText !== null ? ' for ' . $comboText . ($per === 'cbm' ? ' per CBM' : '') : '') . ' quoted ' . $data['quoted_on'] . ($data['is_primary'] ? ' (used for pricing)' : ''));
        Flash::set('success', 'Supplier price recorded' . ($data['is_primary'] ? ' and used for pricing.' : '.'));
        header("Location: {$back}");
    }

    /** "20 mm ₹1,600.00 per m² · 50 mm and above ₹64,000.00 per CBM (typed; others calculated)" for the audit log. */
    private static function gridText(array $typed, array $rules): string
    {
        $parts = [];
        foreach ($typed as $mm => $v) {
            $parts[] = ThicknessPrices::label((int) $mm, (int) $rules['cbm_from']) . ' ₹' . number_format((float) $v, 2) . ((int) $mm === ThicknessPrices::CBM ? ' per CBM' : ' per m²');
        }
        return implode(' · ', $parts) . ' (typed; others calculated)';
    }

    /** D-300 (point 12): one supplier price — every thickness, CHA + transport per container, its history. */
    public function editPrice(array $params): void
    {
        $s = ProductSupplierRepository::find((int) ($params['supplierId'] ?? 0));
        $product = $s !== null ? ProductRepository::find((int) $s['product_id']) : null;
        if ($s === null || $product === null) {
            http_response_code(404);
            echo 'Supplier price not found.';
            return;
        }
        $settings = PricingService::settings();
        $rules = ThicknessPrices::rules($product, $settings);
        $grid = ($s['price_basis'] ?? '') === 'grid';
        View::render('products/supplier_price_edit', [
            'product' => $product, 'row' => $s, 'rules' => $rules,
            'grid' => $grid ? ($__g = ThicknessPrices::values($rules, ThicknessPrices::storedFor((int) $s['id']), ThicknessPrices::autoFill($s))) : null,
            'card' => $grid ? self::priceCard($product, $s, $rules, $settings, $__g) : null,
            'canSeeBuild' => self::can('view_price_buildup'),
            'fob' => PricingService::fobParts($product, $s, ($s['price_basis'] ?? '') === 'cbm' ? 'cbm' : 'unit', null, $settings, $rules),
            'history' => ThicknessPrices::historyFor((int) $s['id']),
            'pickList' => SupplierRepository::pickList(),
            'canRecord' => self::can('record_market_prices'),
            'canChangeRules' => self::can('manage_pricing'),
        ], 'layout/base');
    }

    public function savePrice(array $params): void
    {
        $supplierId = (int) ($params['supplierId'] ?? 0);
        $s = ProductSupplierRepository::find($supplierId);
        $product = $s !== null ? ProductRepository::find((int) $s['product_id']) : null;
        if ($s === null || $product === null) {
            Flash::set('error', 'That supplier price no longer exists.');
            header('Location: /products');
            return;
        }
        $productId = (int) $s['product_id'];
        $back = "/products/suppliers/{$supplierId}/price";
        $grid = ($s['price_basis'] ?? '') === 'grid';
        $rules = ThicknessPrices::rules($product);
        $_POST['price_basis'] = (string) $s['price_basis'];
        $_POST['supplier_name'] = (string) $s['supplier_name'];
        $picked = null;
        if ((int) ($_POST['supplier_id'] ?? 0) > 0 || ($s['supplier_id'] ?? null) === null) {
            [$picked, $pickError] = self::pickedSupplier();
            if ($pickError !== null) {
                Flash::set('error', $pickError);
                header("Location: {$back}");
                return;
            }
            $_POST['supplier_name'] = (string) $picked['supplier_legal_name'];
        }
        [$data, $error] = self::readMarketPrice($grid);
        $typed = [];
        // D-335: thickness prices change through "Add / update a price"; the older grid boxes (price[mm]) still work.
        $gridBoxes = $grid && is_array($_POST['price'] ?? null);
        if ($error === null && $gridBoxes) {
            [$typed, $error] = ThicknessPrices::readForm($_POST, $rules);
        }
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: {$back}");
            return;
        }
        $userId = (int) self::user()['id'];
        $oldPrice = $s['fob_value'] !== null ? number_format((float) $s['fob_value'], 2, '.', '') : null;
        $before = ($grid ? self::gridText(ThicknessPrices::typedFor($supplierId), $rules) : '₹' . number_format((float) (new ProductService())->effectiveFobForSupplier($s, $product), 2))
            . ' quoted ' . (PricingService::priceDate($s) ?? '—');
        $oldCha = $s['cha_transport_per_container'] !== null ? number_format((float) $s['cha_transport_per_container'], 2, '.', '') : null;
        $oldIncl = (int) ($s['includes_packing_loading'] ?? 1) === 1;
        $s['fob_source'] = 'direct';
        if (!$grid) {
            $s['fob_value'] = $data['fob_value'];
        }
        $s['notes'] = $data['notes'];
        $s['location'] = $data['location'] ?? $s['location'];
        $s['supplier_name'] = $data['supplier_name'];
        ProductSupplierRepository::update($supplierId, $s, $userId);
        PricingRepository::setSupplierDates($supplierId, $data['quoted_on'], $data['valid_until']);
        ProductSupplierRepository::setPriceDetails($supplierId, $picked !== null ? (int) $picked['id'] : (int) $s['supplier_id'],
            $data['includes_packing_loading'], $data['cha_transport_per_container']);
        // D-300: the price history — who, when, old → new.
        if ($gridBoxes) {
            ThicknessPrices::saveTyped($supplierId, $typed, $rules, $userId);
        } elseif ($grid) {
            // Nothing: the prices themselves are kept as they are.
        } elseif ($oldPrice !== number_format((float) $data['fob_value'], 2, '.', '')) {
            ThicknessPrices::history($supplierId, 'Price per ' . ((string) ($product['reference_unit'] ?? '') ?: 'unit'), $oldPrice, number_format((float) $data['fob_value'], 2, '.', ''), $userId);
        }
        $newCha = $data['cha_transport_per_container'] !== null ? number_format((float) $data['cha_transport_per_container'], 2, '.', '') : null;
        if ($oldCha !== $newCha) {
            ThicknessPrices::history($supplierId, 'CHA + transport per container', $oldCha ?? 'product default', $newCha ?? 'product default', $userId);
        }
        if ($oldIncl !== $data['includes_packing_loading']) {
            ThicknessPrices::history($supplierId, 'Price includes packing and loading', $oldIncl ? 'yes' : 'no', $data['includes_packing_loading'] ? 'yes' : 'no', $userId);
        }
        $after = ($grid ? self::gridText($gridBoxes ? $typed : ThicknessPrices::typedFor($supplierId), $rules) : '₹' . number_format((float) $data['fob_value'], 2)) . ' quoted ' . $data['quoted_on'];
        AuditLogRepository::log($userId, 'MARKET_PRICE_CHANGED', 'product_suppliers', $supplierId, null, $before, $after);
        Flash::set('success', 'Supplier price saved.');
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
        if (($s['price_basis'] ?? 'unit') === 'combo') {
            // D-256: an exact price is switched on or off by itself.
            $now = (int) ($s['combo_in_use'] ?? 0) !== 1;
            PricingRepository::setComboInUse($supplierId, $now);
            AuditLogRepository::log($userId, 'MARKET_PRICE_USED_FOR_PRICING', 'product_suppliers', $supplierId, 'combo_in_use', $now ? '0' : '1', $now ? '1' : '0');
            Flash::set('success', $now ? "{$s['supplier_name']}'s exact price is now used for matching quotes." : "{$s['supplier_name']}'s exact price is no longer used.");
            header("Location: /products/{$productId}/market-prices");
            return;
        }
        if (($s['price_basis'] ?? 'unit') === 'cbm') {
            // D-255: the per-CBM price used for thick stone (50 mm and above).
            $old = PricingService::cbmSupplier($productId);
            PricingRepository::setCbmPrimary($productId, $supplierId);
            AuditLogRepository::log($userId, 'MARKET_PRICE_USED_FOR_PRICING', 'product_suppliers', $supplierId, 'is_cbm_primary', $old !== null ? $old['supplier_name'] . ' #' . $old['id'] : null, $s['supplier_name'] . ' #' . $supplierId);
            Flash::set('success', "{$s['supplier_name']}'s per-CBM price is now used for thick stone. New quotes use it.");
            header("Location: /products/{$productId}/market-prices");
            return;
        }
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
