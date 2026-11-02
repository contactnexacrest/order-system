-- Owner decision 2 Nov 2026 (D-239, "Zoho limits yes"): this app's share of the
-- Zoho API calls of the organisation (shared with every other Zoho app and
-- integration of the company).
--   zoho_api_usage   — calls counted per environment, per minute and per day
--                      (India time); every Zoho Books call, reads and the
--                      OAuth token included.
--   zoho_token_cache — the one access token (encrypted) every process shares
--                      for 50 minutes, refreshed under a database lock.
--   settings         — zoho_api_daily_limit (800), zoho_api_minute_limit (30).
-- MySQL-safe: CREATE TABLE IF NOT EXISTS and INSERT … WHERE NOT EXISTS only. Run after 2026-11-02za.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS zoho_api_usage (
  environment  VARCHAR(12) NOT NULL,              -- 'test' | 'production'
  bucket_type  VARCHAR(8)  NOT NULL,              -- 'minute' | 'day'
  bucket       VARCHAR(16) NOT NULL,              -- 'YYYY-MM-DD HH:MM' | 'YYYY-MM-DD' (Asia/Kolkata)
  calls        INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (environment, bucket_type, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS zoho_token_cache (
  cache_key   CHAR(64)  NOT NULL PRIMARY KEY,     -- sha256(environment | refresh token)
  token_enc   TEXT      NOT NULL,                 -- access token, encrypted (APP_ENCRYPTION_KEY)
  expires_at  DATETIME  NOT NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'zoho_api_daily_limit', '800', 'number', 'zoho_books',
       'Max Zoho calls per day for this app (India time). Every Zoho Books call counts — reads, the login token and sends. When used up, syncing pauses until midnight. Zoho''s own daily limit is per organisation and shared with the company''s other Zoho apps, so keep this below it.', 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'zoho_api_daily_limit');

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'zoho_api_minute_limit', '30', 'number', 'zoho_books',
       'Max Zoho calls per minute for this app. The scheduled sync waits for the next minute; Sync now and the order buttons stop and ask to try again in a minute. Zoho allows about 100 per minute per organisation, shared with the company''s other Zoho apps.', 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'zoho_api_minute_limit');
