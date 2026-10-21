'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const piIntakeRepository = require('../../src/repositories/piIntakeRepository');
const { formatDate } = require('../../src/services/documentDataAssembler');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of QuotationAcceptancePlaceholderTest.php. User-reported batch
 * item 8: "Acceptance of Quotation No. *" ==> this should also autofill
 * - no more placeholder". Identical bug to 90-item backlog #16 (fixed
 * for Payment Terms Confirmation in piIntakePaymentTermsPlaceholder.test.js)
 * — the computed sentence was only ever passed into the HTML placeholder
 * attribute, never into the textarea's real value, so a fresh required
 * field looked empty. Fixed by prefilling the real Quotation
 * reference/date this order's own Quotation document was actually
 * issued with.
 */
describe('PI intake quotation-acceptance placeholder (user-reported batch, item 8)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestDocument(orderId, typeCode, status = 'draft') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const ref = `JEST-QADOC-${Math.random().toString(16).slice(2, 10)}`;
    await db.execute(
      'INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:order_id, :type_id, :ref, :status)',
      { order_id: orderId, type_id: type.id, ref, status }
    );
    return ref;
  }

  function textareaBody(html, name) {
    const start = html.indexOf(`name="${name}"`);
    if (start === -1) return null;
    const fragment = html.slice(start, start + 600);
    const closeTagIdx = fragment.indexOf('</textarea>');
    const innerStart = fragment.indexOf('>') + 1;
    return fragment.slice(innerStart, closeTagIdx);
  }

  it('prefills the real quotation reference and date on a fresh submission, not just a placeholder', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const qtRef = await createTestDocument(orderId, 'QT');
    const token = await piIntakeRepository.createLink(orderId, null);

    const res = await request(app).get(`/pi-details/${token}`);
    expect(res.status).toBe(200);

    const body = textareaBody(res.text, 'quotation_acceptance_reference');
    expect(body).not.toBeNull();
    expect(body.trim()).not.toBe('');
    expect(body).toContain(`We accept Quotation ${qtRef} dated`);
    expect(body).toContain(formatDate(new Date().toISOString().slice(0, 10)));
  });

  it("shows the client's own previous answer verbatim, not the computed default", async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestDocument(orderId, 'QT');
    const token = await piIntakeRepository.createLink(orderId, null);
    const submission = await piIntakeRepository.findValidByToken(token);

    await db.execute('UPDATE pi_intake_submissions SET quotation_acceptance_reference = :v WHERE id = :id', {
      v: 'We accept Quotation MY-OWN-WORDING',
      id: submission.id,
    });

    const res = await request(app).get(`/pi-details/${token}`);
    const body = textareaBody(res.text, 'quotation_acceptance_reference');

    expect(body.trim()).toBe('We accept Quotation MY-OWN-WORDING');
  });
});
