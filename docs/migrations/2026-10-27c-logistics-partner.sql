-- Owner request (2026-10-27): Step 9a picks the freight forwarder from the
-- Logistics Partners directory (new service type "Freight Forwarder")
-- instead of free text. Safe to run more than once.
SET NAMES utf8mb4;

ALTER TABLE logistics_partners
  MODIFY COLUMN service_type ENUM('cha','transportation','both','freight_forwarder') NOT NULL;

ALTER TABLE order_freight
  ADD COLUMN IF NOT EXISTS freight_forwarder_partner_id BIGINT UNSIGNED NULL AFTER freight_forwarder_contact;

-- An earlier draft of this change used a supplier type and a supplier id
-- column instead; remove them where they were already applied.
ALTER TABLE order_freight DROP COLUMN IF EXISTS freight_forwarder_supplier_id;
DELETE FROM dropdown_options
 WHERE list_key = 'supplier_type' AND option_value = 'Logistics Partner (Freight Forwarder)'
   AND NOT EXISTS (SELECT 1 FROM supplier_type_links WHERE supplier_type = 'Logistics Partner (Freight Forwarder)');
