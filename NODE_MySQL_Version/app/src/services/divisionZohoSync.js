'use strict';

/**
 * D-257 Zoho (owner 5 Nov 2026: "make sure the Zoho sync is complete"): the
 * money of the new divisions goes to Zoho Books like Export's does — at the
 * scheduled sync and Sync now (caSyncService.fullSync), or from the record's
 * own page — with the same sync log, retry, API allowance (D-239) and
 * duplicate guards:
 *
 *  - Domestic Trade, and Import & Distribution's local sale: the GST tax
 *    invoice, then each buyer receipt as a customer payment applied to it;
 *  - Import & Distribution: the supplier's bill, the customs duty bill and
 *    the clearance charges bill, each payment to the supplier abroad as a
 *    vendor payment on the bill, the duty payment as a vendor payment to
 *    Customs on the duty bill, and the IGST input-credit journal;
 *  - Merchant Trade: the sale invoice and the supplier's bill, the buy leg as
 *    vendor payments and the sell leg as customer payments.
 *
 * A payment waits until its invoice or bill is in Books. Records dated before
 * the go-live date are not sent (the CA books them by hand). Same as PHP
 * DivisionZohoSync.
 */

const db = require('../config/db');
const zohoBooksService = require('./zohoBooksService');
const zohoGuard = require('./zohoGuard');
const zohoApiBudget = require('./zohoApiBudget');
const zohoSyncLogRepository = require('../repositories/zohoSyncLogRepository');
const divisionService = require('./divisionService');

const TYPE_LABELS = {
  domestic_invoice: 'domestic GST tax invoice',
  domestic_receipt: 'domestic buyer receipt',
  import_bills: 'import (supplier + customs duty bill)',
  import_payment: 'payment to an import supplier',
  import_duty_payment: 'customs duty payment',
  import_igst_journal: 'import IGST credit journal',
  merchant_deal: 'merchant trade invoice + bill',
  merchant_payment: 'merchant trade payment',
};

const n2 = (v) => Number(v).toFixed(2);

/** What the next sync would send, by type (the same selectors run() pushes from). With only = [kind, id] limited to one record. */
async function pending(start, only = null) {
  const since = (col) => (start !== null ? ` AND ${col} >= ${require('mysql2').escape(start)}` : '');
  const one = (kind, col) => (only !== null ? (only[0] === kind ? ` AND ${col} = ${Number(only[1])}` : ' AND 1 = 0') : '');
  const q = (sql) => db.query(sql);
  return {
    domestic_invoice: await q(`SELECT d.order_id, o.division_id, d.tax_invoice_no FROM domestic_orders d JOIN orders o ON o.id = d.order_id
      WHERE d.tax_invoice_no IS NOT NULL AND d.zoho_invoice_id IS NULL${since('d.tax_invoice_date')}${one('order', 'd.order_id')} ORDER BY d.order_id`),
    domestic_receipt: await q(`SELECT p.*, o.division_id FROM domestic_payments p JOIN domestic_orders d ON d.order_id = p.order_id JOIN orders o ON o.id = p.order_id
      WHERE p.zoho_payment_id IS NULL AND d.zoho_invoice_id IS NOT NULL${since('p.received_on')}${one('order', 'd.order_id')} ORDER BY p.id`),
    import_bills: await q(`SELECT s.id, s.division_id, s.reference FROM import_shipments s
      WHERE s.boe_no IS NOT NULL AND s.bcd_total IS NOT NULL AND (s.zoho_bill_id IS NULL OR s.zoho_duty_bill_id IS NULL
        OR (s.zoho_clearance_bill_id IS NULL AND s.freight_inr + s.insurance_inr + s.cha_inr + s.port_inr + s.other_inr > 0))${since('s.boe_date')}${one('import', 's.id')} ORDER BY s.id`),
    import_payment: await q(`SELECT p.*, s.division_id, s.reference FROM import_payments p JOIN import_shipments s ON s.id = p.shipment_id
      WHERE p.zoho_payment_id IS NULL AND s.zoho_bill_id IS NOT NULL${since('p.paid_on')}${one('import', 's.id')} ORDER BY p.id`),
    import_duty_payment: await q(`SELECT s.id, s.division_id, s.reference FROM import_shipments s
      WHERE s.duty_paid_on IS NOT NULL AND s.duty_zoho_payment_id IS NULL AND s.zoho_duty_bill_id IS NOT NULL${since('s.duty_paid_on')}${one('import', 's.id')} ORDER BY s.id`),
    import_igst_journal: await q(`SELECT s.id, s.division_id, s.reference FROM import_shipments s
      WHERE s.igst_credit_on IS NOT NULL AND s.zoho_igst_journal_id IS NULL AND s.zoho_duty_bill_id IS NOT NULL${since('s.igst_credit_on')}${one('import', 's.id')} ORDER BY s.id`),
    merchant_deal: await q(`SELECT m.id, m.division_id, m.reference FROM merchant_deals m
      WHERE m.sale_invoice_no IS NOT NULL AND m.invoice_date IS NOT NULL AND (m.zoho_invoice_id IS NULL OR m.zoho_bill_id IS NULL)${since('m.invoice_date')}${one('deal', 'm.id')} ORDER BY m.id`),
    merchant_payment: await q(`SELECT p.*, m.division_id, m.reference FROM merchant_payments p JOIN merchant_deals m ON m.id = p.deal_id
      WHERE p.zoho_payment_id IS NULL AND ((p.leg = 'buy' AND m.zoho_bill_id IS NOT NULL) OR (p.leg = 'sell' AND m.zoho_invoice_id IS NOT NULL))${since('p.moved_on')}${one('deal', 'm.id')} ORDER BY p.id`),
  };
}

