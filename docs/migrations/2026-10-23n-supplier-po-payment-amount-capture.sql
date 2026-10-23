-- External QA report R-11 (SPO-PAY-01, Medium, Both): marking a
-- Supplier PO's advance/balance payment as "paid" only ever recorded a
-- date — the amount actually paid (which can differ from the PO's own
-- planned advance_amount_inr/balance_amount_inr: a partial payment, a
-- bank-charge deduction, a rate adjustment) was never captured at all.
-- Adds one nullable column per leg; NULL for every pre-existing paid
-- row means "not captured — fall back to the planned amount", which is
-- exactly what OrderSupplierPoRepository::paidOnDate() now does via
-- COALESCE, so this never breaks a report on already-paid POs.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — same schema).

SET NAMES utf8mb4;

ALTER TABLE order_supplier_po
  ADD COLUMN IF NOT EXISTS advance_paid_amount_inr DECIMAL(14,2) NULL,
  ADD COLUMN IF NOT EXISTS balance_paid_amount_inr DECIMAL(14,2) NULL;
