'use strict';

/**
 * Owner points 6 / 15 (2 Nov 2026): "only permitted people see amounts —
 * not a single leak". The audit log stores old/new values, and many rows
 * carry money (a remittance amount, a supplier bill, a commission, a cost).
 * Whoever may read the audit log still sees that the action happened, by
 * whom and when — but a row's values are hidden unless they also hold the
 * permission for that kind of money. Used by the audit log page, the order
 * audit page, the per-order report's Full Audit Trail, the dashboard's
 * Recent Activity and their CSV exports. PHP twin: AuditMoneyMask.php.
 */
const db = require('../config/db');

const HIDDEN = '— hidden (no permission to see these amounts)';

const PERMISSIONS = {
  selling: ['view_selling_prices'],
  buyer_payments: ['view_buyer_payments'],
  purchase: ['view_purchase_prices'],
  logistics: ['view_logistics_costs'],
  incentives: ['manage_incentives'],
  // D-172: Accounts sees what to pay.
  payouts: ['manage_incentives', 'pay_incentives'],
  financials: ['manage_order_financials', 'ca_module_view'],
};

const ACTION_RULES = [
  [/^INCENTIVE_PAID|^ASSOCIATE_PAYOUT|^ASSOCIATE_CLAWBACK|^ASSOCIATE_INVOICE/, 'payouts'],
  [/INCENTIVE|COMMISSION|PAYOUT|CLAWBACK|ASSOCIATE_SETTING|LADDER|ASSOCIATE_RATE/, 'incentives'],
  [/^ORDER_(COST_ENTRY|EXPENSE|TRAVEL_EXPENSE|FINANCIALS|INSPECTION_FEE|FULLY_SETTLED)|^CA_EXPENSE|EXPORT_BENEFIT|GST_REFUND|CAFIN|PROFIT/, 'financials'],
  [/^(ADVANCE|BALANCE|FREIGHT)_PAYMENT|MILESTONE_PAYMENT|^LC_|^ORDER_LC|^CA_INR_ACTUAL|EXCHANGE_RATE|SHORTFALL|SHORT_PAYMENT|^CA_FIRC|CLIENT_PAYMENT_REPORT|CANCELLATION_REFUND|^CA_BANK_STATEMENT|PAYMENT_REPORTED/, 'buyer_payments'],
  [/^LOGISTICS_BILL|^ORDER_FREIGHT|FREIGHT_TERMS|FREIGHT_COST/, 'logistics'],
  [/^SUPPLIER_(PO|ADVANCE|BALANCE|BILL)|^PURCHASE_(AWARD|QUOTE)|APPROVAL_LIMIT|SUPPLIER_PAYMENT/, 'purchase'],
  [/^AMENDMENT_|PAYMENT_SCHEDULE|QUOTATION_VALUE|ORDER_PRODUCT|UNIT_PRICE/, 'selling'],
];

const ENTITY_RULES = {
  incentive_payouts: 'payouts', incentive_settings: 'incentives', order_incentives: 'incentives', user_incentive_roles: 'incentives',
  order_cost_entries: 'financials', order_expenses: 'financials', order_travel_expenses: 'financials', ca_expenses: 'financials',
  ca_export_benefits: 'financials', gst_refund_claims: 'financials', gst_refund_credits: 'financials', order_inspection_fees: 'financials',
  order_payment_status: 'buyer_payments', order_payment_milestones: 'buyer_payments', order_lc_details: 'buyer_payments',
  order_lc_amendments: 'buyer_payments', order_payment_shortfalls: 'buyer_payments', client_payment_reports: 'buyer_payments',
  ca_bank_statement_lines: 'buyer_payments',
  order_freight: 'logistics',
  order_supplier_po: 'purchase', purchase_quotes: 'purchase', purchase_requirements: 'purchase',
  order_products: 'selling', amendments: 'selling', payment_presets: 'selling',
};

const MONEY_FIELD = /price|amount|cost|rate|fob|freight|insurance|commission|incentive|profit|_inr|value|payable|paid|advance|balance|expense|margin/i;

async function vendorBillClass(billId, action) {
  if (action.startsWith('LOGISTICS')) return 'logistics';
  if (billId > 0) {
    const row = await db.queryOne('SELECT vendor_kind FROM order_vendor_bills WHERE id = :id', { id: billId });
    if (row && row.vendor_kind === 'logistics') return 'logistics';
  }
  return 'purchase';
}

/** The kind of money an audit row carries, or null when it carries none. */
async function classFor(row) {
  const action = String(row.action_type || '');
  const entity = String(row.entity_type || '');
  const field = String(row.field_name || '');
  if (entity === 'order_vendor_bills' || action.startsWith('VENDOR_BILL')) {
    return vendorBillClass(Number(row.entity_id || 0), action);
  }
  for (const [regex, cls] of ACTION_RULES) {
    if (regex.test(action)) return cls;
  }
  if (ENTITY_RULES[entity]) return ENTITY_RULES[entity];
  if (field !== '' && MONEY_FIELD.test(field)) {
    if (/cost|supplier|factory|purchase/i.test(field)) return 'purchase';
    if (/freight|insurance/i.test(field)) return 'logistics';
    if (/commission|incentive/i.test(field)) return 'incentives';
    if (/profit|expense|margin/i.test(field)) return 'financials';
    if (/advance|balance|paid|_inr|rate/i.test(field)) return 'buyer_payments';
    return 'selling';
  }
  return null;
}

/** Whether these permissions (req.permissions) show this class of money. */
function canSee(permissions, cls) {
  return (PERMISSIONS[cls] || []).some((p) => Boolean(permissions && permissions[p]));
}

/** One row, with its values hidden when these permissions may not see that money. */
async function mask(permissions, row) {
  const cls = await classFor(row);
  if (cls === null || canSee(permissions, cls)) return row;
  const out = { ...row };
  for (const key of ['old_value', 'new_value']) {
    if (out[key] !== null && out[key] !== undefined && out[key] !== '') out[key] = HIDDEN;
  }
  if (out.reason) out.reason = String(out.reason).replace(/\d[\d,]*(\.\d+)?/g, '•••');
  out.values_hidden = true;
  return out;
}

async function maskAll(permissions, rows) {
  const out = [];
  for (const row of rows || []) out.push(await mask(permissions, row));
  return out;
}

module.exports = { HIDDEN, classFor, canSee, mask, maskAll };
