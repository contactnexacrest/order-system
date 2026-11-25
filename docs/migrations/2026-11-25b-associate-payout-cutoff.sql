-- 2026-11-25b — Owner decision D-421 (25 Nov 2026): associate commission payout cut-off day.
--  - New setting incentive_settings.associate_payout_cutoff_day = 20: a commission ready on or before the 20th
--    (the day the client's last payment is cleared + the waiting days) is paid in that month's run (last day of the
--    month); after the 20th it is paid in the next month's run.
--  - The waiting days (associate_commission_due_days) become 0 (was 30 since D-245, 4 Nov 2026).
--  - Both are settings: Associates › Commission settings (the MD, the ED and people with "Manage associate rules").
--  - Commission payouts already created keep the dates they were given; the new rule applies to commissions that
--    become payable after this runs.
-- Plain INSERT / UPDATE (owner D-371): safe to run twice.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
INSERT INTO incentive_settings (setting_key, setting_value)
  SELECT 'associate_payout_cutoff_day', '20' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM incentive_settings WHERE setting_key = 'associate_payout_cutoff_day');
UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'associate_commission_due_days';
