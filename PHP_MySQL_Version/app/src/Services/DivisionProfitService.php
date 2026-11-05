<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;

/**
 * Owner decision D-257 Phase 5 (5 Nov 2026): profit by division, with the
 * company's own costs shared out as overhead in proportion to each
 * division's revenue. Shown on the Order Profitability report only when two
 * or more divisions are active and the person is looking at "All
 * divisions" (company level); with one division the report stays exactly as
 * it was. CAFIN is not touched.
 *
 * Costs come from ca_expenses that are not linked to an order (an order's
 * own expenses are already in that order's cost): division_id empty = a
 * company-wide cost, shared by revenue; a division's own = charged to it
 * alone. Only ₹ expenses count. Port: divisionProfitService.js.
 */
final class DivisionProfitService
{
    private static function paise(float|int|string $v): int
    {
        return GstCalculator::scaled(is_float($v) ? number_format($v, 2, '.', '') : (string) $v, 2);
    }

    /**
     * @param list<array<string,mixed>> $rows the report's order rows (order_id, revenue_inr, profit_*)
     * @return array<string,mixed>|null null = nothing to show
     */
    public static function summary(array $rows, ?string $dateFrom, ?string $dateTo, bool $profitAfterIncentives): ?array
    {
        if (!DivisionService::isMulti() || DivisionService::currentIds() !== null) {
            return null;
        }
        $pdo = Database::connection();
        $divisionOf = [];
        $ids = array_map(static fn (array $r): int => (int) $r['order_id'], $rows);
        if ($ids !== []) {
            foreach ($pdo->query('SELECT id, division_id FROM orders WHERE id IN (' . implode(',', $ids) . ')')->fetchAll() as $o) {
                $divisionOf[(int) $o['id']] = (int) $o['division_id'];
            }
        }
        $divisions = [];
        foreach (DivisionService::active() as $d) {
            $divisions[(int) $d['id']] = ['code' => (string) $d['code'], 'name' => (string) $d['name'], 'revenue' => 0, 'profit' => 0, 'own' => 0, 'orders' => 0];
        }
        $profitKey = $profitAfterIncentives ? 'profit_after_incentive_inr' : 'profit_before_commission_inr';
        foreach ($rows as $r) {
            $div = $divisionOf[(int) $r['order_id']] ?? DivisionService::EXPORT_ID;
            if (!isset($divisions[$div])) {
                $d = DivisionService::find($div);
                $divisions[$div] = ['code' => (string) ($d['code'] ?? $div), 'name' => (string) ($d['name'] ?? $div), 'revenue' => 0, 'profit' => 0, 'own' => 0, 'orders' => 0];
            }
            $divisions[$div]['revenue'] += self::paise($r['revenue_inr']);
            $divisions[$div]['profit'] += self::paise($r[$profitKey]);
            $divisions[$div]['orders']++;
        }
        $where = ["order_id IS NULL", "currency_code = 'INR'"];
        $params = [];
        if ($dateFrom) {
            $where[] = 'expense_date >= :f';
            $params['f'] = $dateFrom;
        }
        if ($dateTo) {
            $where[] = 'expense_date <= :t';
            $params['t'] = $dateTo;
        }
        $stmt = $pdo->prepare('SELECT division_id, SUM(amount) AS total FROM ca_expenses WHERE ' . implode(' AND ', $where) . ' GROUP BY division_id');
        $stmt->execute($params);
        $overhead = 0;
        foreach ($stmt->fetchAll() as $c) {
            if ($c['division_id'] === null) {
                $overhead += self::paise((string) $c['total']);
            } elseif (isset($divisions[(int) $c['division_id']])) {
                $divisions[(int) $c['division_id']]['own'] += self::paise((string) $c['total']);
            }
        }
        $keys = array_keys($divisions);
        $weights = array_map(static fn (int $k): int => max(0, $divisions[$k]['revenue']), $keys);
        $totalRevenue = array_sum($weights);
        $shares = $totalRevenue > 0 ? ImportService::spread($overhead, $weights) : array_fill(0, count($keys), 0);
        $out = [];
        foreach ($keys as $i => $k) {
            $d = $divisions[$k];
            $out[] = [
                'division_id' => $k,
                'code' => $d['code'],
                'name' => $d['name'],
                'orders' => $d['orders'],
                'revenue_inr' => GstCalculator::money($d['revenue']),
                'revenue_share_pct' => $totalRevenue > 0 ? GstCalculator::money(GstCalculator::roundDiv($weights[$i] * 10000, $totalRevenue)) : '0.00',
                'profit_inr' => GstCalculator::money($d['profit']),
                'own_costs_inr' => GstCalculator::money($d['own']),
                'overhead_share_inr' => GstCalculator::money($shares[$i]),
                'profit_after_overhead_inr' => GstCalculator::money($d['profit'] - $d['own'] - $shares[$i]),
            ];
        }
        return [
            'rows' => $out,
            'overhead_inr' => GstCalculator::money($overhead),
            'overhead_unshared' => $totalRevenue === 0 && $overhead > 0,
            'profit_label' => $profitAfterIncentives ? 'Profit after commission & incentive' : 'Profit before commission & incentive',
        ];
    }
}
