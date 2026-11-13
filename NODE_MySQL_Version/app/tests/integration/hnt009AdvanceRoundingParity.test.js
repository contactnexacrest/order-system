'use strict';

/**
 * HNT-009 (owner-confirmed twice, 13 Nov 2026): the advance, the balance and
 * the amount in words come out exactly as on the PHP stack, which uses PHP's
 * round(). The old Node rounding printed USD 4,159.23 × 50 % as 2,079.61
 * (PHP 2,079.62) and 9,017.30 × 45 % as 4,057.78 (PHP 4,057.79), on the PI,
 * the order page's "Advance due", the Record Advance prefill and the balance.
 *
 * tests/fixtures/hnt009_half_cent_cases.json holds PHP's own figures for
 * 297 half-cent cases (the hunt's brute-force mismatches) and the words
 * cases; the PHP twin is AdvanceRoundingParityHnt009Test.php.
 */
const fs = require('fs');
const path = require('path');
const nunjucks = require('nunjucks');
const db = require('../../src/config/db');
const { phpRound } = require('../../src/helpers/money');
const amountInWords = require('../../src/helpers/amountInWords');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const paymentActionService = require('../../src/services/paymentActionService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

const ROOT = path.join(__dirname, '..', '..');
const CASES = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'hnt009_half_cent_cases.json'), 'utf8'));
const money = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

describe('HNT-009: advance / balance / amount in words round as PHP round()', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('the fixture holds both named examples and a few hundred half-cent cases', () => {
    expect(CASES.advance.length).toBeGreaterThanOrEqual(250);
    expect(CASES.advance[0]).toEqual({ fob: '4159.23', pct: '50', advance: '2079.62', balance: '2079.61' });
    expect(CASES.advance[1]).toEqual({ fob: '9017.30', pct: '45', advance: '4057.79', balance: '4959.51' });
  });

  it.each(CASES.advance.map((c) => [c.fob, c.pct, c.advance, c.balance]))('FOB %s × %s %% → advance %s, balance %s (as PHP)', (fob, pct, advance, balance) => {
    expect(phpRound(Number(fob) * Number(pct) / 100, 2).toFixed(2)).toBe(advance);
    expect(orderPaymentStatusRepository.balanceDue(fob, pct, null).toFixed(2)).toBe(balance);
  });

  it.each(CASES.words.map((c) => [c.amount, c.usd, c.indian]))('amount in words for %s matches PHP', (amount, usd, indian) => {
    expect(amountInWords.format(Number(amount), 'USD')).toBe(usd);
    expect(amountInWords.formatIndian(Number(amount))).toBe(indian);
  });

  it('AmountInWords 1.005 USD reads "One and One Cents" as on PHP', () => {
    expect(amountInWords.format(1.005, 'USD')).toBe('US Dollars One and One Cents Only');
  });

  it('phpRound matches PHP round() on known edge values', () => {
    expect(phpRound(1.005, 2)).toBe(1.01);
    expect(phpRound(-12.345, 2)).toBe(-12.35);
    expect(phpRound(100.49999999999999, 0)).toBe(101);
    expect(phpRound(8484053.915, 2)).toBe(8484053.92);
    expect(phpRound(9721615.995, 2)).toBe(9721616);
    expect(phpRound(0.00005, 4)).toBe(0.0001);
  });

  it.each([
    ['4159.23', 50, '2,079.62', '2,079.61'],
    ['9017.30', 45, '4,057.79', '4,959.51'],
  ])('PI for FOB %s at %s %% advance prints PHP figures', async (fob, pct, advance, balance) => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    await orderProductRepository.add(orderId, 1, 'Test granite slab', null, null, '1', false, 'PCS', fob);
    if (!(await orderPaymentStatusRepository.find(orderId))) await orderPaymentStatusRepository.initializeForOrder(orderId);
    await db.execute('UPDATE orders SET advance_pct = :a, balance_pct = :b WHERE id = :id', { a: pct, b: 100 - pct, id: orderId });

    const data = await documentDataAssembler.assemble(orderId, 'PI');
    expect(data.financial.advance_amount).toBe(advance);
    expect(data.financial.balance_amount).toBe(balance);
    expect(data.payment_status.balance_amount).toBe(balance);

    // The client payment page's "advance due" is the same figure.
    const order = await db.queryOne('SELECT * FROM orders WHERE id = :id', { id: orderId });
    expect(money(await paymentActionService.amountDue(order, 'advance'))).toBe(advance);
  });

  it('the order page "Advance due" and the Record Advance prefill use PHP rounding', () => {
    const view = fs.readFileSync(path.join(ROOT, 'views/orders/show.njk'), 'utf8');
    const setLine = view.split('\n').find((l) => l.includes('{% set advanceDue ='));
    expect(setLine).toContain('| round_money');
    expect(fs.readFileSync(path.join(ROOT, 'src/server.js'), 'utf8')).toContain("njkEnv.addFilter('round_money', (v) => require('./helpers/money').phpRound(Number(v) || 0, 2));");

    const env = new nunjucks.Environment();
    env.addFilter('float', (v) => parseFloat(v) || 0);
    env.addFilter('round_money', (v) => phpRound(Number(v) || 0, 2));
    const html = env.renderString(`${setLine.trim()}{{ advanceDue.toFixed(2) }}`, { fobTotal: '4159.23', order: { advance_pct: '50.00' } });
    expect(html.replace(/\{#.*?#\}/g, '').trim()).toBe('2079.62');
  });

  it('the posted advance is checked against the same advance due (ordersController)', () => {
    const src = fs.readFileSync(path.join(ROOT, 'src/controllers/ordersController.js'), 'utf8');
    expect(src).toContain("const advanceDue = require('../helpers/money').phpRound(fobTotal * advancePctNum / 100, 2);");
  });
});
