-- ================================================================
-- NexaCrest Export Operations Webapp — SEED DATA (Phase A)
-- Apply AFTER schema.sql, to the same database.
--
-- Judgment call flagged for your review: the roles/permissions matrix
-- below is a first-cut RBAC model built from the general shape of the
-- spec (Admin / MD / functional roles), not a verbatim transcription of
-- a role-by-role permission table from the spec — I don't have one
-- confirmed line-by-line in front of me. Treat it as a working default:
-- easy to correct via the Admin RBAC screens later, nothing is hardcoded
-- in application code.
--
-- Placeholder values (company address, GSTIN, bank details, alert
-- day-counts, etc.) are marked PLACEHOLDER below, per your instruction
-- to seed placeholders now and swap in real values during testing.
-- ================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- ROLES
-- ================================================================
INSERT INTO roles (name, description, is_system_role) VALUES
  ('Admin',               'Full system access, including user/role administration and audit log.', 1),
  ('Managing Director',   'Full business authority: approvals, all order/document actions, reporting.', 0),
  ('Executive Director',  'Full business authority: approvals, all order/document actions, reporting.', 0),
  ('Export Executive',    'Day-to-day order handling, quotations through order confirmation, document generation.', 0),
  ('Accounts Executive',  'Payment tracking, balance follow-up, financial reporting.', 0),
  ('Logistics Executive', 'Packing, freight, BL instruction, shipping-stage documents.', 0),
  ('Viewer / Auditor',    'Read-only access to reports and audit trail.', 0);

-- ================================================================
-- PERMISSIONS
-- ================================================================
INSERT INTO permissions (permission_key, name, description, category) VALUES
  ('manage_users',              'Manage users',               'Create/edit/deactivate user accounts and assign roles.', 'admin'),
  ('manage_permissions',        'Manage roles & permissions', 'Edit role/user permission matrix.',                     'admin'),
  ('manage_company_settings',   'Manage company settings',    'Edit company_settings key-value configuration.',        'admin'),
  ('manage_assets',             'Manage assets',               'Upload/replace logo, signature, seal, watermark, email header.', 'admin'),
  ('view_audit_log',            'View audit log',              'View the immutable system audit trail.',                'admin'),
  ('manage_report_definitions', 'Manage saved reports',         'Create/edit/delete saved report definitions.',          'reports'),
  ('view_reports',              'View reports',                 'Run and view reports and dashboards.',                  'reports'),
  ('manage_orders',             'Manage orders',                 'Create/edit orders and advance them through stage gates.', 'orders'),
  ('generate_documents',        'Generate documents',            'Generate QT/PI/OC/PL/CI/etc. for an order.',            'documents'),
  ('approve_documents',         'Approve documents',             'Approve/release a generated document to the buyer.',   'documents'),
  ('edit_locked_data',          'Override locked data',          'Edit a field that is normally locked after stage sign-off.', 'documents'),
  ('view_client_email_full',    'View full client email',        'See a client''s full email address (vs a masked view).', 'clients'),
  ('download_pdf',              'Download PDF',                  'Download a generated PDF document.',                    'documents'),
  ('cross_verify_documents',    'Cross-verify documents',        'Add an independent pass/fail quality check on any generated document — separate from the formal reviewer sign-off.', 'documents'),
  ('approve_email_send',        'Approve email send (Level 2)',  'Level-2 approval for a deferred client email before it actually sends (Section 10 — Email & Deferred Send System).', 'documents'),
  ('manage_sample_data',        'Manage sample data',            'Load/clear the Sample Data Playground (test clients/orders only — never real data).', 'admin'),
  ('manage_field_protection',   'Manage field protection',       'Request or approve locking/unlocking a protected field (company setting, T&C clause, or payment preset). Approving your own request is blocked — a different privileged user must confirm.', 'admin'),
  ('delete_assets',             'Delete assets',                 'Permanently remove a superseded (inactive) logo/signature/seal/watermark/email-header upload. The currently active asset for a type can never be deleted this way — replace it first.', 'admin'),
  ('manage_signatories',        'Manage signatories & designations', 'Manage the designations list, mark a user as signatory-eligible, upload their signature/designation-seal images, and set the global and per-document-type default signatory.', 'admin'),
  ('view_product_catalog',      'View product catalog',          'Search and view individual products in the internal product catalog.', 'catalog'),
  ('browse_product_catalog',    'Browse product catalog',        'Browse the full product catalog list, not just search results.', 'catalog'),
  ('view_product_pricing',      'View product pricing',          'See product pricing, supplier list, and misc charges.', 'catalog'),
  ('manage_product_catalog',    'Manage product catalog',        'Create, edit, and delete products, suppliers, images, and misc charges.', 'catalog'),
  ('view_archived_orders',      'View archived orders',          'See orders that have been archived out of the default listing. Archiving never deletes anything — this only gates who can look an archived order up.', 'orders'),
  ('manage_hs_codes',           'Manage HS code master list',    'Add, edit, and deactivate HS codes in the master list order creation picks from — kept separate from ordinary order-entry access so a new code always goes through a privileged person first.', 'catalog');

-- ================================================================
-- ROLE_PERMISSIONS — first-cut matrix (see note above)
-- ================================================================
INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Export Executive'
  AND p.permission_key IN ('manage_orders','generate_documents','download_pdf','view_reports','view_client_email_full','cross_verify_documents','view_product_catalog','browse_product_catalog','view_product_pricing','view_archived_orders');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Accounts Executive'
  AND p.permission_key IN ('manage_orders','download_pdf','view_reports','view_client_email_full','cross_verify_documents','view_product_catalog','browse_product_catalog','view_product_pricing','view_archived_orders');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Logistics Executive'
  AND p.permission_key IN ('manage_orders','generate_documents','download_pdf','cross_verify_documents','view_product_catalog','browse_product_catalog','view_archived_orders');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Viewer / Auditor'
  AND p.permission_key IN ('view_reports','view_audit_log','view_product_catalog');

-- ================================================================
-- USERS — one seeded account to get in the door.
-- Email/password are PLACEHOLDERs — change both immediately.
-- Password below is "ChangeMe#2026" (bcrypt hash) — force_password_change=1
-- means you'll be made to set a new one on first login regardless.
-- ================================================================
INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
SELECT 'Gulmohar Sontakke', 'gulmohar.sontakke@nexacrestinternational.com', NULL,
       '$2y$12$SRa3a47hKlgsRGskEZfWJerGPgxLI8jSnVlckkHENnfs9VRao/You',
       r.id, 1, 1, 0
FROM roles r WHERE r.name = 'Managing Director';

-- ================================================================
-- CURRENCIES / PORTS / INCOTERMS (minimal, DB-editable via Admin later)
-- ================================================================
INSERT INTO currencies (code, name, is_default, is_active) VALUES
  ('USD', 'US Dollar', 1, 1),
  ('EUR', 'Euro', 0, 1),
  ('GBP', 'Pound Sterling', 0, 1);

-- Chennai is the real, confirmed loading port (every QT/PI/OC source
-- template names it explicitly). Discharge port is deliberately NOT
-- seeded with an example here — it's buyer-specific, entered per client/
-- order, and per your standing instruction not to bake a particular
-- destination region into the system as a default.
INSERT INTO ports (name, country, port_role, is_default, is_active, sort_order) VALUES
  ('Chennai, India', 'India', 'loading', 1, 1, 1);

INSERT INTO incoterms (code, label_template, requires_port_role, is_default, is_active, sort_order) VALUES
  ('FOB', '{code} {port} — Incoterms® 2020', 'loading',    1, 1, 1),
  ('CFR', '{code} {port} — Incoterms® 2020', 'discharge',  0, 1, 2),
  ('CIF', '{code} {port} — Incoterms® 2020', 'discharge',  0, 1, 3);

-- ================================================================
-- PAYMENT PRESETS — the structural fix for the Tier1/Tier2 contamination
-- found in the source documents (see ARCHITECTURE.md section 4.3).
-- ================================================================
INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, balance_pct, balance_trigger_option, balance_days, currency_id, requires_md_approval, is_active)
SELECT 'Standard — New Buyer', 1, 40.00, 'against Proforma Invoice before production commences',
       60.00, 'A_BEFORE_SHIPMENT', 3, c.id, 0, 1
FROM currencies c WHERE c.code = 'USD';

INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, balance_pct, balance_trigger_option, balance_days, currency_id, requires_md_approval, is_active)
SELECT 'Established Buyer — Post-BL', 0, 40.00, 'against Proforma Invoice before production commences',
       60.00, 'B_AGAINST_BL', 7, c.id, 1, 1
FROM currencies c WHERE c.code = 'USD';

-- Both presets ship protected by default — they drive where money is
-- actually sent/received; see field_protection_requests to unprotect.
UPDATE payment_presets SET is_protected = 1;

-- ================================================================
-- STAGES MASTER — the 9 gates from StageGate.docx / WallReference.docx
-- ================================================================
INSERT INTO stages_master (stage_number, stage_slug, stage_name, sequence, is_active) VALUES
  (1, 'quotation',              'Enquiry & Quotation',              1, 1),
  (2, 'buyer_po',                'Buyer Purchase Order',             2, 1),
  (3, 'pi',                      'Proforma Invoice',                 3, 1),
  (4, 'oc_production',           'Order Confirmation',               4, 1),
  (5, 'supplier_po',             'Supplier Purchase Order',          5, 1),
  (6, 'freight',                 'Freight Payment',                  6, 1),
  (7, 'bl_instruction',          'Packing & BL Instruction',         7, 1),
  (8, 'commercial_invoice',      'Commercial Invoice & Balance',     8, 1),
  (9, 'closure',                 'Document Despatch & Closure',      9, 1);

