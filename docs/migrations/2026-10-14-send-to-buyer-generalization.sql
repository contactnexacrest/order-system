-- ================================================================
-- DB MIGRATION — 2026-10-14
-- 90-item backlog #12: generalize "Send to Buyer" to every document
-- type. The code-side fix (document_types.category now drives the
-- Send to Buyer button instead of a hand-maintained exclusion list)
-- needs NO schema change — it reads a column (email_templates /
-- document_types) that already existed. This migration only fills a
-- CONTENT gap it exposed: 4 customer_facing document types (BUYERPO,
-- ANNEXA, PL, BLE) never had a send_{code} email template seeded, so
-- staff sending one of those for the first time would land on an
-- empty "Select a template…" picker instead of a ready preview — the
-- same gap QT/PI/OC/CI/FDN never had.
--
-- Pure data insert into the existing email_templates table (no ALTER
-- TABLE, no new table). INSERT IGNORE makes it safe to run more than
-- once — template_key is UNIQUE, so a prior run is a silent no-op.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — they use the same
-- schema). Already applied to both local dev databases (nexacrest,
-- nexacrest_node_test) during this session. NOT yet applied anywhere
-- else.
-- ================================================================

INSERT IGNORE INTO email_templates (template_key, subject, body, footer) VALUES
  ('send_buyerpo', 'Buyer PO — Order Acceptance {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached our formal acceptance of your Purchase Order, {document_reference} dated {generated_date}, issued against Order Confirmation {oc_ref} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_annexa', 'Annexure A {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached Annexure A {document_reference} dated {generated_date}, accompanying your order (Buyer Inquiry Ref: {buyer_inquiry_ref}). This annexure must always be read together with its parent document.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_pl', 'Packing List {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached Packing List {document_reference} dated {generated_date} for your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_ble', 'Bill of Lading Endorsement {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached the Bill of Lading Endorsement {document_reference} dated {generated_date} for your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}');
