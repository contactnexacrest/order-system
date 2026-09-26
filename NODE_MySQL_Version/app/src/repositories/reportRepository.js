'use strict';

const db = require('../config/db');
const orderRepository = require('./orderRepository');
const documentRepository = require('./documentRepository');
const amendmentRepository = require('./amendmentRepository');
const disputeRepository = require('./disputeRepository');
const testModeService = require('../services/testModeService');

/**
 * Test Mode (docs/schema.sql Section V) — reports must never mix test and
 * production data. Every query below is scoped to match the CURRENT mode:
 * with Test Mode on, only test-flagged orders/clients show up; off, only
 * real ones do. `isTestModeFlag()` is the one place that reads the switch.
 */
async function isTestModeFlag() {
  return (await testModeService.isEnabled()) ? 1 : 0;
}

/**
 * Port of App\Repositories\ReportRepository. Spec Section 16 — "REPORTS:
 * Per client ... Per order ... Aggregate ... Export to CSV/Excel." Three
 * query shapes, one per report type named in the spec — kept as plain
 * read queries here; reportController decides which one a saved
 * report_definitions row runs.
 */

/** @returns {Promise<object>} order history, payments, products, totals for one client */
async function perClient(clientId) {
  const isTestMode = await isTestModeFlag();
  const client = await db.queryOne('SELECT * FROM clients WHERE id = :id', { id: clientId });
  if (client && parseInt(client.is_test_data, 10) !== isTestMode) {
    // A real client while Test Mode is on, or a test client while it's
    // off — never shown, same contract as "client not found".
    return { client: null, orders: [], documents: [], payments: [], products: [], total_fob_value_by_currency: {}, total_cleared_by_currency: {} };
  }

  const orders = await db.query(
    `SELECT o.id, o.order_reference, o.status, o.created_at,
            i.code AS incoterm_code, cur.code AS currency_code,
            sm.stage_name AS current_stage_name
     FROM orders o
     JOIN incoterms i ON i.id = o.incoterm_id
     JOIN currencies cur ON cur.id = o.currency_id
     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
     WHERE o.client_id = :client_id AND o.is_test_data = :is_test_data
     ORDER BY o.created_at DESC`,
    { client_id: clientId, is_test_data: isTestMode }
  );

  const orderIds = orders.map((o) => o.id);
  let documents = [];
  let payments = [];
  let products = [];
  // Keyed by currency_code — see "currency-blind totals" fix. A single
  // client can have orders in different currencies (currency_id lives on
  // `orders`, not `clients`), so a single scalar total would silently add
  // e.g. USD and EUR amounts together as if they were the same unit. Every
  // row below carries its own order's currency_code (joined in SQL, not
  // assumed), and totals are grouped by it.
  const totalFobByCurrency = {};
  const totalClearedByCurrency = {};

  if (orderIds.length) {
    const placeholders = orderIds.map((_, i) => `:id${i}`).join(',');
    const params = {};
    orderIds.forEach((id, i) => { params[`id${i}`] = id; });

    documents = await db.query(
      `SELECT d.order_id, dt.code AS type_code, d.document_reference, d.revision_number, d.status, d.generated_at
       FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
       WHERE d.order_id IN (${placeholders}) ORDER BY d.order_id, d.generated_at`,
      params
    );

    payments = await db.query(
      `SELECT ops.order_id, ops.advance_amount, ops.advance_cleared_at, ops.balance_amount, ops.balance_cleared_at,
              ops.freight_amount, ops.freight_cleared_at, cur.code AS currency_code
       FROM order_payment_status ops
       JOIN orders o2 ON o2.id = ops.order_id
       JOIN currencies cur ON cur.id = o2.currency_id
       WHERE ops.order_id IN (${placeholders})`,
      params
    );
    for (const p of payments) {
      const cc = p.currency_code;
      if (p.advance_cleared_at) {
        totalClearedByCurrency[cc] = (totalClearedByCurrency[cc] || 0) + (parseFloat(p.advance_amount) || 0);
      }
      if (p.balance_cleared_at) {
        totalClearedByCurrency[cc] = (totalClearedByCurrency[cc] || 0) + (parseFloat(p.balance_amount) || 0);
      }
    }

    products = await db.query(
      `SELECT p.order_id, p.description, p.quantity, p.quantity_is_tbc, p.unit, p.unit_price, p.fob_value, cur.code AS currency_code
       FROM order_products p
       JOIN orders o2 ON o2.id = p.order_id
       JOIN currencies cur ON cur.id = o2.currency_id
       WHERE p.order_id IN (${placeholders}) AND p.is_active = 1 ORDER BY p.order_id, p.line_no`,
      params
    );
    for (const prod of products) {
      const cc = prod.currency_code;
      totalFobByCurrency[cc] = (totalFobByCurrency[cc] || 0) + (parseFloat(prod.fob_value ?? 0) || 0);
    }
  }

  return {
    client,
    orders,
    documents,
    payments,
    products,
    total_fob_value_by_currency: sortObjectKeys(totalFobByCurrency),
    total_cleared_by_currency: sortObjectKeys(totalClearedByCurrency),
  };
}

function sortObjectKeys(obj) {
  const sorted = {};
  for (const key of Object.keys(obj).sort()) {
    sorted[key] = obj[key];
  }
  return sorted;
}

/** @returns {Promise<object>} all stage data, all documents, full audit for one order */
async function perOrder(orderId) {
  const isTestMode = await isTestModeFlag();
  const order = await orderRepository.find(orderId);
  if (order && parseInt(order.is_test_data, 10) !== isTestMode) {
    return { order: null, stages: [], documents: [], audit: [] };
  }

  const stages = await db.query(
    `SELECT sm.stage_name, sm.sequence, os.status, os.unlocked_at, os.gate_passed_at, os.skip_reason,
            u.name AS gate_passed_by_name
     FROM order_stages os
     JOIN stages_master sm ON sm.id = os.stage_id
     LEFT JOIN users u ON u.id = os.gate_passed_by
     WHERE os.order_id = :order_id ORDER BY sm.sequence`,
    { order_id: orderId }
  );

  const documents = await documentRepository.forOrder(orderId);

  // "Full audit" for an order = audit_log rows against the order itself,
  // plus every document/amendment/dispute that belongs to it — audit_log
  // has no order_id column of its own (by design: one immutable log for
  // every entity type in the system, not a per-order shadow table), so
  // this assembles the order's audit trail by first collecting the
  // entity ids that belong to it.
  const documentIds = documents.map((d) => d.id);
  const amendmentIds = (await amendmentRepository.forOrder(orderId)).map((a) => a.id);
  const disputeIds = (await disputeRepository.forOrder(orderId)).map((d) => d.id);

  const conditions = ["(al.entity_type = 'orders' AND al.entity_id = :order_id)"];
  const params = { order_id: orderId };
  if (documentIds.length) {
    conditions.push(`(al.entity_type = 'documents' AND al.entity_id IN (${documentIds.map((id) => parseInt(id, 10)).join(',')}))`);
  }
  if (amendmentIds.length) {
    conditions.push(`(al.entity_type = 'amendments' AND al.entity_id IN (${amendmentIds.map((id) => parseInt(id, 10)).join(',')}))`);
  }
  if (disputeIds.length) {
    conditions.push(`(al.entity_type = 'disputes' AND al.entity_id IN (${disputeIds.map((id) => parseInt(id, 10)).join(',')}))`);
  }

  const audit = await db.query(
    `SELECT al.*, u.name AS user_name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
     WHERE ${conditions.join(' OR ')} ORDER BY al.created_at ASC`,
    params
  );

  return { order, stages, documents, audit };
}

