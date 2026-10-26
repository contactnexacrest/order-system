-- Owner requests (2026-10-26, t4). Safe to run more than once.
SET NAMES utf8mb4;

-- Step 12 (Closure — Financials, Benefits & Costs) can be skipped with a
-- reason and marked complete; no amount is written, so nothing reads as 0.
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS financials_skipped_at   DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS financials_skipped_by   BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS financials_skip_reason  VARCHAR(500)    NULL;

-- Step 7c: the supplier uploads their signed PO / acknowledgment through a
-- no-login Supplier Action Link (like the Buyer Action Link), or staff
-- record that the supplier did not acknowledge (reason required).
ALTER TABLE order_supplier_po
  ADD COLUMN IF NOT EXISTS supplier_action_token_hash       CHAR(64)        NULL,
  ADD COLUMN IF NOT EXISTS supplier_action_token_plain      TEXT            NULL,
  ADD COLUMN IF NOT EXISTS supplier_action_token_expires_at DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS ack_skipped_at                   DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS ack_skipped_by                   BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS ack_skip_reason                  VARCHAR(500)    NULL,
  ADD INDEX IF NOT EXISTS idx_supplier_po_action_token (supplier_action_token_hash);
