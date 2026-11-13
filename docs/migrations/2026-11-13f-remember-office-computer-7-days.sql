-- 2026-11-13f — "Remember an office computer for … days" becomes 7 days (owner decision D-369, 13 Nov 2026)
-- Settings › Security › login_remember_browser_days: staff who tick "This is my own office computer"
-- skip the e-mailed login code on that computer for this many days. D-283 (11 Nov) set 30; the owner
-- chose 7 for safety (13 Nov). The password is still asked at every login.
--
--   1. The value changes from 30 to 7 only where it is still 30 (the old default). A value someone
--      already changed in Settings (for example 0 or 14) is kept.
--   2. The setting's help text says "default 7".
-- Computers remembered before this run keep their old expiry (at most 30 days from when they were
-- ticked); every new tick lasts 7 days. To end them all at once, use "Forget all remembered
-- computers" (My Account, or Admin in Users › Edit) or set the days to 0 and back to 7.
--
-- Safe to run twice: plain UPDATEs (no schema change, MySQL-safe). A fresh install (schema.sql +
-- seed.sql) already has 7. Run after 2026-11-13e, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-13f-remember-office-computer-7-days.sql
SET NAMES utf8mb4;

UPDATE company_settings SET setting_value = '7'
  WHERE setting_key = 'login_remember_browser_days' AND setting_value = '30';

UPDATE company_settings SET description = 'Staff who tick "This is my own office computer" skip the e-mailed login code on that computer for this many days (0 to 90; default 7; 0 = always ask). A password change or "Forget all remembered computers" ends it.'
  WHERE setting_key = 'login_remember_browser_days';

SELECT CONCAT('2026-11-13f done: login_remember_browser_days = ', setting_value,
              IF(setting_value = '7', ' (the new default).', ' (kept: it had been changed in Settings).')) AS result
  FROM company_settings WHERE setting_key = 'login_remember_browser_days';
