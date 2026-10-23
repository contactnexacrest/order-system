-- External QA report Q-ROLE-1/Q-ROLE-3/Q-RPT-1 (owner review, 2026-10-23):
--
-- Q-ROLE-1: Export Executive held manage_payments, which covers both
-- RECORDING a buyer payment (what the client says they sent) and
-- CLEARING it (confirming the bank actually received it, which also
-- unlocks the next stage gate). The same person doing both defeats the
-- maker-checker separation this app already enforces everywhere else
-- (document review, email-send approval, amendment approval, order
-- cancellation). Splits clear_payments out of manage_payments.
--
-- Q-ROLE-3/Q-RPT-1: supplier advance/balance payments could only be
-- marked paid by Admin/MD/ED (manage_order_financials) — Accounts, who
-- already owns buyer-side payment tracking, could not confirm the
-- supplier side, and the "Outgoing (Supplier) Payments" section of the
-- Today's Payments report (and its CSV export) was visible to anyone
-- holding view_reports, including the Viewer/Auditor role — exposing
-- supplier INR cost data to a broad, low-privilege audience. Splits
-- manage_supplier_payments out of manage_order_financials, deliberately
-- NOT granting the broader manage_order_financials permission (which
-- also covers cost entries, export benefits, and the margin-revealing
-- profitability report) to Accounts — only this narrower slice.
--
-- Apply to both the PHP and Node MySQL databases — same schema.

SET NAMES utf8mb4;

-- External QA report L-32: INSERT IGNORE makes this safe to re-run.
INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('clear_payments', 'Clear a buyer payment',
   'Confirm a buyer payment actually landed in the bank, unlocking the next stage gate — split out of manage_payments so the person who records a payment is never also the one who confirms it. Accounts/Admin/MD/ED only by default.',
   'orders'),
  ('manage_supplier_payments', 'Manage supplier payments',
   'Mark a supplier payment as paid, and see the Outgoing (Supplier) side of Today''s Payments — split out of manage_order_financials so Accounts can confirm supplier payments without the broader financials/profitability access.',
   'orders');

-- Admin, Managing Director, Executive Director already hold every
-- permission via seed.sql's wildcard grant at initial seed time — that
-- CROSS JOIN only runs once, so a permission added later (like these
-- two) has to be granted to those three roles explicitly here.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director')
  AND p.permission_key IN ('clear_payments', 'manage_supplier_payments');

-- Accounts Executive: already owns payment tracking, and should own
-- both the confirming half of it and the supplier side.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Accounts Executive'
  AND p.permission_key IN ('clear_payments', 'manage_supplier_payments');
