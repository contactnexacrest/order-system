'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Port of BliCoopPrepSectionTest.php. 90-item backlog #38/#55: "BL
 * Instruction Sheet creation screen missing" and "Missing BLI/COO/
 * BL-endorsement steps in order-detail sidebar". Both BLI and COOPREP
 * already generate real, data-complete documents — what was missing was
 * a discoverable, explained place to trigger them, instead of a single
 * generic "Generate X" button lost among a dozen identical ones in the
 * Documents & Approvals section (anchored at Stage 1), disconnected from
 * the stage where their source data is actually entered.
 *
 * Unlike the controller-data-capture convention used elsewhere in this
 * suite (see ocAckButtonVisibility.test.js), this fix is pure template
 * reorganization with no controller-side data change at all — nothing
 * new would be exercised by stubbing res.renderView and inspecting the
 * data object, since the documents/shipping data it reads is unchanged.
 * Rendering the full show.njk through nunjucks directly would need a
 * huge, brittle context to avoid throwing on unrelated sections, so this
 * instead asserts directly against the shipped template source — the
 * same artifact that reaches production — for the two structural facts
 * that matter: the generic triggers are gone from their old spot, and
 * each type's new section exists in the Stage it actually belongs to.
 */
describe('BLI/COOPREP get their own explained section (90-item backlog #38/#55)', () => {
  const templatePath = path.join(__dirname, '..', '..', 'views', 'orders', 'show.njk');
  const source = fs.readFileSync(templatePath, 'utf8');

  function countOccurrences(haystack, needle) {
    return haystack.split(needle).length - 1;
  }

  it('BL Instruction Sheet has exactly one trigger, labeled and explained', () => {
    expect(source).toContain('BL Instruction Sheet (for your CHA / shipping line)');
    expect(countOccurrences(source, 'document_type" value="BLI"')).toBe(1);
  });

  it('BL Instruction Sheet section sits after Shipping/Vessel Details, inside the Packing & BL step-card', () => {
    const stage7Start = source.indexOf('id="step-packing-bl"');
    const stage8Start = source.indexOf('id="step-ci-balance"');
    const shippingPos = source.indexOf('Save Shipping Details');
    const bliPos = source.indexOf('BL Instruction Sheet (for your CHA');

    expect(stage7Start).toBeGreaterThan(-1);
    expect(shippingPos).toBeGreaterThan(stage7Start);
    expect(bliPos).toBeGreaterThan(shippingPos);
    expect(bliPos).toBeLessThan(stage8Start);
  });

  it('COO Prep Sheet has exactly one trigger, labeled and explained', () => {
    expect(source).toContain('COO Prep Sheet (internal — for the Chamber of Commerce application)');
    expect(countOccurrences(source, 'document_type" value="COOPREP"')).toBe(1);
  });

  it('COO Prep Sheet section sits inside the Document Despatch & Closure step-card', () => {
    const stage9Start = source.indexOf('id="step-despatch"');
    const coopPos = source.indexOf('COO Prep Sheet (internal — for the Chamber of Commerce application)');

    expect(stage9Start).toBeGreaterThan(-1);
    expect(coopPos).toBeGreaterThan(stage9Start);
  });
});
