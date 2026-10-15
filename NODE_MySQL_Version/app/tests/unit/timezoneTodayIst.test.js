'use strict';

const { resolveAgreementFooter } = require('../../src/services/documentDataAssembler');
const financialYear = require('../../src/helpers/financialYear');

/**
 * 90-item backlog "timezone": downstream callers of the old
 * new Date().toISOString().slice(0, 10) "today" pattern (always UTC,
 * regardless of process.env.TZ) now use todayIst() (tests/unit/
 * dbDateTime.test.js) instead. These pin the two clearest, most
 * reproducible symptoms the agent investigation found.
 */
describe('Downstream callers of the IST-corrected "today" (90-item backlog "timezone")', () => {
  afterEach(() => {
    jest.useRealTimers();
  });

  it('resolveAgreementFooter does not expire the footer a day early during the IST early-morning window', () => {
    // Root cause this guards: documentDataAssembler.resolveAgreementFooter()
    // used to compare against new Date().toISOString().slice(0,10) (UTC
    // "today"), so an agreement expiring 2026-10-16 was already treated as
    // expired at 2026-10-16 01:00 IST — a full day before its real expiry.
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-15T19:30:00Z')); // 2026-10-16 01:00 IST

    const stillValid = resolveAgreementFooter({
      agreement_footer_text: 'Standard Terms Apply',
      agreement_force_expired: false,
      agreement_expiry_date: '2026-10-16',
    });
    expect(stillValid).toBe('Standard Terms Apply');

    const nowExpired = resolveAgreementFooter({
      agreement_footer_text: 'Standard Terms Apply',
      agreement_force_expired: false,
      agreement_expiry_date: '2026-10-15',
    });
    expect(nowExpired).toBeNull();
  });

  it('financialYear.current() does not lag a day behind at the 1 April IST rollover', () => {
    // 2027-04-01 00:30 IST == 2027-03-31 19:00 UTC — the new FY has
    // already started in IST, but the UTC date is still 31 March.
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2027-03-31T19:00:00Z'));

    expect(financialYear.current()).toBe('2027-28');
  });
});
