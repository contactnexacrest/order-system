-- Zoho Books go-live date (owner decision 2026-10-29): only CIs, Debit
-- Notes, payments, bills, vendor payments and order expenses dated on or
-- after zoho_books_sync_start_date go to Zoho Books; anything earlier is
-- booked by the CA as the opening position. Required on the live server
-- (ZOHO_ENVIRONMENT=production): without it nothing is sent. Set it in
-- Admin -> Settings (Zoho Books) before switching Zoho on. Safe to run
-- more than once.
SET NAMES utf8mb4;

INSERT IGNORE INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('zoho_books_sync_start_date', '', 'date', 'zoho_books', 'Go-live date for Zoho Books (YYYY-MM-DD). Only CIs, Debit Notes, payments, bills, vendor payments and order expenses dated on or after it are sent; anything earlier is booked by the CA as the opening position. Required on the live server (ZOHO_ENVIRONMENT=production): without it nothing is sent.', 0);
