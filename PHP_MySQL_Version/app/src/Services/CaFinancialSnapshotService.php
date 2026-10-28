<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Dates;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\OrderComplianceTaskRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExpenseRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\OrderVendorBillRepository;

/**
 * Owner request (2026-10-27, approved mockup, confirmed twice): the CAFIN
 * internal annexure is the complete order snapshot, in this order —
 * A order details (with products and money received from the buyer),
 * B supplier cost (with bills and payments), C every expense (freight &
 * insurance, logistics partner bills, other order costs, order expenses,
 * Zoho Books), D government benefits, E profit (with the cash position),
 * F open items for the CA. Every value is formatted here so the PHP and Node
 * documents print exactly the same. Internal only — never client-facing.
 */
final class CaFinancialSnapshotService
{
    private static function money($value): string
    {
        return number_format((float) $value, 2);
    }

    private static function date($value): string
    {
        return $value ? Dates::short((string) $value) : '—';
    }

    /**
     * Owner decision (2026-10-28, Option B): a bill reads as a Proforma or a
     * Tax invoice, with what it asks for now and which bill replaced which.
     *
     * @param array<string,mixed> $b an enriched bill (OrderVendorBillRepository::forOrder)
     */
    private static function billLabel(array $b): string
    {
        $label = OrderVendorBillRepository::DOC_KINDS[$b['doc_kind']] . ' ' . $b['bill_no'] . ' · ' . self::date($b['bill_date']);
        if ($b['doc_kind'] === 'proforma' && $b['requested_amount_inr'] !== null) {
            $label .= ' — asks ₹' . self::money($b['requested_amount_inr']) . ' now';
        }
        if ($b['replaces_bill_no'] !== null) {
            $label .= ' — replaces proforma ' . $b['replaces_bill_no'];
        }
        if ($b['replaced_by'] !== null) {
            $label .= ' — replaced by tax invoice ' . $b['replaced_by'] . ', not a cost';
        }
        return $label;
    }

    /** @param array<string,mixed> $b every payment on the bill (and, on a tax invoice, those made on the proforma it replaces), then what is still due */
    private static function paidText(array $b): string
    {
        $parts = [];
        foreach ($b['carried_payments'] as $p) {
            $parts[] = '₹' . self::money($p['amount_inr']) . ' on ' . self::date($p['paid_at']) . ' (on proforma ' . $b['replaces_bill_no'] . ')';
        }
        foreach ($b['payments'] as $p) {
            $parts[] = '₹' . self::money($p['amount_inr']) . ' on ' . self::date($p['paid_at']);
        }
        if ($parts === []) {
            return $b['replaced_by'] !== null ? '—' : 'Unpaid';
        }
        if (!$b['settled']) {
            $parts[] = '₹' . self::money($b['remaining']) . ' still due';
        }
        return implode('; ', $parts);
    }

    /** @param array<string,mixed> $b */
    private static function utrText(array $b): string
    {
        $refs = array_map(static fn (array $p): string => $p['payment_mode'] . ' · ' . $p['payment_ref'], $b['payments']);
        return $refs !== [] ? implode('; ', $refs) : '—';
    }

    /** @return array<string,mixed> */
    public static function build(int $orderId): array
    {
        $order = OrderRepository::find($orderId);
        $currency = (string) ($order['currency_code'] ?? '');
        $fob = OrderProductRepository::totalFobValue($orderId);
        $docRef = static function (string $code) use ($orderId): string {
            $doc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, $code);
            return $doc['document_reference'] ?? '—';
        };
        $shipping = OrderShippingRepository::find($orderId);
        $portLoading = $order['port_of_loading_city'] ?? $order['port_of_loading_name'] ?? '—';
        $portDischarge = $order['port_of_discharge_city'] ?? $order['port_of_discharge_name'] ?? $order['port_of_discharge_text'] ?? '—';
        $closedAt = in_array($order['status'], ['complete'], true) ? 'Fully settled' : '— (open)';

