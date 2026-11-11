'use strict';

const db = require('../config/db');

/**
 * Owner decision D-310 (point 27, 11 Nov 2026; mockup approvals.png): one
 * Approvals page listing everything the signed-in person may approve — prices,
 * payment terms, documents (My Reviews), e-mails (Level-2 e-mail approvals),
 * bank details and the rest (amendments, cancellations, purchases, short
 * payments, associate registrations, associate price requests, protection
 * requests, reorder requests).
 *
 * Nothing is approved here: each row opens the exact approval screen that
 * already exists, and rows follow the same permissions and Admin ›
 * Self-approval rules as those screens. Port of App\Services\ApprovalsInbox.
 *
 * Every function takes the request (req.user, req.permissions, req.session):
 * the purchase approval limit depends on the department the person works as.
 */

const TABS = {
  prices: 'Prices',
  payment_terms: 'Payment terms',
  documents: 'Documents',
  emails: 'E-mails',
  bank: 'Bank details',
  other: 'Other',
};

/** Holding any of these shows "Approvals" in the menu even when nothing waits (anyone else sees it once something does). */
const APPROVER_PERMISSIONS = [
  'manage_pricing', 'approve_payment_terms', 'approve_email_send', 'approve_documents', 'edit_locked_data', 'approve_purchases',
  'approve_short_payment', 'manage_associate_bank', 'manage_associates', 'manage_field_protection',
];

/** Payment legs: where on the order page each one is cleared. */
const LEG_ANCHORS = {
  advance: 'step-5e-payment-confirm',
  freight: 'step-9d-freight-clear',
  balance: 'step-balance-clear',
  milestone: 'payment-overview',
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function fmt2(n) {
  return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function shorten(text, max = 90) {
  const t = String(text || '').replace(/\s+/g, ' ').trim();
  return [...t].length > max ? `${[...t].slice(0, max - 1).join('').trimEnd()}…` : t;
}

/** "Today 10:42", "Yesterday 16:05", "9 Nov", "9 Nov 2025" — when an item started waiting. */
function sinceLabel(since, today) {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(since || ''));
  if (!m) return String(since || '');
  const date = `${m[1]}-${m[2]}-${m[3]}`;
  const time = m[4] ? ` ${m[4]}:${m[5]}` : '';
  if (date === today) return `Today${time}`;
  const t = new Date(`${today}T12:00:00Z`);
  t.setUTCDate(t.getUTCDate() - 1);
  if (date === t.toISOString().slice(0, 10)) return `Yesterday${time}`;
  const label = `${parseInt(m[3], 10)} ${MONTHS[parseInt(m[2], 10) - 1]}`;
  return m[1] === String(today).slice(0, 4) ? label : `${label} ${m[1]}`;
}

/** "11 Nov, 10:42" — for the requester's "waiting" note. */
function when(at) {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(at || ''));
  if (!m) return String(at || '');
  return `${parseInt(m[3], 10)} ${MONTHS[parseInt(m[2], 10) - 1]}${m[4] ? `, ${m[4]}:${m[5]}` : ''}`;
}

function perms(req) { return (req && req.permissions) || {}; }

function isApprover(req) {
  const p = perms(req);
  return APPROVER_PERMISSIONS.some((k) => !!p[k]);
}

function counts(items) {
  const out = { all: items.length };
  for (const k of Object.keys(TABS)) out[k] = 0;
  for (const i of items) out[i.tab] += 1;
  return out;
}

function row(key, what, since, orderId, orderRef, who, details, url, button, bar) {
  return { key, tab: 'other', what, tone: 'amber', since, order_id: orderId, order_ref: orderRef, line_no: null, who, details, url, button,
    bar, bar_button: bar !== '' ? button : '' };
}

function visibility(req) {
  return require('./orderAccessService').visibilitySql(Number(req.user.id), perms(req), 'o');
}

async function selfOk(rule, userId) {
  return require('./makerCheckerGuard').selfApprovalAllowed(rule, userId);
}

// ---------------------------------------------------------------- the kinds

