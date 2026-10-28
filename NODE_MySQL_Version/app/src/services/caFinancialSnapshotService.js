'use strict';

const dates = require('../helpers/dates');
const caExpenseRepository = require('../repositories/caExpenseRepository');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const documentRepository = require('../repositories/documentRepository');
const orderComplianceTaskRepository = require('../repositories/orderComplianceTaskRepository');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderExpenseRepository = require('../repositories/orderExpenseRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderShippingRepository = require('../repositories/orderShippingRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');
const orderVendorBillRepository = require('../repositories/orderVendorBillRepository');
const orderLedgerService = require('./orderLedgerService');
const orderProfitabilityService = require('./orderProfitabilityService');

/**
 * Port of App\Services\CaFinancialSnapshotService. Owner request
 * (2026-10-27, approved mockup, confirmed twice): the CAFIN internal annexure
 * is the complete order snapshot, in this order — A order details (with
 * products and money received from the buyer), B supplier cost (with bills
 * and payments), C every expense (freight & insurance, logistics partner
 * bills, other order costs, order expenses, Zoho Books), D government
 * benefits, E profit (with the cash position), F open items for the CA.
 * Every value is formatted here so the PHP and Node documents print exactly
 * the same. Internal only — never client-facing.
 */
const money = (v) => (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const trimmed = (v, places) => (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: places });
const date = (v) => (v ? dates.short(String(v)) : '—');
const ucwords = (s) => String(s).replace(/_/g, ' ').replace(/(^|\s)(\S)/g, (m, sp, c) => sp + c.toUpperCase());
const num = (v) => (v === null || v === undefined || v === '' ? null : Number(v));

/**
 * Owner decision (2026-10-28, Option B): a bill reads as a Proforma or a Tax
 * invoice, with what it asks for now and which bill replaced which. b is an
 * enriched bill (orderVendorBillRepository.forOrder).
 */
function billLabel(b) {
  let label = `${orderVendorBillRepository.DOC_KINDS[b.doc_kind]} ${b.bill_no} · ${date(b.bill_date)}`;
  if (b.doc_kind === 'proforma' && b.requested_amount_inr !== null) label += ` — asks ₹${money(b.requested_amount_inr)} now`;
  if (b.replaces_bill_no !== null) label += ` — replaces proforma ${b.replaces_bill_no}`;
  if (b.replaced_by !== null) label += ` — replaced by tax invoice ${b.replaced_by}, not a cost`;
  return label;
}

/** Every payment on the bill (and, on a tax invoice, those made on the proforma it replaces), then what is still due. */
function paidText(b) {
  const parts = [
    ...b.carried_payments.map((p) => `₹${money(p.amount_inr)} on ${date(p.paid_at)} (on proforma ${b.replaces_bill_no})`),
    ...b.payments.map((p) => `₹${money(p.amount_inr)} on ${date(p.paid_at)}`),
  ];
  if (!parts.length) return b.replaced_by !== null ? '—' : 'Unpaid';
  if (!b.settled) parts.push(`₹${money(b.remaining)} still due`);
  return parts.join('; ');
}

function utrText(b) {
  return b.payments.length ? b.payments.map((p) => `${p.payment_mode} · ${p.payment_ref}`).join('; ') : '—';
}

async function build(orderId) {
  const order = await orderRepository.find(orderId);
  const currency = String(order.currency_code || '');
  const fob = Number(await orderProductRepository.totalFobValue(orderId)) || 0;
  const docRef = async (code) => {
    const doc = await documentRepository.findLatestForOrderAndTypeCode(orderId, code);
    return (doc && doc.document_reference) || '—';
  };
  const shipping = await orderShippingRepository.find(orderId);
  const portLoading = order.port_of_loading_city ?? order.port_of_loading_name ?? '—';
  const portDischarge = order.port_of_discharge_city ?? order.port_of_discharge_name ?? order.port_of_discharge_text ?? '—';

  const details = [
    ['Order Reference', String(order.order_reference), 'Order Status', ucwords(order.status)],
    ['Client', String(order.company_legal_name), 'Client Country', String(order.country_of_destination || '—')],
    ['Buyer Inquiry Ref', String(order.buyer_inquiry_ref || '—'), 'Buyer PO No.', String(order.buyers_po_ref || '—')],
    ['Incoterm & Ports', `${order.incoterm_code} — ${portLoading} → ${portDischarge}`, 'Currency', currency],
    ['FOB Value', `${currency} ${money(fob)}`, 'Payment Terms', `${order.preset_name ? `${order.preset_name} — ` : ''}${trimmed(order.advance_pct, 2)}% advance / ${trimmed(order.balance_pct, 2)}% balance`],
    ['PI / OC / CI', `${await docRef('PI')} · ${await docRef('OC')} · ${await docRef('CI')}`, 'BL No. / BL Date', shipping && shipping.bl_number ? `${shipping.bl_number} · ${date(shipping.bl_date)}` : '—'],
    ['Order Created', date(order.created_at), 'Closed / Fully Settled', order.status === 'complete' ? 'Fully settled' : '— (open)'],
  ];

  const products = (await orderProductRepository.forOrder(orderId)).map((p) => ({
    description: String(p.description),
    hs_code: String(p.hs_code || '—'),
    quantity: Number(p.quantity_is_tbc) ? 'TBC' : `${trimmed(p.quantity, 3)} ${p.unit}`,
    unit_price: p.unit_price !== null ? money(p.unit_price) : '—',
    amount: p.fob_value !== null ? money(p.fob_value) : '—',
  }));

  const payment = (await orderPaymentStatusRepository.find(orderId)) || {};
  const receipts = [];
  let receiptsForeign = 0;
  let receiptsInr = 0;
  for (const [leg, label] of [['advance', 'Advance'], ['freight', 'Freight & insurance (Debit Note)'], ['balance', 'Balance']]) {
    if (!payment[`${leg}_remittance_received_at`]) continue;
    const amount = Number(payment[`${leg}_amount`] || 0);
    const rate = num(payment[`${leg}_exchange_rate`]);
    const actual = num(payment[`${leg}_inr_actual`]);
    const inr = actual !== null ? actual : (rate !== null ? amount * rate : null);
    receiptsForeign += amount;
    receiptsInr += inr || 0;
    receipts.push({
      leg: label,
      amount: money(amount),
      rate: rate !== null ? rate.toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 }) : '—',
      inr: inr !== null ? money(inr) : '—',
      received: date(payment[`${leg}_remittance_received_at`]),
      cleared: date(payment[`${leg}_cleared_at`]),
      basis: actual !== null ? 'INR actual' : (rate !== null ? 'at recorded rate' : 'no rate yet'),
    });
  }

  // B — supplier.
  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  let supplier = null;
  if (supplierPo) {
    const bills = [];
    let paidTotal = 0;
    for (const b of await orderVendorBillRepository.forOrder(orderId, 'supplier')) {
      if (Number(b.supplier_po_id) !== Number(supplierPo.id)) continue;
      paidTotal += b.own_paid;
      bills.push({
        leg: orderVendorBillRepository.SUPPLIER_LEGS[b.bill_for] || b.bill_for,
        bill: billLabel(b),
        taxable: money(b.taxable_inr),
        gst: money(b.gst_inr),
        total: money(b.total_inr),
        paid: paidText(b),
        unpaid: !b.settled,
        due: money(b.remaining),
        utr: utrText(b),
      });
    }
    // Paid before supplier bills were kept — shown from the Supplier PO itself.
    for (const [leg, label] of [['advance', 'Advance'], ['balance', 'Balance']]) {
      if (supplierPo[`${leg}_paid_at`] && !bills.some((row) => row.leg === label)) {
        const amount = Number(supplierPo[`${leg}_paid_amount_inr`] ?? supplierPo[`${leg}_amount_inr`] ?? 0);
        paidTotal += amount;
        bills.push({ leg: label, bill: 'No bill recorded', taxable: '—', gst: '—', total: '—', paid: `₹${money(amount)} on ${date(supplierPo[`${leg}_paid_at`])}`, unpaid: false, utr: '—' });
      }
    }
    const payable = Number(supplierPo.total_payable_inr || 0);
    const qty = supplierPo.quantity !== null && supplierPo.quantity !== '' && supplierPo.quantity !== undefined ? trimmed(supplierPo.quantity, 3) : '—';
    supplier = {
      rows: [
        ['Supplier', String(supplierPo.supplier_legal_name), 'Supplier PO', `${supplierPo.supplier_po_reference} — ${supplierPo.status}`],
        ['Material', String(supplierPo.material_stone_type || '—'), 'Quantity × Rate', `${qty} ${supplierPo.unit || ''} × ₹${supplierPo.unit_price_inr !== null ? money(supplierPo.unit_price_inr) : '—'}`],
        ['Basic Value', `₹${money(supplierPo.basic_value_inr || 0)}`, 'GST', `₹${money(supplierPo.gst_amount_inr || 0)}`],
        ['Total Payable', `₹${money(payable)}`, 'Advance / Balance planned', `₹${money(supplierPo.advance_amount_inr || 0)} / ₹${money(supplierPo.balance_amount_inr || 0)}`],
      ],
      bills,
      paid_total: money(paidTotal),
      paid_check: payable > 0 && Math.abs(paidTotal - payable) < 0.01 ? '= Total payable ✓' : `Payable ₹${money(payable)} · still due ₹${money(Math.max(0, payable - paidTotal))}`,
    };
  }

  const profit = await orderProfitabilityService.computeForOrder(orderId);
  const expenseSummary = await orderExpenseRepository.summary(orderId);

  // C1 — freight & insurance as counted in the profit.
  const freightRows = [
    ['Ocean freight', profit.freight_from_bills ? "Freight forwarder's bills (listed in C2)" : "Confirmed rate (Step 9a) — replaced by the forwarder's bills once added", money(profit.freight_cost_inr)],
    ['Insurance', 'Step 9a', money(profit.insurance_cost_inr)],
  ];
  const c1 = profit.freight_cost_inr + profit.insurance_cost_inr;

  // C2 — logistics partner bills.
  const logistics = [];
  let logisticsPaid = 0;
  let logisticsDue = 0;
  const unpaidBills = [];
  for (const b of await orderVendorBillRepository.forOrder(orderId, 'logistics')) {
    const ocean = b.bill_for === 'ocean_freight';
    logisticsPaid += b.own_paid;
    if (!b.settled) {
      logisticsDue += b.remaining;
      unpaidBills.push(`${b.partner_name || 'Logistics'} ${b.bill_no}, ₹${money(b.remaining)} due`);
    }
    logistics.push({
      partner: String(b.partner_name || '—'),
      for: `${orderVendorBillRepository.LOGISTICS_SERVICES[b.bill_for] || b.bill_for}${ocean ? ' (counted in C1)' : ''}`,
      bill: billLabel(b),
      total: money(b.total_inr),
      paid: paidText(b),
      unpaid: !b.settled,
      utr: utrText(b),
    });
  }
  for (const row of (supplier && supplier.bills) || []) {
    if (row.unpaid) unpaidBills.push(`Supplier ${row.bill}, ₹${row.due} due`);
  }

  // C3 — other order costs (Step 12a).
  const costs = (await orderCostEntryRepository.forOrder(orderId)).map((c) => {
    const travelReplaced = c.category === 'travel_expenses' && expenseSummary.has_travel;
    return {
      category: orderCostEntryRepository.CATEGORIES[c.category] || String(c.category),
      tag: c.category === 'travel_expenses' ? (travelReplaced ? 'replaced by actual travel — not counted' : 'provisional %') : null,
      description: String(c.description || '—'),
      amount: money(c.amount_inr),
      date: date(c.incurred_at || c.created_at),
      by: String(c.recorded_by_name || '—'),
    };
  });

  // C4 — order expenses with receipts.
  let noReceipt = 0;
  const expenses = (await orderExpenseRepository.forOrder(orderId)).map((e) => {
    if (!e.receipt_file_id) noReceipt += 1;
    return {
      date: `${date(e.expense_date)}${Number(e.added_later) ? ' (added later)' : ''}`,
      category: orderExpenseRepository.CATEGORIES[e.category] || String(e.category),
      details: String(e.details),
      amount: money(e.amount_inr),
      paid_by: `${orderExpenseRepository.PAID_BY[e.paid_by] || e.paid_by}${e.paid_by === 'staff' && e.staff_name ? ` — ${e.staff_name}` : ''}`,
      receipt: e.receipt_file_id ? 'Receipt attached' : `No bill — ${e.no_receipt_reason}`,
    };
  });

  // C5 — Zoho Books.
  let zohoTotal = 0;
  const zoho = (await caExpenseRepository.forOrder(orderId)).map((z) => {
    zohoTotal += Number(z.amount);
    return { category: String(z.category), description: String(z.description || '—'), vendor: String(z.vendor_name || '—'), amount: `${z.currency_code} ${money(z.amount)}`, date: date(z.expense_date) };
  });

  const c3 = profit.other_costs_inr;
  const summary = [
    ['C1 Freight & insurance', money(c1)],
    ['C2 Logistics partner bills', money(profit.logistics_cost_inr)],
    ['C3 Other order costs', money(c3)],
    ['C4 Order expenses', money(profit.expenses_inr)],
  ];
  const expensesTotal = c1 + profit.logistics_cost_inr + c3 + profit.expenses_inr;

  // D — benefits.
  let claimed = 0;
  let received = 0;
  let benefitsPending = 0;
  const benefits = (await caExportBenefitRepository.forOrder(orderId)).map((b) => {
    claimed += Number(b.claimed_amount);
    received += Number(b.received_amount || 0);
    if (b.received_amount === null) benefitsPending += 1;
    return {
      scheme: String(b.scheme_name),
      reference: String(b.reference_number || '—'),
      claimed: `${b.currency_code} ${money(b.claimed_amount)}`,
      claimed_date: date(b.claimed_at),
      received: b.received_amount !== null ? `${b.currency_code} ${money(b.received_amount)}` : '—',
      received_date: date(b.received_at),
      status: b.received_amount !== null ? 'Received' : 'Claimed / Pending',
    };
  });

  // E — profit and the cash position.
  const income = profit.revenue_inr + profit.export_benefits_inr;
  const pl = [
    ['line', 'Revenue from the buyer', profit.revenue_is_estimated ? 'estimated — not every leg cleared with its INR actual' : null, money(profit.revenue_inr)],
    ['line', '+ Government export benefits', profit.export_benefits_is_estimated ? 'claimed, not yet received' : null, money(profit.export_benefits_inr)],
    ['sub', 'Total income', null, money(income)],
    ['line', '− Supplier cost (B)', null, money(profit.supplier_cost_inr)],
    ['line', '− Freight & insurance (C1)', null, money(c1)],
    ['line', '− Logistics partner bills (C2)', null, money(profit.logistics_cost_inr)],
    ['line', '− Other order costs (C3)', null, money(c3)],
    ['line', '− Order expenses (C4)', null, money(profit.expenses_inr)],
    ['sub', 'Total cost', null, money(profit.total_cost_inr)],
    ['grand', 'ORDER PROFIT', null, money(profit.profit_inr)],
    ['grand', 'MARGIN', null, profit.margin_pct !== null ? `${money(profit.margin_pct)}%` : '—'],
  ];
  const ledger = (await orderLedgerService.forOrder(orderId, currency, true)).totals;
  const cash = [
    `₹${money(ledger.buyer_inr)}`,
    `₹${money(ledger.benefits_inr)}`,
    `₹${money(ledger.supplier_inr)}`,
    `₹${money(ledger.logistics_inr)}`,
    `₹${money(ledger.expenses_inr)}`,
    `${ledger.net_inr < 0 ? '−₹' : '₹'}${money(Math.abs(ledger.net_inr))}`,
  ];

  // F — open items for the CA.
  const open = unpaidBills.map((u) => ({ warn: true, text: `Unpaid bill — ${u}` }));
  if (profit.revenue_is_estimated) open.push({ warn: false, text: 'Revenue is estimated — not every buyer payment has cleared with its INR actual recorded' });
  if (benefitsPending > 0) open.push({ warn: false, text: `${benefitsPending} government benefit claim(s) not yet received` });
  if (!expenseSummary.has_travel) {
    const travelEntry = costs.filter((c) => c.tag === 'provisional %');
    open.push({ warn: false, text: travelEntry.length ? `Travel is the provisional % of FOB (₹${travelEntry[0].amount}) — no travel expenses with receipts yet` : 'No travel cost recorded for this order' });
  }
  open.push({ warn: noReceipt > 0, text: `Expenses without a receipt: ${noReceipt} · Staff to reimburse: ₹${money(expenseSummary.to_reimburse)}` });
  const openTasks = (await orderComplianceTaskRepository.forOrder(orderId)).filter((t) => !['approved', 'skipped'].includes(t.status));
  open.push({ warn: false, text: openTasks.length ? `Compliance checklist: ${openTasks.length} task(s) open (${openTasks.map((t) => `${t.task_type_name} — ${ucwords(t.status)}`).join(', ')})` : 'Compliance checklist: every task resolved' });

  return {
    subtitle: `${order.order_reference} (${order.company_legal_name})`,
    details,
    products,
    fob_total: money(fob),
    currency,
    receipts,
    receipts_foreign: money(receiptsForeign),
    receipts_inr: money(receiptsInr),
    supplier,
    freight_rows: freightRows,
    c1_total: money(c1),
    logistics,
    logistics_total: money(profit.logistics_cost_inr),
    logistics_paid: money(logisticsPaid),
    logistics_due: money(logisticsDue),
    costs,
    costs_total: money(c3),
    travel_replaced: expenseSummary.has_travel,
    expenses,
    expenses_total: money(expenseSummary.total),
    zoho,
    zoho_total: money(zohoTotal),
    summary,
    expenses_grand: money(expensesTotal),
    benefits,
    benefits_claimed: money(claimed),
    benefits_received: money(received),
    pl,
    cash,
    open,
  };
}

module.exports = { build };
