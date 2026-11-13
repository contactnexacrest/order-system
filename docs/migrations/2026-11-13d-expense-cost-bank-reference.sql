-- 2026-11-13d — Bank reference on order expenses and order costs
-- Owner decision 13 Nov 2026 (QA ZOH-005, Option A): rule D-212 applies to order
-- expenses and order costs too.
--
-- What changes:
--   * order_expenses.bank_reference and order_cost_entries.bank_reference (UTR /
--     transaction ID, up to 150 characters): required on the form when the money
--     went out of the company account, optional for the company card or staff.
--     The Zoho Books description of the expense says what it is, for which order,
--     what it was paid from and the UTR.
--   * order_expenses.zoho_pushed_hash: what was last sent to Zoho Books, so an
--     expense already in Books is updated when its description changes. Every
--     expense already in Books is sent once more on the next sync with the new
--     description (amount, date and accounts unchanged); order costs already in
--     Books are updated the same way through their existing zoho_pushed_hash.
--   * Existing rows keep bank_reference empty: no data is changed here.
--
-- PLAIN ALTER (owner D-371, 13 Nov 2026): three plain ALTER statements, because phpMyAdmin on
-- the shared host refuses the information_schema + PREPARE guard ("#1044 Access denied …
-- information_schema"). Check first with the read-only check script (row 2026-11-13d says
-- NEEDED or already done). A second run stops at once with "#1060 Duplicate column name" —
-- harmless: the columns are already there and nothing is changed.
-- Run after a backup, in filename order (phpMyAdmin › your database › SQL, or):
--   mysql -u <user> -p <database> < docs/migrations/2026-11-13d-expense-cost-bank-reference.sql
SET NAMES utf8mb4;

ALTER TABLE order_expenses ADD COLUMN bank_reference VARCHAR(150) NULL;
ALTER TABLE order_expenses ADD COLUMN zoho_pushed_hash CHAR(64) NULL;
ALTER TABLE order_cost_entries ADD COLUMN bank_reference VARCHAR(150) NULL;
