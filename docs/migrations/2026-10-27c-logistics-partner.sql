-- Owner requests (2026-10-27). Safe to run more than once.
SET NAMES utf8mb4;

-- Logistics partners (freight forwarders) live in the Suppliers directory
-- under their own type; Step 9a picks one instead of free text.
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT 'supplier_type', 'Logistics Partner (Freight Forwarder)', 5, 0, 1
 WHERE NOT EXISTS (SELECT 1 FROM dropdown_options WHERE list_key = 'supplier_type' AND option_value = 'Logistics Partner (Freight Forwarder)');

ALTER TABLE order_freight
  ADD COLUMN IF NOT EXISTS freight_forwarder_supplier_id BIGINT UNSIGNED NULL AFTER freight_forwarder_contact;
