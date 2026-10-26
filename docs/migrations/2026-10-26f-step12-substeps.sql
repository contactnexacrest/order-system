-- Owner request (2026-10-26): Step 12 split into guided sub-steps 12a–12d.
-- Records when staff marked the order's costs complete (12a) and Step 12
-- itself complete (12d). Safe to run more than once.
SET NAMES utf8mb4;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS financials_costs_confirmed_at DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS financials_costs_confirmed_by BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS financials_completed_at       DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS financials_completed_by       BIGINT UNSIGNED NULL;
