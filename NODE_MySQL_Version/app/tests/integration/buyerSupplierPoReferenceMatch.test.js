'use strict';

const db = require('../../src/config/db');
const termsClauseRepository = require('../../src/repositories/termsClauseRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');

/**
 * Regression coverage for the Buyer PO / Supplier PO cross-check against
 * the real reference documents the business actually uses (Point 1 of the
 * original 10-point feedback list, addressed here after the earlier
 * "Section 1 is missing" diagnosis turned out to be wrong — Section 1 was
 * already correctly inherited from _layout.njk's shared block; the two
 * real gaps were the Buyer PO's page title and a duplicated Supplier PO
 * clause, both fixed here).
 *
 * Puppeteer is stubbed out under Jest (see tests/support/mocks), so these
 * tests render the same Nunjucks template + context generate() would
 * build, directly, instead of going through the real PDF pipeline — the
 * PHP mirror of this test (BuyerSupplierPoReferenceMatchTest) verifies the
 * same fix against an actual generated PDF, since dompdf is cheap enough
 * to run for real in PHPUnit.
 */
describe('Buyer PO / Supplier PO — reference document match', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const baseContext = {
    company: {
      legal_name: 'NexaCrest International Private Limited',
      registered_office: 'No. 33, T Ramaiah Garden, 2 Hulimavu Village, Hulimavu, Bangalore South, Bengaluru, Karnataka – 560076, India',
      corporate_office: 'Evolve Work Studio, 4th Floor, The Hub @ Raj Serenity, Khatha No. 10, Begur Koppa Road, Yelenahalli, Bengaluru – 560068, Karnataka, India',
      gstin: '29AAKCN8733G1ZZ',
      iec_pan: 'AAKCN8733G',
      md_name: 'Gulmohar Sontakke',
      md_title: 'Founder & Managing Director',
      phone: '+91-7676463030',
      email: 'gulmohar.sontakke@nexacrestinternational.com',
    },
    meta: { document_reference: 'SC/PO/2026/3009001', client_revision_label: 'Rev.00', generated_date: '30 September 2026' },
    order: {
      quotation_ref: 'SC/QT/2026/3009001', buyer_inquiry_ref: 'NC/SC/2026/3009001',
      quotation_valid_until: '30 October 2026', port_of_discharge: 'Rotterdam, Netherlands',
      quotation_date: '01 September 2026', currency_code: 'USD',
      incoterm_label: 'FOB Chennai, India — Incoterms® 2020', port_of_loading: 'Chennai, India',
      coo_type: 'Non-preferential', include_annexure_a: false,
    },
    buyer: {
      company_legal_name: 'Test Buyer Ltd', billing_address: 'Test Address', consignee_name: 'Same',
      consignee_address: 'Same', vat_eori_tax_no: 'VAT123', contact_person: 'John Doe',
      phone: '+44123', email: 'john@test.com', country_of_destination: 'Netherlands',
    },
    products: [{ description: 'Test Granite Slab', quantity: 100, unit: 'm²', unit_price: 25.5 }],
    financial: { fob_value: '2,550.00', advance_pct: 40, balance_pct: 60, balance_terms_text: 'within 3 working days.' },
    signatory: { name: 'Gulmohar Sontakke', designation: 'Founder & Managing Director', signature_data_uri: null, seal_data_uri: null },
    terms: ['PAYMENT TERMS FINALITY: test clause text.', 'DISPUTE RESOLUTION: test clause text.'],
  };

  function renderDocumentType(code, terms) {
    const twig = documentGenerationService.templatesEnvironment();
    const context = {
      ...baseContext,
      doc_title: documentGenerationService.titleFor(code),
      section1_title: documentGenerationService.section1TitleFor(code),
      terms: terms ?? baseContext.terms,
      terms_section_number: code === 'SUPPO' ? 6 : 5,
      terms_section_title: code === 'SUPPO' ? 'QUALITY & INSPECTION' : 'GENERAL TERMS',
    };
    return twig.render(documentGenerationService.templateFileFor(code), context);
  }

  it('Buyer PO title matches the real reference document exactly', () => {
    const html = renderDocumentType('BUYERPO');
    expect(html).toContain('PURCHASE ORDER');
    expect(html).not.toContain('PURCHASE ORDER — ORDER ACCEPTANCE');
  });

  it('Supplier PO title still matches the reference document', () => {
    const html = renderDocumentType('SUPPO');
    expect(html).toContain('PURCHASE ORDER — MATERIAL PROCUREMENT');
  });

  it("Supplier PO Section 1 is NexaCrest's own company block labelled BUYER", () => {
    const html = renderDocumentType('SUPPO');
    expect(html).toContain('1. BUYER (NexaCrest International Private Limited)');
    expect(html).toContain('NexaCrest International Private Limited');
    expect(html).toContain('29AAKCN8733G1ZZ'); // GSTIN
  });

  it("Buyer PO Section 1 is NexaCrest's own company block labelled SUPPLIER", () => {
    const html = renderDocumentType('BUYERPO');
    expect(html).toContain('1. SUPPLIER');
    expect(html).toContain('NexaCrest International Private Limited');
  });

  it('Supplier PO Quality & Inspection no longer duplicates the Delivery Terms clause', async () => {
    const clauses = await termsClauseRepository.forDocumentTypeCode('SUPPO');
    const titles = clauses.map((c) => c.clause_title);

    expect(titles).not.toContain('Time Is of the Essence');
    expect(clauses).toHaveLength(5);
  });

  it('Supplier PO rendered document shows "of the essence" exactly once', async () => {
    const clauses = await termsClauseRepository.forDocumentTypeCode('SUPPO');
    const html = renderDocumentType('SUPPO', clauses.map((c) => c.clause_text));

    const count = (html.match(/of the essence/g) || []).length;
    expect(count).toBe(1);
  });
});
