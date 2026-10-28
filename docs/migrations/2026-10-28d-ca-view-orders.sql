-- Owner decision (2026-10-28): the CA / Chartered Accountant role gets
-- "View orders" (view_orders, read-only) so a CA can open an order's page —
-- and, with "View & download CAFIN", its CAFIN box. It adds no edit
-- permission. Like everyone without "View all orders", the CA sees only
-- the orders assigned to them. Safe to run more than once.
SET NAMES utf8mb4;

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_orders'
  AND r.name = 'CA / Chartered Accountant';