-- ================================================================
-- DOCUMENT TYPES
-- ================================================================
INSERT INTO document_types (code, name, category, ref_format, never_shown_to_buyer, revision_enabled, min_reviewers_default, is_active) VALUES
  ('QT',        'Quotation',                          'customer_facing', 'SC/QT/{YYYY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('ANNEXA',    'Annexure A',                          'customer_facing', NULL,                        0, 1, 1, 1),
  ('BUYERPO',   'Buyer PO — Order Acceptance',         'customer_facing', 'SC/PO/{YYYY}/{DDMM}{NNN}', 0, 0, 1, 1),
  ('PI',        'Proforma Invoice',                    'customer_facing', 'SC/PI/{YYYY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('OC',        'Order Confirmation',                  'customer_facing', 'SC/OC/{YYYY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('SUPPO',     'Supplier PO — Material Procurement',  'procurement',     'SC/SPO/{YYYY}/{DDMM}{NNN}', 1, 1, 1, 1),
  ('FDN',       'Freight Debit Note',                  'customer_facing', 'SC/FDN/{YYYY}/{DDMM}{NNN}', 0, 1, 1, 1),
  ('PL',        'Packing List',                        'customer_facing', 'SC/PL/{YYYY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('BLI',       'BL Instruction Sheet',                'internal',        'SC/BLI/{YYYY}/{DDMM}{NNN}', 1, 1, 1, 1),
  ('CI',        'Commercial Invoice',                  'customer_facing', 'SC/CI/{YYYY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('COOPREP',   'Certificate of Origin Preparation',   'procurement',     NULL,                        1, 1, 1, 1),
  ('CHECKLIST', 'Cross-Verification Checklist 1 of 4 — Sales / Documentation Officer', 'internal', NULL, 1, 0, 1, 1),
  ('CHECKLIST_2_FINANCE',  'Cross-Verification Checklist 2 of 4 — Accounts / Finance',              'internal', NULL, 1, 0, 1, 1),
  ('CHECKLIST_3_PACKING',  'Cross-Verification Checklist 3 of 4 — Packing / Dispatch Supervisor',   'internal', NULL, 1, 0, 1, 1),
  ('CHECKLIST_4_SHIPPING', 'Cross-Verification Checklist 4 of 4 — Shipping / Logistics Coordinator', 'internal', NULL, 1, 0, 1, 1),
  ('AMD',       'Payment Terms Amendment',              'internal',       'SC/AMD/{YYYY}/{DDMM}{NNN}', 1, 1, 1, 1),
  ('SOP_A_SALES', 'SOP — Sales Process, Tier A (Standard — New Buyer)', 'internal', NULL,               1, 1, 1, 1),
  ('SOP_B_SALES', 'SOP — Sales Process, Tier B (Established Buyer — Post-BL)', 'internal', NULL,        1, 1, 1, 1),
  ('STAGEGATE', 'Stage Gate Reference',                 'internal',       NULL,                        1, 0, 1, 1),
  ('WALLREF',   'Wall Reference',                       'internal',       NULL,                        1, 0, 1, 1);

-- ================================================================
-- TC_CLAUSES / TC_CLAUSE_DOCUMENTS — real Terms & Conditions text
-- extracted verbatim from 01_Quotation.docx / 02_ProformaInvoice.docx /
-- 03_OrderConfirmation.docx during Phase B. {placeholders} inside clause
-- text are substituted at render time from company_settings/order data
-- (advance_pct, balance_pct, quantity_shortfall_tolerance_pct,
-- quotation_validity_days, pi_validity_days) — never hardcoded twice.
-- The 3 is_locked clauses are the mandatory boilerplate the schema
-- comment for tc_clauses already reserved space for.
-- ================================================================

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (10, 'Natural Stone Characteristics', 'Natural Stone Characteristics: All granite supplied is Grade A quality, selected for colour consistency. As with all natural stone, minor variation in shade, grain and veining may occur between pieces — however, visible dramatic colour difference between components of the same order will not occur. Products will conform to the agreed specification.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Natural Stone Characteristics' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (20, 'Quantity Tolerance', 'Quantity Tolerance: Actual quantity shipped will not exceed the ordered quantity. A shortfall of up to {tolerance}% may occur due to natural stone production characteristics and will be invoiced at actual quantity shipped. Buyer will be notified in writing before shipment of any shortfall exceeding {tolerance}%, and shipment will require buyer''s written approval.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Quantity Tolerance' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (30, 'Cancellation', 'Cancellation: Once the advance payment is received, this order is final and binding. It cannot be cancelled, modified, or refunded for any reason, whether or not production has yet commenced.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Cancellation' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (40, 'Partial Shipment', 'Partial Shipment: Not permitted. NexaCrest ships complete orders only — minimum one full FCL. Mixed-product containers (multiple products in one FCL) are permitted.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Partial Shipment' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (50, 'Import Clearance & Destination Charges', 'Import Clearance & Destination Charges: Unless expressly stated otherwise under the agreed Incoterm, the buyer is solely responsible for import customs clearance, import duties, taxes, VAT, permits, licences and all destination-country charges. NexaCrest bears no responsibility for destination-side costs or delays.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Import Clearance & Destination Charges' AND dt.code IN ('QT','PI');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (60, 'Production Commencement', 'Production commences only after {advance_pct}% advance payment is received and CLEARED in NexaCrest''s bank account, and the Order Confirmation has been acknowledged. Remittance copy alone does not constitute payment receipt. Please allow 1–2 banking days for clearance confirmation before expecting production to commence. If the Order Confirmation is not acknowledged within 48 hours of being sent, it is treated as accepted and production proceeds.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Production Commencement' AND dt.code IN ('QT','PI');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (70, 'Weight & Quantity Estimates', 'Weight, CBM, and number of crates are estimates. Actuals are confirmed on the Packing List after production.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Weight & Quantity Estimates' AND dt.code IN ('QT','PI');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (80, 'Pricing Currency & Basis', 'All prices are in USD. FOB Chennai, India unless otherwise stated.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Pricing Currency & Basis' AND dt.code IN ('QT','PI');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (90, 'Shipment Booking (CFR/CIF)', 'Shipment Booking: For CFR/CIF orders, shipment booking will be confirmed only after full freight and insurance payment is received and cleared in NexaCrest''s bank account against the Freight Debit Note. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is verified.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Shipment Booking (CFR/CIF)' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (100, 'Quotation Validity', 'This Quotation is valid for {quotation_validity_days} days from the date of issue as shown in the Valid Until field above. After this date, prices and terms are subject to revision.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Quotation Validity' AND dt.code IN ('QT');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (110, 'CFR/CIF Indicative Freight Validity (Quotation)', 'CFR / CIF pricing: if required, indicative freight shown above is valid for 25 days from quotation date. Actual freight will be confirmed once cargo is packed and ready, and recovered IN ADVANCE by separate Freight Debit Note — payable within 3 working days of issue, and must be received BEFORE shipment booking is confirmed. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is received and verified.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'CFR/CIF Indicative Freight Validity (Quotation)' AND dt.code IN ('QT');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (120, 'Bank Details Notice (Quotation)', 'Bank details for advance payment will be provided on the Proforma Invoice. Full bank details for advance payment will be provided on the Proforma Invoice issued after acceptance of this Quotation.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Bank Details Notice (Quotation)' AND dt.code IN ('QT');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (130, 'Unconfirmed Quantity Pricing', 'If order quantity is not confirmed at quotation stage, the unit price above is valid for {quotation_validity_days} days. Total order value and advance payment amount will be confirmed on the Proforma Invoice once quantity is finalised.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Unconfirmed Quantity Pricing' AND dt.code IN ('QT');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (140, 'Formal PI Issuance', 'A formal Proforma Invoice will be issued upon written acceptance of this quotation.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Formal PI Issuance' AND dt.code IN ('QT');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (100, 'Proforma Invoice Validity', 'This Proforma Invoice is valid for {pi_validity_days} days from date of issue. After this date, prices and terms are subject to revision. Buyer must notify NexaCrest of acceptance and initiate the {advance_pct}% advance payment within this period.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Proforma Invoice Validity' AND dt.code IN ('PI');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (110, 'CFR/CIF Indicative Freight Validity (Proforma Invoice)', 'CFR / CIF orders: freight and insurance shown above are indicative at PI stage. Actual freight will be confirmed once cargo is packed and ready, and recovered IN ADVANCE by Freight Debit Note — payable within 3 working days of issue, and must be received BEFORE shipment booking is confirmed. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is received. Freight payment may occur before or simultaneously with the {balance_pct}% balance T/T.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'CFR/CIF Indicative Freight Validity (Proforma Invoice)' AND dt.code IN ('PI');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (10, 'Subject to Proforma Invoice', 'This Order Confirmation is subject to the commercial terms of the referenced Proforma Invoice.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Subject to Proforma Invoice' AND dt.code IN ('OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (95, 'Bill of Lading Release', 'Bill of Lading: Original negotiable BL (3 originals) will be released to buyer only after {balance_pct}% balance payment is CLEARED in NexaCrest''s bank account. Remittance copy alone does not constitute payment receipt. Please allow 1–2 banking days for clearance confirmation before expecting BL originals to be released.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Bill of Lading Release' AND dt.code IN ('OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (900, 'Payment Terms Finality', 'PAYMENT TERMS FINALITY: The payment terms stated in this document are fixed and binding upon acceptance of this document or receipt of any advance payment, whichever occurs first. Neither party shall unilaterally modify, replace, or impose any additional or different payment terms after such acceptance or receipt of advance payment. Any amendment, modification, waiver, or variation of the payment terms shall be valid only if expressly agreed in writing by authorised representatives of both parties. The agreed payment terms may not be amended or waived by any verbal statement, informal communication, partial payment, purchase-order notation, or course of conduct, whether any of these occur individually or in combination. Any such amendment shall be documented in a formal written Payment Terms Amendment Agreement signed by authorised representatives of both parties before the amendment takes effect.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Payment Terms Finality' AND dt.code IN ('QT','PI','OC','BUYERPO');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (910, 'Dispute Resolution & Public Communications', 'DISPUTE RESOLUTION & PUBLIC COMMUNICATIONS: Any dispute, disagreement, complaint, or claim arising out of or in connection with this transaction shall first be notified to the other party in writing by email to the contact email address stated in this document. Written notice shall be deemed delivered upon confirmation of receipt by the receiving party, or within 48 hours of sending to the email address stated in this document, whichever is earlier. It is the responsibility of each party to ensure their stated contact email address is active and monitored. The receiving party shall respond within ten (10) working days of deemed delivery and both parties shall make reasonable and good-faith efforts to resolve the matter amicably. Until the dispute is resolved, neither party shall publish or communicate any knowingly false, misleading, defamatory, threatening, or materially disparaging statement concerning the other party, the transaction, or the dispute on social media, review platforms, trade forums, industry groups, websites, or any other public or semi-public channel. Nothing in this clause shall restrict either party from making disclosures strictly required by law or from communicating with courts, arbitral tribunals, governmental authorities, regulators, customs authorities, insurers, banks, legal advisers, auditors, or other professional advisers, or from exercising any legal or contractual right or remedy. A party shall be responsible, to the extent permitted by applicable law, for public communications made by its employees, agents, representatives, or other persons acting on its behalf or with its authority. Any breach of this clause shall entitle the non-breaching party to seek removal or correction of the offending content and any other contractual or legal remedies available under applicable law. This clause shall survive completion or termination of the transaction. The restriction on false, misleading, defamatory, threatening, or materially disparaging communications shall continue indefinitely after resolution of any dispute and shall not expire.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Dispute Resolution & Public Communications' AND dt.code IN ('QT','PI','OC','BUYERPO');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (920, 'Acceptance & Order of Precedence', 'ACCEPTANCE & ORDER OF PRECEDENCE: This document, together with the commercial terms and conditions stated herein, constitutes the commercial basis of the transaction. Acceptance of this document, issuance of any purchase order in connection with this transaction, written confirmation of the order, or payment of any advance amount — whether in full or in part — shall each independently constitute acceptance of all terms and conditions stated in this document. Any term or condition contained in a buyer''s purchase order or other document that conflicts with or varies from the terms stated herein shall not apply unless expressly accepted in writing by authorised representatives of NexaCrest International Private Limited.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Acceptance & Order of Precedence' AND dt.code IN ('QT','PI','OC','BUYERPO');

-- ================================================================
-- SUPPLIER PO CLAUSES (Phase C) — from 11_SupplierPO.docx Section 6
-- "Quality & Inspection". A different legal counterparty (supplier, not
-- buyer) so these are their own clause set, not reused from the QT/PI/OC/
-- BUYERPO pool above.
-- ================================================================
INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (10, 'Grade A Only', 'Grade A material only. No mixing of grades within the same order. Any piece found to be below Grade A will be rejected.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Grade A Only' AND dt.code = 'SUPPO';

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (20, 'Inspection Rights', 'NexaCrest reserves the right to inspect material at the supplier''s premises before dispatch or at the delivery location upon receipt.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Inspection Rights' AND dt.code = 'SUPPO';

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (30, 'Rejected Material', 'Material that does not conform to the specifications in Section 3 will be rejected. Supplier must replace rejected material within 7 days at no additional cost to NexaCrest. Balance payment will not be released for rejected material.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Rejected Material' AND dt.code = 'SUPPO';

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (40, 'Colour Consistency', 'All pieces within a single order must be from the same quarry batch to ensure colour consistency. Visible dramatic colour difference between pieces in the same order is not acceptable.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Colour Consistency' AND dt.code = 'SUPPO';

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (50, 'Balance Payment Release Condition', 'Balance payment released only after: delivery complete + NexaCrest inspection passed + written acceptance issued by NexaCrest. Delivery alone does not trigger balance payment.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Balance Payment Release Condition' AND dt.code = 'SUPPO';

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (60, 'Time Is of the Essence', 'Delivery by the agreed date is of the essence of this Purchase Order. Failure to deliver by the agreed date may result in cancellation of this PO and/or recovery of losses incurred by NexaCrest as a result of the delay, including but not limited to demurrage, vessel rebooking charges and buyer penalties.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Time Is of the Essence' AND dt.code = 'SUPPO';

-- Every T&C clause is currently mandatory — none may be omitted (standing
-- instruction, 2026-09-19). All rows ship is_protected = 1; unprotecting
-- any individual clause later requires the peer-approved request flow
-- (field_protection_requests), never a direct edit.
UPDATE tc_clauses SET is_protected = 1;

-- ================================================================
-- DROPDOWN_OPTIONS — minimal starter set (Admin can add more later)
-- ================================================================
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active) VALUES
  ('coo_type', 'GSP Form A (Preferential)', 1, 1, 1),
  ('coo_type', 'Non-Preferential', 2, 0, 1),
  ('container_type', '20ft Standard', 1, 1, 1),
  ('container_type', '40ft Standard', 2, 0, 1),
  ('container_type', '40ft High Cube', 3, 0, 1),
  ('dispute_status', 'Open', 1, 1, 1),
  ('dispute_status', 'Under Review', 2, 0, 1),
  ('dispute_status', 'Resolved', 3, 0, 1),
  ('dispute_status', 'Escalated', 4, 0, 1),
  ('received_from', 'Buyer', 1, 1, 1),
  ('received_from', 'Supplier', 2, 0, 1),
  ('received_from', 'CHA / Shipping Line', 3, 0, 1),
  ('supplier_type', 'Quarry Owner', 1, 1, 1),
  ('supplier_type', 'Processor', 2, 0, 1),
  ('supplier_type', 'Trader', 3, 0, 1);

-- ================================================================
-- FILE_UPLOAD_CONTEXTS — Phase D wires up the first two of these
-- (amendment_signed_copy, dispute_document); the rest are seeded now so
-- the table isn't empty when a future phase builds their upload screens
-- (draft BL / fumigation cert / buyer-approval uploads already have
-- columns reserved on order_shipping/order_packing since Phase A, but no
-- upload endpoint yet — see README "Known gaps").
-- ================================================================
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description) VALUES
  ('amendment_signed_copy', 'pdf,jpg,jpeg,png', 10485760, 'Countersigned Payment Terms Amendment Agreement (Section 8) — activates the amendment once uploaded.'),
  ('dispute_document',      'pdf,jpg,jpeg,png,docx,eml,msg', 10485760, 'Any document related to a dispute — notice, correspondence, supporting evidence.'),
  ('received_remittance',   'pdf,jpg,jpeg,png', 10485760, 'Buyer/bank remittance advice or payment confirmation copy.'),
  ('draft_bl',              'pdf', 10485760, 'Draft Bill of Lading from the CHA/shipping line, pending written approval before originals are issued.'),
  ('fumigation_cert',       'pdf,jpg,jpeg,png', 5242880, 'Fumigation certificate for wooden packing.'),
  ('buyer_approval',        'pdf,jpg,jpeg,png,eml,msg', 5242880, 'Buyer''s written approval (e.g. quantity shortfall, draft BL sign-off).'),
  ('product_image',         'jpg,jpeg,png,webp', 5242880, 'Product image/technical drawing attached to an Annexure A entry.'),
  ('buyer_po_copy',         'pdf,jpg,jpeg,png,eml,msg', 10485760, 'Buyer''s actual signed Purchase Order (Stage 2 gate evidence — Addition beyond the spec''s named key list: recordBuyerPo() previously only captured a reference number typed by staff, with no copy of the PO itself on file).'),
  ('supplier_po_ack',       'pdf,jpg,jpeg,png,eml,msg', 10485760, 'Supplier''s signed acknowledgment of the Supplier PO (Stage 5 gate evidence — same addition/rationale as buyer_po_copy).');

-- ================================================================
-- COMPANY_SETTINGS — every key schema.sql reserves for this table.
-- Values marked PLACEHOLDER need real data before documents go live;
-- everything else is a working default you can tune from /settings.
-- ================================================================
-- Company/bank/LUT values below are the REAL values found in your own
-- source documents (01_Quotation.docx, 02_ProformaInvoice.docx), not
-- placeholders — pulled directly during Phase B while building the QT/PI/OC
-- templates against those actual documents.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('legal_name',            'NexaCrest International Private Limited', 'string', 'company', 'Full legal company name as it appears on all documents.', 0),
  ('registered_office',     'No. 33, T Ramaiah Garden, 2 Hulimavu Village, Hulimavu, Bangalore South, Bengaluru, Karnataka – 560076, India', 'string', 'company', 'Registered office address.', 0),
  ('corporate_office',      'Evolve Work Studio, 4th Floor, The Hub @ Raj Serenity, Khatha No. 10, Begur Koppa Road, Yelenahalli, Bengaluru – 560068, Karnataka, India', 'string', 'company', 'Corporate/working office address, if different.', 0),
  ('gstin',                 '29AAKCN8733G1ZZ',                          'string', 'company', 'GST Identification Number.', 1),
  ('iec_pan',               'AAKCN8733G',                               'string', 'company', 'Import Export Code / PAN.', 1),
  ('md_name',               'Gulmohar Sontakke',                        'string', 'company', 'Founder & Managing Director name, for document signature blocks.', 0),
  ('md_title',              'Founder & Managing Director',              'string', 'company', 'MD title as shown on documents.', 0),
  ('director_name',         'Gulmohar Sontakke',                        'string', 'company', 'Director name, if a director signature is used on any document (open question — confirm whether this is needed; QT/PI/OC templates only show the MD signature block).', 0),
  ('director_title',        'Director',                                 'string', 'company', 'Director title as shown on documents.', 0),
  ('phone',                 '+91-7676463030',                           'string', 'company', 'Company contact phone number.', 0),
  ('email',                 'gulmohar.sontakke@nexacrestinternational.com', 'string', 'company', 'Company contact email address.', 0),
  ('bank_name',             'State Bank of India',                      'string', 'bank',    'Bank name for buyer remittances.', 1),
  ('bank_branch',           'Start Up Hub Branch, Koramangala, Bengaluru', 'string', 'bank',  'Bank branch name.', 1),
  ('bank_account_no',       '44523788330',                              'string', 'bank',    'Bank account number.', 1),
  ('swift_bic',             'SBININBB949',                              'string', 'bank',    'SWIFT/BIC code.', 1),
  ('ifsc',                  'SBIN0064074',                              'string', 'bank',    'IFSC code.', 1),
  ('bank_address',          '1st Floor, 117, 7th Block Industrial Layout, Koramangala, Bengaluru – 560095, India', 'string', 'bank', 'Bank branch address.', 1),
  ('bank_pincode',          '560095',                                    'string', 'bank',    'Bank branch pincode.', 1),
  ('lut_number',            'ZD290626057408W',                         'string', 'lut',     'Letter of Undertaking (LUT) ARN/number for zero-rated export.', 1),
  ('lut_valid_fy',          'FY 2026-27',                                'string', 'lut',     'Financial year the current LUT is valid for.', 1),
  ('lut_expiry_date',       '2027-03-31',                                'date',   'lut',     'LUT expiry date — placeholder (FY 2026-27 end date assumed as 31 March 2027) — the source PI template states the FY but not an explicit expiry date; confirm the real one.', 1),
  ('rcmc_number',           'RCMC/CAPEXIL/03217/2026-2027',             'string', 'capexil', 'CAPEXIL RCMC (Registration-cum-Membership Certificate) number — required on the COO Preparation Sheet (Phase C).', 1),
  ('rcmc_valid_until',      '2027-03-31',                                'date',   'capexil', 'CAPEXIL RCMC certificate expiry date — placeholder, renew annually. Alerts configured via rcmc_alert_days_a/rcmc_escalation_days_b below.', 1),
  ('rbi_purpose_code_advance', 'P0103', 'string', 'rbi', 'RBI purpose code for advance payment wires.', 0),
  ('rbi_purpose_code_balance', 'P0102', 'string', 'rbi', 'RBI purpose code for balance payment wires.', 0),
  ('rbi_purpose_code_freight', 'P0602', 'string', 'rbi', 'RBI purpose code for freight/insurance payment wires.', 0),
  ('default_currency',      'USD', 'string', 'defaults', 'Default quotation currency.', 0),
  ('storage_base_path',     'PLACEHOLDER — must match STORAGE_BASE_PATH in .env', 'string', 'system', 'Documentation only — the app reads the real path from .env, not from this row, so it can never drift out of sync with the filesystem.', 0),
  ('quantity_shortfall_tolerance_pct', '5.00',  'number', 'tolerances', 'Allowed shortfall percentage before a quantity variance is flagged.', 0),
  ('lut_alert_days_x',      '30', 'number', 'alerts', 'Days before LUT expiry to raise an alert.', 0),
  ('lut_escalation_days_y', '15', 'number', 'alerts', 'Days before LUT expiry to escalate to MD.', 0),
  ('rcmc_alert_days_a',     '30', 'number', 'alerts', 'Days before RCMC expiry to raise an alert.', 0),
  ('rcmc_escalation_days_b','15', 'number', 'alerts', 'Days before RCMC expiry to escalate to MD.', 0),
  ('fdn_overdue_days_c',    '3',  'number', 'alerts', 'WORKING days after FDN issue before a freight payment is flagged overdue — must match the "payable within 3 working days of issue" figure in the CFR/CIF Indicative Freight Validity clause (QT/PI) verbatim, since that clause is the written promise to the buyer. (Corrected from an earlier placeholder of 7, which did not match the clause text and used calendar-day arithmetic instead of working days — see check_alerts.php.)', 0),
  ('session_timeout_minutes', '30', 'number', 'security', 'Idle session timeout, in minutes.', 0),
  ('failed_login_lockout_count', '5', 'number', 'security', 'Failed login attempts before account lockout.', 0),
  ('lockout_duration_minutes', '15', 'number', 'security', 'How long an account stays locked after too many failed attempts. (Addition beyond the spec''s named key list — needed to make failed_login_lockout_count actually enforceable; flagging per architecture doc practice.)', 0),
  ('password_min_length',   '10', 'number', 'security', 'Minimum password length.', 0),
  ('password_complexity_json', '{"require_upper":true,"require_number":true,"require_symbol":false}', 'json', 'security', 'Password complexity rules enforced on password change.', 0),
  ('password_expiry_days',  '90', 'number', 'security', 'Days before a password must be changed. Enforced on every request (SessionAuth) — 0 disables the check.', 0),
  ('non_usd_price_buffer_pct', '1.75', 'number', 'tolerances', 'Advisory price buffer shown (not auto-applied) for non-USD quotes, per your instruction.', 0),
  ('quotation_validity_days', '30', 'number', 'documents', 'Days a Quotation stays valid from its issue date (Addition beyond the spec''s named key list — the source Quotation template states "30 days" directly in its T&C text; making it a setting instead of a literal keeps that number DB-driven if it ever changes).', 0),
  ('pi_validity_days',       '15', 'number', 'documents', 'Days a Proforma Invoice stays valid from its issue date. Same addition as quotation_validity_days, for the same reason — the source PI template states "15 days" directly in its T&C text.', 0),
  ('revision_start_number', '1', 'number', 'documents', 'Starting revision number for a new document.', 0),
  ('bl_type_instruction', 'ORIGINAL NEGOTIABLE BILL OF LADING — no exceptions. Do not substitute with Sea Waybill or Express BL.', 'string', 'shipping', 'Mandatory BL type instruction printed on every BL Instruction Sheet (Addition beyond the spec''s named key list — this hard rule was previously hardcoded directly in the BLI template, with no governance or audit trail if it ever needed a one-off exception; a Sea Waybill/Express BL lets the buyer collect cargo without surrendering any document, eliminating NexaCrest''s financial leverage over the balance payment).', 0),
  ('bl_consignee_instruction', 'TO ORDER OF {company}', 'string', 'shipping', 'Mandatory BL consignee instruction — ensures the BL is to NexaCrest''s order so the buyer cannot use it until NexaCrest endorses and releases it. {company} is substituted with the company legal name at render time. Same addition/rationale as bl_type_instruction.', 0),
  ('master_tracking_ref_format', 'NC/SC/{YYYY}/{DDMM}{NNN}', 'string', 'formats', 'Buyer inquiry reference format — matches the format already in use on your existing documents.', 0),
  ('client_number_format',  'SC-CL-{NNNN}', 'string', 'formats', 'Placeholder client numbering format — confirm against your actual convention.', 0),
  ('order_ref_format',      'SC/OC/{YYYY}/{NNN}', 'string', 'formats', 'Placeholder order reference format — confirm against your actual convention.', 0),
  ('dispute_response_days_n', '10', 'number', 'disputes', 'WORKING days allowed for a dispute response before escalation — must match the "ten (10) working days" figure in the Dispute Resolution & Public Communications clause below verbatim, since that clause is the legally binding promise printed on QT/PI/OC/BUYERPO. (Corrected from an earlier placeholder of 7, which did not match the clause text.) response_due_date is computed via WorkingDaysCalculator, which skips weekly_off_days and company_holidays — plain calendar-day arithmetic would silently undercount the deadline by counting Sundays/holidays as working days.', 0),
  ('weekly_off_days', 'sunday', 'string', 'disputes', 'Comma-separated lowercase weekday name(s) that never count as a working day, used by WorkingDaysCalculator (Addition beyond the spec''s named key list — needed to make dispute_response_days_n''s "working days" figure actually computable; NexaCrest''s standard Mon-Sat working week per this same section''s holiday calendar).', 0);

-- Addition beyond the spec's named key list: the signature/seal on generated
-- documents are electronic marks, not a scan of a wet-ink signature or a
-- physical company seal, so a short disclaimer is shown under the signature
-- block. Both the on/off toggle and the wording itself are Admin-editable
-- here (not hardcoded in the templates) so the text can be revised at any
-- time without a code change — e.g. once a physical company seal exists.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('show_generated_document_disclaimer', '1', 'boolean', 'documents', 'Show the "system-generated document" disclaimer under the signature block on any generated document that carries a signature/seal image. Set to 0 to hide it everywhere.', 0),
  ('generated_document_disclaimer_text', 'This is a system-generated document. The signature and company seal shown are NexaCrest''s authorised electronic signature and digital company seal, applied automatically by the order management system under internal document-authorisation controls.', 'string', 'documents', 'Exact wording shown under the signature block when the disclaimer above is enabled. Edit freely — no code change needed.', 0);

-- Protected by default: the 12 is_sensitive fields (identity/banking/
-- compliance — a wrong edit is real financial/legal damage), plus the 3
-- document reference-format strings (changing one mid-year breaks
-- continuity with everything already issued under the old format — a
-- one-way door, not a "your call" field). Everything else in this table
-- stays unlocked; unprotecting any of these later goes through
-- field_protection_requests, never a direct edit.
UPDATE company_settings SET is_protected = 1 WHERE is_sensitive = 1;
UPDATE company_settings SET is_protected = 1
  WHERE setting_key IN ('master_tracking_ref_format', 'client_number_format', 'order_ref_format',
                         'bl_type_instruction', 'bl_consignee_instruction');

-- ================================================================
-- WATERMARK_SETTINGS — global draft watermark (every document starts life
-- as documents.status = 'draft', so this is what every freshly-generated
-- PDF shows until someone reviews/approves it). The final/non-draft row
-- below is what Phase D's approval workflow switches a document to once
-- every required reviewer has approved it (DocumentGenerationService::
-- finalizeApproval()) — still a visible watermark (Business Rule #8: "PDF
-- always watermarked. No clean PDF exists in this system."), just no
-- longer the amber DRAFT one.
-- ================================================================
INSERT INTO watermark_settings (scope, is_draft_mode, mode, text_content, font, font_size, color, opacity, angle) VALUES
  ('global', 1, 'text', 'DRAFT — NOT FOR RELEASE', 'Helvetica', 60, '#a8701f', 0.14, 45),
  ('global', 0, 'text', 'NEXACREST INTERNATIONAL — ORIGINAL', 'Helvetica', 50, '#7a7a7a', 0.08, 45);

-- ================================================================
-- DOCX_GENERATION_SETTINGS — every order-scoped document type can now
-- generate an internal-only DOCX alongside the buyer-facing PDF, at the
-- user's choice via the "Generate DOCX" checkbox on the order page (off
-- by default; PDF is always generated regardless of its checkbox state).
-- Both formats are now built to visually match the real source Word
-- templates, not just content-parity — see renderDocx()'s docblock.
-- AMD is generated through its own bespoke generateAmendment() path and
-- has no DOCX support at all yet, so it isn't in this list.
-- ================================================================
INSERT INTO docx_generation_settings (document_type_id, is_enabled)
SELECT id, 1 FROM document_types WHERE code IN ('QT', 'ANNEXA', 'PI', 'OC', 'BUYERPO', 'SUPPO', 'FDN', 'PL', 'BLI', 'CI', 'COOPREP');

-- ================================================================
-- EMAIL_TEMPLATES — the 9 templates Section 10 names. All subject/body/
-- footer content is DB-driven (Admin-editable from /settings later); the
-- {tokens} below are substituted by EmailDispatchService at send-request
-- time from the order/document/company data — never hardcoded in PHP.
-- Client always receives the watermarked PDF only (never DOCX, never a
-- clean/unwatermarked copy) — enforced in EmailDispatchController, not
-- something a template can override.
-- ================================================================
INSERT INTO email_templates (template_key, subject, body, footer) VALUES
  ('send_qt', 'Quotation {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached our Quotation {document_reference} dated {generated_date} for your reference (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nThis quotation is valid until {quotation_valid_until}. Please let us know if you have any questions or would like to proceed.\n\nRegards,\n{sender_name}\n{sender_title}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_pi', 'Proforma Invoice {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached Proforma Invoice {document_reference} dated {generated_date}, issued against Quotation {quotation_ref} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nThis Proforma Invoice is valid until {pi_valid_until}. Kindly arrange the advance payment as per the terms stated to enable us to commence production.\n\nRegards,\n{sender_name}\n{sender_title}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_oc', 'Order Confirmation {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nWe are pleased to confirm your order. Please find attached Order Confirmation {document_reference} dated {generated_date}, issued against Proforma Invoice {pi_ref} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nProduction will proceed as per the schedule stated in the attached document.\n\nRegards,\n{sender_name}\n{sender_title}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_ci', 'Commercial Invoice {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached Commercial Invoice {document_reference} dated {generated_date} for your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nKindly review the invoice value and payment settlement details and arrange the balance payment as per the terms stated.\n\nRegards,\n{sender_name}\n{sender_title}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_fdn', 'Freight Debit Note {document_reference} — Payment Required Before Shipment',
   'Dear {buyer_contact_person},\n\nPlease find attached Freight Debit Note {document_reference} dated {generated_date} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nPayment is required within 3 working days of this Debit Note''s date. We will confirm shipment booking only upon receipt of full freight payment.\n\nRegards,\n{sender_name}\n{sender_title}',
   '{company_name} | {company_email} | {company_phone}'),
  ('payment_followup', 'Payment Follow-Up — Order {order_reference}',
   'Dear {buyer_contact_person},\n\nThis is a follow-up regarding the pending payment on your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}). Kindly arrange the payment at your earliest convenience and share the remittance copy so we can proceed.\n\nRegards,\n{sender_name}\n{sender_title}',
   '{company_name} | {company_email} | {company_phone}'),
  ('shipment_readiness', 'Shipment Readiness Confirmation — Order {order_reference}',
   'Dear {buyer_contact_person},\n\nWe are pleased to confirm that your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}) is packed and ready for shipment. Please find the relevant shipment details attached.\n\nRegards,\n{sender_name}\n{sender_title}',
   '{company_name} | {company_email} | {company_phone}'),
  ('bl_copy_sent', 'Bill of Lading Copy — Order {order_reference}',
   'Dear {buyer_contact_person},\n\nPlease find attached the scanned copy of the Bill of Lading for your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}). BL originals will be couriered upon clearance of the balance payment, per the agreed terms.\n\nRegards,\n{sender_name}\n{sender_title}',
   '{company_name} | {company_email} | {company_phone}'),
  ('balance_receipt_confirmation', 'Balance Payment Received — Order {order_reference}',
   'Dear {buyer_contact_person},\n\nWe confirm receipt and clearance of the balance payment for your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}). Thank you for your business — we are proceeding with final despatch formalities.\n\nRegards,\n{sender_name}\n{sender_title}',
   '{company_name} | {company_email} | {company_phone}');

-- ================================================================
-- ASSETS — placeholder images generated for every slot (see /assets
-- screen). Paths below assume STORAGE_BASE_PATH = <project>/storage;
-- adjust if you set a different STORAGE_BASE_PATH in .env.
-- ================================================================
-- Real company assets, extracted from the source Word document set
-- (Set 1's embedded media) rather than generic placeholders.
INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'logo', 'NexaCrest Logo', '__STORAGE_BASE_PATH__/assets/logos/logo.jpg', 'image/jpeg', 1, u.id
FROM users u WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'signature', 'Gulmohar Sontakke — Signature (legacy global slot, superseded by user_signature_assets)', '__STORAGE_BASE_PATH__/assets/signatures/gulmohar_sontakke_signature_default.png', 'image/png', 1, u.id
FROM users u WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'seal', 'Company Seal', '__STORAGE_BASE_PATH__/assets/seals/company_seal.png', 'image/png', 1, u.id
FROM users u WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'watermark', 'Watermark — Logo', '__STORAGE_BASE_PATH__/assets/watermarks/watermark_logo.jpg', 'image/jpeg', 1, u.id
FROM users u WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'email_header', 'Email Header — Logo', '__STORAGE_BASE_PATH__/assets/email_headers/email_header_logo.jpg', 'image/jpeg', 1, u.id
FROM users u WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

-- ================================================================
-- SIGNATORIES & DESIGNATIONS (Section M, added 2026-09-20)
-- The company seal above stays the single shared company asset. The two
-- Directors below each carry their own real signature/designation-seal
-- images, extracted from the source documents (Gulmohar Sontakke) and
-- supplied directly by the company (Arti Sontakke).
-- ================================================================
-- 'Director' kept as a generic, unused-by-default title for any future
-- signatory who isn't a protected founder account. Gulmohar and Arti each
-- get their own specific designation below instead, since that title is
-- what's printed on generated documents next to their signature/seal.
INSERT INTO designations (title, is_active) VALUES
  ('Director', 1),
  ('Founder & Managing Director', 1),
  ('Founder & Executive Director', 1);

-- Gulmohar Sontakke already exists as the seeded Admin login
-- (gulmohar.sontakke@nexacrestinternational.com) — mark her signatory-eligible with her
-- own designation and set her as the company's global default signatory,
-- matching the existing company_settings.md_name/md_title.
UPDATE users u
JOIN designations d ON d.title = 'Founder & Managing Director'
SET u.designation_id = d.id, u.is_signatory_eligible = 1
WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

-- Role: Executive Director, matching her real designation — she IS one of
-- the company's two founders and will be marked is_protected_account below
-- (Section Z), so this is a one-time correction, not something meant to be
-- revisited from the Users screen the way an ordinary account's role would be.
INSERT INTO users (name, email, phone, password_hash, role_id, designation_id, is_signatory_eligible, is_active, force_password_change, two_fa_enabled)
SELECT 'Arti Sontakke', 'arti.sontakke@nexacrestinternational.com', NULL,
       '$2y$12$SRa3a47hKlgsRGskEZfWJerGPgxLI8jSnVlckkHENnfs9VRao/You',
       r.id, d.id, 1, 1, 1, 0
FROM roles r, designations d WHERE r.name = 'Executive Director' AND d.title = 'Founder & Executive Director';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'signature', 'Default', '__STORAGE_BASE_PATH__/assets/signatures/gulmohar_sontakke_signature_default.png', 'image/png', 1, 1, u.id
FROM users u WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'designation_seal', 'Director Seal', '__STORAGE_BASE_PATH__/assets/designation_seals/gulmohar_sontakke_director_seal.png', 'image/png', 1, 1, u.id
FROM users u WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'designation_seal', 'Director Seal', '__STORAGE_BASE_PATH__/assets/designation_seals/arti_sontakke_director_seal.webp', 'image/webp', 1, 1,
       (SELECT id FROM users WHERE email = 'gulmohar.sontakke@nexacrestinternational.com')
FROM users u WHERE u.email = 'arti.sontakke@nexacrestinternational.com';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'signature', 'Default', '__STORAGE_BASE_PATH__/assets/signatures/arti_sontakke_signature_default.png', 'image/png', 1, 1, u.id
FROM users u WHERE u.email = 'arti.sontakke@nexacrestinternational.com';

-- ================================================================
-- SUPER ADMIN TIER (Section N, added 2026-09-20)
-- Both founders are the initial permanent Super Admins — real-world
-- Gulmohar Sontakke is NexaCrest's Founder & Managing Director and Arti
-- Sontakke its Founder & Executive Director, the obvious first holders of
-- the unrestricted tier. Promote/demote further (non-founder) holders
-- from /super-admin once logged in.
-- ================================================================
UPDATE users SET is_super_admin = 1
WHERE email IN ('gulmohar.sontakke@nexacrestinternational.com', 'arti.sontakke@nexacrestinternational.com');

-- ================================================================
-- PROTECTED FOUNDER ACCOUNTS (Section Z, added 2026-09-23)
-- Locks both founders' identity/role/designation/eligibility/Super Admin
-- status from ever being changed again through the application, by
-- anyone (see schema.sql Section Z for the enforcing trigger and
-- UserController/SuperAdminService/SignatoryController for the matching
-- application-layer refusals). Set LAST, deliberately, after every field
-- above is already correct — once this flag is 1, none of those fields,
-- including this one, can be changed by any INSERT/UPDATE statement,
-- this seed script included.
-- ================================================================
UPDATE users SET is_protected_account = 1
WHERE email IN ('gulmohar.sontakke@nexacrestinternational.com', 'arti.sontakke@nexacrestinternational.com');

-- Global default signatory = Gulmohar Sontakke (matches legacy md_name).
INSERT INTO company_default_signatory (id, user_id, updated_by)
SELECT 1, u.id, u.id FROM users u WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

-- The personal designation seal is DocumentDataAssembler::signatoryBlock()'s
-- default for every document type (confirmed against the real source
-- templates — PI, OC, and this Amendment all carry it), so this row is a
-- harmless, explicit reaffirmation for AMD specifically, not what makes
-- it happen. An admin can still override any document type to the
-- company seal instead from the Signatories screen's per-document-type
-- table if ever needed.
INSERT INTO document_type_signatories (document_type_id, user_id, use_designation_seal, updated_by)
SELECT dt.id, u.id, 1, u.id
FROM document_types dt, users u
WHERE dt.code = 'AMD' AND u.email = 'gulmohar.sontakke@nexacrestinternational.com';

-- ================================================================
-- INTERNAL REFERENCE LIBRARY (Section R, added 2026-09-21)
-- Content here is deliberately derived only from what this app itself
-- already enforces or has seeded elsewhere (stage-gate conditions in
-- StageGateService/OrderController, the payment_presets rows, the
-- company_settings hard-rule rows) — never invented company policy this
-- codebase has no source for. {placeholder} tokens are substituted from
-- live company_settings at render time (InternalReferenceDocRepository),
-- the same convention as bl_type_instruction's {company} token, so this
-- page never goes stale relative to the actual configured values.
-- ================================================================
INSERT INTO internal_reference_docs (document_type_id, content)
SELECT dt.id, '# Stage Gate Reference — 9-Stage Order Lifecycle

Each stage unlocks the next only after its own gate condition is met. Gate conditions are enforced in code (OrderController + StageGateService) — this page is a read-only reference of what those checks are, so any staff member can see at a glance what has to happen before an order can move forward.

## Stage 1 — Enquiry & Quotation
Gate: Quotation (QT) document generated for the order.

## Stage 2 — Buyer Purchase Order
Gate: Buyer''s signed PO reference number recorded against the order.

## Stage 3 — Proforma Invoice
Gate: Advance payment marked CLEARED in NexaCrest''s bank account (not just received — cleared). This is also the point client portal login is auto-provisioned, if the client has an email on file.

## Stage 4 — Order Confirmation
Gate: Buyer''s acknowledgement of the Order Confirmation recorded.

## Stage 5 — Supplier Purchase Order
Gate: Supplier''s signed acknowledgement of the Supplier PO recorded.

## Stage 6 — Freight Payment
Gate: Freight payment (per the Freight Debit Note) marked cleared.

## Stage 7 — Packing & BL Instruction
Gate: Bill of Lading issuance recorded. Packing data must be within the configured quantity-shortfall tolerance, or have a buyer-approval file attached for any shortfall beyond it — see the Wall Reference.

## Stage 8 — Commercial Invoice & Balance
Gate: Balance payment marked cleared in NexaCrest''s bank account.

## Stage 9 — Document Despatch & Closure
Gate: Order manually closed once all final documents are despatched.

A Super Admin can override a stage''s status/lock directly from the order page in an emergency — every override is logged to the audit trail with a mandatory reason.'
FROM document_types dt WHERE dt.code = 'STAGEGATE';

INSERT INTO internal_reference_docs (document_type_id, content)
SELECT dt.id, '# Wall Reference — Hard Rules Quick Lookup

Pin this page. These are the rules the system enforces automatically — this is what to check by eye when something looks off.

## Reference Number Formats
- Master tracking / buyer inquiry ref: {master_tracking_ref_format}
- Client number: {client_number_format}
- Order reference: {order_ref_format}

## Bill of Lading — Non-Negotiable
- {bl_type_instruction}
- Consignee instruction: {bl_consignee_instruction}
- NexaCrest retains all 3 original BLs until the balance T/T is received and cleared — never release before that.

## Quantity Shortfall Tolerance
- Any shipped quantity within {quantity_shortfall_tolerance_pct}% of the ordered quantity needs no special approval.
- Beyond that tolerance, packing cannot be saved without a buyer-approval file attached — no exceptions, no verbal approvals.

## Dispute Response Deadline
- A logged dispute''s response is due {dispute_response_days_n} WORKING days from the notice date — not calendar days. Weekly off-day: {weekly_off_days}. Check the Holiday Calendar for any dates in between.

## Document Integrity
- Every generated document snapshots the company/bank/LUT details and signatory in force at generation time — a later settings change never rewrites a document that''s already out for review or approval.
- Regenerating an earlier-stage document (e.g. a new QT revision) after a later-stage document already exists triggers an on-screen warning — review whether the later document needs regenerating too.

## Protected Fields
- Reference-number formats, the BL hard rule, and other flagged settings/payment presets/T&C clauses require an explicit unlock plus a written reason before they can be edited. Every unlock and every edit is in the audit log.'
FROM document_types dt WHERE dt.code = 'WALLREF';

INSERT INTO internal_reference_docs (document_type_id, content)
SELECT dt.id, '# Cross-Verification Checklist 1 of 4 — Sales / Documentation Officer

Use this checklist when preparing and issuing: Quotation → PI → Order Confirmation.

## Master Tracking Number
- ⚠ MASTER TRACKING NUMBER — BUYER INQUIRY REF: [NC/SC/YYYY/DDMMNNN]. This number must appear on EVERY document for this order: QT · PI · OC · PL · CI · FDN · BL Instruction. Verify it matches on every document before issuing. This is the single reference by which the complete documentation set for any order can be retrieved.

## A. Quotation Issued
- Buyer Inquiry REF on Quotation matches master tracking number NC/SC/YYYY/DDMMNNN — this is the master number for the entire order ⚠ CRITICAL: if Buyer Inquiry REF is missing or wrong — correct before sending
- Order acceptance confirmed before PI is issued (Accepted by one of: (1) Signed Buyer PO returned to NexaCrest / (2) Written email acceptance from buyer / (3) WhatsApp confirmation (screenshot saved). At least ONE must be on file before PI is issued.) ⚠ Do not issue PI without written acceptance on file in any form
- Quotation number follows format SC/QT/YYYY/DDMMNNN (e.g., SC/QT/2026/0409001)
- Buyer legal name confirmed — exact spelling as per their company registration (Spelling error here propagates to all downstream documents)
- Buyer VAT/EORI/Tax Reg. No. collected (UK: EORI | France: SIRET+TVA | Norway: Org.No+MVA)
- COO type confirmed with buyer — GSP Form A (preferential) or Non-preferential — record buyer''s answer
- Certificate of Origin type recorded on Quotation buyer section
- Incoterm stated explicitly with port name — not just ''FOB'' — must say ''FOB Chennai, India''
- Port of Discharge matches buyer''s stated destination
- Freight row completed correctly — FOB: NIL. CFR/CIF: indicative range + ''IN ADVANCE'' and ''BEFORE booking'' language present ⚠ NEVER omit freight advance payment condition on CFR/CIF orders
- HS Code: 6802.93 for all granite/monument products ⚠ Different product = verify HS Code before issuing
- Quotation validity: 30 days stated
- Payment terms: 40% advance T/T + 60% against scanned BL (Tier 2) OR before shipment (Tier 1)

## B. PI Issued (After Buyer Accepts Quotation In Writing)
- Buyer Inquiry REF on PI matches master tracking number exactly (NC/SC/YYYY/DDMMNNN — must be identical to Quotation) ⚠ CRITICAL: mismatch breaks the entire order tracking chain
- PI number follows format SC/PI/YYYY/DDMMNNN
- PI date set correctly — not pre-dated or post-dated
- PI Valid Until date correctly calculated as 15 days from PI date (e.g., PI dated 04 Sep 2026 → Valid Until must be 19 Sep 2026. Check the amber validity row below the meta bar before sending.) ⚠ Wrong validity date means buyer may miss the payment window or you cannot enforce the expiry
- Quotation reference number on PI matches issued quotation
- Buyer''s PO / Ref No. recorded on PI if buyer provided one (If buyer provided their own PO number — it must appear on the PI. If buyer has no PO number — write NIL. Never leave this field blank.)
- All buyer details match Quotation character-for-character — Name, address, VAT/EORI — no abbreviations introduced
- Product description, size, finish, HS Code match Quotation exactly ⚠ Any deviation from Quotation requires buyer written approval before PI is issued
- Unit price and quantities match Quotation
- FOB Value = Qty × Unit Price correctly calculated
- Freight row: NIL for FOB / indicative amount for CFR-CIF with advance payment condition
- Total PI Value = FOB + Freight + Insurance
- 40% advance amount = Total PI Value × 0.40 — calculated and stated
- 60% balance amount = stated (note: calculated on actual CI value, may differ if shipped short)
- LUT Order No. ZD290626057408W on PI — check it''s current year''s number ⚠ LUT expires 31 March each year — new number required from 1 April
- Bank details on PI: Account No. 44523788330 · SWIFT SBININBB949 · IFSC SBIN0064074 · Pincode 560095 — verify all four before sending PI to buyer (Any error in bank details means buyer''s wire transfer fails or goes to wrong account.)
- RBI Purpose Code P0103 printed in PI bank details section (Buyers must quote P0103 in the ''Purpose of Remittance'' field of their wire transfer form when paying the 40% advance. Without this, SBI may hold or return the payment. Verify it is visible in the bank details section before sending PI to buyer.) ⚠⚠ Missing purpose code = risk of payment delay or return by SBI
- Three legal protection clauses present in PI T&C (Verify PI T&C contains all three: PAYMENT TERMS FINALITY, DISPUTE RESOLUTION & PUBLIC COMMUNICATIONS, and ACCEPTANCE & ORDER OF PRECEDENCE. These are mandatory on every PI issued. If missing — do not send PI until clauses are added.) ⚠⚠ Missing clauses = NexaCrest has no contractual protection against payment term disputes or public naming
- PI signed and sealed before sending

## A2. Annexure A (If Applicable)
- If Annexure A is attached — Annexure shows correct document number (QT or PI number) (Quotation: QT number on Annexure. PI: PI number on Annexure. Never carry over old number.)
- If Annexure A is attached — product descriptions in Annexure match product table exactly (Any mismatch = confusion for buyer. Fix before sending.)
- If Annexure A is attached — Annexure reference line present in T&C of QT/PI
- If Annexure A is attached — Annexure is signed and sealed by Gulmohar Sontakke (Unsigned Annexure is a hanging document with no legal authority. Never send unsigned.) ⚠ An unsigned Annexure can be modified by anyone — always sign before sending
- If Annexure A is attached — Annexure PDF is merged with main QT/PI PDF into ONE file before sending to buyer (Export QT/PI to PDF → Export Annexure to PDF → Merge into one PDF → Send ONE file to buyer. Never send as two separate files.) ⚠ Buyer must receive a single PDF — not two separate documents
- If NO Annexure — delete the Annexure reference line from T&C before sending ⚠ Sending a document that says Annexure is attached when it is not = unprofessional and confusing

## C. 40% Advance Received — Before Production Starts
- Bank remittance copy received from buyer
- Amount matches PI 40% advance figure exactly ⚠ If amount differs — clarify with buyer before starting production
- Payment references correct PI number
- Amount confirmed cleared in NexaCrest SBI account (not just received — cleared)
- Production commencement confirmed in writing to buyer

## D. Order Confirmation Issued (If Buyer Requests)
- OC number follows format SC/OC/YYYY/DDMMNNN Rev.00
- PI reference number matches
- Advance amount received stated correctly
- COO type confirmed on OC (matches buyer''s instruction at Quotation stage)
- CFR/CIF freight note present if applicable

## Sign-Off
- Fields to complete on the physical/filed checklist: Completed by ______ · Date ______ · Shipment Ref ______ · PI No ______'
FROM document_types dt WHERE dt.code = 'CHECKLIST';

INSERT INTO internal_reference_docs (document_type_id, content)
SELECT dt.id, '# Cross-Verification Checklist 2 of 4 — Accounts / Finance

Use this checklist to verify all payments before and after shipment.

## Master Tracking Number
- ⚠ MASTER TRACKING NUMBER — BUYER INQUIRY REF: [NC/SC/YYYY/DDMMNNN]. Verify this number appears on all payment remittances and all documents for this order before processing any payment or issuing any document.

## A. Before Production — 40% Advance
- Bank remittance copy filed against PI number
- Amount received = PI Total × 0.30 (within rounding) ⚠ If less than 40% — production must NOT start. Escalate to MD.
- Payment cleared in NexaCrest SBI account — not just received (Check bank statement or NetBanking — not just the buyer''s remittance copy)
- Receipt acknowledged to buyer with PI reference
- Buyer quoted RBI Purpose Code P0103 on remittance — if not, inform SBI proactively (Check buyer''s remittance copy for Purpose Code P0103. If missing, contact SBI with the PI reference and explain it is an export advance payment so the payment is correctly mapped. Do not wait for the bank to raise a query — resolve proactively.)

## B. CFR/CIF Orders Only — Freight Debit Note
- Freight Debit Note number follows format SC/FDN/YYYY/DDMMNNN Rev.00 (Skip this section for FOB orders)
- Freight amount confirmed from freight forwarder quote — not estimated
- GST treatment confirmed with CA: NIL (cost reimbursement) or 18% IGST
- Freight Debit Note issued to buyer — payment deadline 3 working days stated
- RBI Purpose Code P0602 printed in FDN bank details (Buyers must quote P0602 in the ''Purpose of Remittance'' field when paying the FDN. P0602 covers freight and insurance recovery relating to export of goods. Verify it is visible in the FDN before sending.)
- Freight payment received and cleared BEFORE shipment booking confirmed ⚠ NEVER confirm shipment booking until freight payment is cleared. No exceptions.
- Freight amount on Freight Debit Note matches freight line on Commercial Invoice

## C. Commercial Invoice — Value Verification
- CI FOB Value = sum of all product line amounts on CI
- CI FOB Value matches PI FOB Value (or less if shipped short — within 5% tolerance) ⚠ CI FOB cannot exceed PI FOB. If it does — error on CI. Correct before issuing.
- Quantity shipped ≤ PI quantity — never over ⚠ HARD RULE: quantity over PI is not permitted under any circumstances
- Shortfall ≤5%: acceptable — CI on actuals, buyer notified in writing before shipment
- Shortfall >5%: PI amendment + buyer written approval obtained before shipment
- Total Invoice Value = FOB + Freight + Insurance (as applicable)
- 40% advance deducted correctly in Payment Settlement section
- Freight Debit Note deducted correctly (if CFR/CIF)
- 60% Balance Due = Total Invoice Value − Advance − Freight paid
- Verification row: Advance + Freight + Balance = Total Invoice Value (Arithmetic must balance exactly — check before issuing CI)
- Amount in Words matches Total Invoice Value figure
- LUT Order No. ZD290626057408W on CI — current year''s number ⚠ Update every April — wrong LUT number can invalidate IGST refund claim

## D. After Shipment — 60% Balance
- Scanned BL copy received from CHA
- Scanned BL copy sent to buyer immediately (Buyer needs this to initiate their 60% T/T payment)
- 7-day countdown started from BL date (Tier 2 only) (Follow up on Day 5 if payment not received)
- RBI Purpose Code P0102 printed in CI bank details section (Buyers must quote P0102 in the ''Purpose of Remittance'' field when paying the 60% balance. P0102 covers realisation of export bills for goods. Verify it is visible in the CI bank details before sending CI to buyer.)
- 60% balance received and cleared in NexaCrest SBI account
- Amount matches CI 60% Balance Due exactly ⚠ If buyer pays less — do not release BL originals. Clarify shortfall first.
- BL originals NOT released until 60% is confirmed cleared ⚠ NEVER instruct CHA or shipping line to release cargo before 60% is cleared
- Payment receipt filed against CI number

## E. Annual Compliance Checks
- LUT renewed before 1 April each year — new Order Number updated on all templates ⚠ If LUT expired: cannot export zero-rated. Renew immediately.
- RCMC validity checked 60 days before expiry — renewal initiated ⚠ If RCMC expired: CAPEXIL cannot issue COO. Shipment documents incomplete.

## Sign-Off
- Fields to complete on the physical/filed checklist: Completed by ______ · Date ______ · Shipment Ref ______ · PI No ______'
FROM document_types dt WHERE dt.code = 'CHECKLIST_2_FINANCE';

INSERT INTO internal_reference_docs (document_type_id, content)
SELECT dt.id, '# Cross-Verification Checklist 3 of 4 — Packing / Dispatch Supervisor

Use this checklist during and after packing — before cargo leaves factory/warehouse.

## Master Tracking Number
- ⚠ MASTER TRACKING NUMBER — BUYER INQUIRY REF: [NC/SC/YYYY/DDMMNNN]. Verify this number is on the Packing List before packing begins. Every crate shipping mark must reference this order.

## A. Before Packing Starts
- PI received and reviewed — product description, size, finish, quantity confirmed
- Production matches PI specification: stone type, finish, dimensions ⚠ Any deviation from PI specification must be approved by Sales before packing
- Packing material available: wooden crates, fumigation-ready
- Fumigation arranged — certificate to be collected after fumigation (Fumigation is standard for every NexaCrest shipment — not optional)

## B. During Packing — Quantity Control
- ⚠ HARD RULE: Actual quantity packed must NEVER exceed PI quantity. If packing produces more than PI quantity — remove excess. Do not pack over PI quantity. Shortfall ≤5% from PI quantity: acceptable — note actual quantity packed. Shortfall >5% from PI quantity: STOP — notify Sales immediately before completing packing.
- Running total of quantity packed tracked against PI quantity (Stop when PI quantity is reached — do not continue)
- Each crate contents recorded: product, quantity (pcs/m²), dimensions
- Crate dimensions measured after packing: L × W × H in cm
- Net weight of each crate recorded (stone only)
- Gross weight of each crate recorded (stone + packaging)
- CBM of each crate calculated: L × W × H ÷ 1,000,000

## C. Crate Marking
- Each crate stencilled with correct shipping marks in this exact format: Line 1: NEXACREST; Line 2: [BUYER NAME — exact as on PI]; Line 3: [PORT OF DISCHARGE — e.g., TILBURY UK]; Line 4: C-NNN/TOTAL (e.g., C-001/010 for crate 1 of 10); Line 5: [PI NUMBER — e.g., SC/PI/2026/0001]; Line 6: MADE IN INDIA
- Crate numbers sequential: C-001/010 through C-010/010 (No gaps, no duplicates)
- Buyer name on crates matches PI buyer name exactly (Character-for-character — no abbreviations)
- Port of discharge on crates matches PI/BL Instruction Sheet port

## D. Packing List Preparation Data
- Total quantity packed recorded (m² or pcs)
- Total net weight recorded
- Total gross weight recorded
- Total CBM recorded
- Total number of crates recorded
- Variance from PI estimates checked: Quantity must be ≤ PI quantity — if >5% short from PI, notify Sales; CBM within ±10% of PI estimate is acceptable — if >10% variance, notify Sales; Weight within ±10% of PI estimate is acceptable
- All packing data handed to Documentation Officer to complete Packing List
- Fumigation certificate collected and handed to Documentation Officer

## Sign-Off
- Fields to complete on the physical/filed checklist: Completed by ______ · Date ______ · Shipment Ref ______ · PI No ______'
FROM document_types dt WHERE dt.code = 'CHECKLIST_3_PACKING';

INSERT INTO internal_reference_docs (document_type_id, content)
SELECT dt.id, '# Cross-Verification Checklist 4 of 4 — Shipping / Logistics Coordinator (BL / COO)

Use this checklist from Packing List finalisation through BL endorsement and document courier to buyer.

## Master Tracking Number
- ⚠ MASTER TRACKING NUMBER — BUYER INQUIRY REF: [NC/SC/YYYY/DDMMNNN]. Verify this number appears on PL, CI, BL Instruction, and all shipping documents before sending to CHA. Single reference to retrieve entire order documentation.

## A. Packing List — Verify Before Issuing
- PL number follows format SC/PL/YYYY/DDMMNNN Rev.00
- PL date = date packing completed and actuals confirmed
- PI reference on PL matches
- Buyer name/address on PL matches PI character-for-character
- Product description on PL matches PI and CI exactly
- HS Code on PL matches PI and CI: 6802.93 (or correct code per product)
- Actual quantity on PL ≤ PI quantity ⚠ HARD RULE — quantity over PI not permitted
- Crate count on PL matches physical count of sealed crates
- Shipping marks on PL match marks stencilled on physical crates

## B. BL Instruction Sheet — Before Sending to CHA
- BL Instruction Sheet completed — all fields filled
- Buyer name on BL instruction matches PI/PL character-for-character
- ⚠ MANDATORY — BL TYPE: Original Negotiable Bill of Lading — 3 Originals ONLY. NEVER request Sea Waybill or Express BL. If CHA suggests otherwise — refuse and escalate to MD. BL Consignee: TO ORDER OF NEXACREST INTERNATIONAL PRIVATE LIMITED. Reason: Buyer cannot collect cargo until NexaCrest endorses and releases the original BL. NexaCrest retains all 3 originals until 60% balance T/T is cleared.
- Freight terms on BL instruction: Freight Collect (FOB) or Freight Prepaid (CFR/CIF)
- BL Instruction Sheet sent to CHA
- Draft BL received from CHA and approved by NexaCrest in writing before originals are issued (CHA must send the draft BL to NexaCrest for written approval before issuing any original BL. Check every field: consignee (TO ORDER OF NEXACREST), BL type (Original Negotiable), freight terms, port names, vessel, crate count. Reply in writing (email) confirming approval. Original BL must NOT be issued without this written approval.) ⚠⚠ Never let CHA issue original BL without NexaCrest''s written approval — errors on original BL are costly to correct

## C. Shipping Bill — CHA Files With Indian Customs
- CHA confirms Shipping Bill filed before COO application initiated ⚠ COO cannot be applied before Shipping Bill is filed — critical sequence
- Shipping Bill FOB value matches CI FOB value exactly
- Shipping Bill HS Code matches CI and PL

## D. COO — Apply Via CAPEXIL After Shipping Bill Filed
- COO type confirmed: GSP Form A or Non-preferential (per buyer''s instruction)
- COO Preparation Sheet completed — all fields match CI and PL
- COO application submitted to CAPEXIL (via CHA or directly)
- COO received from CAPEXIL — details verified against CI

## E. BL Received From CHA
- 3 original BLs received from CHA — count physically ⚠ If fewer than 3 originals received — contact CHA immediately
- BL type confirmed: Original Negotiable BL (not Sea Waybill)
- BL consignee reads: TO ORDER OF NEXACREST INTERNATIONAL PRIVATE LIMITED
- BL date matches CI date
- BL vessel name, voyage, port of loading/discharge match BL Instruction Sheet
- BL crate count matches PL crate count exactly
- BL gross weight matches PL gross weight
- Scanned copy of BL sent to buyer immediately (Buyer needs this to initiate 60% balance T/T payment)
- Scanned copy of BL sent to Accounts to start 7-day countdown
- All 3 original BLs stored securely at NexaCrest — NOT given to buyer or CHA

## F. 60% Balance Confirmed Cleared — BL Endorsement
- ONLY after Accounts confirms 60% balance T/T is CLEARED in NexaCrest SBI account. Do not endorse or courier BL originals based on buyer''s remittance copy alone. Wait for bank clearance confirmation — then proceed.
- BL ENDORSEMENT — on the BACK of each of the 3 original BLs: apply NexaCrest rubber company stamp; sign over the stamp: Gulmohar Sontakke (wet signature or rubber signature stamp); write date of endorsement; write: For NexaCrest International Private Limited. All 3 originals must be endorsed before couriering. Check all 3 — not just one.
- All 3 original BLs endorsed (stamp + signature + date on back of each)
- Endorsed originals couriered to buyer with tracking number recorded

## G. Final Document Set to Buyer
- Commercial Invoice (signed copy)
- Packing List (signed copy)
- 3 original BLs (endorsed)
- Certificate of Origin (CAPEXIL)
- Fumigation Certificate
- All documents couriered together — courier tracking number recorded

## Sign-Off
- Fields to complete on the physical/filed checklist: Completed by ______ · Date ______ · Shipment Ref ______ · PI No ______

## Master Hard Rules — Nexacrest Export Operations (All Roles)
- Non-negotiable rules. No exceptions. Any deviation must be escalated to MD before proceeding.
- QUANTITY: Actual quantity shipped NEVER exceeds PI quantity. Hard stop — no exceptions. Shortfall ≤5%: acceptable. Shortfall >5%: PI amendment + buyer approval required before shipment.
- PAYMENT — PRODUCTION: Production starts ONLY after 40% advance T/T is confirmed CLEARED in NexaCrest SBI account. Not on remittance copy. Not on buyer''s word. Cleared.
- PAYMENT — FREIGHT: CFR/CIF only — Freight Debit Note must be paid and cleared BEFORE shipment booking is confirmed. NexaCrest cannot book container until freight is settled.
- PAYMENT — BALANCE: 60% balance T/T must be CLEARED in NexaCrest account before BL originals are endorsed or released. Scanned BL copy is sent to buyer to initiate payment. Originals are held until cleared.
- BILL OF LADING: Always Original Negotiable BL — 3 originals. Consignee: TO ORDER OF NEXACREST INTERNATIONAL PRIVATE LIMITED. NEVER Sea Waybill or Express BL. CHA hands all 3 originals to NexaCrest only.
- BL ENDORSEMENT: Endorse (rubber stamp + sign + date on back) all 3 originals only AFTER 60% balance is cleared. Courier endorsed originals to buyer. Never courier un-endorsed originals.
- DOCUMENT MATCHING: Buyer name/address must match character-for-character across ALL documents: Quotation, PI, PL, CI, BL, COO. Any mismatch = customs problem. Fix before issuing next document.
- HS CODE: 6802.93 for all worked monumental granite products. Must be identical on PI, PL, CI, COO, and Shipping Bill. Different product: verify HS code before issuing.
- LUT: LUT Order No. ZD290626057408W on every Commercial Invoice. Valid FY 2026-27 only. New LUT required before 1 April every year. Wrong/expired LUT = IGST refund rejected.
- RCMC: Check RCMC validity 60 days before expiry. Expired RCMC = CAPEXIL cannot issue COO = shipment documents incomplete = customs hold at destination.
- COO SEQUENCE: COO application to CAPEXIL only AFTER Shipping Bill is filed by CHA. Never before. Sequence: Packing done → CHA files Shipping Bill → then apply for COO.
- DRAFT BL APPROVAL: CHA must send draft BL to NexaCrest for written approval before issuing any original BL. NexaCrest checks: consignee wording (TO ORDER OF NEXACREST), BL type (Original Negotiable), freight terms, port names, vessel, crate count. Written approval (email) required before CHA proceeds. Original BL issued without approval = risk of uncorrectable errors.
- SHORTFALL NOTIFICATION: If actual quantity is short of PI quantity (even within 5% tolerance), notify buyer in writing BEFORE shipment. Never ship short without informing buyer.
- BUYER INQUIRY REF: NC/SC/YYYY/DDMMNNN is the MASTER TRACKING NUMBER for every order. It must appear on EVERY document: QT, PI, OC, PL, CI, FDN, BL Instruction. Verify at every stage. This is the single number by which the complete documentation set for any order can be retrieved at any time.

## Pre-Shipment Checklist — Final Gate Check
- Complete this checklist BEFORE sending BL Instruction Sheet to CHA. Sign and file. Do not proceed if any item fails. This is the last internal check before cargo is handed to the shipping line. If any item below cannot be ticked — STOP and resolve before proceeding.

## A. Documents — All Prepared and Verified
- Commercial Invoice signed, sealed, LUT number correct (CI date will be set to BL date — leave blank until BL is confirmed)
- Packing List signed, sealed, actuals match physical cargo
- BL Instruction Sheet completed — all fields filled, Original Negotiable BL specified
- COO type confirmed with buyer and noted on all documents
- Fumigation Certificate obtained and filed

## B. Quantity and Cargo — Final Confirmation
- Actual quantity packed ≤ PI quantity — confirmed ⚠ HARD RULE: quantity over PI not permitted. Stop if exceeded.
- Shortfall (if any) ≤5% — buyer notified in writing ⚠ If >5% short: PI amendment + buyer written approval obtained before proceeding
- Crate count confirmed — matches Packing List
- Crate markings verified against Packing List marks column
- Cargo sealed and ready for pick-up

## C. Payments — Confirmed Before Shipment Booking
- 40% advance confirmed CLEARED in NexaCrest SBI account ⚠ Not on remittance copy. Bank statement confirms cleared.
- Freight Debit Note payment confirmed CLEARED (CFR/CIF orders only) (FOB orders: skip this item)
- Freight Debit Note amount matches freight line on Commercial Invoice

## D. BL Instruction — Confirmed Before Sending to CHA
- ⚠ BL TYPE FINAL CHECK: Original Negotiable BL — 3 originals ONLY. Consignee on BL: TO ORDER OF NEXACREST INTERNATIONAL PRIVATE LIMITED. NEVER Sea Waybill. NEVER Express BL. If in doubt — call MD before sending instruction.
- BL type confirmed: Original Negotiable BL — 3 originals
- BL consignee wording confirmed: TO ORDER OF NEXACREST INTERNATIONAL PRIVATE LIMITED
- Freight terms on BL instruction correct: Freight Collect (FOB) or Freight Prepaid (CFR/CIF)

## E. Post-Shipment Actions Noted
- Reminder set: collect 3 original BLs from CHA after vessel departure
- Reminder set: apply for COO via CAPEXIL after Shipping Bill is filed by CHA
- Reminder set: send scanned BL copy to buyer and Accounts immediately on receipt
- Reminder set: 60% balance due within 7 days of BL date (Tier 2) or before shipment (Tier 1)

## Pre-Shipment Sign-Off
- Fields to complete on the physical/filed checklist: PI No. ______ · Buyer ______ · Completed by ______ · Designation ______ · Date ______ · Shipment ready date ______'
FROM document_types dt WHERE dt.code = 'CHECKLIST_4_SHIPPING';

INSERT INTO internal_reference_docs (document_type_id, content)
SELECT dt.id, '# SOP — Sales Process, Tier A (Standard — New Buyer)

Applies to the "Standard — New Buyer" payment preset: 40% advance / 60% balance, balance payable before shipment, no MD approval required to use this preset.

1. Quotation-stage intake form received — staff reviews the request in the Client Requests queue.
2. Staff manually accepts the request, creating the client record.
3. Staff generates the Quotation (QT) — this is Stage 1''s gate.
4. Buyer returns a signed PO — record the reference number (Stage 2 gate).
5. Generate the Proforma Invoice (PI) and send it for advance payment.
6. Once the advance is received AND cleared in the bank account, mark it cleared (Stage 3 gate) — this also auto-provisions the client''s portal login.
7. Generate the Order Confirmation (OC); record the buyer''s acknowledgement (Stage 4 gate).
8. Continue through Supplier PO, Freight, Packing/BL, Commercial Invoice, and Closure per the Stage Gate Reference.

No MD approval step is required anywhere in this tier — a new buyer on this preset moves through the stages purely on staff sign-off, since the balance is secured before shipment.'
FROM document_types dt WHERE dt.code = 'SOP_A_SALES';

INSERT INTO internal_reference_docs (document_type_id, content)
SELECT dt.id, '# SOP — Sales Process, Tier B (Established Buyer — Post-BL)

Applies to the "Established Buyer — Post-BL" payment preset: 40% advance / 60% balance, balance payable against the Bill of Lading, requires MD approval to use this preset for a given order.

Follow the same 9-stage flow as Tier A, with two differences:

1. Before this preset can be applied to an order, MD approval is required — do not proceed past the advance/balance terms step without it recorded.
2. The balance due date is computed from the BL issuance date, not from advance clearance — do not release any of the 3 original Bills of Lading to the buyer until the balance T/T is received and cleared in NexaCrest''s bank account. This is the entire point of the Bill of Lading hard rule (see Wall Reference) — releasing the BL early gives up NexaCrest''s only financial leverage over an established buyer''s balance payment.

Everything else — Quotation through Closure — follows the same Stage Gate Reference as Tier A.'
FROM document_types dt WHERE dt.code = 'SOP_B_SALES';

-- ================================================================
-- HS CODE MASTER LIST (Section AB) — seeded with the one code the app
-- used to default to ('6802.93', wrong format — a dot, not a plain 6/8
-- digit string). Migrated here as '680293'. Add your real remaining
-- codes from /hs-codes once logged in; this is a starting point, not a
-- complete tariff list.
-- ================================================================
INSERT INTO hs_codes (code, description, is_active, created_by)
SELECT '680293', 'Worked monumental or building stone (granite, etc.) and articles thereof', 1, u.id
FROM users u WHERE u.email = 'gulmohar.sontakke@nexacrestinternational.com';

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
-- POST-IMPORT STEP (manual, not part of this SQL file):
-- Replace the literal string __STORAGE_BASE_PATH__ in the assets table
-- with your actual STORAGE_BASE_PATH (the same value as in .env), e.g.:
--   UPDATE assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/absolute/path/to/storage');
-- ================================================================