/**
 * Aggregate report — date range / stage / Incoterm / country filters, per
 * the spec's own phrasing. All filters optional and combinable.
 *
 * @returns {Promise<Array<object>>}
 */
async function aggregate(dateFrom, dateTo, stageId, incotermId, country) {
  const where = ['o.is_test_data = :is_test_data'];
  const params = { is_test_data: await isTestModeFlag() };
  if (dateFrom) {
    where.push('DATE(o.created_at) >= :date_from');
    params.date_from = dateFrom;
  }
  if (dateTo) {
    where.push('DATE(o.created_at) <= :date_to');
    params.date_to = dateTo;
  }
  if (stageId) {
    where.push('o.current_stage_id = :stage_id');
    params.stage_id = stageId;
  }
  if (incotermId) {
    where.push('o.incoterm_id = :incoterm_id');
    params.incoterm_id = incotermId;
  }
  if (country) {
    where.push('c.country_of_destination = :country');
    params.country = country;
  }

  let sql = `SELECT o.id, o.order_reference, o.status, o.created_at,
                    c.company_legal_name, c.country_of_destination,
                    i.code AS incoterm_code, cur.code AS currency_code,
                    sm.stage_name AS current_stage_name,
                    (SELECT COALESCE(SUM(fob_value), 0) FROM order_products WHERE order_id = o.id AND is_active = 1) AS total_fob_value
             FROM orders o
             JOIN clients c ON c.id = o.client_id
             JOIN incoterms i ON i.id = o.incoterm_id
             JOIN currencies cur ON cur.id = o.currency_id
             LEFT JOIN stages_master sm ON sm.id = o.current_stage_id`;
  if (where.length) {
    sql += ' WHERE ' + where.join(' AND ');
  }
  sql += ' ORDER BY o.created_at DESC';

  return db.query(sql, params);
}

/**
 * Summary statistics for the Aggregate Report — computed from the exact
 * same row set aggregate() returns (never a second, separate query), so
 * the summary numbers and the detail table beneath them can never
 * disagree about what's included.
 *
 * @param {Array<object>} rows
 */
function aggregateSummary(rows) {
  const fobByCurrency = {};
  const byStage = {};
  const byStatus = {};
  const byCountry = {};

  for (const r of rows) {
    const cc = r.currency_code;
    fobByCurrency[cc] = (fobByCurrency[cc] || 0) + (parseFloat(r.total_fob_value) || 0);

    const stage = r.current_stage_name ?? 'Quotation';
    byStage[stage] = (byStage[stage] || 0) + 1;

    byStatus[r.status] = (byStatus[r.status] || 0) + 1;

    const country = r.country_of_destination ?? '—';
    byCountry[country] = (byCountry[country] || 0) + 1;
  }

  return {
    total_orders: rows.length,
    fob_by_currency: sortObjectKeys(fobByCurrency),
    by_stage: sortByCountDesc(byStage),
    by_status: sortByCountDesc(byStatus),
    by_country: sortByCountDesc(byCountry),
  };
}

function sortByCountDesc(obj) {
  const sorted = {};
  for (const key of Object.keys(obj).sort((a, b) => obj[b] - obj[a])) {
    sorted[key] = obj[key];
  }
  return sorted;
}

/** @returns {Promise<string[]>} distinct country_of_destination values seeded so far, for the aggregate filter dropdown */
async function distinctCountries() {
  const rows = await db.query(
    "SELECT DISTINCT country_of_destination FROM clients WHERE country_of_destination IS NOT NULL AND country_of_destination != '' ORDER BY country_of_destination"
  );
  return rows.map((r) => r.country_of_destination);
}

// ================================================================
// OPERATIONS QUEUES / FUNNEL REPORT — added 2026-09-19
// ================================================================
//
// Every bucket below reads columns that already existed for the order
// workflow itself (order_stages via orders.current_stage_id, documents.status,
// order_payment_status, order_shipping, order_supplier_po) plus the new
// orders.status = 'lost' value and its lost_reason/lost_at/lost_by columns.
// Nothing here is a new source of truth — it's new queries against data the
// app was already recording.
//
// Key fact this relies on: orders.current_stage_id always holds the FRONTIER
// stage — the one whose gate has NOT yet passed (StageGateService moves it
// forward the instant a gate passes). So "current_stage_id = X" already means
// "waiting at X", with no need to separately inspect order_stages.status.
//
// "Latest document of type X for this order" is always
// `ORDER BY revision_number DESC LIMIT 1` — matches documentRepository's own
// latestOfType() convention, so a document that was superseded by a later
// revision is correctly ignored in favor of the current one.

const ORDER_ROW_COLS = `o.id, o.order_reference, c.company_legal_name, o.created_at`;
const ORDER_ROW_JOIN = `FROM orders o JOIN clients c ON c.id = o.client_id`;

function latestDocStatusExpr(code) {
  return `(SELECT d.status FROM documents d
             JOIN document_types dt ON dt.id = d.document_type_id
            WHERE dt.code = '${code}' AND d.order_id = o.id
            ORDER BY d.revision_number DESC LIMIT 1)`;
}

function notSentClause(code) {
  const expr = latestDocStatusExpr(code);
  return `(${expr} IS NULL OR ${expr} <> 'sent')`;
}

function sentClause(code) {
  return `${latestDocStatusExpr(code)} = 'sent'`;
}

async function queueRows(whereSql, extraJoins = '') {
  return db.query(
    `SELECT ${ORDER_ROW_COLS} ${ORDER_ROW_JOIN} ${extraJoins} WHERE (${whereSql}) AND o.is_test_data = :is_test_data ORDER BY o.created_at`,
    { is_test_data: await isTestModeFlag() }
  );
}

