-- Owner decision (2 Nov 2026, "Zoho clients/products yes", D-238): clients and
-- the products of the Products menu kept in Zoho Books on their own, each
-- behind its own switch (both OFF until switched on in Admin → Settings →
-- Zoho Books). One way, app → Books, inside the hourly sync / Sync now.
-- Adds: clients.zoho_synced_hash / zoho_synced_at; catalog_products.
-- zoho_item_id / zoho_synced_hash / zoho_synced_at; table
-- zoho_pending_inactivations (a product deleted while in Books, marked
-- inactive there at the next sync); settings zoho_sync_clients and
-- zoho_sync_products. MySQL-safe (no ADD COLUMN IF NOT EXISTS), safe to run twice.
SET NAMES utf8mb4;

SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE clients ADD COLUMN zoho_synced_hash CHAR(64) NULL AFTER zoho_contact_id',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'zoho_synced_hash');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE clients ADD COLUMN zoho_synced_at DATETIME NULL AFTER zoho_synced_hash',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'zoho_synced_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN zoho_item_id VARCHAR(40) NULL AFTER is_active',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'zoho_item_id');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN zoho_synced_hash CHAR(64) NULL AFTER zoho_item_id',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'zoho_synced_hash');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE catalog_products ADD COLUMN zoho_synced_at DATETIME NULL AFTER zoho_synced_hash',
  'SELECT 1') FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME = 'zoho_synced_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS zoho_pending_inactivations (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind        VARCHAR(20)  NOT NULL,          -- 'item' (a deleted product)
  zoho_id     VARCHAR(40)  NOT NULL,
  name        VARCHAR(255) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'zoho_sync_clients', '0', 'boolean', 'zoho_books',
       'Send clients to Zoho Books as customers on their own (one way, app → Zoho, at the hourly sync / Sync now): new and changed clients are created or updated, inactive ones marked inactive. A client goes once its first order has set its currency. Off by default.', 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'zoho_sync_clients');

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT 'zoho_sync_products', '0', 'boolean', 'zoho_books',
       'Send the products of the Products menu to Zoho Books as items (one way, app → Zoho, at the hourly sync / Sync now): name, specifications, HS code, rate 0 — no cost is ever sent. Inactive or deleted products are marked inactive in Zoho. Off by default.', 0
WHERE NOT EXISTS (SELECT 1 FROM company_settings WHERE setting_key = 'zoho_sync_products');
