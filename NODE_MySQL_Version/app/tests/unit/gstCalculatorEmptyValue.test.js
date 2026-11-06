'use strict';

const G = require('../../src/services/gstCalculator');

// Final QA run: the Import & Distribution reports page crashed on Node when a shipment had no landed
// cost yet — String(null) reached the formatter as "null". PHP casts null to "" (0.00); Node now matches.
describe('gstCalculator treats a still-empty money value as 0, like PHP', () => {
  it('formats null, undefined, "" and String(null) as 0.00', () => {
    expect(G.inr(null)).toBe('0.00');
    expect(G.inr(undefined)).toBe('0.00');
    expect(G.inr('')).toBe('0.00');
    expect(G.inr(String(null))).toBe('0.00');
    expect(G.inr(String(undefined))).toBe('0.00');
  });

  it('still formats real amounts exactly', () => {
    expect(G.inr('1234567.5')).toBe('12,34,567.50');
    expect(G.inr('-12.345')).toBe('-12.35');
  });
});
