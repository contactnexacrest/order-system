'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Port of AssociateStatementNoCountsD393Test.php. D-393 (owner, 19 Nov 2026), following D-292: the
 * associate never sees levels, the ladder or counts. The commission statement in the associate portal
 * had kept a "Counts toward your next commission rate: yes / no" row (D-263); it is removed there.
 * Staff still see "Counts toward the ladder" with the counting-order number and the threshold.
 */
describe('Associate statement shows no counts row in the portal (D-393)', () => {
  const src = fs.readFileSync(path.join(__dirname, '../../views/associate_portal/_statement.njk'), 'utf8');

  it('has no associate wording for counts at all', () => {
    expect(src).not.toContain('next commission rate');
  });

  it('shows the ladder row to staff only', () => {
    const m = src.match(/\{% if not c\.portal %\}\s*<tr><th>Counts toward the ladder<\/th>([\s\S]*?)<\/tr>\s*\{% endif %\}/);
    expect(m).not.toBeNull();
    expect(m[1]).toContain('yes (counting order no. {{ c.counting_number }})');
    expect(m[1]).toContain('no — under ₹{{ c.threshold_inr | money0 }} within range');
    expect(src.match(/Counts toward/g)).toHaveLength(1);
  });
});
