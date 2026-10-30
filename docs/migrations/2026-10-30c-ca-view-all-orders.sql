-- Owner decision 2026-10-30: the CA does the books for the whole company, so
-- the CA role sees every order (read-only), not only the orders assigned to
-- it. view_all_orders only widens which orders are visible; the CA role still
-- holds no order-editing permission. Safe to run more than once.
SET NAMES utf8mb4;

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_all_orders'
  AND r.name = 'CA / Chartered Accountant';
