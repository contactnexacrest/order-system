'use strict';

const orderLineValidator = require('../../src/helpers/orderLineValidator');

/**
 * QA-5 ORD-05/ORD-06: pins orderLineValidator's contract for quantity and
 * unit price.
 */
describe('orderLineValidator (QA-5 ORD-05/ORD-06)', () => {
  describe('checkQuantity', () => {
    it('rejects non-numeric values', () => {
      expect(orderLineValidator.checkQuantity('abc', false)).not.toBeNull();
    });
    it('rejects negative and zero values', () => {
      expect(orderLineValidator.checkQuantity('-5', false)).not.toBeNull();
      expect(orderLineValidator.checkQuantity('0', false)).not.toBeNull();
    });
    it('accepts a positive number', () => {
      expect(orderLineValidator.checkQuantity('120', false)).toBeNull();
      expect(orderLineValidator.checkQuantity('12.5', false)).toBeNull();
    });
    it('allows blank or TBC regardless of content', () => {
      expect(orderLineValidator.checkQuantity('', false)).toBeNull();
      expect(orderLineValidator.checkQuantity('garbage', true)).toBeNull();
      expect(orderLineValidator.checkQuantity('-5', true)).toBeNull();
    });
  });

  describe('checkUnitPrice', () => {
    it('rejects non-numeric values', () => {
      expect(orderLineValidator.checkUnitPrice('abc')).not.toBeNull();
    });
    it('rejects negative values', () => {
      expect(orderLineValidator.checkUnitPrice('-1')).not.toBeNull();
    });
    it('accepts zero or positive values', () => {
      expect(orderLineValidator.checkUnitPrice('0')).toBeNull();
      expect(orderLineValidator.checkUnitPrice('45.75')).toBeNull();
    });
    it('allows blank', () => {
      expect(orderLineValidator.checkUnitPrice('')).toBeNull();
    });
  });
});
