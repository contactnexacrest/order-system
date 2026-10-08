'use strict';

const db = require('../../src/config/db');
const clientRepository = require('../../src/repositories/clientRepository');

/**
 * Buyer/Consignee/Notify Party split on the Client record (mirrors PHP's
 * ClientController::collectPartyFields + ClientRepository changes) —
 * covers the structured billing address columns and the two "Same as"
 * flags, including that same_as_buyer/same_as_consignee defaults to 1
 * when omitted (new client, checkbox checked by default).
 */
describe('clientRepository — Buyer/Consignee/Notify Party split', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function baseData(overrides = {}) {
    return {
      company_legal_name: 'Jest Test Buyer Co',
      billing_address: 'Fallback billing address line',
      billing_address_line1: '1 Buyer Street',
      billing_address_line2: 'Suite 2',
      billing_city: 'Buyer City',
      billing_postcode: 'BC123',
      vat_eori_tax_no: 'DE123456789',
      contact_person: 'Buyer Contact',
      email: 'buyer@jest-test.example',
      phone: '+49 111',
      country_of_destination: 'Germany',
      coo_type: 'Non-Preferential',
      ...overrides,
    };
  }

  it('create defaults consignee_same_as_buyer and notify_party_same_as_consignee to 1 when omitted', async () => {
    const id = await clientRepository.create(baseData(), 1, `JEST-${Date.now()}`);
    const row = await clientRepository.find(id);

    expect(row.consignee_same_as_buyer).toBe(1);
    expect(row.notify_party_same_as_consignee).toBe(1);
    expect(row.billing_address_line1).toBe('1 Buyer Street');
    expect(row.billing_city).toBe('Buyer City');
  });

  it('create stores independent consignee_* columns when consignee_same_as_buyer is 0', async () => {
    const id = await clientRepository.create(baseData({
      consignee_same_as_buyer: 0,
      consignee_name: 'Independent Consignee Co',
      consignee_address_line1: '9 Consignee Road',
      consignee_city: 'Consignee City',
      consignee_country: 'France',
      consignee_email: 'consignee@jest-test.example',
    }), 1, `JEST-${Date.now()}-A`);

    const row = await clientRepository.find(id);
    expect(row.consignee_same_as_buyer).toBe(0);
    expect(row.consignee_name).toBe('Independent Consignee Co');
    expect(row.consignee_address_line1).toBe('9 Consignee Road');
    expect(row.consignee_country).toBe('France');
  });

  it('create stores independent notify_party_* columns when notify_party_same_as_consignee is 0', async () => {
    const id = await clientRepository.create(baseData({
      notify_party_same_as_consignee: 0,
      notify_party: 'Freight Forwarder Ltd',
      notify_party_address_line1: '5 Forwarder Lane',
      notify_party_country: 'Netherlands',
      notify_party_email: 'forwarder@jest-test.example',
    }), 1, `JEST-${Date.now()}-B`);

    const row = await clientRepository.find(id);
    expect(row.notify_party_same_as_consignee).toBe(0);
    expect(row.notify_party).toBe('Freight Forwarder Ltd');
    expect(row.notify_party_address_line1).toBe('5 Forwarder Lane');
    expect(row.notify_party_country).toBe('Netherlands');
  });

  it('update can flip consignee_same_as_buyer from 0 back to 1 and clear the independent fields', async () => {
    const id = await clientRepository.create(baseData({
      consignee_same_as_buyer: 0,
      consignee_name: 'Temp Consignee Co',
      consignee_address_line1: '9 Consignee Road',
    }), 1, `JEST-${Date.now()}-C`);
    expect((await clientRepository.find(id)).consignee_same_as_buyer).toBe(0);

    await clientRepository.update(id, baseData({
      consignee_same_as_buyer: 1,
      consignee_name: null,
      consignee_address_line1: null,
    }));

    const row = await clientRepository.find(id);
    expect(row.consignee_same_as_buyer).toBe(1);
    expect(row.consignee_name).toBeNull();
    expect(row.consignee_address_line1).toBeNull();
  });

  it('update overwrites billing address line fields independently of billing_address', async () => {
    const id = await clientRepository.create(baseData(), 1, `JEST-${Date.now()}-D`);

    await clientRepository.update(id, baseData({
      billing_address_line1: '99 New Street',
      billing_city: 'New City',
      billing_postcode: 'NC999',
    }));

    const row = await clientRepository.find(id);
    expect(row.billing_address_line1).toBe('99 New Street');
    expect(row.billing_city).toBe('New City');
    expect(row.billing_postcode).toBe('NC999');
  });
});
