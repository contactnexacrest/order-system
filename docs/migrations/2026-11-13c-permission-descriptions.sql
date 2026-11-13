-- 2026-11-13c — Permission descriptions brought up to date (QA findings PLN-003, PLN-004, PLN-005)
-- Text only: the descriptions shown on Users & roles › Roles and permissions. No permission is
-- added, removed, granted or withdrawn; no role changes; nothing else is touched.
--
--   1. record_market_prices — it also unlocks Edit price (with preview and undo), base and
--      thickness prices, linking supplier prices, and (together with manage_suppliers) the
--      offer Active switch and "+ Add product" on a supplier (D-359 / D-360 / D-361).
--   2. edit_order_post_confirmation — since D-342 product rows and prices are fixed from the
--      first approved Quotation for everyone and change only by a "Products & prices" amendment.
--   3. manage_compliance_task_types — the order-page checklist uses manage_compliance_checklist
--      (D-226), not close_orders; and it names the roles that hold it today.
--
-- Safe to run twice: plain UPDATEs by permission_key (no schema change, MySQL-safe). A fresh
-- install (schema.sql + seed.sql) already has these texts. Run after 2026-11-13b, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-13c-permission-descriptions.sql
SET NAMES utf8mb4;

UPDATE permissions SET description = 'Record and edit supplier prices for products (Edit price, base and thickness prices, the one used for pricing, dates) and link prices to suppliers; with manage_suppliers also the offer Active switch and + Add product. No selling prices or floors.'
  WHERE permission_key = 'record_market_prices';

UPDATE permissions SET description = 'Edit order details once Order Confirmation has been issued (Stage 4+), with a reason; every use is logged. Product rows and prices are fixed from the first approved Quotation (D-342) and change only by a Products & prices amendment.'
  WHERE permission_key = 'edit_order_post_confirmation';

UPDATE permissions SET description = 'Add or deactivate compliance task types (ECGC Cover, Pre-Shipment Inspection, etc.). Does not grant the order-page checklist itself - that uses manage_compliance_checklist. Held by Admin, MD, ED, System Administrator, Export Compliance Manager/Director.'
  WHERE permission_key = 'manage_compliance_task_types';

SELECT CONCAT('2026-11-13c done: ', COUNT(*), ' of 3 permission descriptions up to date.') AS result
  FROM permissions
  WHERE (permission_key = 'record_market_prices' AND description LIKE 'Record and edit supplier prices%')
     OR (permission_key = 'edit_order_post_confirmation' AND description LIKE '%Products & prices amendment.')
     OR (permission_key = 'manage_compliance_task_types' AND description LIKE '%uses manage_compliance_checklist%');