        $details = [
            ['Order Reference', (string) $order['order_reference'], 'Order Status', ucwords(str_replace('_', ' ', (string) $order['status']))],
            ['Client', (string) $order['company_legal_name'], 'Client Country', (string) ($order['country_of_destination'] ?: '—')],
            ['Buyer Inquiry Ref', (string) ($order['buyer_inquiry_ref'] ?: '—'), 'Buyer PO No.', (string) ($order['buyers_po_ref'] ?: '—')],
            ['Incoterm & Ports', $order['incoterm_code'] . ' — ' . $portLoading . ' → ' . $portDischarge, 'Currency', $currency],
            ['FOB Value', $currency . ' ' . self::money($fob), 'Payment Terms', ($order['preset_name'] ? $order['preset_name'] . ' — ' : '') . rtrim(rtrim(number_format((float) $order['advance_pct'], 2), '0'), '.') . '% advance / ' . rtrim(rtrim(number_format((float) $order['balance_pct'], 2), '0'), '.') . '% balance'],
            ['PI / OC / CI', $docRef('PI') . ' · ' . $docRef('OC') . ' · ' . $docRef('CI'), 'BL No. / BL Date', ($shipping['bl_number'] ?? null) ? $shipping['bl_number'] . ' · ' . self::date($shipping['bl_date'] ?? null) : '—'],
            ['Order Created', self::date($order['created_at']), 'Closed / Fully Settled', $closedAt],
        ];

        $products = [];
        foreach (OrderProductRepository::forOrder($orderId) as $p) {
            $products[] = [
                'description' => (string) $p['description'],
                'hs_code' => (string) ($p['hs_code'] ?: '—'),
                'quantity' => $p['quantity_is_tbc'] ? 'TBC' : rtrim(rtrim(number_format((float) $p['quantity'], 3), '0'), '.') . ' ' . $p['unit'],
                'unit_price' => $p['unit_price'] !== null ? self::money($p['unit_price']) : '—',
                'amount' => $p['fob_value'] !== null ? self::money($p['fob_value']) : '—',
            ];
        }

        $payment = OrderPaymentStatusRepository::find($orderId) ?? [];
        $receipts = [];
        $receiptsForeign = 0.0;
        $receiptsInr = 0.0;
        foreach (['advance' => 'Advance', 'freight' => 'Freight & insurance (Debit Note)', 'balance' => 'Balance'] as $leg => $label) {
            if (empty($payment[$leg . '_remittance_received_at'])) {
                continue;
            }
            $amount = (float) ($payment[$leg . '_amount'] ?? 0);
            $rate = $payment[$leg . '_exchange_rate'] ?? null;
            $actual = $payment[$leg . '_inr_actual'] ?? null;
            $inr = $actual !== null ? (float) $actual : ($rate !== null ? $amount * (float) $rate : null);
            $receiptsForeign += $amount;
            $receiptsInr += (float) $inr;
            $receipts[] = [
                'leg' => $label,
                'amount' => self::money($amount),
                'rate' => $rate !== null ? number_format((float) $rate, 4) : '—',
                'inr' => $inr !== null ? self::money($inr) : '—',
                'received' => self::date($payment[$leg . '_remittance_received_at']),
                'cleared' => self::date($payment[$leg . '_cleared_at'] ?? null),
                'basis' => $actual !== null ? 'INR actual' : ($rate !== null ? 'at recorded rate' : 'no rate yet'),
            ];
        }