/** For the "Waiting for Zoho" badge: counts by type, only for the divisions this request may see. */
async function pendingCounts(start) {
  const out = {};
  for (const [type, rows] of Object.entries(await pending(start))) {
    const n = rows.filter((r) => divisionService.allows(Number(r.division_id))).length;
    if (n > 0) out[type] = n;
  }
  return out;
}

/**
 * Sends what is waiting. Every record is logged on its own; one failing never
 * stops the others (a spent allowance stops the run, D-239).
 */
async function run(triggeredBy, userId, only = null) {
  const result = { sent: 0, failed: 0, skipped: 0 };
  if (!(await zohoBooksService.isEnabled())) {
    if (only === null) await zohoSyncLogRepository.log('division_sync', null, null, null, 'skipped', null, 'Zoho Books is not enabled — no Domestic Trade, Import & Distribution or Merchant Trade records sent.', triggeredBy, userId);
    result.skipped = 1;
    return result;
  }
  const start = await zohoBooksService.syncStartDate();
  if (start === null && zohoGuard.isProduction()) {
    await zohoSyncLogRepository.log('division_sync', null, null, null, 'error', null, 'No go-live date is set (Settings → Zoho Books → "Sync records dated from"), so nothing was sent.', triggeredBy, userId);
    result.failed = 1;
    return result;
  }
  const log = (type, entity, id, status, ref, message) => zohoSyncLogRepository.log(type, entity, id, null, status, ref, message, triggeredBy, userId);
  const step = async (type, entity, id, push, message) => {
    try {
      const ref = await push();
      await log(type, entity, id, 'success', ref, message(ref));
      result.sent += 1;
    } catch (e) {
      zohoApiBudget.rethrow(e);
      await log(type, entity, id, 'error', null, e.message);
      result.failed += 1;
    }
  };
  const applied = (r) => (r.applied > 0 ? `, applied ${n2(r.applied)}` : ', held unapplied (nothing left to pay on it in Books)');

  // The invoices and bills first, so the payments find them in the same run.
  for (const r of (await pending(start, only)).domestic_invoice) {
    await step('domestic_invoice', 'order', Number(r.order_id), () => zohoBooksService.pushDomesticInvoice(Number(r.order_id), userId),
      () => `Sent GST tax invoice ${r.tax_invoice_no} as Zoho Books invoice (marked sent).`);
  }
  for (const r of (await pending(start, only)).import_bills) {
    await step('import_bills', 'import_shipment', Number(r.id), async () => Object.values(await zohoBooksService.pushImport(Number(r.id), userId)).join(' / '),
      (ids) => `Sent import ${r.reference}: the supplier's bill, the customs duty bill and the clearance charges bill, whichever were not in Books yet (${ids}).`);
  }
  for (const r of (await pending(start, only)).merchant_deal) {
    await step('merchant_deal', 'merchant_deal', Number(r.id), async () => Object.values(await zohoBooksService.pushMerchantDeal(Number(r.id), userId)).join(' / '),
      (ids) => `Sent merchant trade deal ${r.reference}: the sale invoice (marked sent) and the supplier's bill (${ids}).`);
  }

  for (const p of (await pending(start, only)).domestic_receipt) {
    let note = '';
    await step('domestic_receipt', 'order', Number(p.order_id), async () => {
      const r = await zohoBooksService.pushDomesticReceipt(p);
      await db.execute('UPDATE domestic_payments SET zoho_payment_id = :z WHERE id = :id AND zoho_payment_id IS NULL', { z: r.payment_id, id: p.id });
      note = applied(r);
      return r.payment_id;
    }, () => `Sent receipt ${p.bank_reference} (INR ${n2(p.amount_inr)}) as Zoho Books customer payment${note}. ${p.description}`);
  }
  for (const p of (await pending(start, only)).import_payment) {
    let note = '';
    await step('import_payment', 'import_shipment', Number(p.shipment_id), async () => {
      const r = await zohoBooksService.pushImportPayment(p);
      await db.execute('UPDATE import_payments SET zoho_payment_id = :z WHERE id = :id AND zoho_payment_id IS NULL', { z: r.payment_id, id: p.id });
      note = applied(r);
      return r.payment_id;
    }, () => `Sent payment ${p.bank_reference} (${p.amount_fc} at ${p.fx_rate}) as Zoho Books vendor payment${note}. ${p.description}`);
  }
  for (const r of (await pending(start, only)).import_duty_payment) {
    let note = '';
    await step('import_duty_payment', 'import_shipment', Number(r.id), async () => {
      const x = await zohoBooksService.pushImportDutyPayment(Number(r.id));
      await db.execute('UPDATE import_shipments SET duty_zoho_payment_id = :z WHERE id = :id AND duty_zoho_payment_id IS NULL', { z: x.payment_id, id: r.id });
      note = applied(x);
      return x.payment_id;
    }, () => `Sent the customs duty payment of import ${r.reference} as Zoho Books vendor payment to Customs${note}.`);
  }
  for (const r of (await pending(start, only)).import_igst_journal) {
    await step('import_igst_journal', 'import_shipment', Number(r.id), async () => {
      const id = await zohoBooksService.pushImportIgstJournal(Number(r.id));
      await db.execute('UPDATE import_shipments SET zoho_igst_journal_id = :z WHERE id = :id AND zoho_igst_journal_id IS NULL', { z: id, id: r.id });
      return id;
    }, () => `Sent the IGST input-credit journal of import ${r.reference}.`);
  }
  for (const p of (await pending(start, only)).merchant_payment) {
    let note = '';
    await step('merchant_payment', 'merchant_deal', Number(p.deal_id), async () => {
      const r = await zohoBooksService.pushMerchantPayment(p);
      await db.execute('UPDATE merchant_payments SET zoho_payment_id = :z WHERE id = :id AND zoho_payment_id IS NULL', { z: r.payment_id, id: p.id });
      note = applied(r);
      return r.payment_id;
    }, () => `Sent payment ${p.bank_reference} (${p.amount_fc} at ${p.fx_rate}) as Zoho Books ${p.leg === 'buy' ? 'vendor payment (buy leg)' : 'customer payment (sell leg)'}${note}. ${p.description}`);
  }
  return result;
}

