-- 2026-11-11b — E-mail settings, staff e-mail routing, portal access, staying logged in
-- Owner decisions D-280 to D-283 (11 Nov 2026, points 3, 1, 2, 5, 22 and 21).
--
-- What changes:
--   * New table email_settings: one switch per e-mail kind × audience (Staff /
--     Associate / Buyer / Supplier) shown on Settings › E-mail. SHOULD e-mails on,
--     the OPTIONAL "copy of buyer documents to the associate" off. MUST e-mails
--     (login codes, set-password and forgot-password links, bank-details-changed
--     alert, backup-code alert, quotation verification code) have no row: always on.
--   * New table compliance_alert_owners: the responsible person and backup for each
--     company-level compliance alert (LUT, RCMC, agreements, TDS, merchant-trade RBI
--     limits, ECGC policy, monthly eBRC). Empty = the department's manager, then the MD.
--   * Setting mail_sending_enabled is REPLACED by email_testing_mode ("Testing mode —
--     hold e-mails to buyers and suppliers"), with the opposite meaning: if
--     mail_sending_enabled was 0, testing mode starts ON (buyers and suppliers still
--     get nothing, as before); if it was 1, testing mode starts OFF.
--   * Setting session_timeout_minutes is REPLACED by session_timeout_staff_minutes
--     (240), session_timeout_client_minutes (120), session_timeout_associate_minutes
--     (120) and session_absolute_staff_hours (12).
--   * login_remember_browser_days: new wording ("This is my own office computer");
--     the default becomes 30 days — only if it is still 0 (the old default); a value
--     the company chose is kept.
--   * E-mail template client_login_code (the buyer's own login-code wording) added;
--     client_portal_invite gets the permanent login page ({login_link}).
--
-- Safe to run twice: CREATE TABLE IF NOT EXISTS, INSERT IGNORE / WHERE NOT EXISTS,
-- the remember-days UPDATE only while its description is not yet the new one, the
-- template UPDATE only while {login_link} is missing. No column DDL.
-- Run after 2026-11-11a, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-11b-email-settings-sessions.sql
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS email_settings (
  email_kind  VARCHAR(60) NOT NULL,
  audience    ENUM('staff','associate','buyer','supplier') NOT NULL,
  is_enabled  TINYINT(1) NOT NULL DEFAULT 1,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (email_kind, audience),
  CONSTRAINT fk_email_settings_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS compliance_alert_owners (
  item_key             VARCHAR(40) NOT NULL PRIMARY KEY,
  responsible_user_id  BIGINT UNSIGNED NULL,
  backup_user_id       BIGINT UNSIGNED NULL,
  updated_by           BIGINT UNSIGNED NULL,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cao_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id),
  CONSTRAINT fk_cao_backup FOREIGN KEY (backup_user_id) REFERENCES users(id),
  CONSTRAINT fk_cao_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO email_settings (email_kind, audience, is_enabled) VALUES
  ('buyer_documents', 'buyer', 1), ('buyer_documents', 'associate', 0), ('buyer_links', 'buyer', 1), ('buyer_messages', 'buyer', 1),
  ('buyer_followups', 'buyer', 1), ('supplier_links', 'supplier', 1), ('associate_clients', 'associate', 1), ('associate_commission', 'associate', 1),
  ('associate_kyc_price_message', 'associate', 1), ('associate_registration_declined', 'associate', 1), ('associate_code_status', 'associate', 1),
  ('staff_approvals', 'staff', 1), ('staff_counterparty', 'staff', 1), ('staff_compliance', 'staff', 1), ('staff_zoho', 'staff', 1),
  ('staff_daily_summary', 'staff', 1), ('staff_assigned', 'staff', 1), ('staff_other', 'staff', 1);

INSERT IGNORE INTO compliance_alert_owners (item_key) VALUES
  ('lut'), ('rcmc'), ('agreements'), ('tds'), ('merchant_trade_rbi'), ('ecgc_policy'), ('ebrc_monthly');

-- Testing mode replaces mail_sending_enabled (opposite meaning; keeps today's behaviour for buyers and suppliers).
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin)
SELECT 'email_testing_mode', IF(COALESCE((SELECT setting_value FROM company_settings WHERE setting_key = 'mail_sending_enabled'), '1') = '0', '1', '0'),
       'boolean', 'mail', 'D-280: Testing mode — hold e-mails to buyers and suppliers. On = e-mails to buyers and suppliers are not sent (the screen says so and shows the link to copy); staff and associate e-mails and every MUST e-mail (login codes, set-password and forgot-password links, security alerts) still go out. Set in Admin › E-mail settings, with the per-e-mail switches.', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'email_testing_mode');
DELETE FROM company_settings WHERE setting_key = 'mail_sending_enabled';

-- D-283: separate idle timeouts, the staff 12-hour limit.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT x.k, x.v, 'number', 'security', x.d, 0 FROM (
  SELECT 'session_timeout_staff_minutes' AS k, '240' AS v, 'D-283: staff are signed out after this many minutes without activity (5 to 1440; default 240 = 4 hours). A warning with "Stay logged in" shows 2 minutes before.' AS d
  UNION ALL SELECT 'session_timeout_client_minutes', '120', 'D-283: buyers (client portal) are signed out after this many minutes without activity (5 to 1440; default 120 = 2 hours).'
  UNION ALL SELECT 'session_timeout_associate_minutes', '120', 'D-283: associates (associate portal) are signed out after this many minutes without activity (5 to 1440; default 120 = 2 hours).'
  UNION ALL SELECT 'session_absolute_staff_hours', '12', 'D-283: staff log in again at least this often, however active they are (1 to 24 hours; default 12).'
) x WHERE NOT EXISTS (SELECT 1 FROM company_settings c WHERE c.setting_key = x.k);
DELETE FROM company_settings WHERE setting_key = 'session_timeout_minutes';

-- D-283: "This is my own office computer" — 30 days by default (only while still at the old default 0).
UPDATE company_settings
   SET setting_value = IF(setting_value = '0', '30', setting_value),
       description = 'D-265 / D-283: staff may tick "This is my own office computer" on the login-code screen and skip the e-mailed code on that computer for this many days (0 to 90; default 30). 0 = off: the code is asked at every login. A password change or reset, or "Forget all remembered computers" (My Account, or Admin in Users › Edit), forgets them.'
 WHERE setting_key = 'login_remember_browser_days'
   AND description NOT LIKE 'D-265 / D-283:%';

-- E-mail templates: the buyer's own login-code wording; the invite gives the permanent login page.
INSERT INTO email_templates (template_key, subject, body, footer)
SELECT 'client_login_code', 'Your NexaCrest order portal login code',
  'Hello,\n\nYour code to log in to your NexaCrest order portal is: {code}\n\nIt is valid for 10 minutes.\n\nIf you did not try to log in, you can ignore this e-mail — nobody can log in without this code. If it keeps happening, change your password with "Forgot your password?" on the login page.\n\nNexaCrest International Private Limited', NULL
WHERE NOT EXISTS (SELECT 1 FROM email_templates WHERE template_key = 'client_login_code');
UPDATE email_templates
   SET body = REPLACE(REPLACE(body,
       'To set up your login, open this link within 72 hours:\n{set_password_link}', 'To set up your login, open this link within 72 hours (it works once):\n{set_password_link}'),
       'Your login email will be: {email}\n\nNexaCrest International Private Limited', 'Your login email will be: {email}\n\nAfter that, log in any time at:\n{login_link}\n\nNexaCrest International Private Limited')
 WHERE template_key = 'client_portal_invite' AND body NOT LIKE '%{login_link}%';