async function prices(req) {
  const userId = Number(req.user.id);
  const linePricing = require('./orderLinePricing');
  const { sql: vis, params } = visibility(req);
  const lines = await db.query(
    `SELECT op.*, o.order_reference, u.name AS requested_by_name
       FROM order_products op
       JOIN orders o ON o.id = op.order_id
       LEFT JOIN users u ON u.id = op.price_approval_requested_by
      WHERE op.price_approval_requested_at IS NOT NULL AND op.is_active = 1 AND o.status NOT IN ('cancelled', 'lost')${vis}
      ORDER BY op.price_approval_requested_at, op.id`, params);
  const out = [];
  const contexts = {};
  for (const line of lines) {
    // Admin › Self-approval rules: a price this person set themselves (rule price_discount).
    if (Number(line.price_set_by || 0) === userId && !(await selfOk('price_discount', userId))) continue;
    const orderId = Number(line.order_id);
    const no = Number(line.line_no);
    if (!(orderId in contexts)) {
      const order = await require('../repositories/orderRepository').find(orderId);
      contexts[orderId] = order ? await linePricing.context(order) : null;
    }
    const check = contexts[orderId] ? await linePricing.checkLine(line, contexts[orderId]) : { status: null, figures: null };
    const unit = String(line.unit || '').trim();
    const price = line.unit_price !== null && line.unit_price !== undefined ? `USD ${fmt2(line.unit_price)}${unit !== '' ? ` / ${unit}` : ''}` : 'no price';
    const f = check.figures || null;
    let what; let details; let bar;
    if (check.status === linePricing.NO_PRICE) {
      what = 'Price — no product price';
      details = `Product has no price set${line.unit_price !== null && line.unit_price !== undefined ? ` · asked ${price}` : ''}`;
      bar = `line ${no} — its product has no price set${line.unit_price !== null && line.unit_price !== undefined ? ` (asked ${price})` : ''}. The Quotation is held until it is approved.`;
    } else {
      what = 'Price below lowest';
      const lowest = f ? `USD ${fmt2(f.floor)}${f.unit ? ` / ${f.unit}` : ''}` : null;
      details = `${price}${f ? ` · lowest ${fmt2(f.floor)} · minimum ${fmt2(f.minimum)}` : ''}`;
      bar = `line ${no} — ${price} is below the lowest price${lowest !== null ? ` (${lowest})` : ''}. The Quotation is held until it is approved.`;
    }
    out.push({
      key: `price:${line.id}`, tab: 'prices', what, tone: 'red', since: String(line.price_approval_requested_at), order_id: orderId, order_ref: String(line.order_reference), line_no: no,
      who: `line ${no}${line.requested_by_name ? ` · asked by ${line.requested_by_name}` : ''}`,
      details, url: `/orders/${orderId}/products/${line.id}/price-approval`, button: 'Review and approve', bar, bar_button: `Review and approve line ${no}`,
    });
  }
  return out;
}

async function paymentTerms(req) {
  const userId = Number(req.user.id);
  const { sql: vis, params } = visibility(req);
  const rows = await db.query(
    `SELECT o.id, o.order_reference, o.created_by, o.created_at, COALESCE(o.preset_name, pp.preset_name) AS preset_name
       FROM orders o
       JOIN payment_presets pp ON pp.id = o.payment_preset_id
      WHERE COALESCE(o.payment_terms_requires_md_approval, pp.requires_md_approval) = 1
        AND o.payment_terms_approved_at IS NULL AND o.status NOT IN ('cancelled', 'lost')${vis}
      ORDER BY o.created_at, o.id`, params);
  const out = [];
  for (const o of rows) {
    if (Number(o.created_by || 0) === userId && !(await require('./makerCheckerGuard').paymentTermsSelfApprovalAllowed(userId))) continue;
    out.push({
      key: `terms:${o.id}`, tab: 'payment_terms', what: 'Payment terms', tone: 'amber', since: String(o.created_at), order_id: Number(o.id), order_ref: String(o.order_reference), line_no: null,
      who: '', details: String(o.preset_name), url: `/orders/${o.id}#step-1-quotation-prepare`, button: 'Approve terms',
      bar: `the payment terms ("${o.preset_name}") — the Quotation cannot be generated or sent until they are approved.`, bar_button: 'Approve the payment terms',
    });
  }
  return out;
}

