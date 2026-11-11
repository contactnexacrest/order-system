<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;

/**
 * Owner decision D-245, phase P2 (4 Nov 2026): prices on order lines.
 *
 * A goods line picked from Products is checked against the floor of whoever
 * sells it: the staff floor, or — when the order's client has an associate
 * (and the order is one the associate earns on) — that associate's floor at
 * their commission level. Below the floor the line needs the MD's approval,
 * which may go down to the minimum price and never below it; until then the
 * Proforma Invoice cannot be issued. When the PI is issued the figures are
 * fixed on the line (price_snapshot), so later changes to the settings or the
 * supplier price do not move that order's check. An approval holds only while
 * the line keeps the approved price.
 *
 * Lines typed by hand (not picked from Products), service and charge lines,
 * lines without a price yet (To Be Confirmed) and orders not in USD are not
 * checked — prices are built in USD per unit (FOB).
 *
 * Node twin: src/services/orderLinePricing.js.
 */
final class OrderLinePricing
{
    public const OK = 'ok';
    public const BELOW = 'below';
    public const BELOW_MINIMUM = 'below_minimum';
    public const NO_PRICE = 'no_price';
    public const APPROVED = 'approved';
    public const UNCHECKED = 'unchecked';
    /** D-270 (point 25): the line's unit needs a piece size to be compared — asked for, never "too low". */
    public const NEEDS_SIZE = 'needs_size';

    /** Statuses that keep the Proforma Invoice back. */
    public const BLOCKING = [self::BELOW, self::BELOW_MINIMUM, self::NO_PRICE, self::NEEDS_SIZE];

    /**
     * Who sells this order: the client's associate (when the order is one the
     * associate earns on) at their commission level, else staff.
     *
     * @return array{associate: ?array, level: ?array, key: string, label: string, ladder_count: ?int, usd: bool}
     */
    public static function context(array $order): array
    {
        $ctx = ['associate' => null, 'level' => null, 'key' => 'staff', 'label' => 'Staff', 'ladder_count' => null,
            'usd' => strtoupper((string) ($order['currency_code'] ?? '')) === 'USD'];
        $client = !empty($order['client_id']) ? ClientRepository::find((int) $order['client_id']) : null;
        if ($client === null || empty($client['associate_id'])) {
            return $ctx;
        }
        $associate = AssociateRepository::find((int) $client['associate_id']);
        if ($associate === null || !self::associateEarnsOn($order)) {
            return $ctx;
        }
        $orderId = isset($order['id']) ? (int) $order['id'] : null;
        [$level, $count] = self::levelForAssociate($associate, $orderId);
        $ctx['associate'] = $associate;
        $ctx['ladder_count'] = $count;
        if ($level !== null) {
            $ctx['level'] = $level;
            $ctx['key'] = 'L' . (int) $level['id'];
            $ctx['label'] = (string) $associate['name'] . ' (' . PricingService::levelLabel($level) . ')';
        }
        return $ctx;
    }

    /** The order is one the client's associate earns on: forced "yes", or within the first-order window and not forced "no". */
    private static function associateEarnsOn(array $order): bool
    {
        $mode = 'auto';
        if (!empty($order['id'])) {
            $mode = (string) (IncentiveRepository::forOrder((int) $order['id'])['associate_mode'] ?? 'auto');
        }
        if ($mode === 'no') {
            return false;
        }
        if ($mode === 'yes' || empty($order['id'])) {
            return true;
        }
        return IncentiveService::withinFirstTenDays($order);
    }

    /**
     * The associate's commission level: the level fixed on the order when its PI was
     * issued, else their fixed level, or the ladder step for their next counting
     * order (D-245 P3: counting orders replace the P2 stand-in).
     *
     * @return array{0: ?array, 1: ?int} [level, counting orders so far (ladder only)]
     */
    public static function levelForAssociate(array $associate, ?int $excludeOrderId): array
    {
        if ($excludeOrderId !== null) {
            $order = OrderRepository::find($excludeOrderId);
            $fixed = $order !== null && (int) ($order['associate_id'] ?? 0) === (int) $associate['id'] ? AssociateCommission::fixedLevel($order) : null;
            if ($fixed !== null) {
                $level = $fixed['id'] !== null ? PricingRepository::findLevel((int) $fixed['id']) : null;
                // The level as fixed (its value then), even if the level was changed or deactivated since.
                return [array_merge($level ?? [], ['id' => $fixed['id'], 'name' => $fixed['name'], 'kind' => $fixed['kind'], 'value' => $fixed['value']]), null];
            }
        }
        return AssociateCommission::levelForAssociate($associate, $excludeOrderId);
    }

    /**
     * Live figures for a product in this order's context, or ['missing' => reason].
     *
     * @return array<string,mixed>
     */
    public static function liveFigures(array $product, array $ctx, ?array $selection = null, ?float $lineThicknessMm = null): array
    {
        // D-255: with the line's chosen specification options (null = the base product).
        // D-270: and the line's own thickness (a "50 mm and above" option priced per CBM).
        $p = PricingService::forProduct($product, [], null, null, $selection, $lineThicknessMm);
        if ($p['build'] === null) {
            return ['missing' => (string) $p['missing'], 'manual' => (bool) $p['manual']];
        }
        $b = $p['build'];
        $floor = $b['staff_floor'];
        $quote = $b['staff_quote'];
        if ($ctx['level'] !== null) {
            $a = PricingEngine::associate($b['raw'], (string) $ctx['level']['kind'], (float) $ctx['level']['value']);
            $floor = $a['floor'];
            $quote = $a['quote'];
        }
        return [
            'cost' => $b['cost'], 'minimum' => $b['minimum'], 'reserve' => $b['reserve'],
            'staff_floor' => $b['staff_floor'], 'staff_quote' => $b['staff_quote'],
            'floor' => $floor, 'quote' => $quote,
            'sold_by' => $ctx['key'], 'sold_by_label' => $ctx['label'],
            'associate_id' => $ctx['associate'] !== null ? (int) $ctx['associate']['id'] : null,
            'level_id' => $ctx['level'] !== null ? (int) $ctx['level']['id'] : null,
            'level_name' => $ctx['level'] !== null ? (string) $ctx['level']['name'] : null,
            'level_kind' => $ctx['level'] !== null ? (string) $ctx['level']['kind'] : null,
            'level_value' => $ctx['level'] !== null ? (float) $ctx['level']['value'] : null,
            'margin_pct' => $p['effective']['margin_pct'], 'buffer_pct' => $p['effective']['buffer_pct'],
            'other_mode' => $p['effective']['other_mode'], 'other_value' => $p['effective']['other_value'],
            'incentive_mode' => $p['effective']['incentive_mode'], 'incentive_value' => $p['effective']['incentive_value'],
            'program_on' => $p['program_on'], 'fx_rate' => $p['settings']['pricing_fx_rate'],
            'supplier_inr' => $p['supplier_inr'] ?? null,
            'supplier_id' => isset($p['supplier']['id']) ? (int) $p['supplier']['id'] : null,
            'unit' => $p['unit'], 'spec_text' => $p['spec']['text'] ?? null, 'spec_map' => $p['spec']['map'] ?? null,
            // D-270: what the conversion of a line in another unit needs.
            'thickness_mm' => $p['spec']['adj']['thickness_mm'] ?? null, 'weight_t_per_cbm' => self::weightPerCbm($product),
        ];
    }