/** Bucket 1 — Quotation not yet dispatched to the buyer (drafted or not even generated yet). */
async function quotationAwaitingDispatch() {
  return queueRows(`o.status = 'active' AND ${notSentClause('QT')}`);
}

/** Bucket 2 (spec item #15) — QT sent, buyer's own PO not yet recorded (Stage 2, "Buyer PO"). */
async function awaitingBuyerPo() {
  return queueRows(
    `o.status = 'active' AND sm.stage_slug = 'buyer_po' AND ${sentClause('QT')}`,
    'JOIN stages_master sm ON sm.id = o.current_stage_id'
  );
}

/** Spec item #14 — our own Order-Acceptance PO (document type BUYERPO) not yet sent back to the buyer. */
async function orderAcceptanceAwaitingDispatch() {
  return queueRows(
    `o.status = 'active' AND sm.stage_slug NOT IN ('quotation','buyer_po') AND ${notSentClause('BUYERPO')}`,
    'JOIN stages_master sm ON sm.id = o.current_stage_id'
  );
}

/** Spec item #2 — sitting at the PI stage right now. */
async function atPiStage() {
  return queueRows(`o.status = 'active' AND sm.stage_slug = 'pi'`, 'JOIN stages_master sm ON sm.id = o.current_stage_id');
}

/** Spec item #11 — at the OC stage, OC not yet sent. */
async function ocAwaitingDispatch() {
  return queueRows(
    `o.status = 'active' AND sm.stage_slug = 'oc_production' AND ${notSentClause('OC')}`,
    'JOIN stages_master sm ON sm.id = o.current_stage_id'
  );
}

/** Spec item #12 — OC sent, buyer's countersigned acknowledgement not yet recorded (confirmBuyerAcknowledged). */
async function ocAwaitingAcknowledgement() {
  return queueRows(
    `o.status = 'active' AND sm.stage_slug = 'oc_production' AND ${sentClause('OC')}`,
    'JOIN stages_master sm ON sm.id = o.current_stage_id'
  );
}

/** Spec item #16 — reached Supplier PO stage or beyond, nothing drafted yet in order_supplier_po. */
async function supplierPoNotYetCreated() {
  return queueRows(
    `o.status = 'active'
     AND sm.stage_slug NOT IN ('quotation','buyer_po','pi','oc_production')
     AND NOT EXISTS (SELECT 1 FROM order_supplier_po sp WHERE sp.order_id = o.id)`,
    'JOIN stages_master sm ON sm.id = o.current_stage_id'
  );
}

/** Spec item #3 — CI sent, balance unpaid, scanned BL not yet sent to the buyer. */
async function ciAwaitingScannedBl() {
  return queueRows(
    `o.status = 'active' AND ${sentClause('CI')} AND os.scanned_bl_sent_to_buyer_at IS NULL`,
    'JOIN order_shipping os ON os.order_id = o.id'
  );
}

/** Spec item #4 — CI sent, scanned BL already sent, balance payment not yet received. */
async function ciAwaitingBalancePayment() {
  return queueRows(
    `o.status = 'active' AND ${sentClause('CI')}
     AND os.scanned_bl_sent_to_buyer_at IS NOT NULL
     AND ops.balance_remittance_received_at IS NULL`,
    `JOIN order_shipping os ON os.order_id = o.id
     JOIN order_payment_status ops ON ops.order_id = o.id`
  );
}

/** Spec item #5 — balance payment cleared, hard-copy document set not yet couriered to the buyer. */
async function ciAwaitingHardCopyDespatch() {
  return queueRows(
    `o.status = 'active' AND ${sentClause('CI')}
     AND ops.balance_cleared_at IS NOT NULL
     AND os.courier_sent_at IS NULL`,
    `JOIN order_shipping os ON os.order_id = o.id
     JOIN order_payment_status ops ON ops.order_id = o.id`
  );
}

/** Snapshot dashboard (spec items #1, #2, #3, #4, #5, #11, #12, #14, #15, #16). No date range — "right now". */
async function operationsQueues() {
  const [
    quotationAwaitingSend,
    buyerPoAwaited,
    orderAcceptanceAwaitingSend,
    piStage,
    ocAwaitingSend,
    ocAwaitingAck,
    supplierPoNeeded,
    blAwaitingSend,
    balanceAwaited,
    hardCopyAwaited,
  ] = await Promise.all([
    quotationAwaitingDispatch(),
    awaitingBuyerPo(),
    orderAcceptanceAwaitingDispatch(),
    atPiStage(),
    ocAwaitingDispatch(),
    ocAwaitingAcknowledgement(),
    supplierPoNotYetCreated(),
    ciAwaitingScannedBl(),
    ciAwaitingBalancePayment(),
    ciAwaitingHardCopyDespatch(),
  ]);

  return {
    quotationAwaitingSend, // #1
    buyerPoAwaited, // #15
    orderAcceptanceAwaitingSend, // #14
    piStage, // #2
    ocAwaitingSend, // #11
    ocAwaitingAck, // #12
    supplierPoNeeded, // #16
    blAwaitingSend, // #3
    balanceAwaited, // #4
    hardCopyAwaited, // #5
  };
}

/**
 * Date-ranged funnel activity (spec items #6-10, #13). "Reached PI" is the
 * fork point between the quotation funnel and the PI funnel: an order that
 * was marked lost before a PI was ever generated counts as a lost quotation;
 * one lost after a PI exists counts as a lost PI, not a lost quotation.
 * Quotation/PI "sent" counts are anchored on email_log.sent_at (the actual
 * dispatch event), not documents.generated_at (drafting), since a document
 * can sit generated-but-unsent for days.
 */
