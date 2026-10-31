-- Owner decision D-214 (2026-10-31), phase 2: money separation.
-- Sales sees the selling side (selling prices, buyer payments), Procurement
-- the buying side (purchase prices, supplier bills), Logistics only
-- logistics costs (CHA, transport, freight); Accounts & Finance, Company
-- Management, the auditors and the CA see everything. Four "see this money"
-- permissions, and the catch-all permissions split:
--   - the Supplier PO and production follow-up get their own permission
--     (Procurement, not Sales);
--   - "Mark fully settled" gets its own permission (Finance, not Logistics);
--   - freight clearing uses Clear payments (already Finance's), no new key.
-- New rows only; safe to run twice (INSERT IGNORE on unique keys).

SET NAMES utf8mb4;

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('view_selling_prices', 'See selling prices', 'Unit prices, FOB and order values, and the sales documents that carry them (QT, PI, OC, CI, AMD, Debit Note). D-214: Sales, Finance, management, auditors, CA.', 'money'),
  ('view_buyer_payments', 'See buyer payments', 'What the buyer has paid and still owes: the payment snapshot, the remittances and their screenshots. D-214: Sales, Finance, management, auditors, CA.', 'money'),
  ('view_purchase_prices', 'See purchase prices', 'Supplier PO values, factory and supplier costs in the catalogue, supplier bills and their scans, and the Supplier PO document. D-214: Procurement, Finance, management, auditors, CA.', 'money'),
  ('view_logistics_costs', 'See logistics costs', 'CHA, transport and packing costs in the catalogue, freight rates, and logistics bills and their scans. D-214: Logistics, Finance, management, auditors, CA.', 'money'),
  ('manage_supplier_po', 'Supplier PO and production', 'Create and confirm the Supplier PO and record production status (steps 7a-7d). D-214: Procurement, not Sales.', 'orders'),
  ('settle_orders', 'Mark an order fully settled', 'Mark an order fully settled once every rupee is in and out. D-214: Finance.', 'orders');

-- Who sees which money, by role (all editable in Admin afterwards).
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_selling_prices', 'view_buyer_payments', 'view_purchase_prices', 'view_logistics_costs')
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Accounts Executive', 'Finance Manager', 'Finance Director',
                 'Viewer / Auditor', 'CA / Chartered Accountant');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_selling_prices', 'view_buyer_payments')
  AND r.name IN ('Export Executive', 'Sales/Export Manager', 'Sales Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_purchase_prices', 'manage_supplier_po')
  AND r.name IN ('Procurement Executive', 'Procurement Manager', 'Procurement Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_logistics_costs'
  AND r.name IN ('Logistics Executive', 'Logistics Manager', 'Logistics Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('manage_supplier_po', 'settle_orders')
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'settle_orders'
  AND r.name IN ('Accounts Executive', 'Finance Manager', 'Finance Director');

-- Procurement works on the order (supplier PO, production), generates and
-- downloads its own documents (the Supplier PO; a document carrying money it
-- may not see is refused), and opens only the orders assigned to it.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_orders', 'download_pdf')
  AND r.name IN ('Procurement Executive');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'generate_documents'
  AND r.name IN ('Procurement Executive', 'Procurement Manager', 'Procurement Director');
