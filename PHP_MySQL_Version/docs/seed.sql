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
  ('manage_signatories',        'Manage signatories & designations', 'Manage the designations list, mark a user as signatory-eligible, upload their signature/designation-seal images, and set the global and per-document-type default signatory.', 'admin');

-- ================================================================
-- ROLE_PERMISSIONS — first-cut matrix (see note above)
-- ================================================================
INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'Managing Director');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Export Executive'
  AND p.permission_key IN ('manage_orders','generate_documents','download_pdf','view_reports','view_client_email_full','cross_verify_documents');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Accounts Executive'
  AND p.permission_key IN ('manage_orders','download_pdf','view_reports','view_client_email_full','cross_verify_documents');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Logistics Executive'
  AND p.permission_key IN ('manage_orders','generate_documents','download_pdf','cross_verify_documents');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Viewer / Auditor'
  AND p.permission_key IN ('view_reports','view_audit_log');

-- ================================================================
-- USERS — one seeded account to get in the door.
-- Email/password are PLACEHOLDERs — change both immediately.
-- Password below is "ChangeMe#2026" (bcrypt hash) — force_password_change=1
-- means you'll be made to set a new one on first login regardless.
-- ================================================================
INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
SELECT 'Gulmohar Sontakke', 'admin@nexacrest.placeholder', NULL,
       '$2y$12$SRa3a47hKlgsRGskEZfWJerGPgxLI8jSnVlckkHENnfs9VRao/You',
       r.id, 1, 1, 0
