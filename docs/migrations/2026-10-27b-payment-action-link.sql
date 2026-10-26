-- Owner request (2026-10-27): a no-login Payment Action Link the client uses
-- to send their payment details (transaction ID, amount, date, screenshot)
-- for the advance (against the PI), freight (against the Debit Note) and
-- balance (against the Commercial Invoice) — payment only, nothing else.
-- Same hash / encrypted copy / expiry convention as the Buyer Action Link.
-- Safe to run more than once.
SET NAMES utf8mb4;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS payment_action_token_hash       CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS payment_action_token_plain      TEXT     NULL,
  ADD COLUMN IF NOT EXISTS payment_action_token_expires_at DATETIME NULL,
  ADD INDEX IF NOT EXISTS idx_orders_payment_action_token (payment_action_token_hash);
