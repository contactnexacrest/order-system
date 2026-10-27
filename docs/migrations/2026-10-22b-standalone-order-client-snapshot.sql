-- 90-item backlog "standalone order" follow-on: orders.client_id was a
-- live FK to `clients` — OrderRepository::find()/orderRepository.js's
-- find() re-joined it on every read, so a correction to a client's
-- billing/consignee/notify-party details retroactively rewrote what
-- every order for that client — including ones closed years ago —
-- displays and prints. Worse than the earlier lookup-table fixes: this
-- isn't a hypothetical "if an admin edits a config screen" risk —
-- PiIntakeReviewController::accept()/piIntakeReviewController.js's
-- accept() (the normal Stage 3 PI-intake workflow, run on every order)
-- ALWAYS overwrites the shared `clients` row with whatever the buyer
-- just confirmed. This adds snapshot columns to `orders`, backfills them
-- from whatever the live join currently resolves to (accurate as of
-- today — nothing has drifted yet). Apply to both the PHP and Node MySQL
-- databases — same schema.
--
-- The old client_id column is NOT dropped — it stays for admin
-- traceability/reporting only, never read for display or document
-- content again. client_unique_number and agreement_footer_text/
-- agreement_expiry_date/agreement_force_expired are deliberately NOT
-- snapshotted — see schema.sql's own comment on this block for why those
-- four stay a live lookup via client_id.

-- ================================================================
-- STEP 1 — add the new snapshot columns
-- ================================================================

SET NAMES utf8mb4;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'company_legal_name') = 0, 'ALTER TABLE orders ADD COLUMN company_legal_name VARCHAR(255) NULL AFTER show_bl_instructions', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'billing_address') = 0, 'ALTER TABLE orders ADD COLUMN billing_address TEXT NULL AFTER company_legal_name', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'billing_address_line1') = 0, 'ALTER TABLE orders ADD COLUMN billing_address_line1 VARCHAR(255) NULL AFTER billing_address', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'billing_address_line2') = 0, 'ALTER TABLE orders ADD COLUMN billing_address_line2 VARCHAR(255) NULL AFTER billing_address_line1', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'billing_city') = 0, 'ALTER TABLE orders ADD COLUMN billing_city VARCHAR(100) NULL AFTER billing_address_line2', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'billing_postcode') = 0, 'ALTER TABLE orders ADD COLUMN billing_postcode VARCHAR(30) NULL AFTER billing_city', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'billing_country') = 0, 'ALTER TABLE orders ADD COLUMN billing_country VARCHAR(100) NULL AFTER billing_postcode', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'vat_eori_tax_no') = 0, 'ALTER TABLE orders ADD COLUMN vat_eori_tax_no VARCHAR(100) NULL AFTER billing_country', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'contact_person') = 0, 'ALTER TABLE orders ADD COLUMN contact_person VARCHAR(150) NULL AFTER vat_eori_tax_no', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'client_email') = 0, 'ALTER TABLE orders ADD COLUMN client_email VARCHAR(190) NULL AFTER contact_person', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'client_phone') = 0, 'ALTER TABLE orders ADD COLUMN client_phone VARCHAR(30) NULL AFTER client_email', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'country_of_destination') = 0, 'ALTER TABLE orders ADD COLUMN country_of_destination VARCHAR(100) NULL AFTER client_phone', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_same_as_buyer') = 0, 'ALTER TABLE orders ADD COLUMN consignee_same_as_buyer TINYINT(1) NOT NULL DEFAULT 1 AFTER country_of_destination', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_name') = 0, 'ALTER TABLE orders ADD COLUMN consignee_name VARCHAR(255) NULL AFTER consignee_same_as_buyer', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_address') = 0, 'ALTER TABLE orders ADD COLUMN consignee_address TEXT NULL AFTER consignee_name', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_address_line1') = 0, 'ALTER TABLE orders ADD COLUMN consignee_address_line1 VARCHAR(255) NULL AFTER consignee_address', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_address_line2') = 0, 'ALTER TABLE orders ADD COLUMN consignee_address_line2 VARCHAR(255) NULL AFTER consignee_address_line1', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_city') = 0, 'ALTER TABLE orders ADD COLUMN consignee_city VARCHAR(100) NULL AFTER consignee_address_line2', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_postcode') = 0, 'ALTER TABLE orders ADD COLUMN consignee_postcode VARCHAR(30) NULL AFTER consignee_city', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_country') = 0, 'ALTER TABLE orders ADD COLUMN consignee_country VARCHAR(100) NULL AFTER consignee_postcode', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_vat_eori_tax_no') = 0, 'ALTER TABLE orders ADD COLUMN consignee_vat_eori_tax_no VARCHAR(100) NULL AFTER consignee_country', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_contact_person') = 0, 'ALTER TABLE orders ADD COLUMN consignee_contact_person VARCHAR(150) NULL AFTER consignee_vat_eori_tax_no', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_phone') = 0, 'ALTER TABLE orders ADD COLUMN consignee_phone VARCHAR(30) NULL AFTER consignee_contact_person', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'consignee_email') = 0, 'ALTER TABLE orders ADD COLUMN consignee_email VARCHAR(190) NULL AFTER consignee_phone', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party_same_as_consignee') = 0, 'ALTER TABLE orders ADD COLUMN notify_party_same_as_consignee TINYINT(1) NOT NULL DEFAULT 1 AFTER consignee_email', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party') = 0, 'ALTER TABLE orders ADD COLUMN notify_party VARCHAR(255) NULL AFTER notify_party_same_as_consignee', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party_address_line1') = 0, 'ALTER TABLE orders ADD COLUMN notify_party_address_line1 VARCHAR(255) NULL AFTER notify_party', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party_address_line2') = 0, 'ALTER TABLE orders ADD COLUMN notify_party_address_line2 VARCHAR(255) NULL AFTER notify_party_address_line1', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party_city') = 0, 'ALTER TABLE orders ADD COLUMN notify_party_city VARCHAR(100) NULL AFTER notify_party_address_line2', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party_postcode') = 0, 'ALTER TABLE orders ADD COLUMN notify_party_postcode VARCHAR(30) NULL AFTER notify_party_city', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party_country') = 0, 'ALTER TABLE orders ADD COLUMN notify_party_country VARCHAR(100) NULL AFTER notify_party_postcode', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party_contact_person') = 0, 'ALTER TABLE orders ADD COLUMN notify_party_contact_person VARCHAR(150) NULL AFTER notify_party_country', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party_phone') = 0, 'ALTER TABLE orders ADD COLUMN notify_party_phone VARCHAR(30) NULL AFTER notify_party_contact_person', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'notify_party_email') = 0, 'ALTER TABLE orders ADD COLUMN notify_party_email VARCHAR(190) NULL AFTER notify_party_phone', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;


