'use strict';

const documentDataAssembler = require('../../src/services/documentDataAssembler');

/**
 * Buyer/Consignee/Notify Party split (mirrors PHP's
 * DocumentDataAssembler::resolveConsignee/resolveNotifyParty/
 * balanceTriggerSentence) — pure functions, no DB needed. Covers the
 * "same as" resolution (always fresh off the parent party, never a
 * stale copy) and the admin-editable balance-trigger wording fallback.
 */
describe('documentDataAssembler — Buyer/Consignee/Notify Party split', () => {
  describe('resolveConsignee', () => {
    const buyerOrder = {
      company_legal_name: 'Buyer Co Ltd',
      billing_address: 'Fallback Address, City',
      billing_address_line1: '1 Buyer Street',
      billing_address_line2: 'Suite 2',
      billing_city: 'Buyer City',
      billing_postcode: 'BC123',
      country_of_destination: 'Germany',
      vat_eori_tax_no: 'DE123456789',
      contact_person: 'Buyer Contact',
      client_phone: '+49 111',
      client_email: 'buyer@example.com',
    };

    it('defaults to same-as-buyer when the flag is undefined', () => {
      const result = documentDataAssembler.resolveConsignee({ ...buyerOrder });
      expect(result.same_as_buyer).toBe(true);
      expect(result.company_legal_name).toBe('Buyer Co Ltd');
      expect(result.address_line1).toBe('1 Buyer Street');
      expect(result.email).toBe('buyer@example.com');
    });

    it('falls back to billing_address when billing_address_line1 is blank', () => {
      const result = documentDataAssembler.resolveConsignee({ ...buyerOrder, billing_address_line1: null });
      expect(result.address_line1).toBe('Fallback Address, City');
    });

    it('uses the client own consignee_* columns when consignee_same_as_buyer is 0', () => {
      const order = {
        ...buyerOrder,
        consignee_same_as_buyer: 0,
        consignee_name: 'Consignee Co',
        consignee_address_line1: '9 Consignee Road',
        consignee_city: 'Consignee City',
        consignee_country: 'France',
        consignee_email: 'consignee@example.com',
      };
      const result = documentDataAssembler.resolveConsignee(order);
      expect(result.same_as_buyer).toBe(false);
      expect(result.company_legal_name).toBe('Consignee Co');
      expect(result.address_line1).toBe('9 Consignee Road');
      expect(result.country).toBe('France');
      expect(result.email).toBe('consignee@example.com');
    });
  });

  describe('resolveNotifyParty', () => {
    const resolvedConsignee = {
      company_legal_name: 'Consignee Co',
      address_line1: '9 Consignee Road',
      address_line2: null,
      city: 'Consignee City',
      postcode: 'CC456',
      country: 'France',
      contact_person: 'Consignee Contact',
      phone: '+33 222',
      email: 'consignee@example.com',
    };

    it('defaults to same-as-consignee and mirrors the already-resolved consignee', () => {
      const result = documentDataAssembler.resolveNotifyParty({}, resolvedConsignee);
      expect(result.same_as_consignee).toBe(true);
      expect(result.name).toBe('Consignee Co');
      expect(result.email).toBe('consignee@example.com');
    });

    it('uses the client own notify_party_* columns when notify_party_same_as_consignee is 0', () => {
      const order = {
        notify_party_same_as_consignee: 0,
        notify_party: 'Freight Forwarder Ltd',
        notify_party_address_line1: '5 Forwarder Lane',
        notify_party_country: 'Netherlands',
        notify_party_email: 'forwarder@example.com',
      };
      const result = documentDataAssembler.resolveNotifyParty(order, resolvedConsignee);
      expect(result.same_as_consignee).toBe(false);
      expect(result.name).toBe('Freight Forwarder Ltd');
      expect(result.address_line1).toBe('5 Forwarder Lane');
      expect(result.country).toBe('Netherlands');
      expect(result.email).toBe('forwarder@example.com');
    });
  });

  describe('balanceTriggerSentence', () => {
    it('uses the built-in A_BEFORE_SHIPMENT sentence with Calendar Days wording when no preset wording is set', () => {
      const text = documentDataAssembler.balanceTriggerSentence('A_BEFORE_SHIPMENT', 3, null);
      expect(text).toBe('Payable before shipment — within 3 Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest.');
    });

    it('uses the built-in B_AGAINST_BL sentence naming the BL-email date, not BL date', () => {
      const text = documentDataAssembler.balanceTriggerSentence('B_AGAINST_BL', 7, null);
      expect(text).toBe('Payable against scanned copy of Bill of Lading, within 7 Calendar Days of the date NexaCrest emails the scanned BL copy.');
    });

    it('substitutes {days} into the preset own wording when one is set, regardless of option', () => {
      const text = documentDataAssembler.balanceTriggerSentence(
        'A_BEFORE_SHIPMENT', 5, 'Custom: within {days} Calendar Days of {days}-day notice.'
      );
      expect(text).toBe('Custom: within 5 Calendar Days of 5-day notice.');
    });

    it('ignores a blank/whitespace-only wordingTemplate and falls back to the built-in sentence', () => {
      const text = documentDataAssembler.balanceTriggerSentence('A_BEFORE_SHIPMENT', 3, '   ');
      expect(text).toBe('Payable before shipment — within 3 Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest.');
    });
  });
});