async function funnelActivity(dateFrom, dateTo) {
  const isTestMode = await isTestModeFlag();

  async function sentCount(code, dateCol) {
    const clauses = [`dt.code = :code`, `el.status = 'sent'`, `o.is_test_data = :is_test_data`];
    const p = { code, is_test_data: isTestMode };
    if (dateFrom) {
      clauses.push(`DATE(el.${dateCol}) >= :date_from`);
      p.date_from = dateFrom;
    }
    if (dateTo) {
      clauses.push(`DATE(el.${dateCol}) <= :date_to`);
      p.date_to = dateTo;
    }
    const row = await db.queryOne(
      `SELECT COUNT(DISTINCT el.document_id) AS n
         FROM email_log el
         JOIN documents d ON d.id = el.document_id
         JOIN document_types dt ON dt.id = d.document_type_id
         JOIN orders o ON o.id = d.order_id
        WHERE ${clauses.join(' AND ')}`,
      p
    );
    return Number(row?.n ?? 0);
  }

  async function lostCount(reachedPi) {
    const clauses = [`o.status = 'lost'`, `o.is_test_data = :is_test_data`];
    const p = { is_test_data: isTestMode };
    const reachedClause = `EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE dt.code = 'PI' AND d.order_id = o.id)`;
    clauses.push(reachedPi ? reachedClause : `NOT ${reachedClause}`);
    if (dateFrom) {
      clauses.push(`DATE(o.lost_at) >= :date_from`);
      p.date_from = dateFrom;
    }
    if (dateTo) {
      clauses.push(`DATE(o.lost_at) <= :date_to`);
      p.date_to = dateTo;
    }
    const row = await db.queryOne(`SELECT COUNT(*) AS n FROM orders o WHERE ${clauses.join(' AND ')}`, p);
    return Number(row?.n ?? 0);
  }

  async function quotationsWon() {
    const clauses = [
      `EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE dt.code = 'PI' AND d.order_id = o.id)`,
      `o.is_test_data = :is_test_data`,
    ];
    const p = { is_test_data: isTestMode };
    if (dateFrom) {
      clauses.push(`o.pi_date >= :date_from`);
      p.date_from = dateFrom;
    }
    if (dateTo) {
      clauses.push(`o.pi_date <= :date_to`);
      p.date_to = dateTo;
    }
    const row = await db.queryOne(`SELECT COUNT(*) AS n FROM orders o WHERE ${clauses.join(' AND ')}`, p);
    return Number(row?.n ?? 0);
  }

  async function amendmentCount() {
    const clauses = [
      `EXISTS (SELECT 1 FROM orders oo WHERE oo.id = amendments.order_id AND oo.is_test_data = :is_test_data)`,
    ];
    const p = { is_test_data: isTestMode };
    if (dateFrom) {
      clauses.push('DATE(created_at) >= :date_from');
      p.date_from = dateFrom;
    }
    if (dateTo) {
      clauses.push('DATE(created_at) <= :date_to');
      p.date_to = dateTo;
    }
    const row = await db.queryOne(`SELECT COUNT(*) AS n FROM amendments WHERE ${clauses.join(' AND ')}`, p);
    return Number(row?.n ?? 0);
  }

  const [quotationsSent, piSent, quotationsLost, piLost, quotationsWonCount, amendments] = await Promise.all([
    sentCount('QT', 'sent_at'),
    sentCount('PI', 'sent_at'),
    lostCount(false),
    lostCount(true),
    quotationsWon(),
    amendmentCount(),
  ]);

  return {
    quotationsSent, // #6
    quotationsLost, // #7
    quotationsWon: quotationsWonCount, // #8
    piSent, // #9
    piLost, // #10
    amendments, // #13
  };
}

// ================================================================
// PAYMENTS / FINANCIAL REPORT
// ================================================================
//
// "Consolidated financial visibility" was missing entirely before this:
// the dashboard only ever showed two overdue lists, and the per-client
// report only ever showed one client's own payments. This answers
// "how much have we actually collected vs. how much is still
// outstanding, across the whole business, by currency" in one place.
//
// Correctness discipline: the per-currency summary below is folded in JS
// from the SAME rows the detail table renders — never a second,
// independent SQL query — so the two can never disagree about what a
// given order contributed. "Outstanding" only counts an amount that was
// actually invoiced (order_payment_status.*_amount IS NOT NULL); an
// order with no freight amount set (e.g. FOB terms, buyer arranges
// freight) contributes nothing to the freight bucket at all, not a
// false "0 outstanding".

/** @returns {Promise<{rows: Array<object>, by_currency: object}>} */
async function paymentsReport(dateFrom, dateTo) {
  const where = ['o.is_test_data = :is_test_data'];
  const params = { is_test_data: await isTestModeFlag() };
  if (dateFrom) {
    where.push('DATE(o.created_at) >= :date_from');
    params.date_from = dateFrom;
  }
  if (dateTo) {
    where.push('DATE(o.created_at) <= :date_to');
    params.date_to = dateTo;
  }

  const sql = `SELECT o.id, o.order_reference, o.status, o.created_at, c.company_legal_name, cur.code AS currency_code,
                      ops.advance_amount, ops.advance_cleared_at,
                      ops.balance_amount, ops.balance_cleared_at,
                      ops.freight_amount, ops.freight_cleared_at
               FROM orders o
               JOIN clients c ON c.id = o.client_id
               JOIN currencies cur ON cur.id = o.currency_id
               LEFT JOIN order_payment_status ops ON ops.order_id = o.id
               WHERE ${where.join(' AND ')}
               ORDER BY o.created_at DESC`;

  const rows = await db.query(sql, params);

  // Per-row outstanding, computed once here and reused everywhere (view
  // table, CSV export) — nobody else re-derives this formula.
  for (const row of rows) {
    row.advance_outstanding = row.advance_amount !== null
      ? round2(parseFloat(row.advance_amount) - (row.advance_cleared_at ? parseFloat(row.advance_amount) : 0))
      : null;
    row.balance_outstanding = row.balance_amount !== null
      ? round2(parseFloat(row.balance_amount) - (row.balance_cleared_at ? parseFloat(row.balance_amount) : 0))
      : null;
    row.freight_outstanding = row.freight_amount !== null
      ? round2(parseFloat(row.freight_amount) - (row.freight_cleared_at ? parseFloat(row.freight_amount) : 0))
      : null;
  }

  const byCurrency = {};
  for (const r of rows) {
    const cc = r.currency_code;
    if (!byCurrency[cc]) {
      byCurrency[cc] = {
        advance_invoiced: 0, advance_cleared: 0,
        balance_invoiced: 0, balance_cleared: 0,
        freight_invoiced: 0, freight_cleared: 0,
      };
    }
    if (r.advance_amount !== null) {
      byCurrency[cc].advance_invoiced += parseFloat(r.advance_amount);
      if (r.advance_cleared_at) byCurrency[cc].advance_cleared += parseFloat(r.advance_amount);
    }
    if (r.balance_amount !== null) {
      byCurrency[cc].balance_invoiced += parseFloat(r.balance_amount);
      if (r.balance_cleared_at) byCurrency[cc].balance_cleared += parseFloat(r.balance_amount);
    }
    if (r.freight_amount !== null) {
      byCurrency[cc].freight_invoiced += parseFloat(r.freight_amount);
      if (r.freight_cleared_at) byCurrency[cc].freight_cleared += parseFloat(r.freight_amount);
    }
  }
  for (const cc of Object.keys(byCurrency)) {
    const b = byCurrency[cc];
    b.advance_outstanding = round2(b.advance_invoiced - b.advance_cleared);
    b.balance_outstanding = round2(b.balance_invoiced - b.balance_cleared);
    b.freight_outstanding = round2(b.freight_invoiced - b.freight_cleared);
    b.total_outstanding = round2(b.advance_outstanding + b.balance_outstanding + b.freight_outstanding);
    b.advance_invoiced = round2(b.advance_invoiced);
    b.advance_cleared = round2(b.advance_cleared);
    b.balance_invoiced = round2(b.balance_invoiced);
    b.balance_cleared = round2(b.balance_cleared);
    b.freight_invoiced = round2(b.freight_invoiced);
    b.freight_cleared = round2(b.freight_cleared);
  }

  return { rows, by_currency: sortObjectKeys(byCurrency) };
}

