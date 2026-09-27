'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const amendmentRepository = require('../../src/repositories/amendmentRepository');
const amendmentService = require('../../src/services/amendmentService');
const caRepository = require('../../src/repositories/caRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const reportRepository = require('../../src/repositories/reportRepository');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * QA-4 P1 (docs/QA/TEST_PLAN.md Section 6): financial data integrity — the
 * math itself, not authorization/state gates (that's P0's job). Each test
 * pins a formula that already exists in the code against a hand-computed
 * expected value, rather than re-deriving the formula and comparing it to
 * itself.
 */
describe('Financial data integrity (QA-4 P1)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-user-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createTestDocument(orderId, typeCode = 'QT') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, 'draft')`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}` }
    );
    return result.insertId;
  }

  async function createTestAmendment(orderId, amendedAdvancePct) {
    return amendmentRepository.create(
      `AMD-TEST-${Math.random().toString(16).slice(2, 10)}`,
      orderId,
      'Jest financial-integrity amendment',
      'importer',
      { note: 'snapshot' },
      amendedAdvancePct, null, null, null, null, null, null
    );
  }

  function findSettlementRow(rows, orderId, leg) {
    return rows.find((r) => r.order_id === orderId && r.leg === leg) || null;
  }

  function findPaymentsReportRow(rows, orderId) {
    return rows.find((r) => Number(r.id) === orderId) || null;
  }

  // ---------------------------------------------------------------
  // 1. Amendment overrides must never leave advance/balance out of sync
  //    with each other — amendmentService.attachSignedCopyAndActivate()
  //    always derives balance_pct = 100 - advance_pct, rather than
  //    trusting a separately-submitted balance percentage that could
  //    drift from it.
  // ---------------------------------------------------------------

  it('amendment activation always leaves advance_pct + balance_pct summing to 100', async () => {
    for (const amendedAdvancePct of [0.0, 30.0, 55.5, 100.0]) {
      const orderId = await createTestOrder(await createTestClient());
      const amendmentId = await createTestAmendment(orderId, amendedAdvancePct);
      await amendmentService.approveByMd(amendmentId, 1);
      const documentId = await createTestDocument(orderId, 'QT');
      await amendmentRepository.attachDocument(amendmentId, documentId);
      const fileId = await createTestFile(orderId, null);

      await amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, 1);

      const order = await orderRepository.find(orderId);
      expect(Number(order.advance_pct)).toBeCloseTo(amendedAdvancePct, 3);
      expect(Number(order.advance_pct) + Number(order.balance_pct)).toBeCloseTo(100, 3);
    }
  });

  // ---------------------------------------------------------------
  // 2. CA settlement register's forex gain/loss (caRepository.js
  //    settlementRegister()): expected_inr = foreign_amount *
  //    assumed_exchange_rate; forex_gain_loss = inr_actual - expected_inr.
  //    Pinned against a hand-computed value.
  // ---------------------------------------------------------------

  it('CA settlement register forex gain/loss matches a hand-computed value', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Accounts Executive');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-15', userId);
    await orderPaymentStatusRepository.setAssumedExchangeRate(orderId, 90.5, userId);
    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 91200.0, userId);

    const rows = await caRepository.settlementRegister();
    const row = findSettlementRow(rows, orderId, 'advance');

    expect(row).not.toBeNull();
    // Hand-computed: expected_inr = 1000.00 * 90.5 = 90500.00
    expect(row.expected_inr).toBeCloseTo(90500.0, 3);
    // Hand-computed: forex_gain_loss = 91200.00 - 90500.00 = 700.00
    expect(row.forex_gain_loss).toBeCloseTo(700.0, 3);
  });

  it('CA settlement register forex loss is negative when INR-actual is below expected', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser('Accounts Executive');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 2000.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-15', userId);
    await orderPaymentStatusRepository.setAssumedExchangeRate(orderId, 85.0, userId);
    // Hand-computed: expected_inr = 2000.00 * 85.0 = 170000.00; actual came
    // in lower, a real forex loss.
    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 168500.0, userId);

    const rows = await caRepository.settlementRegister();
    const row = findSettlementRow(rows, orderId, 'advance');

    expect(row).not.toBeNull();
    expect(row.expected_inr).toBeCloseTo(170000.0, 3);
    expect(row.forex_gain_loss).toBeCloseTo(-1500.0, 3);
  });

  // ---------------------------------------------------------------
  // 3. Payments report aggregation (reportRepository.paymentsReport()) —
  //    outstanding = invoiced - cleared, per row and per currency.
  //    Measured as a before/after delta rather than an absolute total,
  //    since the disposable test DB accumulates orders from every other
  //    integration test file run in this same process/currency (all via
  //    the shared USD-default fixture) — a delta is immune to that shared
  //    state, an absolute total is not.
  // ---------------------------------------------------------------

  it('payments report outstanding delta matches a hand-computed value', async () => {
    const beforeReport = await reportRepository.paymentsReport(null, null);
    const before = beforeReport.by_currency.USD || { advance_invoiced: 0, advance_cleared: 0, balance_invoiced: 0, balance_cleared: 0, advance_outstanding: 0, balance_outstanding: 0 };

    const orderId = await createTestOrder(await createTestClient()); // USD by default
    const userId = await createTestUser('Accounts Executive');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    // Advance: invoiced 1000, cleared in full -> 0 outstanding.
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-15', userId);
    // Balance: invoiced 1500, never cleared -> fully outstanding.
    await orderPaymentStatusRepository.recordBalanceReceived(orderId, 1500.0, '2026-02-01');

    const afterReport = await reportRepository.paymentsReport(null, null);
    const after = afterReport.by_currency.USD;

    expect(after.advance_invoiced - before.advance_invoiced).toBeCloseTo(1000.0, 3);
    expect(after.advance_cleared - before.advance_cleared).toBeCloseTo(1000.0, 3);
    expect(after.balance_invoiced - before.balance_invoiced).toBeCloseTo(1500.0, 3);
    expect(after.balance_cleared - before.balance_cleared).toBeCloseTo(0.0, 3);
    expect(after.advance_outstanding - before.advance_outstanding).toBeCloseTo(0.0, 3);
    expect(after.balance_outstanding - before.balance_outstanding).toBeCloseTo(1500.0, 3);

    // Row-level check (no shared-state contamination risk — matched by
    // this order's own id): advance_outstanding must be 0 once cleared,
    // balance_outstanding must equal the full invoiced amount while
    // uncleared.
    const row = findPaymentsReportRow(afterReport.rows, orderId);
    expect(row).not.toBeNull();
    expect(Number(row.advance_outstanding)).toBeCloseTo(0.0, 3);
    expect(Number(row.balance_outstanding)).toBeCloseTo(1500.0, 3);
  });

  // ---------------------------------------------------------------
  // 4. Audit log must be genuinely append-only — no route anywhere issues
  //    an UPDATE or DELETE against audit_log. Static source scan rather
  //    than a DB-level test, since the invariant is "no code path
  //    exists," not "a given code path behaves a certain way."
  // ---------------------------------------------------------------

  it('no source file ever issues an UPDATE or DELETE against audit_log', () => {
    const srcDir = path.join(__dirname, '../../src');
    const offenders = [];
    const pattern = /\b(UPDATE|DELETE\s+FROM)\s+audit_log\b/i;

    function walk(dir) {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
          walk(full);
        } else if (entry.isFile() && entry.name.endsWith('.js')) {
          const contents = fs.readFileSync(full, 'utf8');
          if (pattern.test(contents)) {
            offenders.push(full);
          }
        }
      }
    }
    walk(srcDir);

    expect(offenders).toEqual([]);
  });
});