/** My Reviews: the documents waiting on this reviewer (as /reviews). */
async function documents(req) {
  const rows = await db.query(
    `SELECT dr.id, dr.assigned_at, d.order_id, d.document_reference, d.revision_number, dt.code, dt.name AS type_name, o.order_reference
       FROM document_reviews dr
       JOIN documents d ON d.id = dr.document_id
       JOIN document_types dt ON dt.id = d.document_type_id
       LEFT JOIN orders o ON o.id = d.order_id
      WHERE dr.reviewer_id = :u AND dr.status = 'pending' AND d.status != 'superseded'
      ORDER BY dr.assigned_at, dr.id`, { u: Number(req.user.id) });
  return rows.map((r) => ({
    key: `review:${r.id}`, tab: 'documents', what: 'Document review', tone: 'amber', since: String(r.assigned_at),
    order_id: r.order_id !== null ? Number(r.order_id) : null, order_ref: String(r.order_reference || ''), line_no: null, who: String(r.type_name),
    details: `${r.code} ${r.document_reference || '—'} · Rev.${String(parseInt(r.revision_number, 10) || 0).padStart(2, '0')}`,
    url: `/reviews#review-${r.id}`, button: 'Review document',
    bar: `the ${r.type_name} (${r.document_reference || r.code}) waits for your review.`, bar_button: 'Review the document',
  }));
}

/** Email Approvals: sends waiting for Level-2 approval (as /email-approvals). */
async function emails(req) {
  const userId = Number(req.user.id);
  const rows = await db.query(
    `SELECT el.id, el.order_id, el.recipient_email, el.subject, el.requested_by, el.created_at, o.order_reference, dt.name AS document_type_name
       FROM email_log el
       LEFT JOIN orders o ON o.id = el.order_id
       LEFT JOIN documents d ON d.id = el.document_id
       LEFT JOIN document_types dt ON dt.id = d.document_type_id
      WHERE el.status = 'pending_approval'${require('./divisionService').orderIdSql('el.order_id', true)}
      ORDER BY el.created_at, el.id`);
  const out = [];
  for (const r of rows) {
    if (r.requested_by !== null && Number(r.requested_by) === userId && !(await selfOk('email_send', userId))) continue;
    out.push({
      key: `email:${r.id}`, tab: 'emails', what: 'E-mail (Level 2)', tone: 'amber', since: String(r.created_at),
      order_id: r.order_id !== null ? Number(r.order_id) : null, order_ref: String(r.order_reference || ''), line_no: null, who: String(r.document_type_name || ''),
      details: `to ${r.recipient_email} · ${r.subject}`, url: `/email-approvals#email-${r.id}`, button: 'Review e-mail',
      bar: `an e-mail to ${r.recipient_email} waits for your Level-2 approval.`, bar_button: 'Review the e-mail',
    });
  }
  return out;
}

function bankItem(key, b, userId, two, mayVerifyOwn, enteredBy, who, url) {
  // D-293: someone other than the enterer verifies (MD/ED with the "bank_details" rule may, with a reason);
  // with two people, the first checker does not also do the second check.
  if (enteredBy === userId && !mayVerifyOwn) return null;
  if (two && Number(b.first_checked_by || 0) === userId) return null;
  const second = two && !!b.first_checked_by;
  return {
    key, tab: 'bank', what: second ? 'Bank details — 2nd check' : (two ? 'Bank details — 1st check' : 'Bank details — verify'), tone: 'amber',
    since: String(b.confirmed_at || b.requested_at), order_id: null, order_ref: '', line_no: null, who,
    details: `${String(b.bank_name || '').trim()} ••••${b.account_last4 || ''}${second ? ' · first check done' : ' · confirmed by the account holder'}`,
    url, button: 'Verify', bar: '', bar_button: '',
  };
}

