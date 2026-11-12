'use strict';

const clientAddress = require('../../src/helpers/clientAddress');

/**
 * Owner decision D-346 (12 Nov 2026): clients.billing_address is composed
 * from the structured parts as "Line 1, Line 2, City Postcode, Country".
 * Port of PHP's ClientAddressTest.php.
 */
describe('clientAddress.compose (D-346)', () => {
  it('composes all parts as Line 1, Line 2, City Postcode, Country', () => {
    expect(clientAddress.compose('1 Test Street', 'Unit 4', 'Birmingham', 'B1 1AA', 'United Kingdom'))
      .toBe('1 Test Street, Unit 4, Birmingham B1 1AA, United Kingdom');
  });

  it('skips empty parts', () => {
    expect(clientAddress.compose('1 Test Street', '', 'Birmingham', '', 'United Kingdom')).toBe('1 Test Street, Birmingham, United Kingdom');
    expect(clientAddress.compose('1 Test Street', null, '', 'B1 1AA', 'United Kingdom')).toBe('1 Test Street, B1 1AA, United Kingdom');
    expect(clientAddress.compose('', null, '  ', '', null)).toBe('');
  });

  it('trims the parts', () => {
    expect(clientAddress.compose(' 1 Test Street ', ' ', ' Birmingham ', ' B1 1AA ', ' United Kingdom '))
      .toBe('1 Test Street, Birmingham B1 1AA, United Kingdom');
  });
});