        // B — supplier.
        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        $supplier = null;
        if ($supplierPo) {
            $bills = [];
            $paidTotal = 0.0;
            foreach (OrderVendorBillRepository::forOrder($orderId, 'supplier') as $b) {
                if ((int) $b['supplier_po_id'] !== (int) $supplierPo['id']) {
                    continue;
                }
                $paidTotal += $b['own_paid'];
                $bills[] = [
                    'leg' => OrderVendorBillRepository::SUPPLIER_LEGS[$b['bill_for']] ?? $b['bill_for'],
                    'bill' => self::billLabel($b),
                    'taxable' => self::money($b['taxable_inr']),
                    'gst' => self::money($b['gst_inr']),
                    'total' => self::money($b['total_inr']),
                    'paid' => self::paidText($b),
                    'unpaid' => !$b['settled'],
                    'due' => self::money($b['remaining']),
                    'utr' => self::utrText($b),
                ];
            }
            // Paid before supplier bills were kept — shown from the Supplier PO itself.
            foreach (['advance' => 'Advance', 'balance' => 'Balance'] as $leg => $label) {
                $legHasBill = array_filter($bills, static fn (array $row): bool => $row['leg'] === $label) !== [];
                if (!empty($supplierPo[$leg . '_paid_at']) && !$legHasBill) {
                    $amount = (float) ($supplierPo[$leg . '_paid_amount_inr'] ?? $supplierPo[$leg . '_amount_inr'] ?? 0);
                    $paidTotal += $amount;
                    $bills[] = ['leg' => $label, 'bill' => 'No bill recorded', 'taxable' => '—', 'gst' => '—', 'total' => '—', 'paid' => '₹' . self::money($amount) . ' on ' . self::date($supplierPo[$leg . '_paid_at']), 'unpaid' => false, 'utr' => '—'];
                }
            }
            $payable = (float) ($supplierPo['total_payable_inr'] ?? 0);
            $supplier = [
                'rows' => [
                    ['Supplier', (string) $supplierPo['supplier_legal_name'], 'Supplier PO', $supplierPo['supplier_po_reference'] . ' — ' . $supplierPo['status']],
                    ['Material', (string) ($supplierPo['material_stone_type'] ?: '—'), 'Quantity × Rate', ($supplierPo['quantity'] !== null && $supplierPo['quantity'] !== '' ? rtrim(rtrim(number_format((float) $supplierPo['quantity'], 3), '0'), '.') : '—') . ' ' . ($supplierPo['unit'] ?? '') . ' × ₹' . ($supplierPo['unit_price_inr'] !== null ? self::money($supplierPo['unit_price_inr']) : '—')],
                    ['Basic Value', '₹' . self::money($supplierPo['basic_value_inr'] ?? 0), 'GST', '₹' . self::money($supplierPo['gst_amount_inr'] ?? 0)],
                    ['Total Payable', '₹' . self::money($payable), 'Advance / Balance planned', '₹' . self::money($supplierPo['advance_amount_inr'] ?? 0) . ' / ₹' . self::money($supplierPo['balance_amount_inr'] ?? 0)],
                ],
                'bills' => $bills,
                'paid_total' => self::money($paidTotal),
                'paid_check' => $payable > 0 && abs($paidTotal - $payable) < 0.01 ? '= Total payable ✓' : ('Payable ₹' . self::money($payable) . ' · still due ₹' . self::money(max(0, $payable - $paidTotal))),
            ];
        }

        $profit = OrderProfitabilityService::computeForOrder($orderId);
        $expenseSummary = OrderExpenseRepository::summary($orderId);

        // C1 — freight & insurance as counted in the profit.
        $freightRows = [
            ['Ocean freight', $profit['freight_from_bills'] ? "Freight forwarder's bills (listed in C2)" : 'Confirmed rate (Step 9a) — replaced by the forwarder\'s bills once added', self::money($profit['freight_cost_inr'])],
            ['Insurance', 'Step 9a', self::money($profit['insurance_cost_inr'])],
        ];
        $c1 = $profit['freight_cost_inr'] + $profit['insurance_cost_inr'];

        // C2 — logistics partner bills.
        $logistics = [];
        $logisticsPaid = $logisticsDue = 0.0;
        $unpaidBills = [];
        foreach (OrderVendorBillRepository::forOrder($orderId, 'logistics') as $b) {
            $ocean = $b['bill_for'] === 'ocean_freight';
            $logisticsPaid += $b['own_paid'];
            if (!$b['settled']) {
                $logisticsDue += $b['remaining'];
                $unpaidBills[] = ($b['partner_name'] ?? 'Logistics') . ' ' . $b['bill_no'] . ', ₹' . self::money($b['remaining']) . ' due';
            }
            $logistics[] = [
                'partner' => (string) ($b['partner_name'] ?? '—'),
                'for' => (OrderVendorBillRepository::LOGISTICS_SERVICES[$b['bill_for']] ?? $b['bill_for']) . ($ocean ? ' (counted in C1)' : ''),
                'bill' => self::billLabel($b),
                'total' => self::money($b['total_inr']),
                'paid' => self::paidText($b),
                'unpaid' => !$b['settled'],
                'utr' => self::utrText($b),
            ];
        }
        foreach ($supplier['bills'] ?? [] as $row) {
            if ($row['unpaid']) {
                $unpaidBills[] = 'Supplier ' . $row['bill'] . ', ₹' . $row['due'] . ' due';
            }
        }

