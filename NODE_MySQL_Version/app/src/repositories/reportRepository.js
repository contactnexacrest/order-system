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
    return { client: null, orders: [], documents: [], payments: [], products: [], total_fob_value: 0, total_cleared: 0 };
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
  let totalFob = 0;
  let totalCleared = 0;

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
      `SELECT order_id, advance_amount, advance_cleared_at, balance_amount, balance_cleared_at,
              freight_amount, freight_cleared_at
       FROM order_payment_status WHERE order_id IN (${placeholders})`,
      params
    );
    for (const p of payments) {
      if (p.advance_cleared_at) {
        totalCleared += parseFloat(p.advance_amount) || 0;
      }
      if (p.balance_cleared_at) {
        totalCleared += parseFloat(p.balance_amount) || 0;
      }
    }

    products = await db.query(
      `SELECT order_id, description, quantity, quantity_is_tbc, unit, unit_price, fob_value
       FROM order_products WHERE order_id IN (${placeholders}) AND is_active = 1 ORDER BY order_id, line_no`,
      params
    );
    for (const prod of products) {
      totalFob += parseFloat(prod.fob_value ?? 0) || 0;
    }
  }

  return {
    client,
    orders,
    documents,
    payments,
    products,
    total_fob_value: totalFob,
    total_cleared: totalCleared,
  };
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

module.exports = {
  perClient,
  perOrder,
  aggregate,
  distinctCountries,
  operationsQueues,
  funnelActivity,
};
