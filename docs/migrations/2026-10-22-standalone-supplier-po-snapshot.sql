-- 90-item backlog "standalone order" follow-on: order_supplier_po.
-- supplier_id was the same live-FK pattern the orders table itself had —
-- OrderSupplierPoRepository::findLatestForOrder()/
-- orderSupplierPoRepository.js's equivalent re-joined `suppliers` on
-- every read, so a change to a supplier's own name/address/GSTIN/PAN/
-- contact details would retroactively rewrite what an already-issued,
-- signed, or closed Supplier PO displays and prints. This adds snapshot
-- columns to `order_supplier_po`, backfills them from whatever the live
-- join currently resolves to (accurate as of today — nothing has drifted
-- yet). Apply to both the PHP and Node MySQL databases — same schema.
--
-- The old supplier_id column is NOT dropped — it stays for admin
-- traceability/reporting only, never read for document content again.

-- ================================================================
-- STEP 1 — add the new snapshot columns
-- ================================================================
ALTER TABLE order_supplier_po
  ADD COLUMN supplier_legal_name    VARCHAR(255) NULL AFTER supplier_id,
  ADD COLUMN supplier_address       TEXT NULL AFTER supplier_legal_name,
  ADD COLUMN supplier_gstin         VARCHAR(20) NULL AFTER supplier_address,
  ADD COLUMN supplier_pan           VARCHAR(20) NULL AFTER supplier_gstin,
  ADD COLUMN supplier_contact_person VARCHAR(150) NULL AFTER supplier_pan,
  ADD COLUMN supplier_phone         VARCHAR(30) NULL AFTER supplier_contact_person,
  ADD COLUMN supplier_type          VARCHAR(50) NULL AFTER supplier_phone;

-- ================================================================
-- STEP 2 — backfill every existing Supplier PO from whatever the live
-- join currently resolves to. Safe and accurate: nothing has drifted yet
-- as of this migration running, so "today's live-join answer" IS the
-- correct historical record for every Supplier PO that exists right now.
-- ================================================================
UPDATE order_supplier_po sp
  JOIN suppliers s ON s.id = sp.supplier_id
SET
  sp.supplier_legal_name    = s.supplier_legal_name,
  sp.supplier_address       = s.address,
  sp.supplier_gstin         = s.gstin,
  sp.supplier_pan           = s.pan,
  sp.supplier_contact_person = s.contact_person,
  sp.supplier_phone         = s.phone,
  sp.supplier_type          = s.supplier_type;
