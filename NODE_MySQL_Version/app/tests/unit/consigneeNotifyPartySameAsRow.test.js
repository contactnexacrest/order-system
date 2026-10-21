'use strict';

const path = require('path');
const nunjucks = require('nunjucks');

/**
 * Port of ConsigneeNotifyPartySameAsRowTest.php. User-reported batch item
 * 17: "Same as Buyer?" / "Same as Consignee?" is an application-level
 * data-entry option (the checkbox that decides HOW the Consignee/Notify
 * Party fields resolve) — it must never be printed as a row inside a
 * buyer-facing document itself. The resolved field values still print as
 * normal; only the "Same as X?" status row is removed from
 * `_consignee_details.njk` and `_notify_party.njk`.
 */
describe('Consignee / Notify Party "Same as X?" row removed from documents (batch item 17)', () => {
  function templateEnv() {
    return new nunjucks.Environment(new nunjucks.FileSystemLoader(path.join(__dirname, '../../templates')));
  }

  it('consignee details partial never prints "Same as Buyer?" row even when true', () => {
    const html = templateEnv().render('_consignee_details.njk', {
      section_number: 5,
      consignee: {
        same_as_buyer: true,
        company_legal_name: 'Acme Importers Ltd',
        address_line1: '1 Buyer Street',
        address_line2: null,
        city: 'London',
        postcode: 'EC1A 1AA',
        country: 'United Kingdom',
        vat_eori_tax_no: 'GB123456789',
        contact_person: 'Jane Buyer',
        phone: '+44 20 7946 0000',
        email: 'jane@acme.example',
      },
    });

    expect(html).not.toContain('Same as Buyer?');
    expect(html).toContain('Acme Importers Ltd');
    expect(html).toContain('5. CONSIGNEE DETAILS');
  });

  it('consignee details partial never prints "Same as Buyer?" row when false', () => {
    const html = templateEnv().render('_consignee_details.njk', {
      section_number: 5,
      consignee: {
        same_as_buyer: false,
        company_legal_name: 'Independent Consignee Co',
        address_line1: '2 Dock Road',
        address_line2: null,
        city: 'Rotterdam',
        postcode: '3000AA',
        country: 'Netherlands',
        vat_eori_tax_no: 'NL123456789B01',
        contact_person: 'Pieter Consignee',
        phone: '+31 10 000 0000',
        email: 'pieter@independent.example',
      },
    });

    expect(html).not.toContain('Same as Buyer?');
    expect(html).toContain('Independent Consignee Co');
  });

  it('notify party partial never prints "Same as Consignee?" row even when true', () => {
    const html = templateEnv().render('_notify_party.njk', {
      section_number: 6,
      notify_party_block: {
        same_as_consignee: true,
        name: 'Acme Importers Ltd',
        address_line1: '1 Buyer Street',
        address_line2: null,
        city: 'London',
        postcode: 'EC1A 1AA',
        country: 'United Kingdom',
        contact_person: 'Jane Buyer',
        phone: '+44 20 7946 0000',
        email: 'jane@acme.example',
      },
    });

    expect(html).not.toContain('Same as Consignee?');
    expect(html).toContain('Acme Importers Ltd');
    expect(html).toContain('6. NOTIFY PARTY');
  });

  it('notify party partial never prints "Same as Consignee?" row when false', () => {
    const html = templateEnv().render('_notify_party.njk', {
      section_number: 6,
      notify_party_block: {
        same_as_consignee: false,
        name: 'Freight Forwarders Inc',
        address_line1: '9 Harbour Way',
        address_line2: null,
        city: 'Hamburg',
        postcode: '20095',
        country: 'Germany',
        contact_person: 'Hans Forwarder',
        phone: '+49 40 000000',
        email: 'hans@forwarder.example',
      },
    });

    expect(html).not.toContain('Same as Consignee?');
    expect(html).toContain('Freight Forwarders Inc');
  });
});