        // C3 — other order costs (Step 12a).
        $costs = [];
        foreach (OrderCostEntryRepository::forOrder($orderId) as $c) {
            $travelReplaced = $c['category'] === 'travel_expenses' && $expenseSummary['has_travel'];
            $costs[] = [
                'category' => OrderCostEntryRepository::CATEGORIES[$c['category']] ?? (string) $c['category'],
                'tag' => $c['category'] === 'travel_expenses' ? ($travelReplaced ? 'replaced by actual travel — not counted' : 'provisional %') : null,
                'description' => (string) ($c['description'] ?: '—'),
                'amount' => self::money($c['amount_inr']),
                'date' => self::date($c['incurred_at'] ?? $c['created_at']),
                'by' => (string) ($c['recorded_by_name'] ?? '—'),
            ];
        }

        // C4 — order expenses with receipts.
        $expenses = [];
        $noReceipt = 0;
        foreach (OrderExpenseRepository::forOrder($orderId) as $e) {
            if (empty($e['receipt_file_id'])) {
                $noReceipt++;
            }
            $expenses[] = [
                'date' => self::date($e['expense_date']) . (!empty($e['added_later']) ? ' (added later)' : ''),
                'category' => OrderExpenseRepository::CATEGORIES[$e['category']] ?? (string) $e['category'],
                'details' => (string) $e['details'],
                'amount' => self::money($e['amount_inr']),
                'paid_by' => (OrderExpenseRepository::PAID_BY[$e['paid_by']] ?? $e['paid_by']) . ($e['paid_by'] === 'staff' && $e['staff_name'] ? ' — ' . $e['staff_name'] : ''),
                'receipt' => !empty($e['receipt_file_id']) ? 'Receipt attached' : 'No bill — ' . $e['no_receipt_reason'],
            ];
        }

        // C5 — Zoho Books.
        $zoho = [];
        $zohoTotal = 0.0;
        foreach (CaExpenseRepository::forOrder($orderId) as $z) {
            $zohoTotal += (float) $z['amount'];
            $zoho[] = ['category' => (string) $z['category'], 'description' => (string) ($z['description'] ?: '—'), 'vendor' => (string) ($z['vendor_name'] ?: '—'), 'amount' => $z['currency_code'] . ' ' . self::money($z['amount']), 'date' => self::date($z['expense_date'])];
        }

        $c3 = $profit['other_costs_inr'];
        $summary = [
            ['C1 Freight & insurance', self::money($c1)],
            ['C2 Logistics partner bills', self::money($profit['logistics_cost_inr'])],
            ['C3 Other order costs', self::money($c3)],
            ['C4 Order expenses', self::money($profit['expenses_inr'])],
        ];
        $expensesTotal = $c1 + $profit['logistics_cost_inr'] + $c3 + $profit['expenses_inr'];

        // D — benefits.
        $benefits = [];
        $claimed = $received = 0.0;
        $benefitsPending = 0;
        foreach (CaExportBenefitRepository::forOrder($orderId) as $b) {
            $claimed += (float) $b['claimed_amount'];
            $received += (float) ($b['received_amount'] ?? 0);
            if ($b['received_amount'] === null) {
                $benefitsPending++;
            }
            $benefits[] = [
                'scheme' => (string) $b['scheme_name'],
                'reference' => (string) ($b['reference_number'] ?: '—'),
                'claimed' => $b['currency_code'] . ' ' . self::money($b['claimed_amount']),
                'claimed_date' => self::date($b['claimed_at']),
                'received' => $b['received_amount'] !== null ? $b['currency_code'] . ' ' . self::money($b['received_amount']) : '—',
                'received_date' => self::date($b['received_at'] ?? null),
                'status' => $b['received_amount'] !== null ? 'Received' : 'Claimed / Pending',
            ];
        }