    /** D-270 (point 25): tonnes per CBM — the product's own figure, else its category's (stone 2.7); null when neither is set. */
    public static function weightPerCbm(array $product): ?float
    {
        if (isset($product['weight_t_per_cbm']) && $product['weight_t_per_cbm'] !== null && (float) $product['weight_t_per_cbm'] > 0) {
            return (float) $product['weight_t_per_cbm'];
        }
        $categoryId = ProductSpecs::categoryIdFor($product);
        if ($categoryId === null) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT weight_t_per_cbm FROM product_categories WHERE id = :id');
        $stmt->execute(['id' => $categoryId]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null && (float) $v > 0 ? (float) $v : null;
    }

    /** D-270: the line's piece size in cm, [length, width, thickness] (null = not given). */
    public static function lineSize(array $line): array
    {
        return [LineUnits::size($line['piece_length_cm'] ?? null), LineUnits::size($line['piece_width_cm'] ?? null), LineUnits::size($line['piece_thickness_cm'] ?? null)];
    }

    /** D-270: the thickness option chosen on a line, in mm (null = none, or one without a thickness). */
    public static function optionThicknessMm(array $product, ?array $selection): ?float
    {
        if ($selection === null) {
            return null;
        }
        $offered = ProductSpecs::offered($product);
        [$map] = ProductSpecs::normalize($offered, $selection);
        foreach ($offered as $s) {
            if ($s['kind'] !== 'thickness') {
                continue;
            }
            foreach ($s['options'] as $o) {
                if (($map[$s['id']] ?? null) === $o['id'] && $o['thickness_mm'] !== null) {
                    return (float) $o['thickness_mm'];
                }
            }
        }
        return null;
    }

    /**
     * D-270 (point 25): the Thickness (cm) box and the Thickness option stay in sync —
     * a thickness typed in cm chooses the option of that thickness, or, from the
     * product's per-CBM thickness (50 mm) up, its "priced per CBM" option.
     *
     * @return array{0: ?array, 1: ?string} [the selection, or the refusal beside the field]
     */
    public static function syncThickness(?int $catalogProductId, ?array $selection, ?string $thicknessCm): array
    {
        if ($catalogProductId === null || $thicknessCm === null || $thicknessCm === '') {
            return [$selection, null];
        }
        $product = ProductRepository::find($catalogProductId);
        if ($product === null) {
            return [$selection, null];
        }
        $mm = round((float) $thicknessCm * 10, 1);
        $cbmFrom = (int) ($product['cbm_from_mm'] ?? 0) > 0 ? (int) $product['cbm_from_mm'] : PricingService::settings()['cbm_from_mm'];
        foreach (ProductSpecs::offered($product) as $s) {
            if ($s['kind'] !== 'thickness') {
                continue;
            }
            $exact = null;
            $perCbm = null;
            foreach ($s['options'] as $o) {
                if ($o['thickness_mm'] !== null && abs((float) $o['thickness_mm'] - $mm) < 0.05) {
                    $exact = $o['id'];
                }
                $isCbm = $o['change_type'] === 'cbm' || ($o['thickness_mm'] !== null && $o['thickness_mm'] >= $cbmFrom);
                if ($isCbm && ($perCbm === null || $o['thickness_mm'] === null)) {
                    $perCbm = $o['id'];
                }
            }
            $pick = $exact ?? ($mm >= $cbmFrom ? $perCbm : null);
            if ($pick === null) {
                $listed = [];
                foreach ($s['options'] as $o) {
                    if ($o['thickness_mm'] !== null && $o['thickness_mm'] < $cbmFrom) {
                        $listed[] = LineUnits::num($o['thickness_mm'] / 10);
                    }
                }
                return [$selection, 'There is no ' . LineUnits::num($mm) . ' mm ' . $s['name'] . ' option for this product — enter '
                    . ($listed !== [] ? implode(', ', $listed) . ' cm, or ' : '') . LineUnits::num($cbmFrom / 10) . ' cm or more (priced per CBM).'];
            }
            $selection ??= [];
            $selection[(int) $s['id']] = (int) $pick;
            ksort($selection);
            return [$selection, null];
        }
        return [$selection, null];
    }

    /**
     * D-270 (point 25): how the line's unit compares with the unit of its figures.
     *
     * @return array{factor: ?float, missing: list<string>, unconvertible: bool, line_unit: string, ref_unit: string, message: ?string}
     */
    public static function conversionFor(array $line, array $figures, ?array $product = null): array
    {
        [$l, $w, $t] = self::lineSize($line);
        $refUnit = (string) ($figures['unit'] ?? '');
        $lineUnit = (string) ($line['unit'] ?? '');
        $density = isset($figures['weight_t_per_cbm']) ? LineUnits::size($figures['weight_t_per_cbm']) : null;
        $optionMm = isset($figures['thickness_mm']) ? LineUnits::size($figures['thickness_mm']) : null;
        $quick = LineUnits::factor($lineUnit, $refUnit, $l, $w, $t ?? ($optionMm !== null ? $optionMm / 10 : null), $density);
        if ($quick['factor'] === null && !$quick['unconvertible'] && $product === null && !empty($line['catalog_product_id'])) {
            $product = ProductRepository::find((int) $line['catalog_product_id']);
        }
        if ($quick['factor'] === null && !$quick['unconvertible'] && $product !== null) {
            // Figures fixed before point 25 (PI, price request) do not carry these: take them from the product.
            $density ??= self::weightPerCbm($product);
            $optionMm ??= self::optionThicknessMm($product, self::lineSelection($line));
            $quick = LineUnits::factor($lineUnit, $refUnit, $l, $w, $t ?? ($optionMm !== null ? $optionMm / 10 : null), $density);
        }
        $message = null;
        if ($quick['unconvertible']) {
            $message = 'A price per ' . LineUnits::label($lineUnit) . ' cannot be converted to the product\'s price per ' . LineUnits::label($refUnit) . ' — needs MD approval.';
        } elseif ($quick['missing'] !== []) {
            $message = LineUnits::missingMessage($quick['missing'][0], $lineUnit, $refUnit);
        }
        return $quick + ['line_unit' => $lineUnit, 'ref_unit' => $refUnit, 'message' => $message];
    }

    /** D-270: the figures per the line's unit (reference figures × factor). */
    public static function scaleFigures(array $figures, float $factor, string $lineUnit): array
    {
        foreach (['cost', 'minimum', 'reserve', 'staff_floor', 'staff_quote', 'floor', 'quote'] as $k) {
            if (isset($figures[$k]) && $figures[$k] !== null) {
                $figures[$k] = (float) $figures[$k] * $factor;
            }
        }
        if (abs($factor - 1.0) > 1e-9 && $lineUnit !== '') {
            $figures['unit'] = $lineUnit;
        }
        return $figures;
    }

    /**
     * D-270 (point 13): a line's chosen options as labels for the order page — every option,
     * in the product's order; one switched off since is shown as gone, never replaced.
     *
     * @return list<array{name: string, gone: bool}>
     */
    public static function optionChips(array $line, ?array $product = null): array
    {
        $selection = self::lineSelection($line);
        if ($selection === null || empty($line['catalog_product_id'])) {
            return [];
        }
        $product ??= ProductRepository::find((int) $line['catalog_product_id']);
        if ($product === null) {
            return [];
        }
        $chips = [];
        $seen = [];
        foreach (ProductSpecs::offered($product) as $s) {
            if (!array_key_exists($s['id'], $selection)) {
                continue;
            }
            $seen[$s['id']] = true;
            $found = null;
            foreach ($s['options'] as $o) {
                if ($o['id'] === $selection[$s['id']]) {
                    $found = $o;
                }
            }
            $chips[] = $found !== null ? ['name' => (string) $found['name'], 'gone' => false] : self::goneChip($selection[$s['id']]);
        }
        foreach ($selection as $specId => $optionId) {
            if (!isset($seen[$specId])) {
                $chips[] = self::goneChip($optionId);
            }
        }
        return $chips;
    }

    /** @return array{name: string, gone: bool} */
    private static function goneChip(int $optionId): array
    {
        $o = \App\Repositories\SpecRepository::findOption($optionId);
        return ['name' => '"' . ($o !== null ? (string) $o['name'] : 'This option') . '" is no longer offered — choose again', 'gone' => true];
    }

    /** D-255: a line's chosen options from the form (JSON {spec id: option id}); null when none. */
    public static function selectionInput(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $data = $raw;
        } else {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return null;
            }
            $data = json_decode($raw, true);
        }
        if (!is_array($data) || $data === []) {
            return null;
        }
        $out = [];
        foreach ($data as $spec => $option) {
            if (filter_var($spec, FILTER_VALIDATE_INT) !== false && filter_var($option, FILTER_VALIDATE_INT) !== false) {
                $out[(int) $spec] = (int) $option;
            }
        }
        ksort($out);
        return $out === [] ? null : $out;
    }

    /** D-255: stores a line's chosen options and the price request it came from. */
    public static function saveSelection(int $lineId, ?array $selection, ?int $priceRequestId): void
    {
        Database::connection()->prepare('UPDATE order_products SET spec_selection = :s, price_request_id = :pr WHERE id = :id')
            ->execute(['s' => $selection !== null ? json_encode((object) $selection) : null, 'pr' => $priceRequestId, 'id' => $lineId]);
    }

    /** D-255: a line's options as stored (null = none). */
    public static function lineSelection(array $line): ?array
    {
        return self::selectionInput((string) ($line['spec_selection'] ?? ''));
    }

    /** D-255: the figures of the price request a line came from, while it still follows it. */
    public static function priceRequestFigures(array $line): ?array
    {
        if (empty($line['price_request_id'])) {
            return null;
        }
        $r = PriceRequestService::find((int) $line['price_request_id']);
        $f = $r !== null && !empty($r['figures']) ? json_decode((string) $r['figures'], true) : null;
        if (!is_array($f)) {
            return null;
        }
        $f['minimum'] ??= $f['floor'];
        $f['unit'] ??= $r['unit'] ?? null;
        $f['price_request'] = $r['number'];
        return $f;
    }

    /**
     * D-255: the quote and floor for a product with chosen options, for the order form
     * (only the seller's own two prices — never cost).
     *
     * @return array<string,mixed>
     */
    public static function lineQuote(array $product, array $ctx, ?array $selection, ?string $unit = null, array $size = []): array
    {
        // D-270 (point 25): a typed thickness chooses the Thickness option, as on save.
        [$synced, $syncError] = self::syncThickness((int) $product['id'], $selection, $size['piece_thickness_cm'] ?? null);
        if ($syncError !== null) {
            return ['ok' => false, 'reason' => $syncError, 'field' => 'piece_thickness_cm'];
        }
        $selection = $synced;
        $t = LineUnits::size($size['piece_thickness_cm'] ?? null);
        $f = self::liveFigures($product, $ctx, $selection, $t !== null ? $t * 10 : null);
        if (isset($f['missing'])) {
            return ['ok' => false, 'reason' => $f['missing'], 'manual' => !empty($f['manual'])];
        }
        $out = ['ok' => true, 'quote' => $f['quote'], 'floor' => $f['floor'], 'unit' => $f['unit'], 'spec_text' => $f['spec_text'],
            'selection' => $selection, 'ref_unit' => $f['unit'], 'ref_quote' => $f['quote'], 'ref_floor' => $f['floor'], 'factor' => 1.0];
        if ($unit !== null && $unit !== '') {
            $conv = self::conversionFor(['unit' => $unit] + $size + ['spec_selection' => $selection !== null ? json_encode((object) $selection) : null], $f, $product);
            if ($conv['factor'] === null) {
                return ['ok' => false, 'reason' => (string) $conv['message'], 'field' => $conv['missing'][0] ?? null, 'unconvertible' => $conv['unconvertible'],
                    'ref_unit' => $f['unit'], 'ref_quote' => $f['quote'], 'ref_floor' => $f['floor'], 'selection' => $selection];
            }
            $out['factor'] = $conv['factor'];
            $out['quote'] = round($f['quote'] * $conv['factor'], 2);
            $out['floor'] = round($f['floor'] * $conv['factor'], 2);
            $out['unit'] = abs($conv['factor'] - 1.0) > 1e-9 ? $unit : $f['unit'];
        }
        return $out;
    }

    /** Whether a line is in scope of the check at all, with the reason when not. */
    public static function scope(array $line, array $ctx): ?string
    {
        if (($line['line_type'] ?? 'goods') !== 'goods') {
            return 'Service and charge lines are not price-checked.';
        }
        if (empty($line['catalog_product_id'])) {
            return 'Typed by hand — pick it from Products for a price check.';
        }
        if (!$ctx['usd']) {
            return 'Prices are built in USD — no price check on orders in another currency.';
        }
        if (($line['unit_price'] ?? null) === null || $line['unit_price'] === '') {
            return 'No price yet.';
        }
        return null;
    }

    public static function approvalHolds(array $line): bool
    {
        return !empty($line['price_approved_at']) && $line['price_approved_unit_price'] !== null
            && $line['unit_price'] !== null && abs((float) $line['unit_price'] - (float) $line['price_approved_unit_price']) < 0.005
            // Fresh-eyes review M4: and only for the options and unit it was given for.
            && (($line['price_approved_selection'] ?? null) === null || $line['price_approved_selection'] === self::approvalSelectionKey($line));
    }

    /** The line's options and unit as one text, kept with the MD's approval (fresh-eyes review M4). */
    public static function approvalSelectionKey(array $line): string
    {
        $key = trim((string) ($line['spec_selection'] ?? '')) . '|' . trim((string) ($line['unit'] ?? ''));
        // D-270: and the piece size, once one is given (a new size is a new price per piece).
        [$l, $w, $t] = self::lineSize($line);
        if ($l !== null || $w !== null || $t !== null) {
            $key .= '|' . implode('x', array_map(static fn (?float $v): string => $v !== null ? LineUnits::num($v) : '', [$l, $w, $t]));
        }
        return $key;
    }

    /**
     * The check of one line.
     *
     * @return array{status: string, figures: ?array, fixed: bool, note: ?string, approval: ?array, requested: bool}
     */
    public static function checkLine(array $line, array $ctx, ?array $product = null): array
    {
        $out = ['status' => self::UNCHECKED, 'figures' => null, 'fixed' => false, 'note' => null, 'approval' => null,
            'requested' => !empty($line['price_approval_requested_at']), 'reference' => null, 'conversion' => null];
        if (($reason = self::scope($line, $ctx)) !== null) {
            $out['note'] = $reason;
            return $out;
        }
        $snapshot = !empty($line['price_snapshot']) ? json_decode((string) $line['price_snapshot'], true) : null;
        if (is_array($snapshot)) {
            $figures = $snapshot;
            $out['fixed'] = true;
        } elseif (($prFigures = self::priceRequestFigures($line)) !== null) {
            // D-255: a line from a price request keeps the request's prices.
            $figures = $prFigures;
            $out['fixed'] = true;
        } else {
            $product ??= ProductRepository::find((int) $line['catalog_product_id']);
            $t = self::lineSize($line)[2];
            $figures = $product !== null ? self::liveFigures($product, $ctx, self::lineSelection($line), $t !== null ? $t * 10 : null) : ['missing' => 'The product is no longer in Products.'];
        }
        if (self::approvalHolds($line)) {
            $out['approval'] = [
                'by' => (int) $line['price_approved_by'], 'at' => (string) $line['price_approved_at'],
                'reason' => (string) $line['price_approval_reason'],
                'commission_zero' => (int) $line['price_approval_commission_zero'] === 1,
                'incentive_cut' => $line['price_approval_incentive_cut'] !== null ? (float) $line['price_approval_incentive_cut'] : null,
            ];
        }
        if (isset($figures['missing'])) {
            $out['note'] = 'No price set — needs MD approval. ' . $figures['missing'];
            $out['status'] = $out['approval'] !== null ? self::APPROVED : self::NO_PRICE;
            return $out;
        }
        // D-270 (point 25): the line's price is compared in the product's reference unit —
        // the figures are shown per the line's unit, with the reference figures beside them.
        $conv = self::conversionFor($line, $figures, $product);
        $out['reference'] = $figures;
        $out['conversion'] = $conv;
        if ($conv['factor'] === null) {
            $out['note'] = $conv['message'];
            $out['status'] = $out['approval'] !== null ? self::APPROVED : ($conv['unconvertible'] ? self::NO_PRICE : self::NEEDS_SIZE);
            return $out;
        }
        $figures = self::scaleFigures($figures, $conv['factor'], (string) ($line['unit'] ?? ''));
        $out['conversion']['price_ref'] = $conv['factor'] > 0 ? (float) $line['unit_price'] / $conv['factor'] : null;
        $out['figures'] = $figures;
        $price = (float) $line['unit_price'];
        if ($out['approval'] !== null) {
            $out['status'] = self::APPROVED;
        } elseif ($price < (float) $figures['minimum'] - 0.0001) {
            $out['status'] = self::BELOW_MINIMUM;
        } elseif ($price < (float) $figures['floor'] - 0.0001) {
            $out['status'] = self::BELOW;
        } else {
            $out['status'] = self::OK;
        }
        return $out;
    }

    /**
     * Every line of the order with its check, and the lines keeping the PI back.
     *
     * @return array{ctx: array, lines: array<int, array>, blocking: list<array>}
     */
    public static function forOrder(int $orderId): array
    {
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return ['ctx' => [], 'lines' => [], 'blocking' => []];
        }
        $ctx = self::context($order);
        $out = ['ctx' => $ctx, 'lines' => [], 'blocking' => []];
        foreach (OrderProductRepository::forOrder($orderId) as $line) {
            $check = self::checkLine($line, $ctx);
            $check['line'] = $line;
            $out['lines'][(int) $line['id']] = $check;
            if (in_array($check['status'], self::BLOCKING, true)) {
                $out['blocking'][] = $check;
            }
        }
        return $out;
    }

    /**
     * Owner decision D-273 (point 17): the summary bar above the products table —
     * "2 lines OK · 1 line needs MD approval (line 3) — the Quotation waits for it."
     * Null when no line is price-checked.
     *
     * @param array<int, array> $checks forOrder()['lines']
     * @return array{text: string, blocking: bool}|null
     */
    public static function summary(array $checks): ?array
    {
        $groups = ['ok' => [], 'md' => [], 'size' => [], 'minimum' => []];
        foreach ($checks as $c) {
            $no = (int) $c['line']['line_no'];
            match ($c['status']) {
                self::OK, self::APPROVED => $groups['ok'][] = $no,
                self::BELOW, self::NO_PRICE => $groups['md'][] = $no,
                self::NEEDS_SIZE => $groups['size'][] = $no,
                self::BELOW_MINIMUM => $groups['minimum'][] = $no,
                default => null,
            };
        }
        $n = static fn (int $k): string => $k . ' line' . ($k === 1 ? '' : 's');
        $which = static fn (array $nos): string => ' (line' . (count($nos) === 1 ? ' ' : 's ') . implode(', ', $nos) . ')';
        $parts = [];
        if ($groups['ok'] !== []) {
            $parts[] = $n(count($groups['ok'])) . ' OK';
        }
        if ($groups['md'] !== []) {
            $parts[] = $n(count($groups['md'])) . (count($groups['md']) === 1 ? ' needs' : ' need') . ' MD approval' . $which($groups['md']);
        }
        if ($groups['size'] !== []) {
            $parts[] = $n(count($groups['size'])) . (count($groups['size']) === 1 ? ' needs its' : ' need their') . ' piece size' . $which($groups['size']);
        }
        if ($groups['minimum'] !== []) {
            $parts[] = $n(count($groups['minimum'])) . ' below what NexaCrest can accept' . $which($groups['minimum']);
        }
        if ($parts === []) {
            return null;
        }
        $blocking = count($groups['md']) + count($groups['size']) + count($groups['minimum']);
        return ['text' => implode(' · ', $parts) . ($blocking > 0 ? ' — the Quotation waits for ' . ($blocking === 1 ? 'it.' : 'them.') : '.'), 'blocking' => $blocking > 0];
    }

    /**
     * D-273 (point 17): the totals row — goods value, CBM (from each line's unit and piece
     * size; lines whose size is not known are not counted) and containers of 8 CBM.
     *
     * @param list<array> $lines the order's lines
     * @param array<int, array> $checks forOrder()['lines'] (for the option thickness and weight per CBM)
     * @return array{amount: float, cbm: float, counted: int, uncounted: int, containers: int}
     */
    public static function totals(array $lines, array $checks): array
    {
        $out = ['amount' => 0.0, 'cbm' => 0.0, 'counted' => 0, 'uncounted' => 0, 'containers' => 0];
        foreach ($lines as $line) {
            if (($line['line_type'] ?? 'goods') !== 'goods') {
                continue;
            }
            $out['amount'] += (float) ($line['fob_value'] ?? 0);
            $qty = ($line['quantity'] ?? null) !== null && empty($line['quantity_is_tbc']) ? (float) $line['quantity'] : null;
            $ref = $checks[(int) $line['id']]['reference'] ?? null;
            [$l, $w, $t] = self::lineSize($line);
            if ($t === null && isset($ref['thickness_mm']) && $ref['thickness_mm'] !== null) {
                $t = (float) $ref['thickness_mm'] / 10;
            }
            $density = isset($ref['weight_t_per_cbm']) && $ref['weight_t_per_cbm'] !== null ? (float) $ref['weight_t_per_cbm'] : null;
            $per = LineUnits::cbmPerUnit($line['unit'] ?? null, $l, $w, $t, $density);
            if ($qty === null || $per === null) {
                $out['uncounted']++;
                continue;
            }
            $out['cbm'] += $qty * $per;
            $out['counted']++;
        }
        $out['amount'] = round($out['amount'], 2);
        $out['containers'] = LineUnits::containers($out['cbm']);
        $out['cbm'] = round($out['cbm'], 2);
        return $out;
    }

    /** Why the PI cannot be issued yet, or null when it can. */
    public static function piBlockReason(int $orderId): ?string
    {
        return self::documentBlockReason($orderId, 'PI');
    }

    /**
     * Owner decision D-245 P2: why the Quotation (QT) or the Proforma Invoice (PI)
     * cannot be generated yet — a line below its lowest price, below the minimum
     * price, or with no price set, not approved by the MD — or null when it can.
     */
    public static function documentBlockReason(int $orderId, string $typeCode): ?string
    {
        $first = self::forOrder($orderId)['blocking'][0] ?? null;
        if ($first === null) {
            return null;
        }
        $doc = $typeCode === 'QT' ? 'the Quotation' : 'the Proforma Invoice';
        $no = (int) $first['line']['line_no'];
        return match ($first['status']) {
            self::NEEDS_SIZE => "Line {$no} needs its piece size to check its price — " . lcfirst((string) $first['note']) . " Then generate {$doc} (order page → Products).",
            self::NO_PRICE => "Line {$no} has no price set for its product — the MD must approve it before {$doc} (order page → Products).",
            self::BELOW_MINIMUM => "Line {$no} is below the price NexaCrest can accept — raise it before {$doc} (order page → Products).",
            default => "Line {$no} is below the lowest price for this order — raise it, or ask the MD to approve it, before {$doc} (order page → Products).",
        };
    }

    /** Owner decision D-245 P2: whether goods lines on this currency must be picked from Products (Admin › Pricing). */
    public static function productPickRequired(?string $currencyCode): bool
    {
        return strtoupper((string) $currencyCode) === 'USD' && PricingService::settings()['require_product_pick'];
    }

    /** The refusal for a goods line typed by hand when picking from Products is required, else null. */
    public static function pickRefusal(?string $currencyCode, ?int $catalogProductId, string $lineType): ?string
    {
        if ($lineType !== 'goods' || $catalogProductId !== null || !self::productPickRequired($currencyCode)) {
            return null;
        }
        return self::PICK_MESSAGE;
    }

    public const PICK_MESSAGE = 'Pick this goods line from Products — on a USD order goods lines cannot be typed by hand (service and charge lines can). The MD can change this in Admin → Pricing.';

    /**
     * Refuses a price below the product's minimum price before it is saved
     * (nobody, not even the MD, goes below it). Null when the price may be saved.
     */
    public static function saveRefusal(array $order, ?int $catalogProductId, string $lineType, ?string $unitPrice, bool $seesBuildup,
        ?array $selection = null, ?string $unit = null, ?array $priceRequest = null, array $size = []): ?string
    {
        if ($catalogProductId === null || $lineType !== 'goods') {
            return null;
        }
        $ctx = self::context($order);
        if (!$ctx['usd']) {
            return null;
        }
        $product = ProductRepository::find($catalogProductId);
        if ($product === null) {
            return null;
        }
        if ($selection !== null) {
            [, $errors] = ProductSpecs::normalize(ProductSpecs::offered($product), $selection);
            if ($errors !== []) {
                return implode(' ', $errors);
            }
        }
        // D-270 (point 25): any unit of the category — the old "50 mm and above: set the unit to CBM"
        // refusal is gone; the price is converted to the product's reference unit instead.
        if ($priceRequest !== null && !empty($priceRequest['figures'])) {
            $f = json_decode((string) $priceRequest['figures'], true);
            $f['minimum'] ??= $f['floor'] ?? 0;
            $f['unit'] ??= $priceRequest['unit'] ?? null;
        } else {
            $t = LineUnits::size($size['piece_thickness_cm'] ?? null);
            $f = self::liveFigures($product, $ctx, $selection, $t !== null ? $t * 10 : null);
        }
        if (isset($f['missing'])) {
            return null;
        }
        $conv = self::conversionFor(['unit' => $unit, 'catalog_product_id' => $catalogProductId,
            'spec_selection' => $selection !== null ? json_encode((object) $selection) : null] + $size, $f, $product);
        // A missing size is asked for beside the field — never called "too low", never sent to the MD.
        if ($conv['factor'] === null && !$conv['unconvertible']) {
            return (string) $conv['message'];
        }
        if ($unitPrice === null || $unitPrice === '' || $conv['factor'] === null) {
            return null; // no price yet, or a unit that cannot be converted (Set, Lot …): the MD approves it
        }
        $minimum = (float) $f['minimum'] * $conv['factor'];
        if ((float) $unitPrice >= $minimum - 0.0001) {
            return null;
        }
        $per = abs($conv['factor'] - 1.0) > 1e-9;
        return 'This price is below what NexaCrest can accept for ' . $product['name'] . ' — raise it.'
            . ($seesBuildup ? ' Minimum price: USD ' . number_format($minimum, 2) . ($per ? ' per ' . LineUnits::label($unit) . ' (USD ' . number_format((float) $f['minimum'], 2) . ' per ' . LineUnits::label((string) $f['unit']) . ')' : '')
                . '; to go lower, change the product\'s margin (Products → pricing).' : '');
    }

    /**
     * After a line is saved: a changed price voids its approval; a changed product
     * clears its fixed figures; a line now below its floor (or without a price for
     * its product) is sent to the MD for approval.
     */
    public static function afterSave(int $lineId, ?array $before, int $userId): void
    {
        $line = OrderProductRepository::find($lineId);
        if ($line === null) {
            return;
        }
        $pdo = Database::connection();
        $orderId = (int) $line['order_id'];
        // Fresh-eyes review M4: a change of the line's options or unit is a different product for
        // pricing — like a product change it clears the fixed figures and voids the approval.
        $optionsChanged = $before !== null && self::approvalSelectionKey($before) !== self::approvalSelectionKey($line);
        if ($before !== null && ((int) ($before['catalog_product_id'] ?? 0) !== (int) ($line['catalog_product_id'] ?? 0) || $optionsChanged) && !empty($line['price_snapshot'])) {
            $pdo->prepare('UPDATE order_products SET price_snapshot = NULL WHERE id = :id')->execute(['id' => $lineId]);
            $line['price_snapshot'] = null;
        }
        if (!empty($line['price_approved_at']) && (!self::approvalHolds($line) || $optionsChanged)) {
            $pdo->prepare('UPDATE order_products SET price_approved_by = NULL, price_approved_at = NULL, price_approval_reason = NULL, price_approved_unit_price = NULL, price_approved_selection = NULL,
                                  price_approval_commission_zero = 0, price_approval_incentive_cut = NULL WHERE id = :id')->execute(['id' => $lineId]);
            AuditLogRepository::log($userId, 'LINE_PRICE_APPROVAL_VOIDED', 'order_products', $lineId, 'unit_price',
                number_format((float) $line['price_approved_unit_price'], 2, '.', ''), (string) $line['unit_price'],
                $optionsChanged && $line['unit_price'] !== null && abs((float) $line['unit_price'] - (float) $line['price_approved_unit_price']) < 0.005
                    ? "Order line {$line['line_no']}: the options or unit changed after the MD approved it — the approval no longer holds."
                    : "Order line {$line['line_no']}: the price changed after the MD approved it — the approval no longer holds.");
            $line = OrderProductRepository::find($lineId) ?? $line;
        }
        $order = OrderRepository::find($orderId);
        $check = self::checkLine($line, self::context($order ?? []));
        // D-270: a line still missing its size is asked for it on the page — not sent to the MD.
        if (in_array($check['status'], [self::BELOW, self::BELOW_MINIMUM, self::NO_PRICE], true)) {
            self::requestApproval($orderId, $lineId, $userId, true);
        } elseif (!empty($line['price_approval_requested_at'])) {
            $pdo->prepare('UPDATE order_products SET price_approval_requested_at = NULL, price_approval_requested_by = NULL WHERE id = :id')->execute(['id' => $lineId]);
        }
    }

    /**
     * Sends a line to the MD for approval (in-app notification to everyone who
     * manages pricing). Null on success, else the reason it was not sent.
     */
    public static function requestApproval(int $orderId, int $lineId, int $userId, bool $automatic = false): ?string
    {
        $line = OrderProductRepository::find($lineId);
        $order = OrderRepository::find($orderId);
        if ($line === null || $order === null || (int) $line['order_id'] !== $orderId) {
            return 'Order line not found.';
        }
        $check = self::checkLine($line, self::context($order));
        if (!in_array($check['status'], [self::BELOW, self::NO_PRICE], true)) {
            return $check['status'] === self::BELOW_MINIMUM
                ? 'This price is below what NexaCrest can accept — raise it; the MD cannot approve it either.'
                : 'Line ' . (int) $line['line_no'] . ' does not need an approval.';
        }
        if (!empty($line['price_approval_requested_at']) && $automatic) {
            return null;
        }
        Database::connection()->prepare('UPDATE order_products SET price_approval_requested_at = NOW(), price_approval_requested_by = :u WHERE id = :id')
            ->execute(['u' => $userId, 'id' => $lineId]);
        $ref = (string) ($order['order_reference'] ?? "#{$orderId}");
        $message = "Order {$ref}, line {$line['line_no']} ({$line['description']}) needs your price approval: "
            . ($check['status'] === self::NO_PRICE ? 'its product has no price set.' : 'it is below the lowest price for this order.');
        foreach (PermissionRepository::usersWithPermission('manage_pricing') as $mdId) {
            NotificationRepository::create((int) $mdId, null, 'line_price_approval', $orderId, mb_substr($message, 0, 500)); // D-280: one e-mail per approval
        }
        AuditLogRepository::log($userId, 'LINE_PRICE_APPROVAL_REQUESTED', 'order_products', $lineId, null, null, $message);
        return null;
    }

    /**
     * What approving this line would mean, for the MD's approval screen.
     *
     * @return array<string,mixed>
     */
    public static function approvalView(int $orderId, int $lineId): ?array
    {
        $order = OrderRepository::find($orderId);
        $line = OrderProductRepository::find($lineId);
        if ($order === null || $line === null || (int) $line['order_id'] !== $orderId) {
            return null;
        }
        $ctx = self::context($order);
        $check = self::checkLine($line, $ctx);
        $view = ['order' => $order, 'line' => $line, 'ctx' => $ctx, 'check' => $check, 'effect' => null];
        $f = $check['figures'];
        if ($f !== null) {
            $price = (float) $line['unit_price'];
            $qty = $line['quantity'] !== null ? (float) $line['quantity'] : null;
            $reservePerUnit = (float) $f['reserve'];
            $cutPerUnit = min($reservePerUnit, max(0.0, (float) $f['staff_floor'] - $price));
            $view['effect'] = [
                'margin_pct' => $price > 0 ? round(($price - (float) $f['cost']) / $price * 100, 1) : null,
                'short_per_unit' => round(max(0.0, (float) $f['floor'] - $price), 2),
                'reserve_per_unit' => $reservePerUnit,
                'cut_per_unit' => round($cutPerUnit, 2),
                'qty' => $qty,
                'reserve_line' => $qty !== null ? round($reservePerUnit * $qty, 2) : null,
                'after_line' => $qty !== null ? round(($reservePerUnit - $cutPerUnit) * $qty, 2) : null,
                'cut_line' => $qty !== null ? round($cutPerUnit * $qty, 2) : null,
                'commission_zero' => $ctx['associate'] !== null,
            ];
        }
        return $view;
    }

    /** The MD approves a line's price. Null on success, else the refusal text. */
    public static function approve(int $orderId, int $lineId, int $userId, string $reason): ?string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return 'Give a reason of at least 10 characters for approving this price.';
        }
        if (mb_strlen($reason) > 500) {
            return 'Keep the reason under 500 characters.';
        }
        $view = self::approvalView($orderId, $lineId);
        if ($view === null) {
            return 'Order line not found.';
        }
        $line = $view['line'];
        $status = $view['check']['status'];
        $no = (int) $line['line_no'];
        if ($status === self::BELOW_MINIMUM) {
            $f = $view['check']['figures'];
            AuditLogRepository::log($userId, 'LINE_PRICE_APPROVAL_REFUSED', 'order_products', $lineId, 'unit_price', null, (string) $line['unit_price'],
                "Order line {$no}: USD " . number_format((float) $line['unit_price'], 2) . ' is below the minimum price USD ' . number_format((float) $f['minimum'], 2) . ' — not approvable.');
            return "Line {$no}: USD " . number_format((float) $line['unit_price'], 2) . ' is below the minimum price USD ' . number_format((float) $f['minimum'], 2)
                . ' — nobody can approve it, not even the MD. To sell lower, change the product\'s margin (Products → pricing; logged).';
        }
        if ($status === self::NEEDS_SIZE) {
            return "Line {$no} needs its piece size first — " . lcfirst((string) $view['check']['note']);
        }
        if (!in_array($status, [self::BELOW, self::NO_PRICE], true)) {
            return "Line {$no} does not need an approval.";
        }
        if ((int) ($line['price_set_by'] ?? 0) === $userId && !MakerCheckerGuard::selfApprovalAllowed('price_discount', $userId)) {
            return 'You set this price yourself — Admin → Self-approval rules do not let your role approve its own price.';
        }
        $effect = $view['effect'];
        Database::connection()->prepare(
            'UPDATE order_products SET price_approved_by = :u, price_approved_at = NOW(), price_approval_reason = :r, price_approved_unit_price = :p, price_approved_selection = :sel,
                    price_approval_commission_zero = :cz, price_approval_incentive_cut = :ic, price_approval_requested_at = NULL, price_approval_requested_by = NULL
             WHERE id = :id'
        )->execute([
            'u' => $userId, 'r' => $reason, 'p' => number_format((float) $line['unit_price'], 2, '.', ''), 'sel' => self::approvalSelectionKey($line),
            'cz' => ($effect['commission_zero'] ?? false) ? 1 : 0,
            'ic' => isset($effect['cut_line']) ? number_format((float) $effect['cut_line'], 2, '.', '') : null,
            'id' => $lineId,
        ]);
        $what = $status === self::NO_PRICE ? 'no price set for its product' : 'USD ' . number_format((float) $line['unit_price'], 2) . ' below the floor USD ' . number_format((float) $view['check']['figures']['floor'], 2);
        AuditLogRepository::log($userId, 'LINE_PRICE_APPROVED', 'order_products', $lineId, 'unit_price', null, (string) $line['unit_price'],
            "Order line {$no}: {$what} — reason: {$reason}");
        if ((int) ($line['price_set_by'] ?? 0) === $userId) {
            AuditLogRepository::log($userId, 'LINE_PRICE_SELF_APPROVED', 'order_products', $lineId, null, null, 'Self-approval allowed by Admin → Self-approval rules (price_discount).');
        }
        return null;
    }

    /** The MD refuses a line's price: the request is closed and whoever asked is told why. Null on success. */
    public static function refuse(int $orderId, int $lineId, int $userId, string $reason): ?string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return 'Give a reason of at least 10 characters for refusing this price.';
        }
        $view = self::approvalView($orderId, $lineId);
        if ($view === null) {
            return 'Order line not found.';
        }
        $line = $view['line'];
        Database::connection()->prepare('UPDATE order_products SET price_approval_requested_at = NULL, price_approval_requested_by = NULL WHERE id = :id')->execute(['id' => $lineId]);
        $message = 'Order ' . ($view['order']['order_reference'] ?? "#{$orderId}") . ', line ' . (int) $line['line_no'] . ': the MD refused USD '
            . number_format((float) $line['unit_price'], 2) . ' — ' . $reason . ' Raise the price; the Proforma Invoice waits for it.';
        if (!empty($line['price_approval_requested_by']) && (int) $line['price_approval_requested_by'] !== $userId) {
            NotificationRepository::createInAppOnly((int) $line['price_approval_requested_by'], 'line_price_refused', $orderId, mb_substr($message, 0, 500));
        }
        AuditLogRepository::log($userId, 'LINE_PRICE_APPROVAL_REFUSED', 'order_products', $lineId, 'unit_price', null, (string) $line['unit_price'], $message);
        return null;
    }

    /**
     * Fixes the figures on every checked line that has none yet — called when the
     * PI is issued. Lines keep them until their product changes.
     */
    public static function snapshotForPi(int $orderId): void
    {
        // D-245 P3: the associate's commission level is fixed on the order with the PI, first,
        // so the line figures fixed below use that same level.
        AssociateCommission::fixLevelAtPi($orderId);
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return;
        }
        $ctx = self::context($order);
        $stmt = Database::connection()->prepare('UPDATE order_products SET price_snapshot = :s WHERE id = :id AND price_snapshot IS NULL');
        foreach (OrderProductRepository::forOrder($orderId) as $line) {
            if (!empty($line['price_snapshot']) || self::scope($line, $ctx) !== null) {
                continue;
            }
            $product = ProductRepository::find((int) $line['catalog_product_id']);
            if ($product === null) {
                continue;
            }
            // D-255: a line from a price request fixes the request's prices; else the line's options.
            $t = self::lineSize($line)[2];
            $f = self::priceRequestFigures($line) ?? self::liveFigures($product, $ctx, self::lineSelection($line), $t !== null ? $t * 10 : null);
            if (isset($f['missing'])) {
                continue;
            }
            $f['fixed_at'] = date('Y-m-d H:i:s');
            $stmt->execute(['s' => json_encode($f, JSON_PRESERVE_ZERO_FRACTION), 'id' => (int) $line['id']]);
        }
    }

    /**
     * The quote price and floor of every picker product, per seller ("staff" and
     * "L<level id>" for each active level) — what the browser fills in. Only for
     * people who may see selling prices.
     *
     * @return array<int, array<string, array{0: float, 1: float}>>
     */
    public static function pickerPrices(array $pickerProducts): array
    {
        $levels = PricingRepository::levels(true);
        $out = [];
        foreach ($pickerProducts as $pp) {
            $product = ProductRepository::find((int) $pp['id']);
            if ($product === null) {
                continue;
            }
            $p = PricingService::forProduct($product, $levels);
            if ($p['build'] === null) {
                continue;
            }
            $prices = ['staff' => [$p['build']['staff_quote'], $p['build']['staff_floor']]];
            foreach ($p['levels'] as $l) {
                $prices['L' . $l['id']] = [$l['quote'], $l['floor']];
            }
            $out[(int) $pp['id']] = $prices;
        }
        return $out;
    }

    /**
     * D-255: each picker product's specifications and options — names only (no values,
     * no cost), and whether an option is priced per CBM — for the order form's option selects.
     *
     * @return array<int, list<array{id:int, name:string, options: list<array{id:int, name:string, base:bool, cbm:bool}>}>>
     */
    public static function pickerSpecs(array $pickerProducts): array
    {
        $cbmFrom = PricingService::settings()['cbm_from_mm'];
        $out = [];
        foreach ($pickerProducts as $pp) {
            $product = ProductRepository::find((int) $pp['id']);
            if ($product === null) {
                continue;
            }
            $from = (int) ($product['cbm_from_mm'] ?? 0) > 0 ? (int) $product['cbm_from_mm'] : $cbmFrom;
            $specs = [];
            foreach (ProductSpecs::offered($product) as $s) {
                $specs[] = ['id' => $s['id'], 'name' => $s['name'], 'thickness' => $s['kind'] === 'thickness', 'cbm_from' => $from, 'options' => array_map(static fn (array $o): array => [
                    'id' => $o['id'], 'name' => $o['name'], 'base' => $o['is_base'],
                    'cbm' => $s['kind'] === 'thickness' && ($o['change_type'] === 'cbm' || ($o['thickness_mm'] !== null && $o['thickness_mm'] >= $from)),
                    'mm' => $s['kind'] === 'thickness' ? $o['thickness_mm'] : null,
                ], $s['options'])];
            }
            if ($specs !== []) {
                $out[(int) $pp['id']] = $specs;
            }
        }
        return $out;
    }

    /**
     * For the New Order form: each client's seller key ("staff" or "L<level id>").
     *
     * @return array<int, string>
     */
    public static function clientKeys(array $clients): array
    {
        $byAssociate = [];
        $out = [];
        foreach ($clients as $c) {
            $key = 'staff';
            if (!empty($c['associate_id'])) {
                $aid = (int) $c['associate_id'];
                if (!array_key_exists($aid, $byAssociate)) {
                    $associate = AssociateRepository::find($aid);
                    $byAssociate[$aid] = 'staff';
                    if ($associate !== null) {
                        [$level] = self::levelForAssociate($associate, null);
                        $byAssociate[$aid] = $level !== null ? 'L' . (int) $level['id'] : 'staff';
                    }
                }
                $key = $byAssociate[$aid];
            }
            $out[(int) $c['id']] = $key;
        }
        return $out;
    }
}
