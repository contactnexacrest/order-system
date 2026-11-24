'use strict';

/**
 * Owner decision D-410 point 8 (24 Nov 2026, M14 confirmed twice): the Commission Payment Advice.
 *  - one advice per bank transfer (same associate, day and bank reference); the number counts per associate per
 *    financial year: NC/SC/CPA/A0000/YY/NNN; made once; frozen; "Rev n" for an MD correction, the old one superseded;
 *  - company details from Admin, no TAN; only the associate's commission.
 * PHP twin: tests/Integration/AssociatePaymentAdviceD410Test.php.
 */
const crypto = require('crypto');
const db = require('../../src/config/db');
const associateRepository = require('../../src/repositories/associateRepository');
const advices = require('../../src/services/associatePaymentAdvice');
const { createTestClient, createTestOrder } = require('../support/fixtures');

describe('Commission Payment Advice (D-410 point 8)', () => {
  let md;

  afterAll(async () => {
    await require('../../src/services/pdfRenderService').closeBrowser();
    await db.pool.end();
  });

  beforeEach(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Managing Director'");
    md = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest CPA MD', :email, NULL, 'x', :r, 1, 0, 0, 0)`,
      { email: `jest-cpa-${crypto.randomBytes(4).toString('hex')}@email.com`, r: role.id }
    )).insertId;
  });

  async function associate() {
    return associateRepository.create({ name: `CPA Associate ${crypto.randomBytes(2).toString('hex')}`, based_in: 'india', country: 'India', currency_code: 'INR',
      email: null, phone: null, tax_id: null, agreement_signed_on: '2025-12-01', status: 'active', notes: null }, md);
  }

  /** A paid associate payout on a new order of a new client. */
  async function paidPayout(associateId, paidOn, ref, paidInr, tdsInr = 0) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 90, 9000)", { o: orderId });
    return (await db.execute(
      `INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, status, paid_on, paid_inr, tds_inr, tds_section, paid_via, paid_reference)
       VALUES (:o, 'associate', :a, 'associate', :amt, 100, 'USD', :m, 'paid', :d, :p, :t, :sec, 'bank', :ref)`,
      { o: orderId, a: associateId, amt: paidInr + tdsInr, m: paidOn.slice(0, 7), d: paidOn, p: paidInr, t: tdsInr, sec: tdsInr > 0 ? '194H' : null, ref }
    )).insertId;
  }

  it('makes one advice per bank transfer, numbered per associate per financial year', async () => {
    const a = await associate();
    const p1 = await paidPayout(a, '2026-07-31', 'UTR-A', 9000, 200);
    const p2 = await paidPayout(a, '2026-07-31', 'UTR-A', 5000);
    const p3 = await paidPayout(a, '2026-08-31', 'UTR-B', 4000);
    const p4 = await paidPayout(a, '2027-04-30', 'UTR-C', 3000); // the next financial year: numbering starts again
    const ids = await advices.afterPaid([p1, p2, p3, p4], md);
    expect(ids).toHaveLength(3); // UTR-A (two orders), UTR-B and UTR-C
    const no = `A${String(a).padStart(4, '0')}`;
    const numbers = [];
    for (const id of ids) numbers.push((await advices.find(id)).advice_no);
    expect(numbers.sort()).toEqual([`NC/SC/CPA/${no}/26/001`, `NC/SC/CPA/${no}/26/002`, `NC/SC/CPA/${no}/27/001`]);
    const c = advices.storedContext(await advices.find(ids[0]));
    expect(c.lines).toHaveLength(2);
    expect([c.total_gross, c.total_tds, c.total_paid]).toEqual(['₹14,200.00', '₹200.00', '₹14,000.00']);
    expect(await advices.afterPaid([p1, p2], md)).toEqual([]); // made once
  });

  it('stays one advice when payouts of one transfer are marked paid one by one', async () => {
    const a = await associate();
    const p1 = await paidPayout(a, '2026-07-31', 'UTR-SAME', 9000);
    const p2 = await paidPayout(a, '2026-07-31', 'UTR-SAME', 5000);
    const [first] = await advices.afterPaid([p1], md);
    const [second] = await advices.afterPaid([p2], md);
    const old = await advices.find(first);
    const nw = await advices.find(second);
    expect(old.superseded_at).not.toBeNull(); // the first advice is replaced
    expect([nw.advice_no, Number(nw.revision), nw.revision_reason]).toEqual([old.advice_no, 1, 'Another order paid in the same bank transfer was added']);
    expect(advices.storedContext(nw).lines).toHaveLength(2);
    expect(advices.storedContext(nw).total_paid).toBe('₹14,000.00');
  });

  it('is frozen, and an MD correction makes Rev 1', async () => {
    const a = await associate();
    const p = await paidPayout(a, '2026-07-31', 'UTR-WRONG', 9000);
    const [id] = await advices.afterPaid([p], md);
    await db.execute("UPDATE incentive_payouts SET paid_reference = 'UTR-RIGHT' WHERE id = :id", { id: p });
    expect(advices.storedContext(await advices.find(id)).paid_reference).toBe('UTR-WRONG');
    expect(await advices.revise(id, 'short', md)).toContain('10 to 300 characters');
    expect(await advices.revise(id, 'Bank reference typed wrong', md)).toBeNull();
    const old = await advices.find(id);
    expect(old.superseded_at).not.toBeNull();
    const rev = (await advices.forAssociate(a))[0];
    expect([Number(rev.revision), rev.advice_no, advices.storedContext(rev).paid_reference]).toEqual([1, old.advice_no, 'UTR-RIGHT']);
    expect(await advices.revise(id, 'Second try on the old one', md)).toContain('correct the latest one');
  });

  it('shows no TAN and no costs', async () => {
    const a = await associate();
    const [id] = await advices.afterPaid([await paidPayout(a, '2026-07-31', 'UTR-A', 9000)], md);
    let html = await advices.html(await advices.find(id));
    expect(html).toContain('COMMISSION PAYMENT ADVICE');
    html = html.replace(/<style[\s\S]*?<\/style>/g, ' ').replace(/<[^>]+>/g, ' ');
    expect(html).not.toMatch(/\bTAN\b/);
    for (const never of ['Supplier', 'Margin (', 'Cost (FOB)', 'CAFIN']) expect(html).not.toContain(never);
    expect((await advices.pdf(await advices.find(id))).subarray(0, 4).toString()).toBe('%PDF');
    expect(Buffer.from(await advices.docx(await advices.find(id))).subarray(0, 2).toString()).toBe('PK');
  }, 60000);
});