        // E — profit and the cash position.
        $income = $profit['revenue_inr'] + $profit['export_benefits_inr'];
        $pl = [
            ['line', 'Revenue from the buyer', $profit['revenue_is_estimated'] ? 'estimated — not every leg cleared with its INR actual' : null, self::money($profit['revenue_inr'])],
            ['line', '+ Government export benefits', $profit['export_benefits_is_estimated'] ? 'claimed, not yet received' : null, self::money($profit['export_benefits_inr'])],
            ['sub', 'Total income', null, self::money($income)],
            ['line', '− Supplier cost (B)', null, self::money($profit['supplier_cost_inr'])],
            ['line', '− Freight & insurance (C1)', null, self::money($c1)],
            ['line', '− Logistics partner bills (C2)', null, self::money($profit['logistics_cost_inr'])],
            ['line', '− Other order costs (C3)', null, self::money($c3)],
            ['line', '− Order expenses (C4)', null, self::money($profit['expenses_inr'])],
            ['sub', 'Total cost', null, self::money($profit['total_cost_inr'])],
            ['grand', 'ORDER PROFIT', null, self::money($profit['profit_inr'])],
            ['grand', 'MARGIN', null, $profit['margin_pct'] !== null ? number_format((float) $profit['margin_pct'], 2) . '%' : '—'],
        ];
        $ledger = OrderLedgerService::forOrder($orderId, $currency, true)['totals'];
        $cash = [
            '₹' . self::money($ledger['buyer_inr']),
            '₹' . self::money($ledger['benefits_inr']),
            '₹' . self::money($ledger['supplier_inr']),
            '₹' . self::money($ledger['logistics_inr']),
            '₹' . self::money($ledger['expenses_inr']),
            ($ledger['net_inr'] < 0 ? '−₹' : '₹') . self::money(abs($ledger['net_inr'])),
        ];

        // F — open items for the CA.
        $open = [];
        foreach ($unpaidBills as $u) {
            $open[] = ['warn' => true, 'text' => 'Unpaid bill — ' . $u];
        }
        if ($profit['revenue_is_estimated']) {
            $open[] = ['warn' => false, 'text' => 'Revenue is estimated — not every buyer payment has cleared with its INR actual recorded'];
        }
        if ($benefitsPending > 0) {
            $open[] = ['warn' => false, 'text' => $benefitsPending . ' government benefit claim(s) not yet received'];
        }
        if (!$expenseSummary['has_travel']) {
            $travelEntry = array_values(array_filter($costs, static fn (array $c): bool => $c['tag'] === 'provisional %'));
            $open[] = ['warn' => false, 'text' => $travelEntry !== []
                ? 'Travel is the provisional % of FOB (₹' . $travelEntry[0]['amount'] . ') — no travel expenses with receipts yet'
                : 'No travel cost recorded for this order'];
        }
        $open[] = ['warn' => $noReceipt > 0, 'text' => 'Expenses without a receipt: ' . $noReceipt . ' · Staff to reimburse: ₹' . self::money($expenseSummary['to_reimburse'])];
        $openTasks = array_values(array_filter(OrderComplianceTaskRepository::forOrder($orderId), static fn (array $t): bool => !in_array($t['status'], ['approved', 'skipped'], true)));
        $open[] = ['warn' => false, 'text' => $openTasks === []
            ? 'Compliance checklist: every task resolved'
            : 'Compliance checklist: ' . count($openTasks) . ' task(s) open (' . implode(', ', array_map(static fn (array $t): string => $t['task_type_name'] . ' — ' . ucwords(str_replace('_', ' ', (string) $t['status'])), $openTasks)) . ')'];

        return [
            'subtitle' => $order['order_reference'] . ' (' . $order['company_legal_name'] . ')',
            'details' => $details,
            'products' => $products,
            'fob_total' => self::money($fob),
            'currency' => $currency,
            'receipts' => $receipts,
            'receipts_foreign' => self::money($receiptsForeign),
            'receipts_inr' => self::money($receiptsInr),
            'supplier' => $supplier,
            'freight_rows' => $freightRows,
            'c1_total' => self::money($c1),
            'logistics' => $logistics,
            'logistics_total' => self::money($profit['logistics_cost_inr']),
            'logistics_paid' => self::money($logisticsPaid),
            'logistics_due' => self::money($logisticsDue),
            'costs' => $costs,
            'costs_total' => self::money($c3),
            'travel_replaced' => $expenseSummary['has_travel'],
            'expenses' => $expenses,
            'expenses_total' => self::money($expenseSummary['total']),
            'zoho' => $zoho,
            'zoho_total' => self::money($zohoTotal),
            'summary' => $summary,
            'expenses_grand' => self::money($expensesTotal),
            'benefits' => $benefits,
            'benefits_claimed' => self::money($claimed),
            'benefits_received' => self::money($received),
            'pl' => $pl,
            'cash' => $cash,
            'open' => $open,
        ];
    }
}