/** The record page's "Send to Zoho Books": everything of this record that is waiting, now. @return {[string|null, string]} [error, success message] */
async function sendNow(kind, id, userId) {
  if (!(await zohoBooksService.isEnabled())) return ['Zoho Books is not connected (Settings → Zoho Books) — nothing was sent.', ''];
  let r;
  try {
    r = await run('manual', userId, [kind, id]);
  } catch (e) {
    if (!(e instanceof zohoApiBudget.ZohoSyncStop)) throw e;
    await zohoSyncLogRepository.log('division_sync', null, null, null, 'error', null, e.message, 'manual', userId);
    return [`Zoho Books: ${e.message}`, ''];
  }
  if (r.failed > 0) return [`Zoho Books: ${r.sent} record(s) sent, ${r.failed} failed — see the Zoho Sync Log for why.`, ''];
  return [null, r.sent > 0 ? `${r.sent} record(s) sent to Zoho Books (invoice / bills and their payments).` : 'Nothing of this record was waiting for Zoho Books.'];
}

/** The "App vs Zoho" check for one Domestic Trade order, import or merchant trade deal (reads only). */
async function check(kind, id) {
  const records = [];
  const total3 = (s) => Number((Number(s.bcd_total) + Number(s.sws_total) + Number(s.igst_total)).toFixed(2));
  if (kind === 'order') {
    const dom = await require('../repositories/domesticOrderRepository').find(id);
    const order = await require('../repositories/orderRepository').find(id);
    if (!dom || !order) throw new Error(`Order #${id} not found.`);
    const c = await db.queryOne('SELECT zoho_contact_id_inr FROM clients WHERE id = :id', { id: order.client_id });
    const contact = (c && c.zoho_contact_id_inr) || null;
    if (dom.tax_invoice_no) records.push({ group: 'Sales', item: `GST tax invoice ${dom.tax_invoice_no}`, kind: 'invoice', number: String(dom.tax_invoice_no), zoho_id: dom.zoho_invoice_id, amount: Number(dom.invoice_total), unit: 'INR', vendor: false, contact });
    for (const p of await require('../repositories/domesticOrderRepository').payments(id)) {
      records.push({ group: 'Receipts', item: `Receipt ${p.bank_reference} on ${String(p.received_on).slice(0, 10)}`, kind: 'payment', number: '', zoho_id: p.zoho_payment_id || null, amount: Number(p.amount_inr), unit: 'INR', vendor: false, contact });
    }
  } else if (kind === 'import') {
    const repo = require('../repositories/importRepository');
    const s = await repo.find(id);
    if (!s) throw new Error(`Import #${id} not found.`);
    const supplier = await zohoBooksService.contactIdByName(String(s.supplier_name), 'vendor');
    const customs = await zohoBooksService.contactIdByName(zohoGuard.isProduction() ? 'Indian Customs' : `${zohoGuard.TEST_PREFIX}Indian Customs`, 'vendor');
    let billTotal = 0;
    for (const l of await repo.lines(id)) billTotal += Number((Number(l.quantity) * Number(l.unit_price_fc)).toFixed(2));
    records.push({ group: 'Purchase', item: `Supplier's bill ${s.reference}`, kind: 'bill', number: String(s.reference), zoho_id: s.zoho_bill_id, amount: Number(billTotal.toFixed(2)), unit: String(s.currency_code), vendor: true, contact: supplier });
    if (s.boe_no && s.bcd_total !== null) records.push({ group: 'Customs', item: `Customs duty bill BE-${s.boe_no}`, kind: 'bill', number: `BE-${s.boe_no}`, zoho_id: s.zoho_duty_bill_id, amount: total3(s), unit: 'INR', vendor: true, contact: customs });
    if (zohoBooksService.importClearanceTotal(s) > 0 && s.boe_no) {
      const cha = await zohoBooksService.contactIdByName(String(s.cha_name || '').trim() !== '' ? String(s.cha_name) : 'Import clearance agent', 'vendor');
      records.push({ group: 'Customs', item: `Clearance charges bill CLR-${s.boe_no}`, kind: 'bill', number: `CLR-${s.boe_no}`, zoho_id: s.zoho_clearance_bill_id || null, amount: zohoBooksService.importClearanceTotal(s), unit: 'INR', vendor: true, contact: cha });
    }
    for (const p of await repo.payments(id)) {
      records.push({ group: 'Payments to the supplier', item: `${String(p.kind).charAt(0).toUpperCase()}${String(p.kind).slice(1)} payment ${p.bank_reference} on ${String(p.paid_on).slice(0, 10)}`, kind: 'payment', number: '', zoho_id: p.zoho_payment_id || null, amount: Number(p.amount_fc), unit: String(s.currency_code), vendor: true, contact: supplier });
    }
    if (s.duty_paid_on) records.push({ group: 'Customs', item: `Duty payment ${s.duty_bank_reference} on ${String(s.duty_paid_on).slice(0, 10)}`, kind: 'payment', number: '', zoho_id: s.duty_zoho_payment_id, amount: total3(s), unit: 'INR', vendor: true, contact: customs });
    if (s.igst_credit_on) records.push({ group: 'Customs', item: `IGST input-credit journal on ${String(s.igst_credit_on).slice(0, 10)}`, kind: 'journal', number: '', zoho_id: s.zoho_igst_journal_id, amount: Number(Number(s.igst_total).toFixed(2)), unit: 'INR', vendor: false, contact: null });
  } else {
    const repo = require('../repositories/merchantTradeRepository');
    const d = await repo.find(id);
    if (!d) throw new Error(`Deal #${id} not found.`);
    const buyer = await zohoBooksService.contactIdByName(String(d.buyer_name), 'customer');
    const supplier = await zohoBooksService.contactIdByName(String(d.supplier_name), 'vendor');
    let sell = 0;
    let buy = 0;
    for (const l of await repo.lines(id)) {
      sell += Number((Number(l.quantity) * Number(l.sell_price_fc)).toFixed(2));
      buy += Number((Number(l.quantity) * Number(l.buy_price_fc)).toFixed(2));
    }
    if (d.sale_invoice_no) {
      records.push({ group: 'Sale', item: `Sale invoice ${d.sale_invoice_no}`, kind: 'invoice', number: String(d.sale_invoice_no), zoho_id: d.zoho_invoice_id, amount: Number(sell.toFixed(2)), unit: String(d.sale_currency), vendor: false, contact: buyer });
      records.push({ group: 'Purchase', item: `Supplier's bill ${d.po_no}`, kind: 'bill', number: String(d.po_no), zoho_id: d.zoho_bill_id, amount: Number(buy.toFixed(2)), unit: String(d.purchase_currency), vendor: true, contact: supplier });
    }
    for (const p of await repo.payments(id)) {
      const isBuy = p.leg === 'buy';
      records.push({ group: isBuy ? 'Buy leg' : 'Sell leg', item: `${isBuy ? 'Paid' : 'Received'} ${p.bank_reference} on ${String(p.moved_on).slice(0, 10)}`, kind: 'payment', number: '', zoho_id: p.zoho_payment_id || null, amount: Number(p.amount_fc), unit: String(isBuy ? d.purchase_currency : d.sale_currency), vendor: isBuy, contact: isBuy ? supplier : buyer });
    }
  }
  const rows = await zohoBooksService.matchRecords(records);
  const counts = {};
  for (const r of rows) counts[r.status] = (counts[r.status] || 0) + 1;
  return { rows, counts };
}

module.exports = { TYPE_LABELS, pending, pendingCounts, run, sendNow, check };