/** Bank details waiting to be verified (associates, suppliers, logistics partners). */
async function bank(req) {
  const p = perms(req);
  const userId = Number(req.user.id);
  const check = require('./bankDetailsCheck');
  const divisionService = require('./divisionService');
  const out = [];
  const two = (await check.people()) === 'two';
  const mayVerifyOwn = !!(await check.maySelfVerify(userId));
  if (p.manage_associate_bank && (await divisionService.moduleEnabled('associates'))) {
    const rows = await db.query(
      `SELECT b.id, b.associate_id, b.bank_name, b.account_last4, b.requested_by_user, b.first_checked_by, b.requested_at, b.confirmed_at, a.name
         FROM associate_bank_accounts b JOIN associates a ON a.id = b.associate_id
        WHERE b.status = 'pending'${divisionService.sql('a.division_id')} ORDER BY b.id`);
    for (const b of rows) {
      const item = bankItem(`associate_bank:${b.id}`, b, userId, two, mayVerifyOwn, Number(b.requested_by_user || 0), `Associate · ${b.name}`, `/associates/${b.associate_id}#bank`);
      if (item) out.push(item);
    }
  }
  const vendors = [];
  if (p.manage_suppliers) vendors.push(['supplier', 'JOIN suppliers v ON v.id = c.vendor_id', 'v.supplier_legal_name', 'Supplier', (id) => `/suppliers/${id}/edit#bank-changes`]);
  if (p.manage_logistics_partners) vendors.push(['logistics_partner', 'JOIN logistics_partners v ON v.id = c.vendor_id', 'v.partner_name', 'Logistics partner', (id) => `/logistics-partners/${id}/edit#bank-changes`]);
  for (const [kind, join, nameCol, label, url] of vendors) {
    const rows = await db.query(
      `SELECT c.id, c.vendor_id, c.bank_name, c.account_last4, c.entered_by, c.first_checked_by, c.entered_at AS requested_at, c.confirmed_at, ${nameCol} AS name
         FROM vendor_bank_changes c ${join}
        WHERE c.vendor_kind = :k AND c.status = 'pending' ORDER BY c.id`, { k: kind });
    for (const c of rows) {
      const item = bankItem(`vendor_bank:${c.id}`, c, userId, two, mayVerifyOwn, Number(c.entered_by || 0), `${label} · ${c.name}`, url(Number(c.vendor_id)));
      if (item) out.push(item);
    }
  }
  return out;
}