function round2(n) {
  return Math.round((n + Number.EPSILON) * 100) / 100;
}

// ================================================================
// DISPUTE REPORT
// ================================================================
// Disputes previously only ever surfaced as generic audit_log rows
// inside the per-order report — no cross-order view of "how many are
// open, how old is the oldest one, how long do we typically take to
// resolve one." days_open is computed once, in SQL, per row; the
// status/avg-resolution summary below is folded from those SAME rows
// in JS — never a second, independent query — so it can't disagree
// with the detail table.

/** @returns {Promise<{rows: Array<object>, by_status: object, open_count: number, avg_resolution_days: ?number}>} */
async function disputesReport(dateFrom, dateTo) {
  const where = ['o.is_test_data = :is_test_data'];
  const params = { is_test_data: await isTestModeFlag() };
  if (dateFrom) {
    where.push('d.notice_date >= :date_from');
    params.date_from = dateFrom;
  }
  if (dateTo) {
    where.push('d.notice_date <= :date_to');
    params.date_to = dateTo;
  }

  const rows = await db.query(
    `SELECT d.id, d.order_id, o.order_reference, c.company_legal_name, d.notice_date, d.from_party,
            d.status, d.response_due_date, d.resolved_at, d.created_at,
            DATEDIFF(COALESCE(d.resolved_at, NOW()), d.notice_date) AS days_open
     FROM disputes d
     JOIN orders o ON o.id = d.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE ${where.join(' AND ')}
     ORDER BY d.notice_date DESC`,
    params
  );

  const byStatus = {};
  let openCount = 0;
  let resolvedDaysSum = 0;
  let resolvedCount = 0;
  for (const r of rows) {
    byStatus[r.status] = (byStatus[r.status] || 0) + 1;
    if (r.status !== 'Resolved') {
      openCount++;
    } else {
      resolvedDaysSum += Number(r.days_open);
      resolvedCount++;
    }
  }

  return {
    rows,
    by_status: sortByCountDesc(byStatus),
    open_count: openCount,
    avg_resolution_days: resolvedCount > 0 ? Math.round((resolvedDaysSum / resolvedCount) * 10) / 10 : null,
  };
}

// ================================================================
// AMENDMENT REPORT
// ================================================================
// Amendments previously only ever surfaced as a bare count in the
// funnel section, or as generic audit_log rows in the per-order
// report. This lists every amendment with its actual terms and a
// status/requested-by breakdown folded from the same rows.

/** @returns {Promise<{rows: Array<object>, by_status: object, by_requested_by: object}>} */
async function amendmentsReport(dateFrom, dateTo) {
  const where = ['o.is_test_data = :is_test_data'];
  const params = { is_test_data: await isTestModeFlag() };
  if (dateFrom) {
    where.push('DATE(a.created_at) >= :date_from');
    params.date_from = dateFrom;
  }
  if (dateTo) {
    where.push('DATE(a.created_at) <= :date_to');
    params.date_to = dateTo;
  }

  const rows = await db.query(
    `SELECT a.id, a.amendment_reference, a.order_id, o.order_reference, c.company_legal_name,
            a.reason, a.requested_by, a.status, a.effective_from, a.created_at,
            a.amended_advance_amount, a.amended_balance_amount, cur.code AS currency_code
     FROM amendments a
     JOIN orders o ON o.id = a.order_id
     JOIN clients c ON c.id = o.client_id
     JOIN currencies cur ON cur.id = o.currency_id
     WHERE ${where.join(' AND ')}
     ORDER BY a.created_at DESC`,
    params
  );

  const byStatus = {};
  const byRequestedBy = {};
  for (const r of rows) {
    byStatus[r.status] = (byStatus[r.status] || 0) + 1;
    byRequestedBy[r.requested_by] = (byRequestedBy[r.requested_by] || 0) + 1;
  }

  return { rows, by_status: sortByCountDesc(byStatus), by_requested_by: sortByCountDesc(byRequestedBy) };
}

// ================================================================
// ORDER/CLIENT SEARCH — Reports hub
// ================================================================
// The Reports index previously just told staff to already know the
// numeric order id ("go directly to /reports/order/<id>"). This is a
// plain partial-match lookup by order reference or client company
// name, scoped to the current Test Mode state like every other query
// in this file, capped at 25 rows (a search box, not a full listing).

/** @returns {Promise<Array<object>>} */
async function searchOrders(query) {
  const q = (query || '').trim();
  if (q === '') {
    return [];
  }
  const like = `%${q}%`;
  return db.query(
    `SELECT o.id, o.order_reference, o.status, o.created_at, c.company_legal_name
     FROM orders o JOIN clients c ON c.id = o.client_id
     WHERE o.is_test_data = :is_test_data
       AND (o.order_reference LIKE :q OR c.company_legal_name LIKE :q2)
     ORDER BY o.created_at DESC
     LIMIT 25`,
    { is_test_data: await isTestModeFlag(), q: like, q2: like }
  );
}

// ================================================================
// MONTH-OVER-MONTH TRENDS
// ================================================================
// Everything else in this file is either a point-in-time snapshot
// (Queues) or a single flat date-range total (Funnel, Aggregate) —
// there was no way to see whether the business is growing or slowing
// month to month. Builds the canonical list of the last N months
// FIRST (so a month with zero activity still appears as a zero row,
// never silently dropped), then merges each GROUP BY query's rows
// into it by month key.

