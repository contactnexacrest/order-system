-- Owner decisions (2 Nov 2026).
-- (1) Logistics enters the CHA / transport / freight-forwarder bills; Finance
--     pays them (maker / checker). New permission manage_logistics_bills =
--     add a logistics partner bill, and remove one that has no payment on it.
--     Granted to Logistics Executive / Manager / Director and to every role
--     that already holds manage_supplier_payments, so nobody loses anything.
--     Recording a payment stays with manage_supplier_payments.
-- (2) A buyer remittance can carry "our bank's charges on this credit" (the
--     inward-remittance fee our own bank debits separately, in INR incl.
--     GST). Booked as an order cost at the moment the receipt is recorded.
-- MySQL-safe (no ADD COLUMN IF NOT EXISTS), safe to run twice. Run after 02u.
SET NAMES utf8mb4;

SET @db := DATABASE();

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'manage_logistics_bills', 'Add logistics partner bills',
       'Add a CHA, transport or freight-forwarder bill to an order and remove one that has no payment on it. Paying it needs Manage supplier payments.',
       'orders'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'manage_logistics_bills');

INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.permission_key = 'manage_logistics_bills'
 WHERE (r.name IN ('Logistics Executive', 'Logistics Manager', 'Logistics Director')
        OR r.id IN (SELECT rp.role_id FROM role_permissions rp
                      JOIN permissions sp ON sp.id = rp.permission_id
                     WHERE sp.permission_key = 'manage_supplier_payments'))
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_payment_receipts ADD COLUMN own_bank_charges_inr DECIMAL(14,2) NULL AFTER bank_charges_cost_id',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_payment_receipts' AND COLUMN_NAME = 'own_bank_charges_inr');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE order_payment_receipts ADD COLUMN own_bank_charges_cost_id BIGINT UNSIGNED NULL AFTER own_bank_charges_inr',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'order_payment_receipts' AND COLUMN_NAME = 'own_bank_charges_cost_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