async function other(req) {
  const p = perms(req);
  const userId = Number(req.user.id);
  const divisionService = require('./divisionService');
  const partPayment = require('./partPaymentService');
  const { sql: vis, params } = visibility(req);
  const out = [];

  if (p.approve_documents) {
    const rows = await db.query(`SELECT a.id, a.order_id, a.amendment_reference, a.reason, a.created_by, a.created_at, o.order_reference
                                   FROM amendments a JOIN orders o ON o.id = a.order_id WHERE a.status = 'pending'${vis} ORDER BY a.created_at, a.id`, params);
    for (const a of rows) {
      if (a.created_by !== null && Number(a.created_by) === userId && !(await selfOk('amendment', userId))) continue;
      out.push(row(`amendment:${a.id}`, 'Amendment', String(a.created_at), Number(a.order_id), String(a.order_reference), String(a.amendment_reference),
        shorten(a.reason), `/orders/${a.order_id}/amendments`, 'Review amendment', `amendment ${a.amendment_reference} waits for MD approval.`));
    }
  }

  if (p.edit_locked_data) {
    const rows = await db.query(`SELECT c.id, c.order_id, c.reason, c.requested_by, c.requested_at, o.order_reference, u.name AS requested_by_name
                                   FROM order_cancellations c JOIN orders o ON o.id = c.order_id LEFT JOIN users u ON u.id = c.requested_by
                                  WHERE c.status = 'pending'${vis} ORDER BY c.requested_at, c.id`, params);
    for (const c of rows) {
      if (c.requested_by !== null && Number(c.requested_by) === userId && !(await selfOk('cancellation', userId))) continue;
      out.push(row(`cancellation:${c.id}`, 'Cancellation request', String(c.requested_at), Number(c.order_id), String(c.order_reference),
        c.requested_by_name ? `asked by ${c.requested_by_name}` : '', shorten(c.reason), `/orders/${c.order_id}#cancellation-request`, 'Review cancellation', 'a request to cancel this order.'));
    }
  }

  if (p.approve_purchases && (await divisionService.moduleEnabled('supplier_purchase'))) {
    const purchasing = require('./purchasingService');
    const rows = await db.query(`SELECT pr.*, o.order_reference, q.unit_price_inr, q.gst_percent, s.supplier_legal_name
                                   FROM purchase_requirements pr JOIN orders o ON o.id = pr.order_id
                                   LEFT JOIN purchase_quotes q ON q.id = pr.awarded_quote_id LEFT JOIN suppliers s ON s.id = q.supplier_id
                                  WHERE pr.status = 'awarded' AND pr.approval_needed = 1 AND pr.awarded_quote_id IS NOT NULL${vis} ORDER BY pr.awarded_at, pr.id`, params);
    for (const r of rows) {
      const value = purchasing.awardValue({ unit_price_inr: r.unit_price_inr, gst_percent: r.gst_percent }, Number(r.quantity));
      if ((await purchasing.approvalRefusal(req, r, value)) !== null) continue;
      out.push(row(`purchase:${r.id}`, 'Purchase approval', String(r.awarded_at), Number(r.order_id), String(r.order_reference), `PR-${r.id}`,
        `${r.supplier_legal_name || ''} · ₹${fmt2(value)} · ${shorten(r.description)}`, `/procurement/${r.id}`, 'Review purchase',
        `purchase PR-${r.id} (${r.supplier_legal_name || ''}) waits for approval.`));
    }
  }

  if (p.approve_short_payment) {
    for (const s of await shortRequests(vis, params)) {
      if (Number(s.requested_by) === userId) continue; // asked because they could not clear it themselves
      const milestoneId = Number(s.milestone_id) > 0 ? Number(s.milestone_id) : null;
      if ((await partPayment.recordedBy(Number(s.order_id), String(s.leg), milestoneId, userId)) && !(await selfOk('short_payment', userId))) continue;
      const label = partPayment.label(String(s.leg), milestoneId ? { seq: s.milestone_seq } : null);
      const owed = partPayment.money(String(s.currency_code || ''), s.owed);
      out.push(row(`short:${s.id}`, 'Short payment', String(s.requested_at), Number(s.order_id), String(s.order_reference),
        s.requested_by_name ? `asked by ${s.requested_by_name}` : '', `${label}: ${owed} still owed — clear it short with a reason, or wait for the rest`,
        `/orders/${s.order_id}#${LEG_ANCHORS[String(s.leg)] || 'payment-overview'}`, 'Review payment',
        `${label.toLowerCase()} is short by ${owed} — clear it short with a reason, or wait for the rest.`));
    }
  }

  if (p.manage_associates && (await divisionService.moduleEnabled('associates'))) {
    for (const r of await db.query("SELECT id, name, country, based_in, created_at FROM associate_registrations WHERE status = 'pending' ORDER BY created_at, id")) {
      out.push(row(`registration:${r.id}`, 'Associate registration', String(r.created_at), null, '', `Associate · ${r.name}`,
        `Based in ${r.based_in === 'india' ? 'India' : 'abroad'}${r.country ? ` · ${r.country}` : ''}`, '/associates/registrations', 'Review registration', ''));
    }
  }

  if (p.manage_pricing && (await divisionService.moduleEnabled('associates'))) {
    const rows = await db.query(`SELECT r.id, r.number, r.requested_at, r.selection_text, pr.name AS product_name, a.name AS associate_name
                                   FROM price_requests r JOIN catalog_products pr ON pr.id = r.product_id JOIN associates a ON a.id = r.associate_id
                                  WHERE r.status = 'pending'${divisionService.sql('r.division_id')} ORDER BY r.requested_at, r.id`);
    for (const r of rows) {
      out.push(row(`price_request:${r.id}`, 'Associate price request', String(r.requested_at), null, '', `Associate · ${r.associate_name}`,
        `${r.number} · ${r.product_name}${String(r.selection_text || '').trim() !== '' ? ` · ${r.selection_text}` : ''}`, '/associates/price-requests?show=pending', 'Give a price', ''));
    }
  }

  if (p.manage_field_protection) {
    const isSuper = await require('./superAdminService').isEffective(userId);
    const rows = await db.query(`SELECT f.id, f.record_label, f.table_name, f.requested_action, f.reason, f.requested_by, f.created_at, u.name AS requested_by_name
                                   FROM field_protection_requests f LEFT JOIN users u ON u.id = f.requested_by WHERE f.status = 'pending' ORDER BY f.created_at, f.id`);
    for (const f of rows) {
      if (Number(f.requested_by) === userId && !isSuper) continue;
      out.push(row(`protection:${f.id}`, f.requested_action === 'lock' ? 'Protect a field' : 'Unprotect a field', String(f.created_at), null, '',
        f.requested_by_name ? `asked by ${f.requested_by_name}` : '', `${f.record_label} · ${shorten(f.reason)}`, '/admin/field-protection', 'Review request', ''));
    }
  }

  if (p.manage_orders) {
    const rows = await db.query(`SELECT rr.id, rr.source_order_id, rr.submitted_at, rr.notes, o.order_reference
                                   FROM order_reorder_requests rr JOIN orders o ON o.id = rr.source_order_id
                                  WHERE rr.status = 'pending'${vis} ORDER BY rr.submitted_at, rr.id`, params);
    for (const r of rows) {
      out.push(row(`reorder:${r.id}`, 'Reorder request', String(r.submitted_at), null, String(r.order_reference), 'from the buyer',
        shorten(r.notes || ''), `/reorder-requests/${r.id}`, 'Review reorder', ''));
    }
  }
  return out;
}