/** @returns {Promise<Array<object>>} one row per month, oldest first */
async function monthlyTrends(months = 12) {
  const isTestMode = await isTestModeFlag();

  const now = new Date();
  const firstOfThisMonth = new Date(now.getFullYear(), now.getMonth(), 1);
  const monthKeys = [];
  for (let i = months - 1; i >= 0; i--) {
    const d = new Date(firstOfThisMonth.getFullYear(), firstOfThisMonth.getMonth() - i, 1);
    monthKeys.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
  }

  const result = {};
  for (const mk of monthKeys) {
    result[mk] = { month: mk, orders_created: 0, quotations_sent: 0, pi_sent: 0, lost: 0, fob_by_currency: {} };
  }
  const fromDate = `${monthKeys[0]}-01`;

  const ordersRows = await db.query(
    `SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n
     FROM orders WHERE is_test_data = :t AND created_at >= :from GROUP BY ym`,
    { t: isTestMode, from: fromDate }
  );
  for (const row of ordersRows) {
    if (result[row.ym]) result[row.ym].orders_created = Number(row.n);
  }

  const fobRows = await db.query(
    `SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS ym, cur.code AS cc, COALESCE(SUM(p.fob_value), 0) AS total
     FROM orders o
     JOIN currencies cur ON cur.id = o.currency_id
     JOIN order_products p ON p.order_id = o.id AND p.is_active = 1
     WHERE o.is_test_data = :t AND o.created_at >= :from GROUP BY ym, cc`,
    { t: isTestMode, from: fromDate }
  );
  for (const row of fobRows) {
    if (result[row.ym]) result[row.ym].fob_by_currency[row.cc] = round2(parseFloat(row.total));
  }

  const qtRows = await db.query(
    `SELECT DATE_FORMAT(el.sent_at, '%Y-%m') AS ym, COUNT(DISTINCT el.document_id) AS n
     FROM email_log el
     JOIN documents doc ON doc.id = el.document_id
     JOIN document_types dt ON dt.id = doc.document_type_id
     JOIN orders o ON o.id = doc.order_id
     WHERE dt.code = 'QT' AND el.status = 'sent' AND o.is_test_data = :t AND el.sent_at >= :from
     GROUP BY ym`,
    { t: isTestMode, from: fromDate }
  );
  for (const row of qtRows) {
    if (result[row.ym]) result[row.ym].quotations_sent = Number(row.n);
  }

  const piRows = await db.query(
    `SELECT DATE_FORMAT(el.sent_at, '%Y-%m') AS ym, COUNT(DISTINCT el.document_id) AS n
     FROM email_log el
     JOIN documents doc ON doc.id = el.document_id
     JOIN document_types dt ON dt.id = doc.document_type_id
     JOIN orders o ON o.id = doc.order_id
     WHERE dt.code = 'PI' AND el.status = 'sent' AND o.is_test_data = :t AND el.sent_at >= :from
     GROUP BY ym`,
    { t: isTestMode, from: fromDate }
  );
  for (const row of piRows) {
    if (result[row.ym]) result[row.ym].pi_sent = Number(row.n);
  }

  const lostRows = await db.query(
    `SELECT DATE_FORMAT(lost_at, '%Y-%m') AS ym, COUNT(*) AS n
     FROM orders WHERE is_test_data = :t AND status = 'lost' AND lost_at >= :from GROUP BY ym`,
    { t: isTestMode, from: fromDate }
  );
  for (const row of lostRows) {
    if (result[row.ym]) result[row.ym].lost = Number(row.n);
  }

  return monthKeys.map((mk) => result[mk]);
}

// ================================================================
// STAFF PRODUCTIVITY REPORT
// ================================================================
// Deliberately scoped to two straightforward, reliably-attributable
// GROUP BY queries — documents.generated_by (who actually generated
// each document) and audit_log.user_id (overall activity level) — and
// NOT a derived "average turnaround per staff member" metric. Orders
// themselves carry no created_by column, so any "who initiated this
// order" figure would have to be inferred (e.g. via the first QT
// document), which risks being subtly wrong. Given the explicit
// requirement that every number here be trustworthy, a smaller set of
// unambiguous counts beats a larger set with a shaky derived figure.
// Gated on the view_staff_reports permission (Admin/MD/ED only by
// default), not the general view_reports every other report here
// uses, since this shows individual staff activity rather than
// business data.

/** @returns {Promise<Array<object>>} one row per user, sorted by name */
async function staffProductivity(dateFrom, dateTo) {
  const isTestMode = await isTestModeFlag();

  const docWhere = ['o.is_test_data = :is_test_data'];
  const docParams = { is_test_data: isTestMode };
  if (dateFrom) {
    docWhere.push('DATE(d.generated_at) >= :date_from');
    docParams.date_from = dateFrom;
  }
  if (dateTo) {
    docWhere.push('DATE(d.generated_at) <= :date_to');
    docParams.date_to = dateTo;
  }

  const docRows = await db.query(
    `SELECT u.id AS user_id, u.name AS user_name, dt.code AS type_code, COUNT(*) AS n
     FROM documents d
     JOIN document_types dt ON dt.id = d.document_type_id
     JOIN orders o ON o.id = d.order_id
     LEFT JOIN users u ON u.id = d.generated_by
     WHERE ${docWhere.join(' AND ')}
     GROUP BY u.id, u.name, dt.code
     ORDER BY u.name, dt.code`,
    docParams
  );

  const byUser = {};
  for (const r of docRows) {
    const uid = r.user_id !== null ? Number(r.user_id) : 0;
    if (!byUser[uid]) {
      byUser[uid] = { user_id: uid, user_name: r.user_name ?? 'Unattributed (system-generated)', documents_total: 0, by_type: {}, audit_actions: 0 };
    }
    byUser[uid].by_type[r.type_code] = Number(r.n);
    byUser[uid].documents_total += Number(r.n);
  }

  // audit_log carries no test-data flag of its own — Test Mode
  // deliberately never writes audit_log rows for test records at all
  // (see docs/schema.sql Section V / README), so no is_test_data filter
  // is needed or even possible here.
  const auditWhere = [];
  const auditParams = {};
  if (dateFrom) {
    auditWhere.push('DATE(al.created_at) >= :date_from');
    auditParams.date_from = dateFrom;
  }
  if (dateTo) {
    auditWhere.push('DATE(al.created_at) <= :date_to');
    auditParams.date_to = dateTo;
  }
  const auditSql = `SELECT al.user_id, u.name AS user_name, COUNT(*) AS n
                     FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
                     ${auditWhere.length ? 'WHERE ' + auditWhere.join(' AND ') : ''}
                     GROUP BY al.user_id, u.name`;
  const auditRows = await db.query(auditSql, auditParams);
  for (const r of auditRows) {
    const uid = r.user_id !== null ? Number(r.user_id) : 0;
    if (!byUser[uid]) {
      byUser[uid] = { user_id: uid, user_name: r.user_name ?? 'System', documents_total: 0, by_type: {}, audit_actions: 0 };
    }
    byUser[uid].audit_actions = Number(r.n);
  }

  return Object.values(byUser).sort((a, b) => String(a.user_name).localeCompare(String(b.user_name)));
}

