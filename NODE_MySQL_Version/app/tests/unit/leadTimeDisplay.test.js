'use strict';

const { formatRange } = require('../../src/helpers/leadTimeDisplay');

/**
 * Port of LeadTimeDisplayTest.php. 90-item backlog item 16: lead-time
 * days-vs-weeks display setting. formatRange() is the pure function the
 * Order Detail page's Production/Sailing Lead Time rows call — the only
 * display conversion; the stored figures and every input field always
 * stay in days.
 */
describe('leadTimeDisplay.formatRange (90-item backlog item 16)', () => {
  it('days unit renders plain integers', () => {
    expect(formatRange(45, 60, 'days')).toBe('45–60');
  });

  it('weeks unit converts and rounds to one decimal', () => {
    expect(formatRange(45, 60, 'weeks')).toBe('6.4–8.6');
  });

  it('weeks unit drops a trailing .0', () => {
    expect(formatRange(14, 21, 'weeks')).toBe('2–3');
  });

  it('both null renders an em dash', () => {
    expect(formatRange(null, null, 'days')).toBe('—');
    expect(formatRange(null, null, 'weeks')).toBe('—');
  });

  it('one side null falls back to a question mark on that side only', () => {
    expect(formatRange(45, null, 'days')).toBe('45–?');
    expect(formatRange(null, 60, 'weeks')).toBe('?–8.6');
  });

  it('an unrecognized unit falls back to days behavior', () => {
    expect(formatRange(45, 60, 'anything-else')).toBe('45–60');
  });
});
