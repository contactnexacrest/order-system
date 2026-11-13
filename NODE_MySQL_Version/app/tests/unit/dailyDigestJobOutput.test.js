'use strict';

const { consoleLines } = require('../../src/jobs/dailyDigest');

/**
 * QA MTX-007: the Node daily digest job prints the same lines as PHP
 * cron/daily_digest.php — including the associate commission and the
 * incentive lock reminder results, which it used to run but not print.
 */
describe('daily digest job output (QA MTX-007)', () => {
  test('prints the associate commission and incentive lock reminder lines as PHP does', () => {
    const lines = consoleLines({
      mailOff: true,
      associateReminders: 2,
      bankExpired: 1,
      vendorBank: { expired: 0, applied: 3 },
      associatePayouts: { fixed: 1, held: 0, released: 2 },
      incentiveLockReminders: 4,
    });
    expect(lines).toHaveLength(5);
    expect(lines[1]).toBe('Associate reminders: 2 sent.');
    expect(lines[2]).toBe('Bank changes: 1 associate and 0 supplier change(s) cancelled (not confirmed); 3 supplier change(s) put in use.');
    expect(lines[3]).toBe('Associate commission: {"fixed":1,"held":0,"released":2}');
    expect(lines[4]).toBe('Incentive lock reminders: 4 sent.');
  });
});