// ================================================================
// DEBTORS / RECEIVABLES AGEING REPORT — closes a real gap: the Payments
// report shows each leg's outstanding amount but never how LONG it's
// been outstanding, which is what actually drives follow-up priority and
// provisioning decisions.
//
// Due-date basis per leg (documented here since it's a judgment call, not
// a stored field for every leg): advance and freight are each due
// promptly once invoiced, so age is counted from the order's own
// created_at; balance already has an explicit computed due date
// (order_payment_status.balance_due_date, per the payment preset's
// trigger option) — use that when set, falling back to created_at for
// the (rare, event-triggered) presets that never populate it.
// ================================================================

const AGEING_BUCKETS = ['Current (not yet due)', '1-30 days', '31-60 days', '61-90 days', '90+ days'];

function ageingBucket(daysOverdue) {
  if (daysOverdue === null || daysOverdue <= 0) return AGEING_BUCKETS[0];
  if (daysOverdue <= 30) return AGEING_BUCKETS[1];
  if (daysOverdue <= 60) return AGEING_BUCKETS[2];
  if (daysOverdue <= 90) return AGEING_BUCKETS[3];
  return AGEING_BUCKETS[4];
}

function daysBetween(fromDate, toDate) {
  const oneDayMs = 24 * 60 * 60 * 1000;
  return Math.floor((toDate.getTime() - fromDate.getTime()) / oneDayMs);
}

/** @returns {Promise<{rows: Array<object>, by_bucket: object, buckets: Array<string>}>} */
async function ageingReport() {
  const isTestMode = await isTestModeFlag();

  const orders = await db.query(
    `SELECT o.id, o.order_reference, o.created_at, c.company_legal_name, cur.code AS currency_code,
            ops.advance_amount, ops.advance_cleared_at,
            ops.balance_amount, ops.balance_cleared_at, ops.balance_due_date,
            ops.freight_amount, ops.freight_cleared_at
     FROM orders o
     JOIN clients c ON c.id = o.client_id
     JOIN currencies cur ON cur.id = o.currency_id
     JOIN order_payment_status ops ON ops.order_id = o.id
     WHERE o.is_test_data = :is_test_data AND o.status NOT IN ('lost')
       AND (
         (ops.advance_amount IS NOT NULL AND ops.advance_cleared_at IS NULL) OR
         (ops.balance_amount IS NOT NULL AND ops.balance_cleared_at IS NULL) OR
         (ops.freight_amount IS NOT NULL AND ops.freight_cleared_at IS NULL)
       )
     ORDER BY o.created_at ASC`,
    { is_test_data: isTestMode }
  );

  const today = new Date(new Date().toISOString().slice(0, 10));
  const rows = [];
  const byBucket = {};
  const legs = { advance: 'Advance', balance: 'Balance', freight: 'Freight' };

  for (const o of orders) {
    const createdAt = new Date(String(o.created_at).slice(0, 10));

    for (const [legKey, legLabel] of Object.entries(legs)) {
      const amount = o[`${legKey}_amount`];
      const clearedAt = o[`${legKey}_cleared_at`];
      if (amount === null || clearedAt !== null) continue; // not invoiced, or already cleared — not a debtor

      const dueDate = legKey === 'balance' && o.balance_due_date !== null
        ? new Date(String(o.balance_due_date).slice(0, 10))
        : createdAt;

      const daysOverdue = daysBetween(dueDate, today);
      const bucket = ageingBucket(daysOverdue);
      const cc = o.currency_code;

      rows.push({
        order_id: o.id,
        order_reference: o.order_reference,
        company_legal_name: o.company_legal_name,
        currency_code: cc,
        leg: legLabel,
        amount: parseFloat(amount),
        due_date: dueDate.toISOString().slice(0, 10),
        days_overdue: Math.max(0, daysOverdue),
        bucket,
      });

      byBucket[bucket] ??= {};
      byBucket[bucket][cc] = (byBucket[bucket][cc] ?? 0) + parseFloat(amount);
    }
  }

  rows.sort((a, b) => b.days_overdue - a.days_overdue);
  for (const bucket of Object.keys(byBucket)) {
    byBucket[bucket] = sortObjectKeys(byBucket[bucket]);
  }

  return { rows, by_bucket: byBucket, buckets: AGEING_BUCKETS };
}

// ================================================================
// FREIGHT COST REPORT — closes a real gap: freight terms/costs are
// recorded per order (order_freight, order_payment_status) but never
// rolled up anywhere to see total freight spend or forwarder split.
// ================================================================

/** @returns {Promise<{rows: Array<object>, by_forwarder: object}>} */
async function freightCostReport(dateFrom, dateTo) {
  const isTestMode = await isTestModeFlag();
  const where = ['o.is_test_data = :is_test_data', 'fr.id IS NOT NULL'];
  const params = { is_test_data: isTestMode };
  if (dateFrom) {
    where.push('DATE(o.created_at) >= :date_from');
    params.date_from = dateFrom;
  }
  if (dateTo) {
    where.push('DATE(o.created_at) <= :date_to');
    params.date_to = dateTo;
  }

  const rows = await db.query(
    `SELECT o.id, o.order_reference, o.created_at, c.company_legal_name, cur.code AS currency_code,
            i.code AS incoterm_code,
            fr.freight_forwarder_name, fr.confirmed_freight_rate, fr.insurance_amount, fr.freight_cleared_at,
            ops.freight_amount, ops.freight_cleared_at AS payment_cleared_at
     FROM orders o
     JOIN clients c ON c.id = o.client_id
     JOIN currencies cur ON cur.id = o.currency_id
     JOIN incoterms i ON i.id = o.incoterm_id
     JOIN order_freight fr ON fr.order_id = o.id
     LEFT JOIN order_payment_status ops ON ops.order_id = o.id
     WHERE ${where.join(' AND ')}
     ORDER BY o.created_at DESC`,
    params
  );

  const byForwarder = {};
  for (const r of rows) {
    const forwarder = r.freight_forwarder_name || '(not yet assigned)';
    const cc = r.currency_code;
    byForwarder[forwarder] ??= {};
    byForwarder[forwarder][cc] ??= { order_count: 0, total_rate: 0, total_insurance: 0, total_cleared: 0 };
    byForwarder[forwarder][cc].order_count++;
    byForwarder[forwarder][cc].total_rate += parseFloat(r.confirmed_freight_rate ?? 0);
    byForwarder[forwarder][cc].total_insurance += parseFloat(r.insurance_amount ?? 0);
    if (r.payment_cleared_at) {
      byForwarder[forwarder][cc].total_cleared += parseFloat(r.freight_amount ?? 0);
    }
  }

  return { rows, by_forwarder: sortObjectKeys(byForwarder) };
}

