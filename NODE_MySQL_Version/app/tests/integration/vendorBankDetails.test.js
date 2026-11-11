'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const supplierController = require('../../src/controllers/supplierController');
const logisticsPartnerController = require('../../src/controllers/logisticsPartnerController');
const vendorDetailsController = require('../../src/controllers/vendorDetailsController');
const supplierRepository = require('../../src/repositories/supplierRepository');
const logisticsPartnerRepository = require('../../src/repositories/logisticsPartnerRepository');
const vendorDetailsRepository = require('../../src/repositories/vendorDetailsRepository');
const vendorBankChangeRepository = require('../../src/repositories/vendorBankChangeRepository');
const { TEST_ADMIN_EMAIL } = require('../support/globalSetup');

/**
 * Port of VendorBankDetailsTest.php. Owner request (2026-10-27): suppliers
 * and logistics partners carry the bank details we pay them into — filled by
 * staff on their behalf, or by them through a one-use Vendor Details Link.
 * Account numbers are stored encrypted; a change by the vendor (or by staff
 * without ticking "verified") must be checked again before paying.
 *
 * Owner decisions 11 Nov 2026 (D-294): a change no longer replaces the account
 * in use — it waits (vendor_bank_changes) for the vendor's confirmation through
 * a link e-mailed to the registered e-mail, then for staff verification. The
 * full flow is tested in associateOwnerDecisionsNov11.test.js.
 */
