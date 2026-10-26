-- Owner decision (2026-10-26): "we don't need mail on each audit
-- step/event/log". The audit log is a record only — the code no longer
-- sends an email for every audit-logged action, so the catch-all address
-- that received a copy of each one is retired. Safe to run more than once.
SET NAMES utf8mb4;

DELETE FROM company_settings WHERE setting_key = 'notification_email';
