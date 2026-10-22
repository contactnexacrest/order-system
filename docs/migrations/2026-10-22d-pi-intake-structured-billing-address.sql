-- External QA report L-19 (MIG-03): this ALTER exists in schema.sql
-- (SECTION BI, 90-item backlog #45) but was never captured as its own
-- migration file, so a production DB upgraded by applying docs/migrations
-- in sequence never gets it — the PI details form breaks for any
-- code path that writes/reads these structured billing columns instead
-- of the old flat billing_address text box.
--
-- client_intake_submissions (the QT-stage public form) already got a
-- structured billing address (SECTION BF); pi_intake_submissions' own
-- buyer billing_address was left behind as the one remaining flat
-- free-text box on either public intake form — its Consignee and Notify
-- Party sections were already fully structured, but the buyer's OWN
-- address on the very same form was not. Same pattern, same column
-- names, same fallback convention: billing_address stays (never removed
-- — still what a pre-this-change row has, and what the review screen
-- falls back to), the new structured columns are used when present.
-- Apply to both the PHP and Node MySQL databases — same schema.
ALTER TABLE pi_intake_submissions
  ADD COLUMN billing_address_line1 VARCHAR(255) NULL AFTER billing_address,
  ADD COLUMN billing_address_line2 VARCHAR(255) NULL AFTER billing_address_line1,
  ADD COLUMN billing_city VARCHAR(100) NULL AFTER billing_address_line2,
  ADD COLUMN billing_postcode VARCHAR(30) NULL AFTER billing_city,
  ADD COLUMN billing_country VARCHAR(100) NULL AFTER billing_postcode;
