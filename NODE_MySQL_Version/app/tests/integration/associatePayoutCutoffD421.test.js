'use strict';

// Owner decision D-421 (25 Nov 2026): associate commission payout cut-off day. The commission is ready on the day the
// client's last payment is cleared + the waiting days; ready on or before the cut-off day → paid in that month's run,
// after it → next month's run. Start values: 0 waiting days, cut-off on the 20th, payment on the last day of the
// month, Sunday/holiday → the working day before. PHP twin: AssociatePayoutCutoffD421Test.php.

const db = require('../../src/config/db');
const ac = require('../../src/services/associateCommission');

describe('D-421 associate payout cut-off day', () => {
  const set = (over = {}) => ({ due_days: 0, cutoff_day: 20, run: 'monthly', last_day: true, day: 0, holiday_rule: 'before', ...over });

  beforeAll(async () => {
    await db.execute("DELETE FROM company_holidays WHERE holiday_date BETWEEN '2026-12-01' AND '2027-02-28'");
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('pays a commission cleared by the cut-off this month', async () => {
    expect(await ac.associateRunOn('2026-12-15', set())).toBe('2026-12-31');
    expect(await ac.associateRunOn('2026-12-20', set())).toBe('2026-12-31');
  });

  it('pays one cleared after the cut-off next month, on the working day before a Sunday', async () => {
    expect(await ac.associateRunOn('2026-12-24', set())).toBe('2027-01-30');
    expect(await ac.associateRunOn('2026-12-21', set())).toBe('2027-01-30');
  });

  it('takes the cut-off as a setting and adds the waiting days first', async () => {
    expect(await ac.associateRunOn('2026-12-24', set({ cutoff_day: 25 }))).toBe('2026-12-31');
    const s = set({ due_days: 10 });
    const ready = await ac.dueOn('2026-12-15', s);
    expect(ready).toBe('2026-12-25');
    expect(await ac.associateRunOn(ready, s)).toBe('2027-01-30');
  });

  it('ignores the cut-off for a weekly run', async () => {
    expect(await ac.associateRunOn('2026-12-24', set({ run: 'weekly', last_day: false, day: 5 }))).toBe('2026-12-25');
  });

  it('works with a fixed payment day after the cut-off', async () => {
    const s = set({ last_day: false, day: 28 });
    expect(await ac.associateRunOn('2026-12-15', s)).toBe('2026-12-28');
    expect(await ac.associateRunOn('2026-12-22', s)).toBe('2027-01-28');
  });

  it('builds the settings page examples with the same rule', async () => {
    const rows = await ac.payExamples(set(), '2026-12-05');
    expect(rows.map((r) => r.cleared)).toEqual(['2026-12-10', '2026-12-20', '2026-12-21', '2026-12-28']);
    expect(rows.map((r) => r.paid)).toEqual(['2026-12-31', '2026-12-31', '2027-01-30', '2027-01-30']);
    expect(rows.map((r) => r.by_cutoff)).toEqual([true, true, false, false]);
  });

  it('reads the setting from incentive_settings', async () => {
    await db.execute("DELETE FROM incentive_settings WHERE setting_key = 'associate_payout_cutoff_day'");
    await db.execute("INSERT INTO incentive_settings (setting_key, setting_value) VALUES ('associate_payout_cutoff_day', '25')");
    expect((await ac.settings()).cutoff_day).toBe(25);
    await db.execute("UPDATE incentive_settings SET setting_value = '20' WHERE setting_key = 'associate_payout_cutoff_day'");
  });

  it('tells the associate when it is paid in plain words (owner, 25 Nov 2026)', () => {
    expect(ac.whenPaidText(set())).toBe("We pay it in our monthly payment on the last day of the month: if the buyer's last payment reached our bank by the 20th, in that month's payment; if later, in the next month's.");
    expect(ac.whenPaidText(set({ due_days: 10 }))).toBe("We pay it 10 days after the buyer's last payment reached our bank, in our monthly payment on the last day of the month: if that day is on or before the 20th, in that month's payment; if later, in the next month's.");
    expect(ac.whenPaidText(set({ run: 'weekly', last_day: false, day: 5 }))).toBe("We pay it after the buyer's last payment has reached our bank, in our weekly payment every Friday.");
  });
});