// ================================================================
// PRODUCT-WISE / HS-CODE SALES REPORT — closes a real gap: there is no
// view anywhere of which products/HS codes actually drive business, only
// per-order line items.
// ================================================================

/** @returns {Promise<{rows: Array<object>, by_hs_code: object}>} */
async function productSalesReport(dateFrom, dateTo) {
  const isTestMode = await isTestModeFlag();
  const where = ['o.is_test_data = :is_test_data', 'p.is_active = 1'];
  const params = { is_test_data: isTestMode };
  if (dateFrom) {
    where.push('DATE(o.created_at) >= :date_from');
    params.date_from = dateFrom;
  }
  if (dateTo) {
    where.push('DATE(o.created_at) <= :date_to');
    params.date_to = dateTo;
  }

  const rows = await db.query(
    `SELECT p.description, p.hs_code, cur.code AS currency_code,
            COUNT(DISTINCT p.order_id) AS order_count,
            COALESCE(SUM(p.quantity), 0) AS total_quantity,
            COALESCE(SUM(p.fob_value), 0) AS total_fob_value
     FROM order_products p
     JOIN orders o ON o.id = p.order_id
     JOIN currencies cur ON cur.id = o.currency_id
     WHERE ${where.join(' AND ')}
     GROUP BY p.hs_code, p.description, cur.code
     ORDER BY total_fob_value DESC`,
    params
  );

  const byHsCode = {};
  for (const r of rows) {
    const hs = r.hs_code;
    const cc = r.currency_code;
    byHsCode[hs] ??= { description: r.description, by_currency: {} };
    byHsCode[hs].by_currency[cc] ??= { order_count: 0, total_quantity: 0, total_fob_value: 0 };
    byHsCode[hs].by_currency[cc].order_count += parseInt(r.order_count, 10);
    byHsCode[hs].by_currency[cc].total_quantity += parseFloat(r.total_quantity);
    byHsCode[hs].by_currency[cc].total_fob_value += parseFloat(r.total_fob_value);
  }
  const sortedEntries = Object.entries(byHsCode).sort(([, a], [, b]) => {
    const aTotal = Object.values(a.by_currency).reduce((sum, c) => sum + c.total_fob_value, 0);
    const bTotal = Object.values(b.by_currency).reduce((sum, c) => sum + c.total_fob_value, 0);
    return bTotal - aTotal;
  });

  return { rows, by_hs_code: Object.fromEntries(sortedEntries) };
}

// ================================================================
// SUPPLIER PERFORMANCE REPORT — closes a real gap: supplier POs are
// tracked per order but never rolled up to see which suppliers sign
// fastest or deliver on time. Uses order_supplier_po.signed_at (added
// alongside this report — see schema.sql Section AK) for signing
// turnaround, and order_packing.packing_date vs required_delivery_date
// as the best available on-time-delivery proxy (the schema has no
// separate "material received from supplier" timestamp — packing cannot
// start before the material is in hand).
// ================================================================

/** @returns {Promise<Array<object>>} one row per supplier, most POs first */
async function supplierPerformanceReport() {
  const isTestMode = await isTestModeFlag();
  const rows = await db.query(
    `SELECT s.id AS supplier_id, s.supplier_legal_name,
            spo.order_id, spo.created_at, spo.signed_at, spo.required_delivery_date, spo.status,
            pk.packing_date
     FROM order_supplier_po spo
     JOIN suppliers s ON s.id = spo.supplier_id
     JOIN orders o ON o.id = spo.order_id
     LEFT JOIN order_packing pk ON pk.order_id = spo.order_id
     WHERE o.is_test_data = :is_test_data
     ORDER BY s.supplier_legal_name`,
    { is_test_data: isTestMode }
  );

  const bySupplier = {};
  for (const r of rows) {
    const sid = r.supplier_id;
    bySupplier[sid] ??= {
      supplier_name: r.supplier_legal_name,
      po_count: 0,
      signed_count: 0,
      total_signing_days: 0,
      delivery_tracked_count: 0,
      on_time_count: 0,
    };
    bySupplier[sid].po_count++;

    if (r.signed_at !== null) {
      bySupplier[sid].signed_count++;
      const days = (new Date(r.signed_at).getTime() - new Date(r.created_at).getTime()) / 86400000;
      bySupplier[sid].total_signing_days += Math.max(0, days);
    }

    if (r.required_delivery_date !== null && r.packing_date !== null) {
      bySupplier[sid].delivery_tracked_count++;
      if (r.packing_date <= r.required_delivery_date) {
        bySupplier[sid].on_time_count++;
      }
    }
  }

  const result = Object.values(bySupplier).map((s) => ({
    ...s,
    avg_signing_days: s.signed_count > 0 ? round2(s.total_signing_days / s.signed_count) : null,
    on_time_pct: s.delivery_tracked_count > 0 ? round2((100 * s.on_time_count) / s.delivery_tracked_count) : null,
  }));
  result.sort((a, b) => b.po_count - a.po_count);
  return result;
}

// ================================================================
// CONVERSION-RATE REPORT — closes a real gap: the Queues/Funnel section
// shows raw counts (quotations sent/won/lost) but never the actual
// conversion percentage a sales lead would want at a glance.
// ================================================================

async function conversionRateReport(dateFrom, dateTo) {
  const funnel = await funnelActivity(dateFrom, dateTo);

  const quotationsSent = funnel.quotationsSent;
  const quotationsWon = funnel.quotationsWon;
  const quotationsLost = funnel.quotationsLost;
  const piSent = funnel.piSent;
  const piLost = funnel.piLost;

  return {
    quotations_sent: quotationsSent,
    quotation_to_pi_pct: quotationsSent > 0 ? round2((100 * quotationsWon) / quotationsSent) : null,
    quotation_lost_pct: quotationsSent > 0 ? round2((100 * quotationsLost) / quotationsSent) : null,
    pi_sent: piSent,
    pi_to_confirmed_pct: piSent > 0 ? round2((100 * (piSent - piLost)) / piSent) : null,
    pi_lost_pct: piSent > 0 ? round2((100 * piLost) / piSent) : null,
    overall_quotation_to_confirmed_pct: quotationsSent > 0 ? round2((100 * (piSent - piLost)) / quotationsSent) : null,
  };
}

module.exports = {
  perClient,
  perOrder,
  aggregate,
  aggregateSummary,
  distinctCountries,
  operationsQueues,
  funnelActivity,
  paymentsReport,
  disputesReport,
  amendmentsReport,
  searchOrders,
  monthlyTrends,
  staffProductivity,
  ageingReport,
  freightCostReport,
  productSalesReport,
  supplierPerformanceReport,
  conversionRateReport,
};
