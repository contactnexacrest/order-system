<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\SpecRepository;

/**
 * Owner decision D-300 (point 12, 11 Nov 2026): one "Price rules" page per
 * category (Products › Categories › <category> › Price rules, also in the
 * Products menu) holds every rule used to work out a supplier's prices:
 *
 *   - the thickness %s (the category's Thickness options, e.g. 30 mm +35 %);
 *   - × K: 50 mm and above per CBM = the base (20 mm) price per m² × K;
 *   - "+ Add a thickness" (e.g. 18 mm −5 %);
 *   - what one container holds (CBM, maximum tonnes) and the stone weight
 *     (tonnes per CBM — also used for Ton prices and the weight limit).
 *
 * Only the MD (manage_pricing) changes them, with a reason; every change is
 * in the Audit Log. A product can have its own values ("own rule" badge).
 * Node twin: src/services/priceRules.js.
 */
final class PriceRules
{
    /**
     * The category's rules as the page shows them.
     *
     * @return array{category: array<string,mixed>, spec: ?array, thickness: list<array<string,mixed>>, k: ?float,
     *               container_cbm: ?float, container_max_t: ?float, weight: ?float, cbm_from: int, base_mm: int}
     */
    public static function forCategory(int $categoryId): array
    {
        $category = ProductCategoryRepository::find($categoryId) ?? [];
        $cbmFrom = (int) PricingService::settings()['cbm_from_mm'];
        $spec = null;
        foreach (SpecRepository::categorySpecs($categoryId, true) as $s) {
            if ($s['kind'] === 'thickness') {
                $spec = $s;
                break;
            }
        }
        $rows = [];
        $baseMm = 20;
        if ($spec !== null) {
            foreach (SpecRepository::optionsFor([(int) $spec['id']])[(int) $spec['id']] ?? [] as $o) {
                if ($o['product_id'] !== null || (int) $o['is_active'] !== 1 || $o['thickness_mm'] === null || (int) $o['thickness_mm'] >= $cbmFrom
                    || !in_array($o['change_type'], ['none', 'pct'], true)) {
                    continue;
                }
                $rows[] = ['id' => (int) $o['id'], 'name' => (string) $o['name'], 'mm' => (int) $o['thickness_mm'],
                    'pct' => $o['change_type'] === 'pct' ? (float) $o['value'] : 0.0, 'base' => (int) $o['is_base'] === 1];
                if ((int) $o['is_base'] === 1) {
                    $baseMm = (int) $o['thickness_mm'];
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['mm'] <=> $b['mm']);
        $num = static fn ($v): ?float => $v === null || $v === '' ? null : (float) $v;
        return [
            'category' => $category, 'spec' => $spec, 'thickness' => $rows, 'cbm_from' => $cbmFrom, 'base_mm' => $baseMm,
            'k' => $num($category['thick_k_factor'] ?? null),
            'container_cbm' => $num($category['container_cbm'] ?? null),
            'container_max_t' => $num($category['container_max_t'] ?? null),
            'weight' => $num($category['weight_t_per_cbm'] ?? null),
            // D-361 (mockup p6): the category's own margin, other costs and buffer (empty = global),
            // and the global values shown beside every empty field.
            'margin_pct' => $num($category['margin_pct'] ?? null),
            'other_cost_pct' => $num($category['other_cost_pct'] ?? null),
            'buffer_pct' => $num($category['buffer_pct'] ?? null),
            'global' => PricingService::settings(),
        ];
    }

    /**
     * Saves the page: thickness %s, a new thickness, × K, container and weight — with a
     * reason, each change in the Audit Log. Returns [number of changes, error].
     *
     * @return array{0: int, 1: ?string}
     */
    public static function save(int $categoryId, array $post, int $userId): array
    {
        $r = self::forCategory($categoryId);
        if ($r['category'] === []) {
            return [0, 'That category no longer exists.'];
        }
        $reason = trim((string) ($post['reason'] ?? ''));
        if (($reasonError = \App\Helpers\ReasonValidator::check($reason)) !== null) {
            return [0, 'Reason for the change: ' . $reasonError];
        }
        $pctIn = is_array($post['pct'] ?? null) ? $post['pct'] : [];
        $pctChanges = [];
        foreach ($r['thickness'] as $t) {
            if ($t['base'] || !array_key_exists((string) $t['id'], $pctIn) && !array_key_exists($t['id'], $pctIn)) {
                continue;
            }
            $v = trim((string) ($pctIn[$t['id']] ?? $pctIn[(string) $t['id']]));
            if (!preg_match('/^-?\d{1,3}(\.\d{1,2})?$/', $v) || (float) $v <= -100 || (float) $v > 500) {
                return [0, "{$t['name']}: enter the % compared with {$r['base_mm']} mm (e.g. 35 or -5), above −100 and at most 500, with at most 2 decimals."];
            }
            if (abs((float) $v - $t['pct']) > 1e-9) {
                $pctChanges[] = [$t, (float) $v];
            }
        }
        $num = static function (string $key, float $min, float $max, int $dec, string $label) use ($post): array {
            $v = trim((string) ($post[$key] ?? ''));
            if ($v === '') {
                return [null, null];
            }
            if (!preg_match('/^\d{1,6}(\.\d{1,' . $dec . '})?$/', $v) || (float) $v < $min || (float) $v > $max) {
                return [null, "{$label}: enter a number from {$min} to {$max} with at most {$dec} decimals, or leave it empty."];
            }
            return [(float) $v, null];
        };
        [$k, $e1] = $num('thick_k_factor', 1, 1000, 3, '50 mm and above: 20 mm price per SQM ×');
        [$cbm, $e2] = $num('container_cbm', 0.5, 100, 2, 'One container holds (CBM)');
        [$maxT, $e3] = $num('container_max_t', 1, 100, 2, 'Container maximum (tonnes)');
        [$weight, $e4] = $num('weight_t_per_cbm', 0.1, 20, 3, 'Stone weight (tonnes per CBM)');
        // D-361: the category's own %s — empty = follow the global value.
        [$margin, $e5] = $num('margin_pct', 0, 89.99, 2, 'Margin %');
        [$other, $e6] = $num('other_cost_pct', 0, 100, 2, 'Other costs %');
        [$buffer, $e7] = $num('buffer_pct', 0, 100, 2, 'Buffer %');
        foreach ([$e1, $e2, $e3, $e4, $e5, $e6, $e7] as $e) {
            if ($e !== null) {
                return [0, $e];
            }
        }
        $newMm = trim((string) ($post['new_mm'] ?? ''));
        $newPct = trim((string) ($post['new_change'] ?? ''));
        $add = null;
        if ($newMm !== '' || $newPct !== '') {
            if ($r['spec'] === null) {
                return [0, 'This category has no Thickness specification yet — add one under "Options and their price effect" first.'];
            }
            $mm = filter_var($newMm, FILTER_VALIDATE_INT);
            if ($mm === false || $mm < 1 || $mm >= $r['cbm_from']) {
                return [0, "New thickness: whole millimetres from 1 to " . ($r['cbm_from'] - 1) . " (from {$r['cbm_from']} mm the price is per CBM)."];
            }
            foreach ($r['thickness'] as $t) {
                if ($t['mm'] === $mm) {
                    return [0, "{$mm} mm is already in the list — change its % there."];
                }
            }
            if (!preg_match('/^-?\d{1,3}(\.\d{1,2})?$/', $newPct) || (float) $newPct <= -100 || (float) $newPct > 500 || (float) $newPct == 0.0) {
                return [0, "New thickness: enter its % compared with {$r['base_mm']} mm (e.g. -5 or 20), not 0."];
            }
            $add = ['mm' => $mm, 'pct' => (float) $newPct];
        }
        $changes = 0;
        Database::transaction(static function () use ($categoryId, $r, $pctChanges, $k, $cbm, $maxT, $weight, $margin, $other, $buffer, $add, $reason, $userId, &$changes): void {
            foreach ($pctChanges as [$t, $v]) {
                $opt = SpecRepository::findOption($t['id']);
                SpecRepository::updateOption($t['id'], ['name' => $opt['name'], 'is_base' => (int) $opt['is_base'], 'change_type' => 'pct',
                    'value' => number_format($v, 4, '.', ''), 'thickness_mm' => $opt['thickness_mm']], $userId);
                AuditLogRepository::log($userId, 'PRICE_RULE_CHANGED', 'spec_options', $t['id'], $t['name'] . ' %', ThicknessPrices::fmt($t['pct']) . '%', ThicknessPrices::fmt($v) . '%', $reason);
                $changes++;
            }
            $fields = ['thick_k_factor' => [$r['k'], $k, '× K (50 mm and above per CBM)'], 'container_cbm' => [$r['container_cbm'], $cbm, 'Container CBM'],
                'container_max_t' => [$r['container_max_t'], $maxT, 'Container maximum tonnes'], 'weight_t_per_cbm' => [$r['weight'], $weight, 'Stone weight t per CBM'],
                'margin_pct' => [$r['margin_pct'], $margin, 'Margin %'], 'other_cost_pct' => [$r['other_cost_pct'], $other, 'Other costs %'], 'buffer_pct' => [$r['buffer_pct'], $buffer, 'Buffer %']];
            foreach ($fields as $col => [$old, $new, $label]) {
                if (($old === null) !== ($new === null) || ($old !== null && abs($old - $new) > 1e-9)) {
                    Database::connection()->prepare("UPDATE product_categories SET {$col} = :v WHERE id = :id")->execute(['v' => $new, 'id' => $categoryId]);
                    AuditLogRepository::log($userId, 'PRICE_RULE_CHANGED', 'product_categories', $categoryId, $col, $old === null ? '—' : ThicknessPrices::fmt($old), $new === null ? '—' : ThicknessPrices::fmt($new), $reason);
                    $changes++;
                }
            }
            if ($add !== null) {
                $id = SpecRepository::createOption(['spec_id' => (int) $r['spec']['id'], 'product_id' => null, 'name' => $add['mm'] . ' mm', 'is_base' => 0,
                    'change_type' => 'pct', 'value' => number_format($add['pct'], 4, '.', ''), 'thickness_mm' => $add['mm'], 'sort_order' => $add['mm']], $userId);
                AuditLogRepository::log($userId, 'PRICE_RULE_ADDED', 'spec_options', $id, 'thickness', null, $add['mm'] . ' mm ' . ThicknessPrices::fmt($add['pct']) . '%', $reason);
                $changes++;
            }
        });
        return [$changes, null];
    }

    /** "30 mm = 20 mm + 35 % · 40 mm = 20 mm + 70 % · 50 mm+ per CBM = 20 mm × 40 · container 8 CBM / 27 t" */
    public static function summary(array $rules): string
    {
        $parts = [];
        foreach ($rules['rows'] as $row) {
            if ($row['mm'] === $rules['base_mm']) {
                continue;
            }
            $parts[] = $row['mm'] . ' mm = ' . $rules['base_mm'] . ' mm ' . ($row['pct'] < 0 ? '− ' : '+ ') . ThicknessPrices::fmt(abs($row['pct'])) . ' %';
        }
        if ($rules['k'] !== null) {
            $parts[] = $rules['cbm_from'] . ' mm+ per CBM = ' . $rules['base_mm'] . ' mm × ' . ThicknessPrices::fmt((float) $rules['k']);
        }
        $parts[] = 'container ' . ThicknessPrices::fmt((float) $rules['container_cbm']) . ' CBM / ' . ThicknessPrices::fmt((float) $rules['container_max_t']) . ' t';
        return implode(' · ', $parts);
    }
}
