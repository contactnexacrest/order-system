'use strict';

// Requiring config/env (transitively required by essentially every module
// under test, including config/db below) is what actually applies the fix
// — this file exists to pin down that the fix is in effect and stays that
// way, not to apply it itself.
require('../../src/config/env');

/**
 * QA-5 (DEF-04 — external QA report cross-verification, Owner Decision #5:
 * "Use Indian Standard Time"): PHP's bootstrap.php sets
 * date_default_timezone_set('Asia/Kolkata'); Node had no equivalent, so
 * every Date computation ran in the container's own timezone instead of
 * IST — affecting 2FA/password-reset expiry windows, the working-days/
 * holiday calendar, PI validity dates, report date filters, and audit-log
 * timestamps (AUTH-03/07, PI-01, CP-07, TZ-01/02 on the Node stack).
 */
describe('Process timezone (QA-5 DEF-04)', () => {
  it('runs in Indian Standard Time (UTC+05:30), not the container default', () => {
    expect(process.env.TZ).toBe('Asia/Kolkata');

    const d = new Date('2026-06-15T00:00:00Z'); // midnight UTC, well clear of any DST edge case
    expect(d.getHours()).toBe(5);
    expect(d.getMinutes()).toBe(30);
    expect(d.toString()).toContain('GMT+0530');
  });
});