/** Every item this person may approve now, oldest first (cached on the request). */
async function items(req) {
  if (req._approvalItems) return req._approvalItems;
  const p = perms(req);
  let list = [];
  if (p.manage_pricing) list = list.concat(await prices(req));
  if (p.approve_payment_terms) list = list.concat(await paymentTerms(req));
  list = list.concat(await documents(req));
  if (p.approve_email_send) list = list.concat(await emails(req));
  list = list.concat(await bank(req));
  list = list.concat(await other(req));
  list.sort((a, b) => (String(a.since) < String(b.since) ? -1 : String(a.since) > String(b.since) ? 1 : (a.key < b.key ? -1 : a.key > b.key ? 1 : 0)));
  req._approvalItems = list;
  return list;
}

function resetCache(req) {
  if (req) delete req._approvalItems;
}

/** The menu badge: never lets a failing query break the page around it (the Approvals page itself still shows the error). */
async function countFor(req) {
  try {
    return (await items(req)).length;
  } catch (e) {
    require('../helpers/logger').error('APPROVALS_INBOX', e);
    return 0;
  }
}

/** The items of one order (the yellow bar at the top of the order page). */
async function forOrder(req, orderId) {
  return (await items(req)).filter((i) => Number(i.order_id || 0) === Number(orderId));
}

/** What this person asked for on the order that someone else must approve. */
async function waitingOnOthers(req, orderId) {
  const userId = Number(req.user.id);
  const mine = (await forOrder(req, orderId)).map((i) => i.key);
  const out = [];
  const lines = await db.query(`SELECT id, line_no, price_approval_requested_at FROM order_products
                                 WHERE order_id = :o AND is_active = 1 AND price_approval_requested_at IS NOT NULL AND price_approval_requested_by = :u ORDER BY line_no`, { o: orderId, u: userId });
  for (const r of lines) {
    if (!mine.includes(`price:${r.id}`)) out.push(`Line ${r.line_no} — sent to the MD for approval on ${when(r.price_approval_requested_at)} — waiting.`);
  }
  const queries = [
    ['cancellation', "SELECT id, requested_at AS at FROM order_cancellations WHERE order_id = :o AND status = 'pending' AND requested_by = :u", 'Cancellation request'],
    ['amendment', "SELECT id, created_at AS at FROM amendments WHERE order_id = :o AND status = 'pending' AND created_by = :u", 'Amendment'],
    ['email', "SELECT id, created_at AS at FROM email_log WHERE order_id = :o AND status = 'pending_approval' AND requested_by = :u", 'E-mail'],
    ['short', 'SELECT id, requested_at AS at FROM short_payment_requests WHERE order_id = :o AND requested_by = :u', 'Short payment'],
  ];
  for (const [kind, sql, label] of queries) {
    for (const r of await db.query(`${sql} ORDER BY id`, { o: orderId, u: userId })) {
      if (kind === 'short' && !(await shortRequestOpen(Number(r.id)))) continue;
      if (!mine.includes(`${kind}:${r.id}`)) out.push(`${label} — sent for approval on ${when(r.at)} — waiting.`);
    }
  }
  return out;
}

// ------------------------------------------------- short payment requests

/**
 * D-310: a payment that came in short waits for a manager with "Approve short
 * payment". The refusal of the person clearing it files the request (one per
 * payment leg, refreshed when asked again); every such manager is told once.
 */
