<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\FinancialYear;

/**
 * CAFIN as CSV for many orders (owner request 2026-10-30): the internal CA
 * Financial Annexure is a snapshot of the complete order, so it can also be
 * taken out for every order at once — never for the client. Two files:
 *
 * - summary: one row per order with the key figures (the same figures the
 *   CAFIN's expenses summary, profit and cash position show);
 * - detail: every CAFIN line of every order, with the order and the section.
 *
 * Both come from CaFinancialSnapshotService / OrderProfitabilityService, the
 * same calculation as the PDF, so the CSV always agrees with it. Test and
 * sample orders are never included.
 */
final class CafinExportService
{
    public const STATUSES = [
        'all' => 'All orders',
        'active' => 'In progress (incl. documents sent)',
        'complete' => 'Fully settled',
        'lost' => 'Lost or cancelled',
    ];

    /** @return list<int> the order ids for the financial year (by order date) and status, oldest first */
    public static function orderIds(string $fy, string $status): array
    {
        ['start' => $from, 'end' => $to] = FinancialYear::bounds($fy);
        $statusSql = match ($status) {
            'active' => "AND o.status IN ('active','shipped_docs_sent','disputed')",
            'complete' => "AND o.status = 'complete'",
            'lost' => "AND o.status IN ('lost','cancelled')",
            default => '',
        };
        $stmt = Database::connection()->prepare(
            "SELECT o.id FROM orders o
             WHERE o.is_test_data = 0 AND o.is_sample_data = 0
               AND DATE(o.created_at) BETWEEN :f AND :t {$statusSql}
             ORDER BY o.created_at, o.id"
        );
        $stmt->execute(['f' => $from, 't' => $to]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> the financial years that have real orders, newest first */
    public static function financialYears(): array
    {
        $dates = Database::connection()->query('SELECT created_at FROM orders WHERE is_test_data = 0 AND is_sample_data = 0')->fetchAll(\PDO::FETCH_COLUMN);
        $years = FinancialYear::labelsPresentIn($dates);
        if (!in_array(FinancialYear::current(), $years, true)) {
            $years[] = FinancialYear::current();
        }
        rsort($years);
        return $years;
    }

    public const SUMMARY_HEADERS = [
        'Order reference', 'Buyer enquiry ref', 'Client', 'Destination country', 'Status', 'Order date', 'Currency', 'FOB value',
        'Revenue (INR)', 'Revenue estimated', 'Govt export benefits (INR)', 'Total income (INR)', 'Supplier cost (INR)',
        'C1 Freight & insurance (INR)', 'C2 Logistics partner bills (INR)', 'C3 Other order costs (INR)', 'of which team incentive (INR)',
        'of which associate commission (INR)', 'C4 Order expenses (INR)', 'C5 Expenses booked in Zoho Books (INR)', 'Total expenses excl. supplier (INR)',
        'Total cost (INR)', 'Order profit (INR)', 'Margin %',
        'Cash: received from buyer (INR)', 'Cash: benefits received (INR)', 'Cash: paid to supplier (INR)', 'Cash: paid to logistics (INR)',
        'Cash: expenses paid (INR)', 'Cash: net so far (INR)',
    ];

    /** @param list<int> $orderIds @return list<list<string|float>> */
    public static function summaryRows(array $orderIds): array
    {
        $rows = [];
        foreach ($orderIds as $orderId) {
            $o = \App\Repositories\OrderRepository::find($orderId);
            $p = OrderProfitabilityService::computeForOrder($orderId);
            $cash = OrderLedgerService::forOrder($orderId, (string) ($o['currency_code'] ?? 'INR'), true)['totals'];
            $c1 = $p['freight_cost_inr'] + $p['insurance_cost_inr'];
            $rows[] = [
                (string) $o['order_reference'], (string) ($o['buyer_inquiry_ref'] ?? ''), (string) $o['company_legal_name'], (string) ($o['country_of_destination'] ?? ''),
                (string) $o['status'], substr((string) $o['created_at'], 0, 10), (string) ($o['currency_code'] ?? ''),
                self::num(\App\Repositories\OrderProductRepository::totalFobValue($orderId)),
                self::num($p['revenue_inr']), $p['revenue_is_estimated'] ? 'Yes' : 'No', self::num($p['export_benefits_inr']),
                self::num($p['revenue_inr'] + $p['export_benefits_inr']), self::num($p['supplier_cost_inr']),
                self::num($c1), self::num($p['logistics_cost_inr']), self::num($p['other_costs_inr']), self::num($p['team_incentive_inr']),
                self::num($p['associate_commission_inr']), self::num($p['expenses_inr']), self::num($p['zoho_expenses_inr']),
                self::num($c1 + $p['logistics_cost_inr'] + $p['other_costs_inr'] + $p['expenses_inr'] + $p['zoho_expenses_inr']),
                self::num($p['total_cost_inr']), self::num($p['profit_inr']), $p['margin_pct'] !== null ? number_format((float) $p['margin_pct'], 2, '.', '') : '',
                self::num($cash['buyer_inr']), self::num($cash['benefits_inr']), self::num($cash['supplier_inr']), self::num($cash['logistics_inr']),
                self::num($cash['expenses_inr']), self::num($cash['net_inr']),
            ];
        }
        return $rows;
    }

    public const DETAIL_HEADERS = ['Order reference', 'Client', 'Section', 'Item', 'Description', 'Date', 'Amount', 'Currency', 'Amount (INR)', 'Note'];

    /** @param list<int> $orderIds @return list<list<string>> every CAFIN line of every order */
    public static function detailRows(array $orderIds): array
    {
        $rows = [];
        foreach ($orderIds as $orderId) {
            $o = \App\Repositories\OrderRepository::find($orderId);
            $s = CaFinancialSnapshotService::build($orderId);
            $add = static function (string $section, string $item, string $desc = '', string $date = '', string $amount = '', string $currency = '', string $inr = '', string $note = '') use (&$rows, $o): void {
                $rows[] = [(string) $o['order_reference'], (string) $o['company_legal_name'], $section, $item, $desc, $date, self::plain($amount), $currency, self::plain($inr), $note];
            };
            foreach ($s['details'] as $d) {
                $add('A. Order details', (string) $d[0], (string) $d[1]);
                $add('A. Order details', (string) $d[2], (string) $d[3]);
            }
            foreach ($s['products'] as $p) {
                $add('A. Products', $p['description'], 'HS ' . $p['hs_code'] . ' · ' . $p['quantity'] . ' × ' . $p['unit_price'], '', $p['amount'], $s['currency']);
            }
            $add('A. Products', 'TOTAL FOB', '', '', $s['fob_total'], $s['currency']);
            foreach ($s['receipts'] as $r) {
                $add('A. Money received from the buyer', $r['leg'], 'rate ' . $r['rate'] . ' · received ' . $r['received'], $r['cleared'], $r['amount'], $s['currency'], $r['inr'], $r['basis']);
            }
            $add('A. Money received from the buyer', 'TOTAL', '', '', $s['receipts_foreign'], $s['currency'], $s['receipts_inr']);
            foreach ($s['supplier']['rows'] ?? [] as $r) {
                $add('B. Supplier cost', (string) $r[0], (string) $r[1]);
                $add('B. Supplier cost', (string) $r[2], (string) $r[3]);
            }
            foreach ($s['supplier']['bills'] ?? [] as $b) {
                $add('B. Supplier bills & payments', $b['leg'], $b['bill'] . ' · ' . $b['paid'] . ' · ' . $b['utr'], '', '', 'INR', $b['total'], $b['unpaid'] ? 'unpaid' : '');
            }
            foreach ($s['freight_rows'] as $f) {
                $add('C1. Freight & insurance', (string) $f[0], (string) $f[1], '', '', 'INR', (string) $f[2]);
            }
            foreach ($s['logistics'] as $l) {
                $add('C2. Logistics partner bills', $l['partner'], $l['for'] . ' · ' . $l['bill'] . ' · ' . $l['paid'] . ' · ' . $l['utr'], '', '', 'INR', $l['total'], $l['unpaid'] ? 'unpaid' : '');
            }
            foreach ($s['costs'] as $c) {
                $add('C3. Other order costs', $c['category'], $c['description'], $c['date'], '', 'INR', $c['amount'], (string) ($c['tag'] ?? ''));
            }
            foreach ($s['expenses'] as $e) {
                $add('C4. Order expenses', $e['category'], $e['details'] . ' · ' . $e['paid_by'], $e['date'], '', 'INR', $e['amount'], $e['receipt']);
            }
            foreach ($s['zoho'] as $z) {
                [$cur, $amt] = array_pad(explode(' ', $z['amount'], 2), 2, '');
                $add('C5. Expenses booked in Zoho Books', $z['category'], $z['description'] . ' · ' . $z['vendor'], $z['date'], $amt, $cur, $cur === 'INR' ? $amt : '', (string) ($z['tag'] ?? ''));
            }
            foreach ($s['summary'] as $line) {
                $add('C. Expenses summary', (string) $line[0], '', '', '', 'INR', (string) $line[1]);
            }
            $add('C. Expenses summary', 'TOTAL EXPENSES (excluding supplier)', '', '', '', 'INR', $s['expenses_grand']);
            foreach ($s['benefits'] as $b) {
                [$cur, $amt] = array_pad(explode(' ', $b['received'] !== '—' ? $b['received'] : $b['claimed'], 2), 2, '');
                $add('D. Government export benefits', $b['scheme'], 'ref ' . $b['reference'] . ' · claimed ' . $b['claimed'] . ' on ' . $b['claimed_date'], $b['received_date'], $amt, $cur, $cur === 'INR' ? $amt : '', $b['status']);
            }
            foreach ($s['pl'] as $line) {
                $add('E. Profit', (string) $line[1], '', '', '', str_ends_with((string) $line[3], '%') ? '%' : 'INR', (string) $line[3], (string) ($line[2] ?? ''));
            }
            foreach (['Received from buyer', 'Benefits received', 'Paid to supplier', 'Paid to logistics', 'Expenses', 'Net so far'] as $i => $label) {
                $add('E. Cash position so far', $label, '', '', '', 'INR', (string) $s['cash'][$i]);
            }
            foreach ($s['open'] as $item) {
                $add('F. Open items for the CA', $item['text'], '', '', '', '', '', $item['warn'] ? 'needs attention' : '');
            }
        }
        return $rows;
    }

    private static function num(float|int|string|null $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    /** "₹1,23,456.00" / "−₹5.00" / "—" → "123456.00" / "-5.00" / "" — numbers Excel can add up. */
    private static function plain(string $formatted): string
    {
        $t = trim(str_replace(['₹', ',', ' '], '', $formatted));
        if ($t === '' || $t === '—') {
            return '';
        }
        $t = str_replace('−', '-', $t);
        return is_numeric(rtrim($t, '%')) ? rtrim($t, '%') : $formatted;
    }
}