describe('Vendor bank details and the Vendor Details Link (owner request 2026-10-27)', () => {
  let userId;
  const BANK = {
    bank_account_name: 'Heera Granites', bank_name: 'State Bank of India', bank_branch: 'Udaipur Main',
    bank_ifsc: 'sbin0001234', bank_account_number: '123456789012', bank_account_number_confirm: '1234 5678 9012',
    bank_account_type: 'Current',
  };

  beforeAll(async () => {
    userId = (await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL })).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { html: null };
    res.redirect = () => {};
    res.status = () => res;
    res.send = () => {};
    res.renderView = (view, locals) => { res.view = view; res.locals = locals; };
    return res;
  }

  async function call(fn, params, body) {
    const req = { user: { id: userId }, params, body, session: {}, permissions: {} };
    const res = fakeRes();
    await fn(req, res);
    const messages = flash.pull(req);
    return { res, flash: messages[messages.length - 1] || { type: '', message: '' } };
  }

  async function createSupplier(extra) {
    const name = `Heera Granites ${Math.random().toString(16).slice(2, 8)}`;
    const out = await call(supplierController.create, {}, { supplier_legal_name: name, types: ['Quarry Owner'], email: 'accounts@heera.test', ...extra });
    const row = await db.queryOne('SELECT id FROM suppliers WHERE supplier_legal_name = :name', { name });
    return { id: row ? row.id : null, name, flash: out.flash };
  }

  it('lets staff fill the bank details, stored encrypted — waiting for confirmation (D-294)', async () => {
    const bad = await createSupplier({ ...BANK, bank_account_number_confirm: '999' });
    expect(bad.id).toBeNull();
    expect(bad.flash.message).toContain('do not match');

    const { id, name } = await createSupplier({ ...BANK });
    const row = await supplierRepository.find(id);
    expect(vendorDetailsRepository.bankDetails(row).has_bank).toBe(false); // not in use until confirmed and verified
    const [change] = await vendorBankChangeRepository.forVendor('supplier', id);
    expect(change.status).toBe('awaiting_confirmation');
    expect(String(change.bank_account_number)).not.toContain('123456789012');
    expect(vendorBankChangeRepository.number(change)).toBe('123456789012');
    expect(change.bank_ifsc).toBe('SBIN0001234');
    expect(change.sent_to_email).toBe('accounts@heera.test');

    // Editing the address only records no bank change.
    await call(supplierController.update, { id: String(id) }, { supplier_legal_name: name, types: ['Quarry Owner'], address: 'New road' });
    expect(await vendorBankChangeRepository.forVendor('supplier', id)).toHaveLength(1);

    // A new account number replaces the change still waiting.
    await call(supplierController.update, { id: String(id) }, { supplier_legal_name: name, types: ['Quarry Owner'], bank_account_number: '555566667777', bank_account_number_confirm: '555566667777',
      bank_account_name: 'Heera Granites', bank_name: 'State Bank of India', bank_ifsc: 'SBIN0001234' });
    const changes = await vendorBankChangeRepository.forVendor('supplier', id);
    expect(vendorBankChangeRepository.number(changes[0])).toBe('555566667777');
    expect([changes[0].status, changes[1].status, changes[1].cancelled_how]).toEqual(['awaiting_confirmation', 'cancelled', 'replaced']);
    expect(vendorDetailsRepository.bankDetails(await supplierRepository.find(id)).has_bank).toBe(false);
  });

  it('lets the vendor fill their details once through the link', async () => {
    const { id } = await createSupplier({});
    const sent = await call(supplierController.sendDetailsLink, { id: String(id) }, {});
    expect(sent.flash.message).toContain('emailed to accounts@heera.test');
    const token = vendorDetailsRepository.liveDetailsToken(await supplierRepository.find(id));
    expect(token).toBeTruthy();

    expect((await call(vendorDetailsController.show, { token }, {})).res.view).toBe('vendor_details/show');
    const refused = await call(vendorDetailsController.submit, { token }, { ...BANK, bank_ifsc: 'BAD' });
    expect(refused.flash.message).toContain('valid 11-character IFSC');

    const done = await call(vendorDetailsController.submit, { token }, { ...BANK, gstin: '08AAAAA0000A1Z5', contact_person: 'Accounts Manager' });
    expect(done.res.view).toBe('vendor_details/thanks');
    const row = await supplierRepository.find(id);
    // D-294: what the vendor typed waits for their confirmation (link to the registered e-mail), then staff verify it.
    expect(vendorDetailsRepository.bankDetails(row).has_bank).toBe(false);
    const [change] = await vendorBankChangeRepository.forVendor('supplier', id);
    expect([change.status, change.entered_source, change.sent_to_email]).toEqual(['awaiting_confirmation', 'vendor', 'accounts@heera.test']);
    expect(vendorBankChangeRepository.number(change)).toBe('123456789012');
    expect(row.gstin).toBe('08AAAAA0000A1Z5');
    expect(row.contact_person).toBe('Accounts Manager');
    expect((await call(vendorDetailsController.show, { token }, {})).res.view).toBe('vendor_details/link_expired');
  });

  it('works for a logistics partner and keeps the account on file when left empty', async () => {
    const partnerId = await logisticsPartnerRepository.create({ partner_name: 'Blue Wave Logistics', service_type: 'freight_forwarder' }, userId);
    await vendorDetailsRepository.saveBankDetails('logistics_partner', partnerId, { bank_account_name: 'Blue Wave', bank_name: 'HDFC', bank_ifsc: 'HDFC0000123', bank_account_number: '998877665544' }, 'staff', userId);
    await call(logisticsPartnerController.sendDetailsLink, { id: String(partnerId) }, {});
    const token = vendorDetailsRepository.liveDetailsToken(await logisticsPartnerRepository.find(partnerId));
    const shown = await call(vendorDetailsController.show, { token }, {});
    expect(shown.res.locals.kind).toBe('logistics_partner');

    await call(vendorDetailsController.submit, { token }, { bank_account_name: 'Blue Wave', bank_name: 'HDFC', bank_ifsc: 'HDFC0000123', contact_person_name: 'Operations Manager' });
    const partner = await logisticsPartnerRepository.find(partnerId);
    expect(partner.contact_person_name).toBe('Operations Manager');
    expect(vendorDetailsRepository.bankDetails(partner).bank_account_number).toBe('998877665544');
  });
});