FROM roles r WHERE r.name = 'Admin';

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
  ('CHECKLIST', 'Cross-Verification Checklist',        'internal',        NULL,                        1, 0, 1, 1),
  ('AMD',       'Payment Terms Amendment',              'internal',       'SC/AMD/{YYYY}/{DDMM}{NNN}', 1, 1, 1, 1),
  ('SOP_A_SALES', 'SOP — Sales Process (Tier reference)', 'internal',     NULL,                        1, 1, 1, 1),
  ('SOP_B_SALES', 'SOP — Sales Process (Tier reference)', 'internal',     NULL,                        1, 1, 1, 1),
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
  (10, 'Natural Stone Characteristics', 'All granite supplied is Grade A quality, selected for colour consistency. As with all natural stone, minor variation in shade, grain and veining may occur between pieces — however, visible dramatic colour difference between components of the same order will not occur. Products will conform to the agreed specification.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Natural Stone Characteristics' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (20, 'Quantity Tolerance', 'Actual quantity shipped will not exceed the ordered quantity. A shortfall of up to {tolerance}% may occur due to natural stone production characteristics and will be invoiced at actual quantity shipped. Buyer will be notified in writing before shipment of any shortfall exceeding {tolerance}%, and shipment will require buyer''s written approval.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Quantity Tolerance' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (30, 'Cancellation', 'Orders may not be cancelled after production has commenced. Cancellation before production commencement is subject to written agreement and recovery of costs incurred. The {advance_pct}% advance is non-refundable once production has commenced.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Cancellation' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (40, 'Partial Shipment', 'Not permitted. NexaCrest ships complete orders only — minimum one full FCL. Mixed-product containers (multiple products in one FCL) are permitted.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Partial Shipment' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (50, 'Import Clearance & Destination Charges', 'Unless expressly stated otherwise under the agreed Incoterm, the buyer is solely responsible for import customs clearance, import duties, taxes, VAT, permits, licences and all destination-country charges. NexaCrest bears no responsibility for destination-side costs or delays.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Import Clearance & Destination Charges' AND dt.code IN ('QT','PI');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (60, 'Production Commencement', 'Production commences only after {advance_pct}% advance payment is received and CLEARED in NexaCrest''s bank account. Remittance copy alone does not constitute payment receipt. Please allow 1–2 banking days for clearance confirmation before expecting production to commence.', 'active', 0);
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
  (90, 'Shipment Booking (CFR/CIF)', 'Shipment booking will be confirmed only after full freight and insurance payment is received and cleared in NexaCrest''s bank account against the Freight Debit Note. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is verified.', 'active', 0);
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
  (95, 'Bill of Lading Release', 'Original negotiable BL (3 originals) will be released to buyer only after {balance_pct}% balance payment is CLEARED in NexaCrest''s bank account. Remittance copy alone does not constitute payment receipt. Please allow 1–2 banking days for clearance confirmation before expecting BL originals to be released.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Bill of Lading Release' AND dt.code IN ('OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (900, 'Payment Terms Finality', 'The payment terms stated in this document are fixed and binding upon acceptance of this document or receipt of any advance payment, whichever occurs first. Neither party shall unilaterally modify, replace, or impose any additional or different payment terms after such acceptance or receipt of advance payment. Any amendment, modification, waiver, or variation of the payment terms shall be valid only if expressly agreed in writing by authorised representatives of both parties. The agreed payment terms may not be amended or waived by any verbal statement, informal communication, partial payment, purchase-order notation, or course of conduct, whether any of these occur individually or in combination. Any such amendment shall be documented in a formal written Payment Terms Amendment Agreement signed by authorised representatives of both parties before the amendment takes effect.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Payment Terms Finality' AND dt.code IN ('QT','PI','OC','BUYERPO');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (910, 'Dispute Resolution & Public Communications', 'Any dispute, disagreement, complaint, or claim arising out of or in connection with this transaction shall first be notified to the other party in writing by email to the contact email address stated in this document. Written notice shall be deemed delivered upon confirmation of receipt by the receiving party, or within 48 hours of sending to the email address stated in this document, whichever is earlier. It is the responsibility of each party to ensure their stated contact email address is active and monitored. The receiving party shall respond within ten (10) working days of deemed delivery and both parties shall make reasonable and good-faith efforts to resolve the matter amicably. Until the dispute is resolved, neither party shall publish or communicate any knowingly false, misleading, defamatory, threatening, or materially disparaging statement concerning the other party, the transaction, or the dispute on social media, review platforms, trade forums, industry groups, websites, or any other public or semi-public channel. Nothing in this clause shall restrict either party from making disclosures strictly required by law or from communicating with courts, arbitral tribunals, governmental authorities, regulators, customs authorities, insurers, banks, legal advisers, auditors, or other professional advisers, or from exercising any legal or contractual right or remedy. A party shall be responsible, to the extent permitted by applicable law, for public communications made by its employees, agents, representatives, or other persons acting on its behalf or with its authority. Any breach of this clause shall entitle the non-breaching party to seek removal or correction of the offending content and any other contractual or legal remedies available under applicable law. This clause shall survive completion or termination of the transaction. The restriction on false, misleading, defamatory, threatening, or materially disparaging communications shall continue indefinitely after resolution of any dispute and shall not expire.', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Dispute Resolution & Public Communications' AND dt.code IN ('QT','PI','OC','BUYERPO');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (920, 'Acceptance & Order of Precedence', 'This document, together with the commercial terms and conditions stated herein, constitutes the commercial basis of the transaction. Acceptance of this document, issuance of any purchase order in connection with this transaction, written confirmation of the order, or payment of any advance amount — whether in full or in part — shall each independently constitute acceptance of all terms and conditions stated in this document. Any term or condition contained in a buyer''s purchase order or other document that conflicts with or varies from the terms stated herein shall not apply unless expressly accepted in writing by authorised representatives of NexaCrest International Private Limited.', 'active', 1);
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
  ('product_image',         'jpg,jpeg,png,webp', 5242880, 'Product image/technical drawing attached to an Annexure A entry.');

-- ================================================================
-- COMPANY_SETTINGS — every key schema.sql reserves for this table.
-- Values marked PLACEHOLDER need real data before documents go live;
-- everything else is a working default you can tune from /settings.
-- ================================================================
-- Company/bank/LUT values below are the REAL values found in your own
-- source documents (01_Quotation.docx, 02_ProformaInvoice.docx), not
-- placeholders — pulled directly during Phase B while building the QT/PI/OC
-- templates against those actual documents. Only bank_pincode wasn't shown
-- in the extracted table text (the bank address string didn't break the
-- pincode out separately) and stays a placeholder below.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('legal_name',            'NexaCrest International Private Limited', 'string', 'company', 'Full legal company name as it appears on all documents.', 0),
  ('registered_office',     'No. 33, T Ramaiah Garden, 2 Hulimavu Village, Hulimavu, Bangalore South, Bengaluru, Karnataka – 560076, India', 'string', 'company', 'Registered office address.', 0),
  ('corporate_office',      'Evolve Work Studio, 4th Floor, The Hub @ Raj Serenity, Khatha No. 10, Begur Koppa Road, Yelenahalli, Bengaluru – 560068, Karnataka, India', 'string', 'company', 'Corporate/working office address, if different.', 0),
  ('gstin',                 '29AAKCN8733G1ZZ',                          'string', 'company', 'GST Identification Number.', 1),
  ('iec_pan',               'AAKCN8733G',                               'string', 'company', 'Import Export Code / PAN.', 1),
  ('md_name',               'Gulmohar Sontakke',                        'string', 'company', 'Founder & Managing Director name, for document signature blocks.', 0),
  ('md_title',              'Founder & Managing Director',              'string', 'company', 'MD title as shown on documents.', 0),
  ('director_name',         'PLACEHOLDER — Director Name',              'string', 'company', 'Director name, if a director signature is used on any document (open question — confirm whether this is needed; QT/PI/OC templates only show the MD signature block).', 0),
  ('director_title',        'Director',                                 'string', 'company', 'Director title as shown on documents.', 0),
  ('phone',                 '+91-7676463030',                           'string', 'company', 'Company contact phone number.', 0),
  ('email',                 'gulmohar.sontakke@nexacrestinternational.com', 'string', 'company', 'Company contact email address.', 0),
  ('bank_name',             'State Bank of India',                      'string', 'bank',    'Bank name for buyer remittances.', 1),
  ('bank_branch',           'Start Up Hub Branch, Koramangala, Bengaluru', 'string', 'bank',  'Bank branch name.', 1),
  ('bank_account_no',       '44523788330',                              'string', 'bank',    'Bank account number.', 1),
  ('swift_bic',             'SBININBB949',                              'string', 'bank',    'SWIFT/BIC code.', 1),
  ('ifsc',                  'SBIN0064074',                              'string', 'bank',    'IFSC code.', 1),
  ('bank_address',          '1st Floor, 117, 7th Block Industrial Layout, Koramangala, Bengaluru – 560095, India', 'string', 'bank', 'Bank branch address.', 1),
  ('bank_pincode',          'PLACEHOLDER-PINCODE',                      'string', 'bank',    'Bank branch pincode (not broken out separately in the source PI template — likely 560095, confirm before relying on it separately from bank_address).', 1),
  ('lut_number',            'ZD290626057408W',                         'string', 'lut',     'Letter of Undertaking (LUT) ARN/number for zero-rated export.', 1),
  ('lut_valid_fy',          'FY 2026-27',                                'string', 'lut',     'Financial year the current LUT is valid for.', 1),
  ('lut_expiry_date',       '2027-03-31',                                'date',   'lut',     'LUT expiry date — placeholder (FY 2026-27 end date assumed as 31 March 2027) — the source PI template states the FY but not an explicit expiry date; confirm the real one.', 1),
  ('rcmc_number',           'PLACEHOLDER-RCMC-NUMBER',                  'string', 'capexil', 'CAPEXIL RCMC (Registration-cum-Membership Certificate) number — required on the COO Preparation Sheet (Phase C). Placeholder — confirm the real number from the RCMC certificate.', 1),
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
  ('fdn_overdue_days_c',    '7',  'number', 'alerts', 'Days after FDN issue before a freight payment is flagged overdue.', 0),
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
  ('dispute_response_days_n', '7', 'number', 'disputes', 'Days allowed for a dispute response before escalation.', 0);

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
-- DOCX_GENERATION_SETTINGS — QT/PI/OC also get an internal-only DOCX copy
-- alongside the buyer-facing PDF (content-parity, not pixel-parity — see
-- README's Phase B scope note).
-- ================================================================
INSERT INTO docx_generation_settings (document_type_id, is_enabled)
SELECT id, 1 FROM document_types WHERE code IN ('QT', 'PI', 'OC');

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
FROM users u WHERE u.email = 'admin@nexacrest.placeholder';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'signature', 'Gulmohar Sontakke — Signature (legacy global slot, superseded by user_signature_assets)', '__STORAGE_BASE_PATH__/assets/signatures/gulmohar_sontakke_signature_default.png', 'image/png', 1, u.id
FROM users u WHERE u.email = 'admin@nexacrest.placeholder';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'seal', 'Company Seal', '__STORAGE_BASE_PATH__/assets/seals/company_seal.png', 'image/png', 1, u.id
FROM users u WHERE u.email = 'admin@nexacrest.placeholder';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'watermark', 'Watermark — Logo', '__STORAGE_BASE_PATH__/assets/watermarks/watermark_logo.jpg', 'image/jpeg', 1, u.id
FROM users u WHERE u.email = 'admin@nexacrest.placeholder';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'email_header', 'Email Header — Logo', '__STORAGE_BASE_PATH__/assets/email_headers/email_header_logo.jpg', 'image/jpeg', 1, u.id
FROM users u WHERE u.email = 'admin@nexacrest.placeholder';

-- ================================================================
-- SIGNATORIES & DESIGNATIONS (Section M, added 2026-09-20)
-- The company seal above stays the single shared company asset. The two
-- Directors below each carry their own real signature/designation-seal
-- images, extracted from the source documents (Gulmohar Sontakke) and
-- supplied directly by the company (Arti Sontakke).
-- ================================================================
INSERT INTO designations (title, is_active) VALUES ('Director', 1);

-- Gulmohar Sontakke already exists as the seeded Admin login
-- (admin@nexacrest.placeholder) — mark her signatory-eligible with the
-- Director designation and set her as the company's global default
-- signatory, matching the existing company_settings.md_name/md_title.
UPDATE users u
JOIN designations d ON d.title = 'Director'
SET u.designation_id = d.id, u.is_signatory_eligible = 1
WHERE u.email = 'admin@nexacrest.placeholder';

-- Role deliberately conservative (Viewer / Auditor, read-only) — this
-- account exists so Arti Sontakke can be a signatory on documents; it does
-- NOT assume her actual operational role in the business. Admin should
-- change role_id from the Users screen to whatever is actually correct.
INSERT INTO users (name, email, phone, password_hash, role_id, designation_id, is_signatory_eligible, is_active, force_password_change, two_fa_enabled)
SELECT 'Arti Sontakke', 'arti.sontakke@nexacrest.placeholder', NULL,
       '$2y$12$SRa3a47hKlgsRGskEZfWJerGPgxLI8jSnVlckkHENnfs9VRao/You',
       r.id, d.id, 1, 1, 1, 0
FROM roles r, designations d WHERE r.name = 'Viewer / Auditor' AND d.title = 'Director';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'signature', 'Default', '__STORAGE_BASE_PATH__/assets/signatures/gulmohar_sontakke_signature_default.png', 'image/png', 1, 1, u.id
FROM users u WHERE u.email = 'admin@nexacrest.placeholder';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'designation_seal', 'Director Seal', '__STORAGE_BASE_PATH__/assets/designation_seals/gulmohar_sontakke_director_seal.png', 'image/png', 1, 1, u.id
FROM users u WHERE u.email = 'admin@nexacrest.placeholder';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'designation_seal', 'Director Seal', '__STORAGE_BASE_PATH__/assets/designation_seals/arti_sontakke_director_seal.webp', 'image/webp', 1, 1,
       (SELECT id FROM users WHERE email = 'admin@nexacrest.placeholder')
FROM users u WHERE u.email = 'arti.sontakke@nexacrest.placeholder';

-- ================================================================
-- SUPER ADMIN TIER (Section N, added 2026-09-20)
-- The seeded admin login is the initial permanent Super Admin — real-world
-- Gulmohar Sontakke is NexaCrest's Founder & Managing Director, the
-- obvious first holder of the unrestricted tier. Promote/demote further
-- holders from /super-admin once logged in.
-- ================================================================
UPDATE users SET is_super_admin = 1 WHERE email = 'admin@nexacrest.placeholder';

-- Global default signatory = Gulmohar Sontakke (matches legacy md_name).
INSERT INTO company_default_signatory (id, user_id, updated_by)
SELECT 1, u.id, u.id FROM users u WHERE u.email = 'admin@nexacrest.placeholder';

-- Per the explicit business rule (Payment Terms Amendment is legally
-- signed by a Director in that capacity, using the personal designation
-- seal) — every other document type falls back to the global default
-- (company seal, standard MD signature block) unless an admin sets
-- another per-document-type row here.
INSERT INTO document_type_signatories (document_type_id, user_id, use_designation_seal, updated_by)
SELECT dt.id, u.id, 1, u.id
FROM document_types dt, users u
WHERE dt.code = 'AMD' AND u.email = 'admin@nexacrest.placeholder';

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
-- POST-IMPORT STEP (manual, not part of this SQL file):
-- Replace the literal string __STORAGE_BASE_PATH__ in the assets table
-- with your actual STORAGE_BASE_PATH (the same value as in .env), e.g.:
--   UPDATE assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/absolute/path/to/storage');
-- ================================================================