-- ================================================================
-- STEP 2 — backfill every existing order from whatever the live join
-- currently resolves to. Safe and accurate: nothing has drifted yet as
-- of this migration running, so "today's live-join answer" IS the
-- correct historical record for every order that exists right now.
--
-- External QA report L-32: restricted to rows not yet snapshotted
-- (company_legal_name IS NULL) — without this, re-running the
-- migration after a client's details later legitimately changed
-- would silently overwrite the correct FROZEN historical snapshot
-- with today's (now-drifted) live values, defeating the entire point
-- of this migration.
-- ================================================================
UPDATE orders o
  JOIN clients c ON c.id = o.client_id
SET
  o.company_legal_name      = c.company_legal_name,
  o.billing_address          = c.billing_address,
  o.billing_address_line1    = c.billing_address_line1,
  o.billing_address_line2    = c.billing_address_line2,
  o.billing_city             = c.billing_city,
  o.billing_postcode         = c.billing_postcode,
  o.billing_country          = c.billing_country,
  o.vat_eori_tax_no          = c.vat_eori_tax_no,
  o.contact_person           = c.contact_person,
  o.client_email             = c.email,
  o.client_phone             = c.phone,
  o.country_of_destination   = c.country_of_destination,
  o.consignee_same_as_buyer  = c.consignee_same_as_buyer,
  o.consignee_name           = c.consignee_name,
  o.consignee_address        = c.consignee_address,
  o.consignee_address_line1  = c.consignee_address_line1,
  o.consignee_address_line2  = c.consignee_address_line2,
  o.consignee_city           = c.consignee_city,
  o.consignee_postcode       = c.consignee_postcode,
  o.consignee_country        = c.consignee_country,
  o.consignee_vat_eori_tax_no = c.consignee_vat_eori_tax_no,
  o.consignee_contact_person = c.consignee_contact_person,
  o.consignee_phone          = c.consignee_phone,
  o.consignee_email          = c.consignee_email,
  o.notify_party_same_as_consignee = c.notify_party_same_as_consignee,
  o.notify_party             = c.notify_party,
  o.notify_party_address_line1 = c.notify_party_address_line1,
  o.notify_party_address_line2 = c.notify_party_address_line2,
  o.notify_party_city        = c.notify_party_city,
  o.notify_party_postcode    = c.notify_party_postcode,
  o.notify_party_country     = c.notify_party_country,
  o.notify_party_contact_person = c.notify_party_contact_person,
  o.notify_party_phone       = c.notify_party_phone,
  o.notify_party_email       = c.notify_party_email
WHERE o.company_legal_name IS NULL;