async function recordShortPaymentRequest(orderId, leg, milestone, userId, owed, currency) {
  const partPayment = require('./partPaymentService');
  const milestoneId = milestone ? Number(milestone.id) : 0;
  const existing = await db.queryOne('SELECT id FROM short_payment_requests WHERE order_id = :o AND leg = :l AND milestone_id = :m', { o: orderId, l: leg, m: milestoneId });
  if (existing && (await shortRequestOpen(Number(existing.id)))) {
    await db.execute('UPDATE short_payment_requests SET owed_amount = :a WHERE id = :id', { a: Math.round(owed * 100) / 100, id: existing.id });
    return;
  }
  await db.execute(
    `INSERT INTO short_payment_requests (order_id, leg, milestone_id, owed_amount, currency_code, requested_by, requested_at)
     VALUES (:o, :l, :m, :a, :c, :u, NOW())
     ON DUPLICATE KEY UPDATE owed_amount = VALUES(owed_amount), currency_code = VALUES(currency_code), requested_by = VALUES(requested_by), requested_at = NOW()`,
    { o: orderId, l: leg, m: milestoneId, a: Math.round(owed * 100) / 100, c: currency || null, u: userId });
  const order = await require('../repositories/orderRepository').find(orderId);
  const label = partPayment.label(leg, milestone);
  const message = `Order ${(order && order.order_reference) || `#${orderId}`}: ${label} is short by ${partPayment.money(currency, owed)} and waits for your approval to clear it short (Approvals).`;
  const notifications = require('../repositories/notificationRepository');
  for (const managerId of await require('../repositories/permissionRepository').usersWithPermission('approve_short_payment')) {
    if (Number(managerId) !== Number(userId)) await notifications.create(Number(managerId), null, 'short_payment_approval', orderId, message.slice(0, 500));
  }
  await require('../repositories/auditLogRepository').log(userId, 'SHORT_PAYMENT_APPROVAL_REQUESTED', 'orders', orderId, `${leg}${milestone ? `:${milestone.seq || ''}` : ''}`, null, String(Math.round(owed * 100) / 100), message);
}

/** What is still owed, or null when the request is answered (cleared short, or the rest received). */
async function shortOwed(r) {
  const partPayment = require('./partPaymentService');
  let milestone = null;
  if (Number(r.milestone_id) > 0) {
    milestone = await require('../repositories/paymentMilestoneRepository').find(Number(r.milestone_id));
    if (!milestone) return null;
  }
  const s = await partPayment.summary(Number(r.order_id), String(r.leg), milestone);
  if (s.owed === null || s.owed === undefined || s.owed <= partPayment.TOLERANCE) return null;
  if (s.shortfall && String(s.shortfall.approved_at) >= String(r.requested_at)) return null;
  return Number(s.owed);
}

async function shortRequestOpen(requestId) {
  const r = await db.queryOne('SELECT r.*, o.status AS order_status FROM short_payment_requests r JOIN orders o ON o.id = r.order_id WHERE r.id = :id', { id: requestId });
  if (!r || ['cancelled', 'lost'].includes(r.order_status)) return false;
  return (await shortOwed(r)) !== null;
}

/** The open short payment requests of the orders this person may see. */
async function shortRequests(vis, params) {
  const rows = await db.query(
    `SELECT r.*, o.order_reference, o.status AS order_status, u.name AS requested_by_name, m.seq AS milestone_seq
       FROM short_payment_requests r JOIN orders o ON o.id = r.order_id
       LEFT JOIN users u ON u.id = r.requested_by
       LEFT JOIN order_payment_milestones m ON m.id = r.milestone_id
      WHERE o.status NOT IN ('cancelled', 'lost')${vis} ORDER BY r.requested_at, r.id`, params);
  const out = [];
  for (const r of rows) {
    const owed = await shortOwed(r);
    if (owed !== null) out.push({ ...r, owed });
  }
  return out;
}

module.exports = {
  TABS, APPROVER_PERMISSIONS, LEG_ANCHORS,
  items, counts, countFor, forOrder, waitingOnOthers, isApprover, resetCache, sinceLabel, when,
  recordShortPaymentRequest, shortRequestOpen,
};
