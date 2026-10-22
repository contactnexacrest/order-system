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
ALTER TABLE orders
  ADD COLUMN company_legal_name       VARCHAR(255) NULL AFTER show_bl_instructions,
  ADD COLUMN billing_address          TEXT NULL AFTER company_legal_name,
  ADD COLUMN billing_address_line1    VARCHAR(255) NULL AFTER billing_address,
  ADD COLUMN billing_address_line2    VARCHAR(255) NULL AFTER billing_address_line1,
  ADD COLUMN billing_city             VARCHAR(100) NULL AFTER billing_address_line2,
  ADD COLUMN billing_postcode         VARCHAR(30) NULL AFTER billing_city,
  ADD COLUMN billing_country          VARCHAR(100) NULL AFTER billing_postcode,
  ADD COLUMN vat_eori_tax_no          VARCHAR(100) NULL AFTER billing_country,
  ADD COLUMN contact_person           VARCHAR(150) NULL AFTER vat_eori_tax_no,
  ADD COLUMN client_email             VARCHAR(190) NULL AFTER contact_person,
  ADD COLUMN client_phone             VARCHAR(30) NULL AFTER client_email,
  ADD COLUMN country_of_destination   VARCHAR(100) NULL AFTER client_phone,
  ADD COLUMN consignee_same_as_buyer  TINYINT(1) NOT NULL DEFAULT 1 AFTER country_of_destination,
  ADD COLUMN consignee_name           VARCHAR(255) NULL AFTER consignee_same_as_buyer,
  ADD COLUMN consignee_address        TEXT NULL AFTER consignee_name,
  ADD COLUMN consignee_address_line1  VARCHAR(255) NULL AFTER consignee_address,
  ADD COLUMN consignee_address_line2  VARCHAR(255) NULL AFTER consignee_address_line1,
  ADD COLUMN consignee_city           VARCHAR(100) NULL AFTER consignee_address_line2,
  ADD COLUMN consignee_postcode       VARCHAR(30) NULL AFTER consignee_city,
  ADD COLUMN consignee_country        VARCHAR(100) NULL AFTER consignee_postcode,
  ADD COLUMN consignee_vat_eori_tax_no VARCHAR(100) NULL AFTER consignee_country,
  ADD COLUMN consignee_contact_person VARCHAR(150) NULL AFTER consignee_vat_eori_tax_no,
  ADD COLUMN consignee_phone          VARCHAR(30) NULL AFTER consignee_contact_person,
  ADD COLUMN consignee_email          VARCHAR(190) NULL AFTER consignee_phone,
  ADD COLUMN notify_party_same_as_consignee TINYINT(1) NOT NULL DEFAULT 1 AFTER consignee_email,
  ADD COLUMN notify_party             VARCHAR(255) NULL AFTER notify_party_same_as_consignee,
  ADD COLUMN notify_party_address_line1 VARCHAR(255) NULL AFTER notify_party,
  ADD COLUMN notify_party_address_line2 VARCHAR(255) NULL AFTER notify_party_address_line1,
  ADD COLUMN notify_party_city        VARCHAR(100) NULL AFTER notify_party_address_line2,
  ADD COLUMN notify_party_postcode    VARCHAR(30) NULL AFTER notify_party_city,
  ADD COLUMN notify_party_country     VARCHAR(100) NULL AFTER notify_party_postcode,
  ADD COLUMN notify_party_contact_person VARCHAR(150) NULL AFTER notify_party_country,
  ADD COLUMN notify_party_phone       VARCHAR(30) NULL AFTER notify_party_contact_person,
  ADD COLUMN notify_party_email       VARCHAR(190) NULL AFTER notify_party_phone;

-- ================================================================
-- STEP 2 — backfill every existing order from whatever the live join
-- currently resolves to. Safe and accurate: nothing has drifted yet as
-- of this migration running, so "today's live-join answer" IS the
-- correct historical record for every order that exists right now.
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
  o.notify_party_email       = c.notify_party_email;
