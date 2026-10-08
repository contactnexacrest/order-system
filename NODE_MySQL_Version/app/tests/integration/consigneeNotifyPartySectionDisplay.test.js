'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const documentGenerationService = require('../../src/services/documentGenerationService');

/**
 * Mirrors PHP's ConsigneeNotifyPartySectionDisplayTest — covers the
 * "always show CONSIGNEE DETAILS / NOTIFY PARTY" company settings and the
 * dynamic section renumbering that replaced the old hardcoded
 * termsSectionNumberFor()/legalTermsSectionNumberFor() lookup maps once
 * those two sections became conditionally hidden. The hard rule under
 * test: a section identical to the Buyer is only hidden when BOTH (a) its
 * own "always show" setting is off AND (b) it's genuinely the same as the
 * Buyer — a section that is actually different from the Buyer always
 * prints, regardless of the setting.
 */
describe('Consignee/Notify Party section display (dynamic section numbering)', () => {
  const SETTING_KEYS = ['always_show_consignee_section', 'always_show_notify_party_section'];
  const originalValues = {};

  beforeAll(async () => {
    for (const key of SETTING_KEYS) {
      originalValues[key] = String((await companySettingsRepository.get(key)) ?? '');
    }
  });

  afterEach(async () => {
    for (const key of SETTING_KEYS) {
      await companySettingsRepository.set(key, originalValues[key], null);
    }
  });

  afterAll(async () => {
    await db.pool.end();
  });

  // --- sectionDisplayFlags() ---------------------------------------

  it('hides Consignee when same as Buyer and the setting is disabled', async () => {
    await companySettingsRepository.set('always_show_consignee_section', '0', null);
    const [showConsignee] = await documentDataAssembler.sectionDisplayFlags(
      { same_as_buyer: true },
      { same_as_consignee: true }
    );
    expect(showConsignee).toBe(false);
  });

  it('shows Consignee when same as Buyer but the setting is enabled', async () => {
    await companySettingsRepository.set('always_show_consignee_section', '1', null);
    const [showConsignee] = await documentDataAssembler.sectionDisplayFlags(
      { same_as_buyer: true },
      { same_as_consignee: true }
    );
    expect(showConsignee).toBe(true);
  });

  it('always shows Consignee when genuinely different from Buyer even if the setting is disabled', async () => {
    await companySettingsRepository.set('always_show_consignee_section', '0', null);
    const [showConsignee] = await documentDataAssembler.sectionDisplayFlags(
      { same_as_buyer: false },
      { same_as_consignee: true }
    );
    expect(showConsignee).toBe(true);
  });

  it('hides Notify when effectively same as Buyer and the setting is disabled', async () => {
    await companySettingsRepository.set('always_show_notify_party_section', '0', null);
    const [, showNotify] = await documentDataAssembler.sectionDisplayFlags(
      { same_as_buyer: true },
      { same_as_consignee: true }
    );
    expect(showNotify).toBe(false);
  });

  it('shows Notify when effectively same as Buyer but the setting is enabled', async () => {
    await companySettingsRepository.set('always_show_notify_party_section', '1', null);
    const [, showNotify] = await documentDataAssembler.sectionDisplayFlags(
      { same_as_buyer: true },
      { same_as_consignee: true }
    );
    expect(showNotify).toBe(true);
  });

  // Chain-break case: Notify resolves same_as_consignee=true, but the
  // Consignee itself is NOT the same as the Buyer — Notify's printed
  // content is actually the (independent) Consignee's content, not the
  // Buyer's. Must always show regardless of the setting.
  it('always shows Notify when the Consignee link breaks the chain to Buyer even if the setting is disabled', async () => {
    await companySettingsRepository.set('always_show_notify_party_section', '0', null);
    const [, showNotify] = await documentDataAssembler.sectionDisplayFlags(
      { same_as_buyer: false },
      { same_as_consignee: true }
    );
    expect(showNotify).toBe(true);
  });

  it('always shows Notify when Notify itself is independent of Consignee even if the setting is disabled', async () => {
    await companySettingsRepository.set('always_show_notify_party_section', '0', null);
    const [, showNotify] = await documentDataAssembler.sectionDisplayFlags(
      { same_as_buyer: true },
      { same_as_consignee: false }
    );
    expect(showNotify).toBe(true);
  });

  // --- sectionNumbersFor() -------------------------------------------

  describe('sectionNumbersFor', () => {
    it('QT: unchanged from historical fixed values when both sections shown', () => {
      const numbers = documentGenerationService.sectionNumbersFor('QT', true, true);
      expect(numbers.buyer).toBe(2);
      expect(numbers.consignee).toBe(3);
      expect(numbers.notify_party).toBeUndefined(); // QT never has one
      expect(numbers.product).toBe(4);
      expect(numbers.shipping).toBe(5);
      expect(numbers.payment).toBe(6);
      expect(numbers.documents_provided).toBe(7);
      expect(numbers.terms).toBe(8);
      expect(numbers.legal_terms).toBe(9);
    });

    it('QT: shifts down by one when Consignee hidden', () => {
      const numbers = documentGenerationService.sectionNumbersFor('QT', false, true);
      expect(numbers.consignee).toBeUndefined();
      expect(numbers.product).toBe(3);
      expect(numbers.shipping).toBe(4);
      expect(numbers.payment).toBe(5);
      expect(numbers.documents_provided).toBe(6);
      expect(numbers.terms).toBe(7);
      expect(numbers.legal_terms).toBe(8);
    });

    it('PI: unchanged from historical fixed values when both sections shown', () => {
      const numbers = documentGenerationService.sectionNumbersFor('PI', true, true);
      expect(numbers.consignee).toBe(3);
      expect(numbers.notify_party).toBe(4);
      expect(numbers.product).toBe(5);
      expect(numbers.shipping).toBe(6);
      expect(numbers.payment).toBe(7);
      expect(numbers.bank).toBe(8);
      expect(numbers.export_doc).toBe(9);
      expect(numbers.terms).toBe(10);
      expect(numbers.legal_terms).toBe(11);
    });

    it('PI: shifts down by two when both sections hidden', () => {
      const numbers = documentGenerationService.sectionNumbersFor('PI', false, false);
      expect(numbers.consignee).toBeUndefined();
      expect(numbers.notify_party).toBeUndefined();
      expect(numbers.product).toBe(3);
      expect(numbers.bank).toBe(6);
      expect(numbers.export_doc).toBe(7);
      expect(numbers.terms).toBe(8);
      expect(numbers.legal_terms).toBe(9);
    });

    it('PI: shifts down by one when only Notify hidden', () => {
      const numbers = documentGenerationService.sectionNumbersFor('PI', true, false);
      expect(numbers.consignee).toBe(3);
      expect(numbers.notify_party).toBeUndefined();
      expect(numbers.product).toBe(4);
      expect(numbers.legal_terms).toBe(10);
    });

    // PL/CI never have Terms & Conditions clauses configured — Legal
    // Terms & Definitions follows the last visible named section
    // directly, with no number reserved for the invisible Terms &
    // Conditions heading (never terms+1, unlike QT/PI/OC).
    it('PL: unchanged from historical fixed values when both sections shown', () => {
      const numbers = documentGenerationService.sectionNumbersFor('PL', true, true);
      expect(numbers.consignee).toBe(3);
      expect(numbers.notify_party).toBe(4);
      expect(numbers.product_summary).toBe(5);
      expect(numbers.crate_breakdown).toBe(6);
      expect(numbers.declaration).toBe(7);
      expect(numbers.terms).toBe(8);
      expect(numbers.legal_terms).toBe(8);
    });

    it('CI: unchanged from historical fixed values when both sections shown', () => {
      const numbers = documentGenerationService.sectionNumbersFor('CI', true, true);
      expect(numbers.consignee).toBe(3);
      expect(numbers.notify_party).toBe(4);
      expect(numbers.shipping_details).toBe(5);
      expect(numbers.product).toBe(6);
      expect(numbers.invoice_value).toBe(7);
      expect(numbers.bank).toBe(8);
      expect(numbers.documents_provided).toBe(9);
      expect(numbers.declaration).toBe(10);
      expect(numbers.terms).toBe(11);
      expect(numbers.legal_terms).toBe(11);
    });

    it('OC: unchanged from historical fixed values when Consignee shown (OC never has a Notify slot)', () => {
      const numbers = documentGenerationService.sectionNumbersFor('OC', true, true);
      expect(numbers.consignee).toBe(3);
      expect(numbers.notify_party).toBeUndefined();
      expect(numbers.order_summary).toBe(4);
      expect(numbers.payment_status).toBe(5);
      expect(numbers.production).toBe(6);
      expect(numbers.documents_provided).toBe(7);
      expect(numbers.terms).toBe(8);
      expect(numbers.legal_terms).toBe(9);
    });

    it('keeps fixed numbers for untoggleable types regardless of flags', () => {
      const withFlags = documentGenerationService.sectionNumbersFor('BUYERPO', true, true);
      const withoutFlags = documentGenerationService.sectionNumbersFor('BUYERPO', false, false);
      expect(withFlags).toEqual(withoutFlags);
      expect(withFlags.terms).toBe(5);
      expect(withFlags.legal_terms).toBe(8);
    });
  });
});
