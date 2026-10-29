-- Owner decision (2026-10-29, confirmed twice): every reference number the
-- app creates uses the 2-digit year — SC/CI/26/2910001 instead of
-- SC/CI/2026/2910001. A GST tax invoice number may have at most 16
-- characters (Zoho Books refuses longer ones in a GST-registered
-- organisation), and the Commercial Invoice and Debit Note had 17; the
-- other references follow so the style is the same everywhere. Numbers
-- already issued keep their 4-digit year; only new ones change. Replaces
-- just the {YYYY} token, so a format otherwise changed by hand keeps the
-- rest of its shape. Safe to run more than once.
SET NAMES utf8mb4;

UPDATE document_types
SET ref_format = REPLACE(ref_format, '{YYYY}', '{YY}')
WHERE ref_format LIKE '%{YYYY}%';

UPDATE company_settings
SET setting_value = REPLACE(setting_value, '{YYYY}', '{YY}')
WHERE setting_key IN ('master_tracking_ref_format', 'order_ref_format')
  AND setting_value LIKE '%{YYYY}%';
