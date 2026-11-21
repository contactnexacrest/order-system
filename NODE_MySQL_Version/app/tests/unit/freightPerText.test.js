'use strict';

const { freightPerText } = require('../../src/services/documentDataAssembler');

/**
 * Port of FreightPerTextTest.php. Owner decision D-401 (21 Nov 2026, confirm 1 + confirm 2): the indicative
 * freight line of the Quotation / Proforma Invoice (PDF and Word) reads "per 20ft Standard container",
 * "per LCL shipment (Less than Container Load)", "per container (type to be confirmed)".
 */
describe('D-401 — what the indicative freight is quoted per', () => {
  it('reads every container type as a container or a shipment', () => {
    const cases = {
      '20ft Standard': '20ft Standard container',
      '40ft Standard': '40ft Standard container',
      '40ft High Cube': '40ft High Cube container',
      '20ft Flat Rack': '20ft Flat Rack container',
      '40ft Flat Rack': '40ft Flat Rack container',
      'LCL (Less than Container Load)': 'LCL shipment (Less than Container Load)',
      'To Be Confirmed': 'container (type to be confirmed)',
      TBC: 'container (type to be confirmed)',
      '': 'container (type to be confirmed)',
      '20ft Reefer Container': '20ft Reefer Container',
    };
    for (const [type, expected] of Object.entries(cases)) expect(freightPerText(type)).toBe(expected);
    expect(freightPerText(null)).toBe('container (type to be confirmed)');
  });
});
