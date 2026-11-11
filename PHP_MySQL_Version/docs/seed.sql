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
  ('Viewer / Auditor',    'Read-only access to reports, audit trail, and the Orders/Clients modules.', 0),
  ('CA / Chartered Accountant', 'External or in-house Chartered Accountant — view-only access to the CA/Accounting module for statutory bookkeeping. No order-management access.', 0);

-- ================================================================
-- PERMISSIONS
-- ================================================================
INSERT INTO permissions (permission_key, name, description, category) VALUES
  ('manage_users',              'Manage users',               'Create/edit/deactivate user accounts and assign roles.', 'admin'),
  ('manage_permissions',        'Manage roles & permissions', 'Edit role/user permission matrix.',                     'admin'),
  ('manage_company_settings',   'Manage company settings',    'Edit company_settings key-value configuration.',        'admin'),
  ('manage_assets',             'Manage assets',               'Upload/replace logo, signature, seal, watermark.', 'admin'),
  ('view_audit_log',            'View audit log',              'View the immutable system audit trail.',                'admin'),
  ('manage_report_definitions', 'Manage saved reports',         'Create/edit/delete saved report definitions.',          'reports'),
  ('view_reports',              'View reports',                 'Run and view reports and dashboards.',                  'reports'),
  ('manage_orders',             'Manage orders',                 'Create/edit orders and advance them through stage gates.', 'orders'),
  ('approve_payment_terms',     'Approve payment terms',         'Approve an order''s payment terms when its preset is marked "Requires MD approval" — needed before the Quotation can be generated or sent. Default: Admin, MD, ED.', 'orders'),
  ('generate_documents',        'Generate documents',            'Generate QT/PI/OC/PL/CI/etc. for an order.',            'documents'),
  ('approve_documents',         'Approve documents',             'Approve/release a generated document to the buyer.',   'documents'),
  ('edit_locked_data',          'Override locked data',          'Edit a field that is normally locked after stage sign-off.', 'documents'),
  ('view_client_email_full',    'View full client email',        'See a client''s full email address (vs a masked view).', 'clients'),
  ('download_pdf',              'Download PDF',                  'Download a generated PDF document.',                    'documents'),
  ('cross_verify_documents',    'Cross-verify documents',        'Add an independent pass/fail quality check on any generated document — separate from the formal reviewer sign-off.', 'documents'),
  ('approve_email_send',        'Approve email send (Level 2)',  'Level-2 approval for a deferred client email before it actually sends (Section 10 — Email & Deferred Send System).', 'documents'),
  ('manage_sample_data',        'Manage sample data',            'Load/clear the Sample Data Playground (test clients/orders only — never real data).', 'admin'),
  ('manage_field_protection',   'Manage field protection',       'Request or approve locking/unlocking a protected field (company setting, T&C clause, or payment preset). Approving your own request is blocked — a different privileged user must confirm.', 'admin'),
  ('delete_assets',             'Delete assets',                 'Permanently remove a superseded (inactive) logo/signature/seal/watermark upload. The currently active asset for a type can never be deleted this way — replace it first.', 'admin'),
  ('manage_signatories',        'Manage signatories & designations', 'Manage the designations list, mark a user as signatory-eligible, upload their signature/designation-seal images, and set the global and per-document-type default signatory.', 'admin'),
  ('view_product_catalog',      'View product catalog',          'Search and view individual products in the internal product catalog.', 'catalog'),
  ('browse_product_catalog',    'Browse product catalog',        'Browse the full product catalog list, not just search results.', 'catalog'),
  ('view_product_pricing',      'View product pricing',          'See product pricing, supplier list, and misc charges.', 'catalog'),
  ('manage_product_catalog',    'Manage product catalog',        'Create, edit, and delete products, suppliers, images, and misc charges.', 'catalog'),
  ('view_archived_orders',      'View archived orders',          'See orders that have been archived out of the default listing. Archiving never deletes anything — this only gates who can look an archived order up.', 'orders'),
  ('manage_hs_codes',           'Manage HS code master list',    'Add, edit, and deactivate HS codes in the master list order creation picks from — kept separate from ordinary order-entry access so a new code always goes through a privileged person first.', 'catalog'),
  ('manage_email_templates',    'Manage email templates',        'Add or edit email templates used when composing a send (never delete — every past send keeps its own frozen copy in the email log regardless).', 'admin'),
  ('view_staff_reports',        'View staff productivity reports', 'View the Staff Productivity report (documents generated and audit-log activity per user) — kept separate from view_reports since it shows individual staff activity, not just business data.', 'reports'),
  ('ca_module_view',            'View CA / Accounting module',   'View the CA/Accounting module (independent of the order-pipeline system) — the INR settlement register and, as later phases land, revenue/expense/reconciliation reports.', 'ca'),
  ('inr_actual_view',           'View INR actual settlement amounts', 'See the actual INR amount credited to the bank for a cleared advance/balance/freight payment.', 'ca'),
  ('inr_actual_edit',           'Add/edit INR actual settlement amounts', 'Record or correct the actual INR amount credited to the bank for a cleared advance/balance/freight payment.', 'ca'),
  ('inr_actual_delete',         'Delete INR actual settlement amounts', 'Remove a recorded INR actual amount (e.g. to correct a mis-entry) — kept separate from edit since this is a destructive correction, not routine data entry.', 'ca'),
  ('ca_module_manage',          'Manage CA / Accounting integrations', 'Trigger a Zoho Books sync and view its log, and lock/unlock a financial year for CA data entry — kept separate from ca_module_view since these are administrative actions with a wider blast radius than routine data entry, not just reading the register.', 'ca'),
  ('ca_fy_lock_override',       'Override a financial year lock', 'Push through a single CA entry even while its financial year is locked — for a genuine backdated correction, never routine use. Every use is logged. Kept separate from ca_module_manage, which can reopen a year outright for everyone.', 'ca'),
  ('edit_order_post_confirmation', 'Edit an order after confirmation', 'Edit order details or product rows once Order Confirmation has been issued (Stage 4+) — a reason is required and every use is logged. Before Stage 4, manage_orders alone is enough.', 'orders'),
  ('manage_payments',            'Manage order payments',         'Record and clear an order''s advance/balance payment (Stages 3 and 8) — QA-5 RBAC-03/04: split out of manage_orders so a Logistics-only user can no longer mark a buyer payment as cleared; kept with Accounts, who already owns payment tracking.', 'orders'),
  ('clear_payments',             'Clear a buyer payment',         'Confirm a buyer payment actually landed in the bank, unlocking the next stage gate — split out of manage_payments so the person who records a payment is never also the one who confirms it. Accounts/Admin/MD/ED only by default.', 'orders'),
  ('manage_shipping',            'Manage shipping & freight',      'Record freight terms/payment and packing/BL/shipping actions (Stages 6-7 and 9) — QA-5 RBAC-04: split out of manage_orders so an Accounts-only user can no longer record a BL.', 'orders'),
  ('close_orders',               'Close or mark an order lost',    'Close a fully-despatched order or mark one lost — QA-5 RBAC-04: split out of manage_orders so an Accounts-only user can no longer close an order.', 'orders'),
  ('data_export_run',            'Export database structure/data', 'Download a full structure-only and data-only dump of the live database, to migrate all historical records into a fresh reinstall. Kept separate from manage_company_settings since it can expose every record, including staff password hashes.', 'admin'),
  ('ca_internal_doc_manage',      'Manage internal CA financial annexure', 'Toggle the internal-only CA Financial Annexure (govt export benefits + expenses) for an order and generate it. Not auto-granted to Accounts Executive/CA roles — only Admin/MD/ED and Super Admin get it by default.', 'ca'),
  ('view_cafin',                  'View & download CAFIN',          'See and download the internal CA Financial Annexure (CAFIN): the CAFIN box on the order page and CAFIN in document lists. Never reaches the client. Grant or remove per role or per person (e.g. a CA during an audit).', 'ca'),
  ('manage_disputes',             'Manage disputes',                'View/raise/update a dispute''s status, attach evidence, and enable/disable the client-facing dispute button. Not auto-inherited from manage_orders — only Admin/MD/ED and Super Admin get it by default.', 'orders'),
  ('respond_to_disputes',         'Respond to disputes',            'Post a reply in a dispute''s reply thread — kept separate from manage_disputes so sales roles can answer without also managing dispute status or the button.', 'orders'),
  ('manage_complaints',           'Manage complaints',              'View complaints, acknowledge/resolve them, and log one on a client''s behalf. Not auto-inherited from manage_orders — only Admin/MD/ED and Super Admin get it by default, same tier as manage_disputes.', 'orders'),
  ('respond_to_complaints',       'Respond to complaints',          'Post a reply in a complaint''s conversation thread — kept separate from manage_complaints so sales roles can answer without also managing its status, same split as respond_to_disputes.', 'orders'),
  ('manage_order_financials',     'Manage order financials',        'View/add/edit an order''s export benefit claims, other costs, and its profitability summary. Stricter than ca_module_view/inr_actual_edit: not auto-granted to Accounts/CA, only Admin/MD/ED and Super Admin by default.', 'ca'),
  ('manage_supplier_payments',    'Manage supplier payments',       'Mark a supplier payment as paid, and see the Outgoing (Supplier) side of Today''s Payments — split out of manage_order_financials so Accounts can confirm supplier payments without the broader financials/profitability access.', 'orders'),
  ('manage_logistics_partners',   'Manage logistics partners',      'Add, edit, and deactivate CHA and transportation partners in the directory order staff pick contacts from. Same tier as manage_hs_codes: Admin/MD/ED and Super Admin only by default.', 'catalog'),
  ('manage_payment_presets',      'Manage payment presets',         'Create, edit, deactivate payment presets (advance/balance %, balance-trigger wording). Drives every order''s payment calc. Same tier as manage_logistics_partners. Existing presets ship protected — unlock via Field Protection first.', 'catalog'),
  ('manage_compliance_task_types', 'Manage compliance task types',   'Add or deactivate compliance task types (ECGC Cover, Pre-Shipment Inspection, etc.). Does not grant the order-page checklist itself - that uses close_orders. Same tier as manage_hs_codes/manage_logistics_partners: Admin/MD/ED and Super Admin only.', 'catalog'),
  ('override_buyer_acknowledgment', 'Override buyer Order Confirmation acknowledgment', 'Record a buyer''s OC acknowledgment manually (e.g. an email reply). Only consulted when oc_ack_override_restricted is on; off by default, where manage_orders alone is enough.', 'orders'),
  ('impersonate_client', 'Log in as a client', 'Start a staff-initiated client-portal session for a client who can''t use the portal themselves. Requires client_impersonation_enabled (global) and the client''s own allow_staff_impersonation flag both on. Every use is logged.', 'clients'),
  -- docs/schema.sql Section AY
  ('view_orders',  'View orders (read-only)',  'See the Orders list and an order''s detail page without being able to create, edit, or act on it. Anyone holding manage_orders already has this implicitly — this permission exists only for a view-only tier that holds no manage_orders.', 'orders'),
  ('view_clients', 'View clients (read-only)', 'See the Clients list and a client''s detail page without being able to create, edit, or act on it. Anyone holding manage_orders already has this implicitly — this permission exists only for a view-only tier that holds no manage_orders.', 'clients'),
  -- Order ownership / assignment visibility (see docs/migrations/2026-10-24b-order-owner-assignment.sql)
  ('view_all_orders',   'View all orders regardless of assignment', 'See every order in the Orders list, Kanban board, and order detail page, not just ones assigned to you. Oversight roles only.', 'orders'),
  ('assign_order_owner', 'Manage order assignees', 'Add or remove which staff are assigned to an order (order_assignees). Visibility-only control — never affects stage-gate or payment/shipping permissions.', 'orders');

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
  AND p.permission_key IN ('manage_orders','manage_payments','manage_shipping','close_orders','generate_documents','download_pdf','view_reports','view_client_email_full','cross_verify_documents','view_product_catalog','browse_product_catalog','view_product_pricing','view_archived_orders','respond_to_disputes','respond_to_complaints','override_buyer_acknowledgment');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Accounts Executive'
  AND p.permission_key IN ('view_cafin','manage_orders','manage_payments','clear_payments','manage_supplier_payments','download_pdf','view_reports','view_client_email_full','cross_verify_documents','view_product_catalog','browse_product_catalog','view_product_pricing','view_archived_orders','ca_module_view','inr_actual_view','inr_actual_edit','ca_module_manage','ca_fy_lock_override','override_buyer_acknowledgment','view_all_orders');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Logistics Executive'
  AND p.permission_key IN ('manage_orders','manage_shipping','close_orders','generate_documents','download_pdf','cross_verify_documents','view_product_catalog','browse_product_catalog','view_archived_orders','override_buyer_acknowledgment');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Viewer / Auditor'
  AND p.permission_key IN ('view_reports','view_audit_log','view_product_catalog','view_orders','view_clients','view_all_orders');

INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'CA / Chartered Accountant'
  AND p.permission_key IN ('ca_module_view','inr_actual_view','view_cafin','view_orders','view_all_orders'); -- view_orders: owner decision 2026-10-28 (read-only, so the CA can open an order and its CAFIN box); view_all_orders: owner decision 2026-10-30 (every order, not only assigned ones)

-- Team incentives (owner decision 2026-10-30): you and the MD manage them,
-- Accounts pays them, staff see their own. Executive Director is left out of
-- manage_incentives on purpose (it is added after the all-permissions grant above).
INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('manage_incentives', 'Manage team incentives', 'See and change the incentive settings, each order''s incentive and profit basis, the associate register and every payout. Owner decision 2026-10-30: you and the MD only.', 'incentives'),
  ('pay_incentives', 'Pay incentives', 'See the incentive payouts list (person, order, amount) and record payments. No profit, no settings, cannot change amounts.', 'incentives'),
  ('view_own_incentives', 'View own incentives', 'See your own incentive amounts once the buyer has fully paid.', 'incentives');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_incentives' AND r.name IN ('Admin', 'Managing Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'pay_incentives' AND r.name IN ('Admin', 'Managing Director', 'Accounts Executive');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1
FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_own_incentives'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Export Executive', 'Accounts Executive', 'Logistics Executive');

-- D-245 P4 (4 Nov 2026): the reward table, minimum profit, new-client bonus, role split and
-- cut-off day are gone; the amount is a % of profit or a fixed amount (Pricing settings), the
-- roles are an editable list (incentive_roles), and the MD is reminded N days before a payout run.
INSERT IGNORE INTO incentive_settings (setting_key, setting_value) VALUES
  ('program_enabled', '0'),
  ('lock_reminder_days', '3');

-- ================================================================
-- USERS — one seeded account to get in the door.
-- Email/password are PLACEHOLDERs — change both immediately.
-- Password below is "ChangeMe#2026" (bcrypt hash) — force_password_change=1
-- means you'll be made to set a new one on first login regardless.
-- ================================================================
INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
SELECT 'Managing Director', 'md@email.com', NULL,
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
INSERT INTO ports (name, city, country, port_role, is_default, is_active, sort_order) VALUES
  ('Chennai, India', 'Chennai', 'India', 'loading', 1, 1, 1);

INSERT INTO incoterms (code, label_template, requires_port_role, is_default, is_active, sort_order) VALUES
  ('FOB', '{code} {port} — Incoterms® 2020', 'loading',    1, 1, 1),
  ('CFR', '{code} {port} — Incoterms® 2020', 'discharge',  0, 1, 2),
  ('CIF', '{code} {port} — Incoterms® 2020', 'discharge',  0, 1, 3);

-- ================================================================
-- PAYMENT PRESETS — the structural fix for the Tier1/Tier2 contamination
-- found in the source documents (see ARCHITECTURE.md section 4.3).
-- ================================================================
INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, currency_id, requires_md_approval, is_active)
SELECT 'Standard — New Buyer', 1, 40.00, 'against Proforma Invoice before production commences', 'CASH_ADVANCE',
       60.00, 'A_BEFORE_SHIPMENT', 3,
       'Payable before shipment — within {days} Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest.',
       c.id, 0, 1
FROM currencies c WHERE c.code = 'USD';

INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, currency_id, requires_md_approval, is_active)
SELECT 'Established Buyer — Post-BL', 0, 40.00, 'against Proforma Invoice before production commences', 'CASH_ADVANCE',
       60.00, 'B_AGAINST_BL', 7,
       'Payable against scanned copy of Bill of Lading, within {days} Calendar Days of the date NexaCrest emails the scanned BL copy.',
       c.id, 1, 1
FROM currencies c WHERE c.code = 'USD';

-- 90-item backlog "30/70 payment preset": two more presets for an
-- established buyer wanting a lighter upfront commitment than the
-- 40/60 presets above — the same Before-Shipment/After-BL pair the
-- 40/60 bucket has (Standard — New Buyer / Established Buyer —
-- Post-BL), just at a 30/70 split instead.
INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, currency_id, requires_md_approval, is_active)
SELECT 'Extended Terms — Before Shipment', 0, 30.00, 'against Proforma Invoice before production commences', 'CASH_ADVANCE',
       70.00, 'A_BEFORE_SHIPMENT', 3,
       'Payable before shipment — within {days} Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest.',
       c.id, 0, 1  -- owner decision (2026-10-28): no MD approval needed
FROM currencies c WHERE c.code = 'USD';

INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, currency_id, requires_md_approval, is_active)
SELECT 'Extended Terms — Post-BL', 0, 30.00, 'against Proforma Invoice before production commences', 'CASH_ADVANCE',
       70.00, 'B_AGAINST_BL', 7,
       'Payable against scanned copy of Bill of Lading, within {days} Calendar Days of the date NexaCrest emails the scanned BL copy.',
       c.id, 1, 1
FROM currencies c WHERE c.code = 'USD';

-- The third real-world payment option NexaCrest offers (per the
-- business, apart from the two 30/70 cash presets above, nothing else
-- is offered): the buyer opens a Letter of Credit instead of wiring a
-- cash advance. payment_method = 'LC' makes the order-detail page's
-- Step 5 render "Record LC Details" / "Confirm LC Usable" in place of
-- "Record Advance Remittance" / "Mark Advance Cleared" — the LC leg
-- replaces the cash advance entirely; the Balance leg afterward is
-- unaffected. advance_pct is 0 here (not a typo) — a sight LC has no
-- separate cash advance leg; the full amount is paid once shipping
-- documents are presented to the bank, so the whole order value sits
-- in balance_pct instead. "LC Amount" on the order page's Record LC
-- Details step is a free-typed field, never derived from advance_pct,
-- so this doesn't change anything about that step itself — only what
-- prints as the advance/balance split on generated documents.
INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, currency_id, requires_md_approval, is_active)
SELECT 'Irrevocable Letter of Credit at Sight', 0, 0.00, 'against Letter of Credit opened before production commences', 'LC',
       100.00, 'B_AGAINST_BL', 7,
       'Payable against scanned copy of Bill of Lading, within {days} Calendar Days of the date NexaCrest emails the scanned BL copy.',
       c.id, 1, 1
FROM currencies c WHERE c.code = 'USD';

-- All five presets ship protected — they drive where money is actually
-- sent/received; see field_protection_requests to unprotect.
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
  ('QT',        'Quotation',                          'customer_facing', 'SC/QT/{YY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('ANNEXA',    'Annexure A',                          'customer_facing', NULL,                        0, 1, 1, 1),
  ('BUYERPO',   'Buyer PO — Order Acceptance',         'customer_facing', 'SC/PO/{YY}/{DDMM}{NNN}', 0, 0, 1, 1),
  ('PI',        'Proforma Invoice',                    'customer_facing', 'SC/PI/{YY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('OC',        'Order Confirmation',                  'customer_facing', 'SC/OC/{YY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('SUPPO',     'Supplier PO — Material Procurement',  'procurement',     'SC/SPO/{YY}/{DDMM}{NNN}', 1, 1, 1, 1),
  ('FDN',       'Freight Debit Note',                  'customer_facing', 'SC/FDN/{YY}/{DDMM}{NNN}', 0, 1, 1, 1),
  ('PL',        'Packing List',                        'customer_facing', 'SC/PL/{YY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('BLI',       'BL Instruction Sheet',                'internal',        'SC/BLI/{YY}/{DDMM}{NNN}', 1, 1, 1, 1),
  ('CI',        'Commercial Invoice',                  'customer_facing', 'SC/CI/{YY}/{DDMM}{NNN}',  0, 1, 1, 1),
  ('COOPREP',   'Certificate of Origin Preparation',   'procurement',     NULL,                        1, 1, 1, 1),
  ('CHECKLIST', 'Cross-Verification Checklist 1 of 4 — Sales / Documentation Officer', 'internal', NULL, 1, 0, 1, 1),
  ('CHECKLIST_2_FINANCE',  'Cross-Verification Checklist 2 of 4 — Accounts / Finance',              'internal', NULL, 1, 0, 1, 1),
  ('CHECKLIST_3_PACKING',  'Cross-Verification Checklist 3 of 4 — Packing / Dispatch Supervisor',   'internal', NULL, 1, 0, 1, 1),
  ('CHECKLIST_4_SHIPPING', 'Cross-Verification Checklist 4 of 4 — Shipping / Logistics Coordinator', 'internal', NULL, 1, 0, 1, 1),
  ('AMD',       'Payment Terms Amendment',              'internal',       'SC/AMD/{YY}/{DDMM}{NNN}', 1, 1, 1, 1),
  ('SOP_A_SALES', 'SOP — Sales Process, Tier A (Standard — New Buyer)', 'internal', NULL,               1, 1, 1, 1),
  ('SOP_B_SALES', 'SOP — Sales Process, Tier B (Established Buyer — Post-BL)', 'internal', NULL,        1, 1, 1, 1),
  ('STAGEGATE', 'Stage Gate Reference',                 'internal',       NULL,                        1, 0, 1, 1),
  ('WALLREF',   'Wall Reference',                       'internal',       NULL,                        1, 0, 1, 1),
  ('CAFIN',     'CA Financial Annexure — Government Benefits & Expenses (Internal Only)', 'internal', 'SC/CAFIN/{YY}/{DDMM}{NNN}', 1, 1, 0, 1),
  ('BLE',       'Bill of Lading Endorsement',           'customer_facing', 'SC/BLE/{YY}/{DDMM}{NNN}', 0, 1, 1, 1);

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
  (10, 'Natural Stone Characteristics', 'Natural Stone Characteristics: The quality of granite supplied is as agreed, selected for colour consistency. As with all natural stone, minor variation in shade, grain and veining may occur between pieces — however, visible dramatic colour difference between components of the same order will not occur. Products will conform to the agreed specification.', 'active', 0);
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
  (90, 'Shipment Booking (CFR/CIF)', 'Shipment Booking: For CFR/CIF orders, shipment booking will be confirmed only after full freight payment (and, for CIF orders, the insurance premium) is received and cleared in NexaCrest''s bank account against the Debit Note. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is verified.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Shipment Booking (CFR/CIF)' AND dt.code IN ('QT','PI','OC');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (100, 'Quotation Validity', 'This Quotation is valid for {quotation_validity_days} days from the date of issue as shown in the Valid Until field above. After this date, prices and terms are subject to revision. An advance payment received after the Valid Until date does not constitute acceptance of this Quotation — NexaCrest will issue a revised Quotation at the same or updated prices before proceeding.', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Quotation Validity' AND dt.code IN ('QT');

INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked) VALUES
  (110, 'CFR/CIF Indicative Freight Validity (Quotation)', 'CFR / CIF pricing: if required, indicative freight shown above is valid for 25 days from quotation date. Actual freight will be confirmed once cargo is packed and ready, and recovered IN ADVANCE by separate Debit Note — payable within 3 Calendar Days of issue, and must be received BEFORE shipment booking is confirmed. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is received and verified.', 'active', 0);
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
  (110, 'CFR/CIF Indicative Freight Validity (Proforma Invoice)', 'CFR / CIF orders: freight and insurance shown above are indicative at PI stage. Actual freight will be confirmed once cargo is packed and ready, and recovered IN ADVANCE by Debit Note — payable within 3 Calendar Days of issue, and must be received BEFORE shipment booking is confirmed. NexaCrest cannot book the container or hand over cargo to the shipping line until freight payment is received. Freight payment may occur before or simultaneously with the {balance_pct}% balance T/T.', 'active', 0);
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

-- NOTE: no "Time Is of the Essence" clause row here (removed 2026-09-30,
-- against the real Supplier PO reference template) — that exact wording is
-- already a static row in Section 5 (Delivery Terms) of the Supplier PO
-- document; a clause row of the same text under Section 6 (Quality &
-- Inspection) duplicated it on every generated PDF/DOCX.

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
  ('container_type', '20ft Flat Rack', 4, 0, 1),
  ('container_type', '40ft Flat Rack', 5, 0, 1),
  ('container_type', 'LCL (Less than Container Load)', 6, 0, 1),
  ('dispute_status', 'Open', 1, 1, 1),
  ('dispute_status', 'Under Review', 2, 0, 1),
  ('dispute_status', 'Resolved', 3, 0, 1),
  ('dispute_status', 'Escalated', 4, 0, 1),
  ('received_from', 'Buyer', 1, 1, 1),
  ('received_from', 'Supplier', 2, 0, 1),
  ('received_from', 'CHA / Shipping Line', 3, 0, 1),
  ('supplier_type', 'Quarry Owner', 1, 1, 1),
  -- 90-item backlog #51a: "Processor" alone was ambiguous against the new
  -- "Factory Owner" option below (both could be read as "owns the
  -- processing facility") — renamed to make clear this is specifically
  -- the job-work case: a unit that processes material supplied by/on
  -- behalf of someone else, not one that owns the quarry or the factory.
  ('supplier_type', 'Processor (Job-Work Basis)', 2, 0, 1),
  ('supplier_type', 'Trader', 3, 0, 1),
  ('supplier_type', 'Factory Owner', 4, 0, 1),
  ('export_benefit_scheme', 'RODTEP', 1, 1, 1),
  ('export_benefit_scheme', 'Duty Drawback', 2, 0, 1),
  ('export_benefit_scheme', 'RoSCTL', 3, 0, 1),
  ('export_benefit_scheme', 'Other', 4, 0, 1),
  -- 90-item backlog #43: the product-line/Supplier-PO Unit field was
  -- plain free text ("SQM/PCS" was only ever a placeholder hint) — this
  -- gives it the same admin-editable dropdown treatment as every other
  -- list on this page, still writing the same plain string into the
  -- existing unit VARCHAR column (no schema change).
  ('unit', 'PCS', 1, 1, 1),
  ('unit', 'SQM', 2, 0, 1),
  ('unit', 'SET', 3, 0, 1),
  ('unit', 'BOX', 4, 0, 1),
  ('unit', 'SQFT', 5, 0, 1),
  ('unit', 'MT', 6, 0, 1),
  ('unit', 'KG', 7, 0, 1),
  ('unit', 'NOS', 8, 0, 1),
  ('unit', 'CBM', 9, 0, 1),
  -- User-reported batch item 12: Dimensional Tolerance on the Supplier PO
  -- Terms form was plain free text with only a placeholder hint
  -- ("e.g., ±1 mm") and no real default — same admin-editable dropdown
  -- treatment as Unit/Surface Finish/GST Rate above, still writing the
  -- same plain string into the existing dimensional_tolerance VARCHAR
  -- column (no schema change to order_supplier_po).
  ('dimensional_tolerance', '±1 mm', 1, 1, 1),
  ('dimensional_tolerance', '±0.5 mm', 2, 0, 1),
  ('dimensional_tolerance', '±2 mm', 3, 0, 1),
  ('dimensional_tolerance', '±3 mm', 4, 0, 1),
  ('dimensional_tolerance', 'As per approved sample', 5, 0, 1),
  -- 90-item backlog #66: Package Type was plain free text defaulting to
  -- the literal PHP string 'Wooden Crates' — this is default=1 for the
  -- exact same reason, so an order's package type is byte-identical to
  -- before this change until an admin actually edits the list.
  ('package_type', 'Wooden Crates', 1, 1, 1),
  ('package_type', 'Wooden Pallets', 2, 0, 1),
  ('package_type', 'Cartons', 3, 0, 1),
  ('package_type', 'Bundles', 4, 0, 1),
  ('package_type', 'Loose (Break Bulk)', 5, 0, 1),
  -- 90-item backlog #51a: Supplier PO's Surface Finish field was plain
  -- free text with no standard list — same dropdown_options treatment,
  -- same manage_dropdown_options admin screen.
  ('surface_finish', 'Polished', 1, 1, 1),
  ('surface_finish', 'Honed', 2, 0, 1),
  ('surface_finish', 'Flamed', 3, 0, 1),
  ('surface_finish', 'Natural/Sandblasted', 4, 0, 1),
  ('surface_finish', 'Rock-Pitched', 5, 0, 1),
  ('surface_finish', 'Bush-Hammered', 6, 0, 1),
  ('surface_finish', 'Leather/Satin', 7, 0, 1),
  -- 90-item backlog #51a: Supplier PO's GST Rate field was plain free
  -- text — standard Indian GST slabs as a dropdown instead. 18% is the
  -- default to match the rate every existing sample Supplier PO already
  -- used (it was a hand-typed literal, not read from this table).
  ('gst_rate', '0', 1, 0, 1),
  ('gst_rate', '5', 2, 0, 1),
  ('gst_rate', '12', 3, 0, 1),
  ('gst_rate', '18', 4, 1, 1),
  ('gst_rate', '28', 5, 0, 1),
  -- 90-item backlog #31: rbi_purpose_code_advance/balance/freight
  -- (company_settings, seeded further below) were plain free text with
  -- no standard list. These 3 values are exactly the codes already in
  -- use as each setting's own default (P0103/P0102/P0602) — deliberately
  -- not inventing additional RBI/FEMA purpose codes here, since getting
  -- one wrong would misdescribe a real bank wire's purpose on an actual
  -- compliance document (Commercial Invoice/Freight Debit Note/Payment
  -- Terms Amendment). An admin can add further codes later via this same
  -- /admin/dropdown-options screen once confirmed with their bank/CA.
  ('rbi_purpose_code', 'P0102', 1, 0, 1),
  ('rbi_purpose_code', 'P0103', 2, 0, 1),
  ('rbi_purpose_code', 'P0602', 3, 0, 1);

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
  ('vendor_bill',           'pdf,jpg,jpeg,png', 10485760, 'A bill / invoice from the supplier or a logistics partner (CHA, transport, freight forwarder) — Steps 7e/10a and the logistics bills card.'),
  ('expense_receipt',       'pdf,jpg,jpeg,png', 10485760, 'Receipt or bill for an order expense (travel, hotel, courier, ...) — Step 12a.'),
  ('draft_bl',              'pdf,doc,docx,xls,xlsx,odt,ods,rtf,txt,csv,jpg,jpeg,png,gif,webp,bmp,tif,tiff,heic,eml,msg,zip', 10485760, 'Draft Bill of Lading from the CHA/shipping line, pending written approval before originals are issued.'),
  ('bl_copy',               'pdf,doc,docx,xls,xlsx,odt,ods,rtf,txt,csv,jpg,jpeg,png,gif,webp,bmp,tif,tiff,heic,eml,msg,zip', 10485760, 'External QA report L-22: scanned FINAL Bill of Lading, attached at the Stage 7->8 BL-issued gate or any time after — distinct from draft_bl above, which is the pre-approval draft.'),
  ('fumigation_cert',       'pdf,jpg,jpeg,png', 5242880, 'Fumigation certificate for wooden packing.'),
  ('buyer_approval',        'pdf,jpg,jpeg,png,eml,msg', 5242880, 'Buyer''s written approval (e.g. quantity shortfall, draft BL sign-off).'),
  ('product_image',         'jpg,jpeg,png,webp', 5242880, 'Product image/technical drawing attached to an Annexure A entry.'),
  ('buyer_po_copy',         'pdf,jpg,jpeg,png,eml,msg', 10485760, 'Buyer''s actual signed Purchase Order (Stage 2 gate evidence — Addition beyond the spec''s named key list: recordBuyerPo() previously only captured a reference number typed by staff, with no copy of the PO itself on file).'),
  ('supplier_po_ack',       'pdf,jpg,jpeg,png,eml,msg', 10485760, 'Supplier''s signed acknowledgment of the Supplier PO (Stage 5 gate evidence — same addition/rationale as buyer_po_copy).'),
  ('order_comment_media',   'jpg,jpeg,png,gif,webp,mp4,mov,webm,pdf', 52428800, 'Images/videos/files attached to an order progress chat comment (50 MB per file). Attachments over MailSenderService''s direct-attach cap are sent to the client as a portal download link instead of an email attachment.'),
  ('order_additional_document',  'pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 15728640, 'Batch 3 #13b — a free-form extra document attached to an order that does not fit any fixed document type (e.g. a buyer-supplied certificate template, a special packing instruction sheet).'),
  ('client_additional_document', 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 15728640, 'Batch 3 #13b — same as order_additional_document, but attached to a client rather than one specific order (e.g. a standing NDA, a general compliance certificate).'),
  ('client_agreement_file',      'pdf,doc,docx', 15728640, 'Item 2 — the actual signed copy of a client''s commercial agreement, tied to that client''s expiry/renew tracking (clients.agreement_file_path etc — see schema.sql Section BE).'),
  ('order_lc_copy',              'pdf,jpg,jpeg,png', 10485760, 'Export process step 5-1a: the final Letter of Credit document for this order, when the client opted for LC payment.'),
  ('associate_agreement',        'pdf', 15728640, 'The signed Commission Structure and Associate Agreement of an associate (associates.agreement_file_id) — internal, manage_incentives only.');

-- ================================================================
-- COMPANY_SETTINGS — every key schema.sql reserves for this table.
-- Values marked PLACEHOLDER need real data before documents go live;
-- everything else is a working default you can tune from /settings.
-- ================================================================
-- Owner decision D-313 (11 Nov 2026): the company's own identity, bank,
-- GSTIN, IEC/PAN, LUT and RCMC values are NOT in this file. The values below
-- are placeholders that pass the settings checks (the GSTIN keeps state code
-- 29, Karnataka, which the domestic GST split reads); Admin › Setup checklist
-- flags each one as Needed until the real value is entered at installation
-- (Admin › Company Settings, Unlock on the protected rows).
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('legal_name',            'NexaCrest International Private Limited', 'string', 'company', 'Full legal company name as it appears on all documents.', 0),
  ('registered_office',     'Registered office address, City – 000000, India', 'string', 'company', 'Registered office address.', 0),
  ('corporate_office',      'Corporate office address, City – 000000, India', 'string', 'company', 'Corporate/working office address, if different.', 0),
  ('gstin',                 '29AAAAA0000A1ZY',                          'string', 'company', 'GST Identification Number.', 1),
  ('iec_pan',               'AAAAA0000A',                               'string', 'company', 'Import Export Code / PAN.', 1),
  ('md_name',               'Managing Director',                        'string', 'company', 'Founder & Managing Director name, for document signature blocks.', 0),
  ('md_title',              'Founder & Managing Director',              'string', 'company', 'MD title as shown on documents.', 0),
  ('director_name',         'Managing Director',                        'string', 'company', 'Director name, if a director signature is used on any document (open question — confirm whether this is needed; QT/PI/OC templates only show the MD signature block).', 0),
  ('director_title',        'Director',                                 'string', 'company', 'Director title as shown on documents.', 0),
  ('phone',                 '+91-0000000000',                           'string', 'company', 'Company contact phone number.', 0),
  ('email',                 'info@email.com', 'string', 'company', 'Company contact email address.', 0),
  ('bank_name',             'Bank name (to be entered)',                'string', 'bank',    'Bank name for buyer remittances.', 1),
  ('bank_branch',           'Branch (to be entered)', 'string', 'bank',  'Bank branch name.', 1),
  ('bank_account_no',       '000000000000',                             'string', 'bank',    'Bank account number.', 1),
  ('swift_bic',             'XXXXINXXXXX',                              'string', 'bank',    'SWIFT/BIC code.', 1),
  ('ifsc',                  'XXXX0000000',                              'string', 'bank',    'IFSC code.', 1),
  ('bank_address',          'Bank branch address, City – 000000, India', 'string', 'bank', 'Bank branch address.', 1),
  ('bank_pincode',          '000000',                                    'string', 'bank',    'Bank branch pincode.', 1),
  ('lut_number',            'AD000000000000X',                         'string', 'lut',     'Letter of Undertaking (LUT) ARN/number for zero-rated export.', 1),
  ('lut_valid_fy',          'FY 2026-27',                                'string', 'lut',     'Financial year the current LUT is valid for.', 1),
  ('lut_expiry_date',       '2027-03-31',                                'date',   'lut',     'LUT expiry date — placeholder (FY 2026-27 end date assumed as 31 March 2027) — the source PI template states the FY but not an explicit expiry date; confirm the real one.', 1),
  ('rcmc_number',           'RCMC/CAPEXIL/00000/2026-2027',             'string', 'capexil', 'CAPEXIL RCMC (Registration-cum-Membership Certificate) number — required on the COO Preparation Sheet (Phase C).', 1),
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
  ('max_bank_charges_pct', '3', 'number', 'tolerances', 'The most bank charges one buyer remittance may carry, as a % of the amount due. Above it the difference must be recorded as a short payment instead.', 0),
  ('fema_realization_alert_days', '270', 'number', 'alerts', 'Days after a settlement leg is marked cleared before the CA / Accounting module flags its FIRC/eBRC reference as still missing — 270 days (9 months) mirrors the RBI/FEMA export-proceeds realization period as a practical proof-of-realization deadline, not a legal shipment-date calculation (this system does not track the shipment date this rule technically runs from).', 0),
  ('session_timeout_staff_minutes', '240', 'number', 'security', 'D-283: staff are signed out after this many minutes without activity (5 to 1440; default 240 = 4 hours). A warning with "Stay logged in" shows 2 minutes before.', 0),
  ('session_timeout_client_minutes', '120', 'number', 'security', 'D-283: buyers (client portal) are signed out after this many minutes without activity (5 to 1440; default 120 = 2 hours).', 0),
  ('session_timeout_associate_minutes', '120', 'number', 'security', 'D-283: associates (associate portal) are signed out after this many minutes without activity (5 to 1440; default 120 = 2 hours).', 0),
  ('session_absolute_staff_hours', '12', 'number', 'security', 'D-283: staff log in again at least this often, however active they are (1 to 24 hours; default 12).', 0),
  ('failed_login_lockout_count', '5', 'number', 'security', 'Failed login attempts before account lockout.', 0),
  ('login_remember_browser_days', '30', 'number', 'security', 'D-265 / D-283: staff may tick "This is my own office computer" on the login-code screen and skip the e-mailed code on that computer for this many days (0 to 90; default 30). 0 = off: the code is asked at every login. A password change or reset, or "Forget all remembered computers" (My Account, or Admin in Users › Edit), forgets them.', 0),
  ('lockout_duration_minutes', '15', 'number', 'security', 'How long an account stays locked after too many failed attempts. (Addition beyond the spec''s named key list — needed to make failed_login_lockout_count actually enforceable; flagging per architecture doc practice.)', 0),
  ('password_min_length',   '10', 'number', 'security', 'Minimum password length.', 0),
  ('password_complexity_json', '{"require_upper":true,"require_number":true,"require_symbol":false}', 'json', 'security', 'Password complexity rules enforced on password change.', 0),
  ('password_expiry_days',  '0', 'number', 'security', 'Days before a password must be changed. Enforced on every request (SessionAuth) — 0 disables the check (D-313: off by default; staff sign in with an e-mailed code).', 0),
  ('non_usd_price_buffer_pct', '1.75', 'number', 'tolerances', 'Advisory price buffer shown (not auto-applied) for non-USD quotes, per your instruction.', 0),
  ('quotation_validity_days', '30', 'number', 'documents', 'Days a Quotation stays valid from its issue date (Addition beyond the spec''s named key list — the source Quotation template states "30 days" directly in its T&C text; making it a setting instead of a literal keeps that number DB-driven if it ever changes).', 0),
  ('pi_validity_days',       '15', 'number', 'documents', 'Days a Proforma Invoice stays valid from its issue date. Same addition as quotation_validity_days, for the same reason — the source PI template states "15 days" directly in its T&C text.', 0),
  ('revision_start_number', '1', 'number', 'documents', 'Starting revision number for a new document.', 0),
  ('bl_type_instruction', 'ORIGINAL NEGOTIABLE BILL OF LADING — no exceptions. Do not substitute with Sea Waybill or Express BL.', 'string', 'shipping', 'Mandatory BL type instruction printed on every BL Instruction Sheet (Addition beyond the spec''s named key list — this hard rule was previously hardcoded directly in the BLI template, with no governance or audit trail if it ever needed a one-off exception; a Sea Waybill/Express BL lets the buyer collect cargo without surrendering any document, eliminating NexaCrest''s financial leverage over the balance payment).', 0),
  ('bl_consignee_instruction', 'TO ORDER OF {company}', 'string', 'shipping', 'Mandatory BL consignee instruction — ensures the BL is to NexaCrest''s order so the buyer cannot use it until NexaCrest endorses and releases it. {company} is substituted with the company legal name at render time. Same addition/rationale as bl_type_instruction.', 0),
  -- 90-item backlog "Annexure note configurability" — same addition/
  -- rationale as bl_type_instruction/bl_consignee_instruction above:
  -- this exact wording was previously hardcoded straight into the
  -- Annexure A template with no governance or audit trail.
  ('annexure_product_note', 'Product images and drawings are for reference only and may show optional accessories or decorative elements not included in the quoted price. All binding specifications are as stated in each product section below. Actual product appearance may vary slightly due to the natural characteristics of granite.', 'string', 'documents', 'Printed in the amber "Note:" banner above every Annexure A''s Product Specification section.', 0),
  ('master_tracking_ref_format', 'NC/SC/{YY}/{DDMM}{NNN}', 'string', 'formats', 'Buyer inquiry reference format — matches the format already in use on your existing documents.', 0),
  ('order_ref_format',      'NC/SC/ORD/{YY}/{DDMM}{NNN}', 'string', 'formats', 'Order reference format (owner decision 4 Nov 2026, D1): a daily counter, e.g. NC/SC/ORD/26/0411001.', 0),
  ('dispute_response_days_n', '10', 'number', 'disputes', 'WORKING days allowed for a dispute response before escalation — must match the "ten (10) working days" figure in the Dispute Resolution & Public Communications clause below verbatim, since that clause is the legally binding promise printed on QT/PI/OC/BUYERPO. (Corrected from an earlier placeholder of 7, which did not match the clause text.) response_due_date is computed via WorkingDaysCalculator, which skips weekly_off_days and company_holidays — plain calendar-day arithmetic would silently undercount the deadline by counting Sundays/holidays as working days.', 0),
  ('weekly_off_days', 'sunday', 'string', 'disputes', 'Comma-separated lowercase weekday name(s) that never count as a working day, used by WorkingDaysCalculator (Addition beyond the spec''s named key list — needed to make dispute_response_days_n''s "working days" figure actually computable; NexaCrest''s standard Mon-Sat working week per this same section''s holiday calendar).', 0),
  ('zoho_mail_enabled',   '0', 'boolean', 'zoho', 'Master on/off switch for sending through the Zoho Mail API. Off by default (no credentials configured yet) — when off, or on any Zoho send failure, mail goes out through the existing SMTP path instead. Never a hard dependency.', 0),
  ('zoho_client_id',      '', 'string',  'zoho', 'Zoho API Console self-client OAuth Client ID.', 1),
  ('zoho_client_secret',  '', 'string',  'zoho', 'Zoho API Console self-client OAuth Client Secret.', 1),
  ('zoho_refresh_token',  '', 'string',  'zoho', 'Zoho OAuth refresh token (mail.send scope) — exchanged for a short-lived access token on each send.', 1),
  ('zoho_account_id',     '', 'string',  'zoho', 'Zoho Mail account ID (from Zoho Mail API''s accounts endpoint) that mail is sent from.', 1),
  ('zoho_from_address',   '', 'string',  'zoho', 'The Zoho mailbox address mail is sent from — must be one of the Zoho account''s own verified addresses.', 0),
  ('zoho_accounts_domain', 'accounts.zoho.in', 'string', 'zoho', 'Zoho OAuth token endpoint domain — Zoho is region-specific (accounts.zoho.com / .eu / .in / .com.cn / .com.au, matching whichever data center the Zoho One account lives in).', 0),
  ('zoho_api_domain',      'mail.zoho.in', 'string', 'zoho', 'Zoho Mail API domain — matches zoho_accounts_domain''s region (mail.zoho.com / .eu / .in / .com.cn / .com.au).', 0),
  -- 90-item backlog "default weight/volume values": New Order's Estimated
  -- Weight & Volume fieldset used to start every order blank, unlike
  -- Package Type/Package Count which already had a configured/hardcoded
  -- default — staff pre-fill these on the create form and edit per order
  -- as always; a blank setting value (cleared via Settings) falls back to
  -- leaving the field blank, same as before this existed.
  ('default_estimated_total_cbm',        '8.2', 'number', 'order_defaults', 'Pre-fills Total CBM (m³) on the New Order form, and on Edit Order for an order with none saved — staff edit per order as always.', 0),
  ('default_estimated_gross_weight_kg',  '27000', 'number', 'order_defaults', 'Pre-fills Gross Weight (kg) on the New Order form, and on Edit Order for an order with none saved — staff edit per order as always.', 0),
  ('default_estimated_net_weight_kg',    '26000', 'number', 'order_defaults', 'Pre-fills Net Weight (kg) on the New Order form, and on Edit Order for an order with none saved — staff edit per order as always.', 0);

-- CA / Accounting module (Phase 3) — Zoho Books sync. A separate OAuth
-- self-client registration from zoho_client_id/etc. above (Zoho Mail and
-- Zoho Books are different API scopes, so their refresh tokens are
-- independent even under the same Zoho One subscription). Off by default
-- — zoho_books_enabled = '0' until every credential below is filled in;
-- CaSyncService treats "not enabled" as a routine no-op, never a blocker
-- (see ZohoSyncLogRepository/CaSyncService docblocks).
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('zoho_books_enabled',            '0', 'boolean', 'zoho_books', 'Master on/off switch for syncing revenue to Zoho Books. Off by default (no credentials configured yet) — when off, Sync Now and the scheduled job both log a single "skipped, not configured" entry and exit cleanly.', 0),
  ('zoho_books_client_id',          '', 'string',  'zoho_books', 'Zoho Books API Console self-client OAuth Client ID.', 1),
  ('zoho_books_client_secret',      '', 'string',  'zoho_books', 'Zoho Books API Console self-client OAuth Client Secret.', 1),
  ('zoho_books_refresh_token',      '', 'string',  'zoho_books', 'Zoho Books OAuth refresh token (ZohoBooks.fullaccess scope) — exchanged for a short-lived access token on each sync.', 1),
  ('zoho_books_organization_id',    '', 'string',  'zoho_books', 'Zoho Books organization ID — required on every Books API call.', 1),
  ('zoho_books_deposit_account_id', '', 'string',  'zoho_books', 'The Zoho Books chart-of-accounts account ID that synced customer payments are recorded as deposited into (e.g. your bank account in Zoho Books).', 1),
  ('zoho_books_sync_start_date',    '', 'date', 'zoho_books', 'Go-live date for Zoho Books (YYYY-MM-DD). Only CIs, Debit Notes, payments, bills, vendor payments and order expenses dated on or after it are sent; anything earlier is booked by the CA as the opening position. Required on the live server (ZOHO_ENVIRONMENT=production): without it nothing is sent.', 0),
  ('zoho_books_restore_checked_at', '', 'string', 'zoho_books', 'Set by the app: when the scheduled Zoho sync last checked every record already in Zoho Books and put back what had been deleted or changed there (once a day; Sync Now always checks). Leave it as it is.', 0),
  ('zoho_books_accounts_domain',    'accounts.zoho.in', 'string', 'zoho_books', 'Zoho OAuth token endpoint domain — region-specific, matching whichever data center the Zoho One account lives in.', 0),
  ('zoho_books_api_domain',         'www.zohoapis.in', 'string', 'zoho_books', 'Zoho Books API domain — matches zoho_books_accounts_domain''s region (www.zohoapis.com / .eu / .in / .com.cn / .com.au).', 0);

-- Addition beyond the spec's named key list: the signature/seal on generated
-- documents are electronic marks, not a scan of a wet-ink signature or a
-- physical company seal, so a short disclaimer is shown under the signature
-- block. Both the on/off toggle and the wording itself are Admin-editable
-- here (not hardcoded in the templates) so the text can be revised at any
-- time without a code change — e.g. once a physical company seal exists.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('show_generated_document_disclaimer', '0', 'boolean', 'documents', 'Show the "system-generated document" disclaimer under the signature block on any generated document that carries a signature/seal image. Defaults to OFF (90-item backlog #21) — set to 1 to show it everywhere.', 0),
  ('generated_document_disclaimer_text', 'This is a system-generated document. The signature and company seal shown are NexaCrest''s authorised electronic signature and digital company seal, applied automatically by the order management system under internal document-authorisation controls.', 'string', 'documents', 'Exact wording shown under the signature block when the disclaimer above is enabled. Edit freely — no code change needed.', 0);

-- Consignee/Notify Party section visibility on buyer-facing documents
-- (QT/PI/OC/PL/CI). These only ever matter when the Consignee is the
-- same as the Buyer (resp. the Notify Party effectively the same as the
-- Buyer, tracing through the Consignee) — DocumentDataAssembler::assemble()
-- ALWAYS shows the section the moment it is genuinely different from the
-- Buyer, regardless of either setting below; a real difference can never
-- be hidden. Default '1' on both reproduces the system's original
-- behaviour (both sections always printed, even when identical to the
-- Buyer) so turning this feature on changes nothing until an Admin
-- deliberately flips one off.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('always_show_consignee_section', '1', 'boolean', 'documents', 'Print the CONSIGNEE DETAILS section on QT/PI/OC/PL/CI even when the Consignee is identical to the Buyer. Set to 0 to omit that section whenever Consignee = Buyer — it still always prints the moment the two genuinely differ, regardless of this setting.', 0),
  ('always_show_notify_party_section', '1', 'boolean', 'documents', 'Print the NOTIFY PARTY section on PI/PL/CI even when the Notify Party is identical to the Buyer (directly, or via "same as Consignee" when the Consignee itself is the Buyer). Set to 0 to omit that section whenever Notify Party = Buyer — it still always prints the moment the two genuinely differ, regardless of this setting.', 0);

-- Wet-signature-required flags. This was discussed early on but left
-- unbuilt (docs/SOP/README.md "Still pending", task tracker item #106):
-- recordBuyerPo() (Stage 2) and confirmSupplierSigned() (Stage 5) have
-- always let staff pass their gate with a button click alone — the
-- upload-signed-copy endpoint next to each one is a separate, optional
-- action, never actually required to advance the stage (see the "Real
-- gap this closes" docblocks on uploadBuyerPoDocument()/
-- uploadSupplierPoDocument() in OrderController). That is the loophole:
-- a stage can be confirmed "signed" with zero physical evidence ever
-- attached to the order. These two flags close it — default ON, so the
-- gate now refuses to pass without at least one uploaded copy already on
-- file for that PO — while staying Admin-toggleable per document type
-- for a workflow that genuinely doesn't need the physical copy (e.g. a
-- trusted repeat counterparty operating purely on emailed confirmations).
-- Amendments need no equivalent flag: attachSignedCopyAndActivate() is
-- already the ONLY path to an amendment becoming active, so that gate was
-- never bypassable in the first place.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('wet_signature_required_buyer_po', '1', 'boolean', 'workflow', 'When on, Stage 2 (Buyer PO) cannot be confirmed until the buyer''s signed PO copy has been uploaded for this order via "Attach Buyer PO copy". Default on — this is the fix for a real gap: recording just a reference number used to be enough to pass the gate.', 0),
  ('wet_signature_required_supplier_po', '1', 'boolean', 'workflow', 'When on, Stage 5 (Supplier PO) cannot be confirmed signed until the supplier''s signed acknowledgment copy has been uploaded for the current Supplier PO version. Default on — same class of gap as the Buyer PO flag above.', 0);

-- Protected by default: the 12 is_sensitive fields (identity/banking/
-- compliance — a wrong edit is real financial/legal damage), plus the 3
-- document reference-format strings (changing one mid-year breaks
-- continuity with everything already issued under the old format — a
-- one-way door, not a "your call" field). Everything else in this table
-- stays unlocked; unprotecting any of these later goes through
-- field_protection_requests, never a direct edit.
UPDATE company_settings SET is_protected = 1 WHERE is_sensitive = 1;
UPDATE company_settings SET is_protected = 1
  WHERE setting_key IN ('master_tracking_ref_format', 'order_ref_format',
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
INSERT INTO watermark_settings (scope, is_draft_mode, mode, text_content, font, font_size, color, opacity, angle, image_opacity) VALUES
  ('global', 1, 'both', 'DRAFT — NOT FOR RELEASE', 'Helvetica', 60, '#a8701f', 0.15, 45, 0.05),
  ('global', 0, 'both', 'NEXACREST INTERNATIONAL — ORIGINAL', 'Helvetica', 50, '#7a7a7a', 0.15, 45, 0.05);

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
   'Dear {buyer_contact_person},\n\nPlease find attached our Quotation {document_reference} dated {generated_date} for your reference (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nThis quotation is valid until {quotation_valid_until}. Please let us know if you have any questions or would like to proceed.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_pi', 'Proforma Invoice {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached Proforma Invoice {document_reference} dated {generated_date}, issued against Quotation {quotation_ref} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nThis Proforma Invoice is valid until {pi_valid_until}. {payment_request_sentence}\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_oc', 'Order Confirmation {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nWe are pleased to confirm your order. Please find attached Order Confirmation {document_reference} dated {generated_date}, issued against Proforma Invoice {pi_ref} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nProduction will proceed as per the schedule stated in the attached document.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_ci', 'Commercial Invoice {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached Commercial Invoice {document_reference} dated {generated_date} for your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{payment_request_sentence}\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_fdn', 'Debit Note {document_reference} — Payment Required Before Shipment',
   'Dear {buyer_contact_person},\n\nPlease find attached Debit Note {document_reference} dated {generated_date} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nPayment is required within 3 Calendar Days of this Debit Note''s date. We will confirm shipment booking only upon receipt of full freight payment.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('payment_followup', 'Payment Follow-Up — Order {order_reference}',
   'Dear {buyer_contact_person},\n\nThis is a follow-up regarding the pending payment on your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}). Kindly arrange the payment at your earliest convenience and share the remittance copy so we can proceed.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('shipment_readiness', 'Shipment Readiness Confirmation — Order {order_reference}',
   'Dear {buyer_contact_person},\n\nWe are pleased to confirm that your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}) is packed and ready for shipment. We will share the shipment documents with you as they are issued.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('bl_copy_sent', 'Bill of Lading Copy — Order {order_reference}',
   'Dear {buyer_contact_person},\n\nPlease find attached the scanned copy of the Bill of Lading for your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}). BL originals will be couriered upon clearance of the balance payment, per the agreed terms.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('balance_receipt_confirmation', 'Balance Payment Received — Order {order_reference}',
   'Dear {buyer_contact_person},\n\nWe confirm receipt and clearance of the balance payment for your order {order_reference} (Buyer Inquiry Ref: {buyer_inquiry_ref}). Thank you for your business — we are proceeding with final despatch formalities.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  -- 90-item backlog #12: "Send to Buyer" is now generalized to every
  -- customer_facing document type by document_types.category rather than
  -- a hand-maintained code list (see EmailDispatchController::renderCompose()'s
  -- send_{code} auto-guess) — these 4 close the remaining content gap so
  -- every customer_facing type lands on a ready preview, not an empty
  -- "Select a template…" picker, the same as QT/PI/OC/CI/FDN already did.
  ('send_buyerpo', 'Buyer PO — Order Acceptance {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached our formal acceptance of your Purchase Order, {document_reference} dated {generated_date}, issued against Quotation {quotation_ref} (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_annexa', 'Annexure A {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached Annexure A {document_reference} dated {generated_date}, accompanying your order (Buyer Inquiry Ref: {buyer_inquiry_ref}). This annexure must always be read together with its parent document.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_pl', 'Packing List {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached Packing List {document_reference} dated {generated_date} for your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  ('send_ble', 'Bill of Lading Endorsement {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached the Bill of Lading Endorsement {document_reference} dated {generated_date} for your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}'),
  -- QA batch 3 MAIL-7: the Export Service Invoice (customer_facing) can be e-mailed too.
  ('send_esi', 'Export Service Invoice {document_reference} — {company_name}',
   'Dear {buyer_contact_person},\n\nPlease find attached Export Service Invoice {document_reference} dated {generated_date} for the services on your order (Buyer Inquiry Ref: {buyer_inquiry_ref}).\n\nKindly arrange the payment as per the terms stated in the invoice.\n\n{sender_signature}',
   '{company_name} | {company_email} | {company_phone}');

-- 90-item backlog #37 (email-templates completeness audit): these 7
-- system/transactional emails (2FA login code, both password resets, the
-- client-portal invite, and the 3 staff-generated one-time links) used to
-- have their subject/body hardcoded inline in PHP, bypassing this table
-- entirely. EmailTemplateRepository::renderOrDefault() now reads from
-- here first and falls back to the exact original hardcoded wording only
-- if a row is missing/deactivated — these rows are seeded with that exact
-- wording, so nothing changes for anyone until an admin actually edits
-- one. Each one's own token set (different from the buyer-document tokens
-- above) is listed in EmailTemplateController::availableTokens().
INSERT INTO email_templates (template_key, subject, body, footer) VALUES
  ('two_factor_code', 'Your NexaCrest login verification code',
   'Your verification code is: {code}\nThis code expires in 10 minutes. If you did not request this, contact your administrator.',
   NULL),
  ('client_portal_invite', 'Your NexaCrest order portal access',
   'Hello,\n\nYour advance payment has been received and cleared — thank you.\n\nYou can now log in to track your order and download your documents (Quotation, Proforma Invoice, and everything issued from here onward) at any time.\n\nTo set up your login, open this link within 72 hours (it works once):\n{set_password_link}\n\nYour login email will be: {email}\n\nAfter that, log in any time at:\n{login_link}\n\nNexaCrest International Private Limited',
   NULL),
  ('client_login_code', 'Your NexaCrest order portal login code',
   'Hello,\n\nYour code to log in to your NexaCrest order portal is: {code}\n\nIt is valid for 10 minutes.\n\nIf you did not try to log in, you can ignore this e-mail — nobody can log in without this code. If it keeps happening, change your password with "Forgot your password?" on the login page.\n\nNexaCrest International Private Limited',
   NULL),
  ('staff_password_reset', 'Reset your NexaCrest password',
   'Hello {name},\n\nA password reset was requested for your NexaCrest Export Operations account ({email}).\n\nTo set a new password, open this link within 45 minutes:\n{reset_link}\n\nIf you didn''t request this, you can ignore this email — your password will not be changed.',
   NULL),
  ('client_portal_password_reset', 'Reset your NexaCrest order portal password',
   'Hello,\n\nA password reset was requested for your NexaCrest order portal account ({email}).\n\nTo set a new password, open this link within 72 hours:\n{reset_link}\n\nIf you didn''t request this, you can ignore this email — your password will not be changed.',
   NULL),
  ('quotation_correction_link', 'Your NexaCrest quotation request — correction link',
   'Hello,\n\nThank you for your quotation request. We''ll review it and get back to you within 24 hours.\n\nSpotted a mistake in what you submitted? You can correct it yourself, as long as we haven''t already processed it, at:\n{correction_link}\n\nThis link works for 7 days.\n\nNexaCrest International Private Limited',
   NULL),
  ('pi_form_link', 'NexaCrest — confirm your PI-stage details',
   'Hello,\n\nThank you for accepting our Quotation for order {order_reference}.\n\nTo issue your Proforma Invoice, please confirm your shipping/consignee details at:\n{pi_form_link}\n\nThis link works for 30 days.\n\nNexaCrest International Private Limited',
   NULL),
  ('buyer_action_link', 'NexaCrest — acknowledge your order / upload your Buyer PO',
   'Hello,\n\nFor order {order_reference}, please use this link to download your Purchase Order, upload the signed copy with your PO number, and acknowledge your Order Confirmation once you receive it:\n{buyer_action_link}\n\nThis link works for 90 days.\n\nNexaCrest International Private Limited',
   NULL);

-- ================================================================
-- ASSETS — placeholder images generated for every slot (see /assets
-- screen). Paths below assume STORAGE_BASE_PATH = <project>/storage;
-- adjust if you set a different STORAGE_BASE_PATH in .env.
-- ================================================================
-- Real company assets, extracted from the source Word document set
-- (Set 1's embedded media) rather than generic placeholders.
INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'logo', 'NexaCrest Logo', '__STORAGE_BASE_PATH__/assets/logos/logo.jpg', 'image/jpeg', 1, u.id
FROM users u WHERE u.email = 'md@email.com';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'signature', 'Managing Director — Signature (legacy global slot, superseded by user_signature_assets)', '__STORAGE_BASE_PATH__/assets/signatures/md_signature_default.png', 'image/png', 1, u.id
FROM users u WHERE u.email = 'md@email.com';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'seal', 'Company Seal', '__STORAGE_BASE_PATH__/assets/seals/company_seal.png', 'image/png', 1, u.id
FROM users u WHERE u.email = 'md@email.com';

INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
SELECT 'watermark', 'Watermark — Logo', '__STORAGE_BASE_PATH__/assets/watermarks/watermark_logo.jpg', 'image/jpeg', 1, u.id
FROM users u WHERE u.email = 'md@email.com';

-- 90-item backlog #7: the two watermark_settings rows above are seeded
-- with mode='both' but this INSERT never set their image_asset_id (it
-- didn't exist yet at that point in the file) — DocumentGenerationService
-- ::watermarkFromRow() only shows the image half of "both" when
-- image_asset_id is actually set, so every document rendered with the
-- seeded defaults silently dropped the image watermark and showed text
-- only. Backfill it now that the watermark asset row above exists.
UPDATE watermark_settings ws
JOIN assets a ON a.asset_type = 'watermark' AND a.is_active = 1
SET ws.image_asset_id = a.id
WHERE ws.mode IN ('image', 'both') AND ws.image_asset_id IS NULL;

-- ================================================================
-- SIGNATORIES & DESIGNATIONS (Section M, added 2026-09-20)
-- The company seal above stays the single shared company asset. The two
-- Directors below each carry their own real signature/designation-seal
-- images, extracted from the source documents (Managing Director) and
-- supplied directly by the company (Executive Director).
-- ================================================================
-- 'Director' kept as a generic, unused-by-default title for any future
-- signatory who isn't a protected founder account. The Managing Director and the Executive Director each
-- get their own specific designation below instead, since that title is
-- what's printed on generated documents next to their signature/seal.
INSERT INTO designations (title, is_active) VALUES
  ('Director', 1),
  ('Founder & Managing Director', 1),
  ('Founder & Executive Director', 1);

-- The Managing Director already exists as the seeded Admin login
-- (md@email.com) — mark the account signatory-eligible with its
-- own designation and set her as the company's global default signatory,
-- matching the existing company_settings.md_name/md_title.
UPDATE users u
JOIN designations d ON d.title = 'Founder & Managing Director'
SET u.designation_id = d.id, u.is_signatory_eligible = 1
WHERE u.email = 'md@email.com';

-- Role: Executive Director, matching her real designation — she IS one of
-- the company's two founders and will be marked is_protected_account below
-- (Section Z), so this is a one-time correction, not something meant to be
-- revisited from the Users screen the way an ordinary account's role would be.
INSERT INTO users (name, email, phone, password_hash, role_id, designation_id, is_signatory_eligible, is_active, force_password_change, two_fa_enabled)
SELECT 'Executive Director', 'ed@email.com', NULL,
       '$2y$12$SRa3a47hKlgsRGskEZfWJerGPgxLI8jSnVlckkHENnfs9VRao/You',
       r.id, d.id, 1, 1, 1, 0
FROM roles r, designations d WHERE r.name = 'Executive Director' AND d.title = 'Founder & Executive Director';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'signature', 'Default', '__STORAGE_BASE_PATH__/assets/signatures/md_signature_default.png', 'image/png', 1, 1, u.id
FROM users u WHERE u.email = 'md@email.com';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'designation_seal', 'Director Seal', '__STORAGE_BASE_PATH__/assets/designation_seals/md_director_seal.png', 'image/png', 1, 1, u.id
FROM users u WHERE u.email = 'md@email.com';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'designation_seal', 'Director Seal', '__STORAGE_BASE_PATH__/assets/designation_seals/ed_director_seal.webp', 'image/webp', 1, 1,
       (SELECT id FROM users WHERE email = 'md@email.com')
FROM users u WHERE u.email = 'ed@email.com';

INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, is_active, uploaded_by)
SELECT u.id, 'signature', 'Default', '__STORAGE_BASE_PATH__/assets/signatures/ed_signature_default.png', 'image/png', 1, 1, u.id
FROM users u WHERE u.email = 'ed@email.com';

-- ================================================================
-- SUPER ADMIN TIER (Section N, added 2026-09-20)
-- Both founders are the initial permanent Super Admins — real-world
-- The Managing Director is NexaCrest's Founder & MD and the Executive
-- Director its Founder & ED, the obvious first holders of
-- the unrestricted tier. Promote/demote further (non-founder) holders
-- from /super-admin once logged in.
-- ================================================================
UPDATE users SET is_super_admin = 1
WHERE email IN ('md@email.com', 'ed@email.com');

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
WHERE email IN ('md@email.com', 'ed@email.com');

-- Global default signatory = the Managing Director (matches legacy md_name).
INSERT INTO company_default_signatory (id, user_id, updated_by)
SELECT 1, u.id, u.id FROM users u WHERE u.email = 'md@email.com';

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
WHERE dt.code = 'AMD' AND u.email = 'md@email.com';

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
- If Annexure A is attached — Annexure is signed and sealed by the Managing Director (Unsigned Annexure is a hanging document with no legal authority. Never send unsigned.) ⚠ An unsigned Annexure can be modified by anyone — always sign before sending
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
- BL ENDORSEMENT — on the BACK of each of the 3 original BLs: apply NexaCrest rubber company stamp; sign over the stamp: the Managing Director (wet signature or rubber signature stamp); write date of endorsement; write: For NexaCrest International Private Limited. All 3 originals must be endorsed before couriering. Check all 3 — not just one.
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
FROM users u WHERE u.email = 'md@email.com';

-- ================================================================
-- HS CODE BULK IMPORT + PRODUCT GUIDE (Section AL) — the business's own
-- granite/marble HS Code Quick Reference (CAPEXIL registration
-- RCMC/CAPEXIL/03217/2026-2027), imported wholesale via the new
-- /hs-codes bulk-import action rather than typed in one at a time.
-- usage_note is only filled in where the Product Guide sheet gives an
-- unambiguous signal for that exact code — left NULL rather than guessed
-- everywhere else.
-- ================================================================
INSERT INTO hs_codes (code, description, usage_note, is_active, created_by)
SELECT v.code, v.description, v.usage_note, 1, u.id
FROM (
  SELECT '25161100' AS code, 'Granite - crude / roughly trimmed' AS description, 'Roughly trimmed only, not squared on all sides - a block that has been squared belongs in 25161200 instead.' AS usage_note
  UNION ALL SELECT '25161200', 'Granite - cut into blocks or slabs', 'Squared/cut raw blocks or slabs (still unpolished) - e.g. a cut-to-size pillar block, all sides cut/squared.'
  UNION ALL SELECT '25162000', 'Sandstone - raw', NULL
  UNION ALL SELECT '25169010', 'Other monumental stone - Pakur stone', NULL
  UNION ALL SELECT '25169020', 'Other monumental stone - boulders', NULL
  UNION ALL SELECT '25169090', 'Other monumental stone - other', NULL
  UNION ALL SELECT '68010000', 'Setts, curbstones, flagstones (natural stone)', NULL
  UNION ALL SELECT '68022310', 'Granite blocks or tiles, polished', 'Polished, flat, custom-to-size - custom-to-size slabs, gangsaw-size slabs, granite tiles, or a flat (unshaped) monument blank.'
  UNION ALL SELECT '68022390', 'Granite - other worked', 'Polished but irregular/random-sized slabs.'
  UNION ALL SELECT '68022900', 'Other worked stone (non-granite/marble)', NULL
  UNION ALL SELECT '68029100', 'Marble, travertine, alabaster - worked', NULL
  UNION ALL SELECT '68029300', 'Granite - worked (finished / monuments)', 'Pre-shaped/profiled monument blanks, complete ready-to-install monument sets, finished/engraved tombstones, and carved 3D articles (e.g. a vase).'
  UNION ALL SELECT '68029900', 'Other worked stone', NULL
) v
CROSS JOIN users u
WHERE u.email = 'md@email.com'
  AND NOT EXISTS (SELECT 1 FROM hs_codes existing WHERE existing.code = v.code);

INSERT INTO hs_code_product_examples (product_description, code_reference, note, sort_order, created_by)
SELECT v.product_description, v.code_reference, v.note, v.sort_order, u.id
FROM (
  SELECT 1 AS sort_order, 'Custom-to-size slabs (polished)' AS product_description, '68022310' AS code_reference, NULL AS note
  UNION ALL SELECT 2, 'Random slabs (polished, irregular)', '68022390', NULL
  UNION ALL SELECT 3, 'Gangsaw size slabs (polished)', '68022310', NULL
  UNION ALL SELECT 4, 'Monument slabs / blanks', '68022310 / 68029300', 'Flat blank -> .2310; pre-shaped/profiled blank -> .9300 - check per SKU'
  UNION ALL SELECT 5, 'Complete ready-to-install monument set', '68029300', NULL
  UNION ALL SELECT 6, 'Cut-to-size raw block (e.g. pillar, unpolished)', '25161200', 'Squared but unworked -> stays in Ch.25'
  UNION ALL SELECT 7, 'Complete raw block, all sides cut/squared', '25161200', 'Roughly trimmed only (not squared) -> 25161100'
  UNION ALL SELECT 8, 'Granite tiles (polished)', '68022310', NULL
  UNION ALL SELECT 9, 'Ready-to-install tombstone (finished/engraved)', '68029300', NULL
  UNION ALL SELECT 10, 'Granite vase', '68029300', 'Carved 3D article'
) v
CROSS JOIN users u
WHERE u.email = 'md@email.com';

-- Client-verification reference documents (Factory/Quarry SOPs and
-- agreement templates) plus the company's own blank legal-document
-- templates (Sales & Supply Agreement, Commercial NDA, Staff NDA — these
-- three added so staff always have the current template a single click
-- away instead of hunting for a copy on a laptop) for the internal
-- Reference Library (Section Y, reference_library_documents). The actual
-- files ship in git under storage/assets/reference_library/ alongside
-- the other committed assets (logos, seals, signatures) so a fresh
-- clone/deploy always has them — unlike storage/internal/reference_library/,
-- which is gitignored runtime state for staff-uploaded custom entries,
-- not these seven fixed originals.
INSERT INTO reference_library_documents (title, file_path, file_original_name, file_mime_type) VALUES
  ('Quarry SOP – Block Selection & Reservation', '__STORAGE_BASE_PATH__/assets/reference_library/quarry_sop_block_selection_and_reservation.pdf', 'Quarry SOP – Block Selection & Reservation.pdf', 'application/pdf'),
  ('Factory SOP – Processing, QC & Packing', '__STORAGE_BASE_PATH__/assets/reference_library/factory_sop_processing_qc_and_packing.pdf', 'Factory SOP – Processing, QC & Packing.pdf', 'application/pdf'),
  ('Factory Processing Agreement (Template)', '__STORAGE_BASE_PATH__/assets/reference_library/factory_processing_agreement_template.docx', 'Factory Processing Agreement (Template).docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
  ('Quarry Block Supply Agreement (Template)', '__STORAGE_BASE_PATH__/assets/reference_library/quarry_block_supply_agreement_template.docx', 'Quarry Block Supply Agreement (Template).docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
  ('International Sales & Supply Agreement (Template)', '__STORAGE_BASE_PATH__/assets/reference_library/sales_supply_agreement_template.docx', 'International Sales & Supply Agreement (Template).docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
  ('Mutual NDA — Commercial Counterparty (Template)', '__STORAGE_BASE_PATH__/assets/reference_library/nda_commercial_counterparty_template.docx', 'Mutual NDA - Commercial Counterparty (Template).docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
  ('Staff Confidentiality & NDA (Template)', '__STORAGE_BASE_PATH__/assets/reference_library/nda_staff_template.docx', 'Staff Confidentiality and NDA (Template).docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
  ('Supplier & Logistics Payments Guide (PDF)', '__STORAGE_BASE_PATH__/assets/reference_library/supplier_and_logistics_payments_guide.pdf', 'Supplier_and_Logistics_Payments_Guide.pdf', 'application/pdf'),
  ('Supplier & Logistics Payments Guide (Word)', '__STORAGE_BASE_PATH__/assets/reference_library/supplier_and_logistics_payments_guide.docx', 'Supplier_and_Logistics_Payments_Guide.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

-- ================================================================
-- COMPLIANCE TASK TYPES — the defaults the checklist on every order ships
-- with; Admin can add more later from the Compliance Task Types screen.
-- ================================================================
-- task_key (D-262): the fixed code the app acts on, so renaming a task never switches its gate off.
INSERT INTO compliance_task_types (name, phase, task_key) VALUES
  ('ECGC Cover', 'order', 'ecgc_cover'),
  ('Pre-Shipment Inspection', 'shipment', 'pre_shipment_inspection'),
  ('Fumigation Certificate', 'shipment', 'fumigation_certificate'),
  ('Phytosanitary Certificate', 'shipment', 'phytosanitary_certificate'),
  ('Due Diligence', 'order', 'due_diligence');

-- ================================================================
-- MAIL REDIRECT & CC (Section AS) — off by default (mail_redirect_enabled
-- = '0'), so nothing changes for any installation until Admin turns it on.
-- mail_cc_emails/mail_default_cc_email ship blank and are Super-Admin-only
-- to edit (requires_super_admin = 1).
-- ================================================================
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin) VALUES
  ('mail_redirect_enabled', '0', 'boolean', 'mail_redirect', 'Independent of Test Mode — when on, every non-security outbound email (order updates, document sends, etc.) is redirected to mail_redirect_address below instead of its real recipient. Staff 2FA codes and password-reset links are never redirected.', 0, 0),
  ('mail_redirect_address', '', 'email', 'mail_redirect', 'Where redirected mail goes when mail_redirect_enabled is on. Required for the redirect to actually work — if left blank while enabled, redirected mail is logged, never sent.', 0, 0),
  ('mail_cc_emails', '', 'email_list', 'mail_redirect', 'Comma-separated additional CC addresses applied to every non-security outbound email, regardless of mail_redirect_enabled. Super Admin only.', 0, 1),
  ('mail_default_cc_email', '', 'email', 'mail_redirect', 'A single default CC address always applied to every non-security outbound email, in addition to mail_cc_emails above. Super Admin only.', 0, 1);

-- ================================================================
-- DEFERRED-MAIL ADMIN CONTROLS + OC ACKNOWLEDGMENT OVERRIDE (Section AU)
-- All default to today's existing behaviour unchanged: sending is on,
-- the 2-level approval queue is on, the OC override stays open to
-- manage_orders, and the auto-confirm window stays 48 hours.
-- ================================================================
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin) VALUES
  ('email_testing_mode', '0', 'boolean', 'mail', 'D-280: Testing mode — hold e-mails to buyers and suppliers. On = e-mails to buyers and suppliers are not sent (the screen says so and shows the link to copy); staff and associate e-mails and every MUST e-mail (login codes, set-password and forgot-password links, security alerts) still go out. Set in Admin › E-mail settings, with the per-e-mail switches.', 0, 0),
  ('reply_to_email', '', 'email', 'mail', 'External QA report L-43: customer emails (QT/PI/OC/etc.) went out from a no-reply address with no Reply-To, so a buyer reply was lost. Set a monitored sales/support address here so replies reach someone; leave blank to fall back to company_settings.email.', 0, 0),
  ('mail_approval_queue_enabled', '1', 'boolean', 'mail', 'On (default) = every buyer-facing document send still needs a second person''s Level-2 approval before it goes out (Section 10). Off = a single staff member with generate_documents can send directly — useful for a small team or while testing.', 0, 0),
  ('oc_ack_override_restricted', '0', 'boolean', 'orders', 'Off (default) = any user with manage_orders can record a buyer''s Order Confirmation acknowledgment manually, as before. On = that action also requires the override_buyer_acknowledgment permission, so it can be restricted to specific roles.', 0, 0),
  ('oc_ack_auto_confirm_hours', '48', 'number', 'orders', 'Hours after the Order Confirmation email is sent before the order auto-confirms if the buyer hasn''t responded (docs/schema.sql Section AE). Lower it for testing; 48 is the production default.', 0, 0),
  ('lead_time_display_unit', 'days', 'string', 'orders', '90-item backlog item 16: whether the Production/Sailing Lead Time ranges on the Order Detail page show in days (default) or weeks. Display only — the stored figures and every input field stay in days regardless.', 0, 0);

-- ================================================================
-- STAFF CLIENT-PORTAL IMPERSONATION (Section AV) — off by default. Also
-- requires the per-client allow_staff_impersonation flag (clients table)
-- and the impersonate_client permission (Admin/MD/ED/Super Admin only by
-- default, same tier as manage_disputes/manage_order_financials).
-- ================================================================
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin) VALUES
  ('client_impersonation_enabled', '0', 'boolean', 'clients', 'Global switch for staff "Log in as this client" (Section AV). Off = the feature is unavailable for every client, regardless of any individual client''s own allow_staff_impersonation flag or who holds the impersonate_client permission.', 0, 0);

-- ================================================================
-- 90-item backlog Phase 6 Item B — global default for whether a client's
-- own "My Orders" portal dashboard shows their full order history
-- (including completed/lost orders) or active orders only. On by default
-- to preserve existing behavior (the portal has always shown everything).
-- Any individual client's own order_history_visibility_override (clients
-- table) takes precedence over this when set.
-- ================================================================
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin) VALUES
  ('client_portal_show_order_history', '1', 'boolean', 'clients', 'On (default) = every client sees their full order history, including completed/lost orders, on their portal dashboard. Off = clients see active/disputed orders only, unless an individual client''s own override says otherwise.', 0, 0);

-- External QA report M-02: same global + per-client override pattern as
-- client_portal_show_order_history above, for document-download
-- visibility. Any individual client's own
-- document_downloads_visibility_override (clients table) takes
-- precedence over this when set.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive, requires_super_admin) VALUES
  ('client_portal_show_document_downloads', '1', 'boolean', 'clients', 'On (default) = every client can download their own order''s approved/sent customer-facing documents from the portal. Off = the Documents section is hidden and direct download links are refused, unless an individual client''s own override says otherwise.', 0, 0);

-- ================================================================
-- SECTION BA — LEGAL TERMS & DEFINITIONS clauses (admin-editable via
-- tc_clauses, same table/CRUD pattern as every other T&C clause — see
-- docs/schema.sql Section BA for the clause_group/visibility_rule
-- columns this relies on). Applies to every buyer-facing document that
-- carries the new "N. LEGAL TERMS & DEFINITIONS" section: QT, PI, OC,
-- PL, CI, FDN (Debit Note), BUYERPO, ANNEXA — never BLI.
-- ================================================================

-- --- LEGAL TERMS box (red) — 6 clauses, clause_order 1-6 within the group ---
INSERT INTO tc_clauses (clause_order, clause_title, clause_text, clause_group, visibility_rule, status, is_protected) VALUES
  (1, 'Cancellation', 'Orders may not be cancelled after production commences. The Advance Payment is non-refundable once production commences. In the event of cancellation after production commences, NexaCrest''s total recovery shall not exceed the Advance Payment plus 20% of the total FOB Order value. The parties agree this represents a genuine pre-estimate of NexaCrest''s unrecoverable production costs, overhead, opportunity loss, and market price risk.', 'legal_terms', 'always', 'active', 1),
  (2, 'Limitation of Liability', 'NexaCrest''s maximum liability under this document shall not exceed the total FOB Order value stated herein. NexaCrest is not liable for any indirect, consequential, or punitive damages, or for loss of profit or business.', 'legal_terms', 'always', 'active', 1),
  (3, 'Production Timeline', 'The estimated shipment timeline stated in this document is indicative only and does not constitute a guaranteed delivery date. NexaCrest will make every effort to meet the estimated timeline. NexaCrest will notify the Buyer in writing within 5 Business Days of becoming aware of any delay to production or shipment, and will provide a revised estimated timeline within 10 Business Days of such notification.', 'legal_terms', 'always', 'active', 1),
  (4, 'Re-inspection and Loading', 'NexaCrest shall load only the confirmed passed pieces. Confirmed failed pieces shall be excluded from the shipment. The Commercial Invoice and all payment obligations shall be adjusted to reflect only the pieces actually loaded and shipped. Neither Party shall have any further claim in respect of the excluded pieces.', 'legal_terms', 'always', 'active', 1),
  (5, 'Structural Defect Claims', 'NexaCrest confirms to the best of its knowledge that Products are free from structural defects present at Loading and not detectable at Pre-loading Inspection. No claim accepted for: (a) conditions detectable at Pre-loading Inspection; (b) damage from Buyer''s processing, cutting, handling, or installation; or (c) natural stone characteristics. Any claim must be raised by the Buyer in writing with photographic evidence within 14 Calendar Days of confirmed receipt at Buyer''s premises. No claim accepted after this period.', 'legal_terms', 'always', 'active', 1),
  (6, 'Governing Law and Dispute Resolution', 'This document is governed by the laws of India. Any dispute shall first be notified in writing by email or WhatsApp. Both parties shall attempt amicable resolution within 45 Calendar Days of written notice. If unresolved, the dispute shall be referred to binding arbitration under the Arbitration and Conciliation Act, 1996. Seat: Bengaluru, Karnataka, India. Language: English. The United Nations Convention on Contracts for the International Sale of Goods is expressly excluded from this Agreement.', 'legal_terms', 'always', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_group = 'legal_terms' AND dt.code IN ('QT','PI','OC','PL','CI','FDN','BUYERPO','ANNEXA');

-- --- DEFINITIONS box (blue) — 12 entries, clause_order 1-12 within the group ---
INSERT INTO tc_clauses (clause_order, clause_title, clause_text, clause_group, visibility_rule, status, is_protected) VALUES
  (1, 'NexaCrest', 'In this document, "NexaCrest International Private Limited" is referred to as "NexaCrest". Both names refer to the same legal entity.', 'definitions', 'always', 'active', 1),
  (2, 'T/T (Telegraphic Transfer)', 'an electronic bank wire transfer sent directly from the Buyer''s bank to NexaCrest''s designated bank account at State Bank of India as stated in this document.', 'definitions', 'always', 'active', 1),
  (3, 'Payment Cleared', 'the relevant payment confirmed as actually credited to NexaCrest''s bank account by NexaCrest''s accounts team. A Remittance Copy alone does not constitute Payment Cleared.', 'definitions', 'always', 'active', 1),
  (4, 'Remittance Copy', 'the bank transfer receipt or SWIFT confirmation sent by the Buyer as evidence of transfer. Receipt of a Remittance Copy by NexaCrest does not constitute Payment Cleared.', 'definitions', 'always', 'active', 1),
  (5, 'Advance Payment', 'the advance percentage of the FOB Order value paid by T/T before production commences, as stated in this document.', 'definitions', 'always', 'active', 1),
  (6, 'Balance Payment', 'the remaining FOB Order value after the Advance Payment, paid by T/T as per the payment trigger stated in this document.', 'definitions', 'always', 'active', 1),
  (7, 'Debit Note', 'a written demand issued by NexaCrest for any agreed cost additional to the FOB Order value, including but not limited to freight, cargo insurance, inspection fees, or certificate fees. Each Debit Note must be confirmed as Payment Cleared before NexaCrest proceeds with the related activity.', 'definitions', 'always', 'active', 1),
  (8, 'Loading', 'the physical act of stuffing Products into the container at NexaCrest''s designated processing facility. Loading is distinct from Shipment.', 'definitions', 'always', 'active', 1),
  (9, 'Shipment', 'the departure of the vessel from the port of loading. Shipment is distinct from Loading and from Delivery.', 'definitions', 'always', 'active', 1),
  (10, 'FCL (Full Container Load)', 'a shipment occupying one complete container, approximately 24-27 MT including packing. Minimum order quantity is 1 FCL.', 'definitions', 'always', 'active', 1),
  (11, 'Production Commences', 'the point at which NexaCrest begins manufacturing the Products for this Order. Production commences only after the Advance Payment is confirmed as Payment Cleared. The Advance Payment is non-refundable from this point.', 'definitions', 'always', 'active', 1),
  (12, 'Shipment Readiness Confirmation', 'a written notification from NexaCrest by email or WhatsApp confirming that the Products have passed quality inspection (NexaCrest''s internal quality check, or third-party Pre-loading Inspection where applicable), are fully packed, and are ready for vessel booking.', 'definitions', 'always', 'active', 1);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_group = 'definitions' AND dt.code IN ('QT','PI','OC','PL','CI','FDN','BUYERPO','ANNEXA');

-- --- Bill of Lading Policy — ordinary T&C-body clause (not a Legal
-- Terms/Definitions box entry), QT/PI/OC/CI only, and ONLY for an order
-- whose payment preset collects the balance against the scanned BL
-- (B_AGAINST_BL) — see docs/schema.sql Section BA's note on
-- visibility_rule. A before-shipment (A_BEFORE_SHIPMENT) order never
-- shows this clause: nothing about "to order of NexaCrest" matters once
-- the balance already cleared before the BL exists, and in that case the
-- BL can be consigned directly to the buyer/consignee. Which clause this
-- rule applies to is itself just this row's own visibility_rule value —
-- change it the same way any other clause is edited, never in code. ---
INSERT INTO tc_clauses (clause_order, clause_title, clause_text, clause_group, visibility_rule, status, is_protected) VALUES
  (96, 'Bill of Lading Policy', 'NexaCrest issues this shipment''s Bill of Lading consigned "To Order of NexaCrest International Private Limited". This is NexaCrest company policy and cannot be changed under any circumstances. Original BLs are released to the Buyer only after Balance Payment is confirmed as Payment Cleared.', 'standard', 'balance_trigger_against_bl', 'active', 0);
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title = 'Bill of Lading Policy' AND dt.code IN ('QT','PI','OC','CI');

-- --- Natural Stone Characteristics — wording corrected to the
-- authoritative text (pre-shipment-sample-approval mechanism), replacing
-- the shorter "no dramatic colour difference" promise that carried no
-- such mechanism. Same clause_id, same linkage — text only. ---
UPDATE tc_clauses SET clause_text = 'The quality of granite supplied is as agreed, selected for colour consistency. As with all natural stone, minor variation in shade, grain and veining may occur between pieces — however, Natural Variation inherent to natural stone including colour, veining, and texture is not a defect. Any claim of colour non-conformance must be assessed against the agreed pre-shipment sample approved by the Buyer in writing prior to Loading. In the absence of an approved pre-shipment sample, the Buyer accepts all colour variation inherent to natural stone. Products will conform to the agreed specification.'
WHERE clause_title = 'Natural Stone Characteristics';

-- --- Old flat Cancellation bullet (clause_order 30, QT/PI/OC T&C body) —
-- deactivated, not deleted. The capped-recovery Cancellation clause now
-- lives only in the Legal Terms box above; keeping both would duplicate
-- and contradict each other (this old wording was an unqualified "cannot
-- be cancelled... for any reason", with no 20%-of-FOB recovery formula).
-- Unprotected first — it ships is_protected=1 like every other clause,
-- and a protected row cannot be deactivated without the unlock gesture;
-- this is the one deliberate exception, made here rather than through
-- that flow since it is a superseded clause, not a live edit. ---
UPDATE tc_clauses SET is_protected = 0 WHERE clause_title = 'Cancellation' AND clause_group = 'standard';
UPDATE tc_clauses SET status = 'inactive' WHERE clause_title = 'Cancellation' AND clause_group = 'standard';

-- --- Document rename: 06_DebitNote (was "Freight Debit Note") — see
-- NexaCrest_Change_Log.txt "DOCUMENT RENAMED". The document_types.code
-- stays FDN (every FK and already-generated document keys on the id,
-- not the code string; renaming the code itself would be a breaking,
-- retroactive change for no benefit) — only the buyer-facing name and
-- reference prefix change. ---
UPDATE document_types SET name = 'Debit Note', ref_format = 'SC/DN/{YY}/{DDMM}{NNN}' WHERE code = 'FDN';

-- --- 90-item backlog #71: ANNEXA was seeded with ref_format = NULL,
-- the same NULL used for genuinely internal/non-generated document types
-- (CHECKLIST, SOP_*, STAGEGATE, WALLREF) — but ANNEXA is
-- 'customer_facing' and goes through the exact same generate/approve/
-- send lifecycle as QT/PI/OC, so it never got a real reference to print
-- on its own meta bar. Gives it the same numbering convention as every
-- other customer-facing document type. ---
UPDATE document_types SET ref_format = 'SC/ANX/{YY}/{DDMM}{NNN}' WHERE code = 'ANNEXA';

-- ================================================================
-- SECTION BQ — UI TEMPLATE SELECTION (added 2026-10-23)
-- ================================================================
INSERT INTO ui_templates (template_key, name, is_active) VALUES
  ('standard', 'Standard', 1),
  ('kanban', 'Kanban Board', 1);

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('default_ui_template', 'standard', 'string', 'ui', 'Which UI template (see Admin > UI Templates) a user sees when they have not chosen one for themselves on their own Account page. Must be an active template — picking it from the dropdown on this screen enforces that.', 0);

-- ================================================================
-- Owner request (2026-10-27): one sample supplier and sample logistics
-- partners, so a fresh install can be tested without adding them first.
-- Deactivate them (Suppliers / Logistics Partners -> Deactivate) once
-- testing is done. Bank details are left empty: the account number is
-- stored encrypted with each install's own key, so it can't be seeded —
-- fill it from the directory or the Vendor Details Link if needed.
-- The freight forwarder is included because Step 9a picks one.
-- ================================================================
INSERT INTO suppliers (supplier_legal_name, address, city, state, gstin, pan, contact_person, contact_person_phone, phone, email, notes, is_active) VALUES
  ('Sample Stone Supplier (for testing)', 'Plot 12, RIICO Industrial Area', 'Kota', 'Rajasthan', '08ABCDE1234F1Z5', 'ABCDE1234F', 'Sales Manager', '+91-0000000000', '+91-0000000000', 'sample.supplier@example.com', 'Sample entry for testing a fresh install — deactivate once testing is done.', 1);
SET @nx_sample_supplier := LAST_INSERT_ID();
INSERT INTO supplier_type_links (supplier_id, supplier_type) VALUES
  (@nx_sample_supplier, 'Quarry Owner'),
  (@nx_sample_supplier, 'Factory Owner');
INSERT INTO supplier_factory_addresses (supplier_id, label, address) VALUES
  (@nx_sample_supplier, 'Kota Factory', 'Plot 12, RIICO Industrial Area, Kota, Rajasthan 324007');

INSERT INTO logistics_partners (partner_name, service_type, address, city, state, phone, email, contact_person_name, contact_person_phone, gstin, pan, notes, is_active) VALUES
  ('Sample CHA & Transport (for testing)', 'both', 'Office 4, Port Road', 'Chennai', 'Tamil Nadu', '+91-0000000000', 'sample.cha@example.com', 'Operations Manager', '+91-0000000000', '33ABCDE1234F1Z6', 'ABCDE1234F', 'Sample entry for testing a fresh install — deactivate once testing is done.', 1),
  ('Sample Freight Forwarder (for testing)', 'freight_forwarder', 'Suite 9, Harbour View', 'Chennai', 'Tamil Nadu', '+91-0000000000', 'sample.forwarder@example.com', 'Operations Manager', '+91-0000000000', '33ABCDE1234F1Z7', 'ABCDE1234F', 'Sample entry for testing a fresh install — deactivate once testing is done.', 1);

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
-- POST-IMPORT STEP (manual, not part of this SQL file):
-- Replace the literal string __STORAGE_BASE_PATH__ with your actual
-- STORAGE_BASE_PATH (the same value as in .env) in every table that uses
-- this placeholder, e.g.:
--   UPDATE assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/absolute/path/to/storage');
--   UPDATE user_signature_assets SET server_path = REPLACE(server_path, '__STORAGE_BASE_PATH__', '/absolute/path/to/storage');
--   UPDATE reference_library_documents SET file_path = REPLACE(file_path, '__STORAGE_BASE_PATH__', '/absolute/path/to/storage');
-- ================================================================

-- Owner decision D-214, phase 1: departments, their designation ladders, the new roles and the
-- memberships of the seeded people (migration docs/migrations/2026-11-01a-departments.sql).
-- Departments
INSERT IGNORE INTO departments (code, name, color, sees_whole_company, sort_order, is_active) VALUES
  ('MGMT',  'Company Management',  '#3a4140', 1, 10, 1),
  ('SALES', 'Sales & Export',      '#2f6fb3', 0, 20, 1),
  ('PROC',  'Procurement',         '#a3591b', 0, 30, 1),
  ('LOG',   'Logistics',           '#6b4fa8', 0, 40, 1),
  ('FIN',   'Accounts & Finance',  '#2c7a4b', 1, 50, 1),
  ('AUDIT', 'Audit & Compliance',  '#6a736f', 1, 60, 1),
  ('ADMIN', 'Administration',      '#6a736f', 0, 70, 1),
  ('OPS',   'Order Desk',          '#9aa39f', 0, 80, 0);

-- New roles (one per new designation). Existing roles are reused for the designations that match them.
INSERT IGNORE INTO roles (name, description, is_system_role) VALUES
  ('Sales/Export Manager',  'Sales & Export department — Manager', 0),
  ('Sales Director',        'Sales & Export department — Director', 0),
  ('Procurement Executive', 'Procurement department — Executive', 0),
  ('Procurement Manager',   'Procurement department — Manager', 0),
  ('Procurement Director',  'Procurement department — Director', 0),
  ('Logistics Manager',     'Logistics department — Manager', 0),
  ('Logistics Director',    'Logistics department — Director', 0),
  ('Finance Manager',       'Accounts & Finance department — Manager', 0),
  ('Finance Director',      'Accounts & Finance department — Director', 0),
  ('System Administrator',  'Administration — users, settings and templates; no business money', 0),
  ('Order Coordinator',     'Order Desk — status of every order, no prices', 0),
  ('Operations Manager',    'Order Desk — Manager', 0);

-- Managers start from their Executive's permissions; Directors from their Manager's.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT n.id, rp.permission_id, rp.is_enabled FROM role_permissions rp JOIN roles o ON o.id = rp.role_id JOIN roles n
 WHERE (o.name = 'Export Executive' AND n.name IN ('Sales/Export Manager', 'Sales Director'))
    OR (o.name = 'Logistics Executive' AND n.name IN ('Logistics Manager', 'Logistics Director'))
    OR (o.name = 'Accounts Executive' AND n.name IN ('Finance Manager', 'Finance Director'));

-- Additional permissions per new role (keys that do not exist are simply skipped).
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r JOIN permissions p ON (
     (r.name IN ('Sales/Export Manager', 'Sales Director') AND p.permission_key IN ('approve_documents', 'approve_payment_terms', 'view_all_orders', 'assign_order_owner', 'manage_disputes', 'manage_complaints'))
  OR (r.name = 'Sales Director' AND p.permission_key IN ('approve_email_send', 'view_staff_reports'))
  OR (r.name IN ('Procurement Executive', 'Procurement Manager', 'Procurement Director') AND p.permission_key IN ('view_orders', 'view_product_catalog', 'browse_product_catalog', 'download_pdf', 'view_own_incentives'))
  OR (r.name IN ('Procurement Manager', 'Procurement Director') AND p.permission_key IN ('manage_suppliers', 'view_all_orders', 'assign_order_owner'))
  OR (r.name IN ('Logistics Manager', 'Logistics Director') AND p.permission_key IN ('view_all_orders', 'assign_order_owner', 'manage_logistics_partners'))
  OR (r.name IN ('Finance Manager', 'Finance Director') AND p.permission_key IN ('manage_order_financials', 'inr_actual_delete', 'view_cafin', 'ca_internal_doc_manage', 'pay_incentives', 'view_all_orders', 'assign_order_owner'))
  OR (r.name = 'Finance Director' AND p.permission_key IN ('view_reports', 'view_staff_reports', 'ca_fy_lock_override', 'approve_payment_terms'))
  OR (r.name = 'System Administrator' AND p.permission_key IN ('manage_users', 'manage_permissions', 'manage_company_settings', 'manage_assets', 'delete_assets', 'manage_email_templates', 'manage_dropdown_options', 'manage_ports', 'manage_hs_codes', 'manage_payment_presets', 'manage_compliance_task_types', 'manage_signatories', 'view_audit_log', 'manage_field_protection', 'data_export_run', 'manage_sample_data', 'manage_report_definitions'))
  OR (r.name IN ('Order Coordinator', 'Operations Manager') AND p.permission_key IN ('view_orders', 'view_all_orders'))
  OR (r.name = 'Operations Manager' AND p.permission_key IN ('assign_order_owner'))
);

-- Designations: each department's ladder, each tied to its own role.
INSERT IGNORE INTO department_designations (department_id, title, level, role_id, sort_order)
SELECT d.id, x.title, x.level, r.id, x.sort_order FROM (
  SELECT 'MGMT' AS dept, 'Managing Director' AS title, 'director' AS level, 'Managing Director' AS role, 1 AS sort_order
  UNION ALL SELECT 'MGMT', 'Executive Director', 'director', 'Executive Director', 2
  UNION ALL SELECT 'SALES', 'Sales/Export Executive', 'executive', 'Export Executive', 1
  UNION ALL SELECT 'SALES', 'Sales/Export Manager', 'manager', 'Sales/Export Manager', 2
  UNION ALL SELECT 'SALES', 'Sales Director', 'director', 'Sales Director', 3
  UNION ALL SELECT 'PROC', 'Procurement Executive', 'executive', 'Procurement Executive', 1
  UNION ALL SELECT 'PROC', 'Procurement Manager', 'manager', 'Procurement Manager', 2
  UNION ALL SELECT 'PROC', 'Procurement Director', 'director', 'Procurement Director', 3
  UNION ALL SELECT 'LOG', 'Logistics Executive', 'executive', 'Logistics Executive', 1
  UNION ALL SELECT 'LOG', 'Logistics Manager', 'manager', 'Logistics Manager', 2
  UNION ALL SELECT 'LOG', 'Logistics Director', 'director', 'Logistics Director', 3
  UNION ALL SELECT 'FIN', 'Accounts Executive', 'executive', 'Accounts Executive', 1
  UNION ALL SELECT 'FIN', 'Finance Manager', 'manager', 'Finance Manager', 2
  UNION ALL SELECT 'FIN', 'Finance Director', 'director', 'Finance Director', 3
  UNION ALL SELECT 'AUDIT', 'Internal Auditor', 'other', 'Viewer / Auditor', 1
  UNION ALL SELECT 'AUDIT', 'External CA', 'other', 'CA / Chartered Accountant', 2
  UNION ALL SELECT 'ADMIN', 'System Administrator', 'other', 'System Administrator', 1
  UNION ALL SELECT 'OPS', 'Order Coordinator', 'executive', 'Order Coordinator', 1
  UNION ALL SELECT 'OPS', 'Operations Manager', 'manager', 'Operations Manager', 2
) x JOIN departments d ON d.code = x.dept JOIN roles r ON r.name = x.role;

-- Existing people: one membership matching the role they hold today (not for Admin-role accounts).
INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
SELECT u.id, dd.id, CURDATE(), 1
  FROM users u
  JOIN roles r ON r.id = u.role_id
  JOIN department_designations dd ON dd.role_id = r.id
  JOIN departments d ON d.id = dd.department_id
 WHERE r.name IN ('Managing Director', 'Executive Director', 'Export Executive', 'Accounts Executive', 'Logistics Executive', 'Viewer / Auditor', 'CA / Chartered Accountant')
   AND NOT EXISTS (SELECT 1 FROM user_department_memberships m WHERE m.user_id = u.id);

-- ---------------------------------------------------------------
-- Owner decision D-214 (2026-10-31), phase 2: money separation.
-- Sales sees the selling side (selling prices, buyer payments), Procurement
-- the buying side (purchase prices, supplier bills), Logistics only
-- logistics costs (CHA, transport, freight); Accounts & Finance, Company
-- Management, the auditors and the CA see everything. Four "see this money"
-- permissions, and the catch-all permissions split:
--   - the Supplier PO and production follow-up get their own permission
--     (Procurement, not Sales);
--   - "Mark fully settled" gets its own permission (Finance, not Logistics);
--   - freight clearing uses Clear payments (already Finance's), no new key.
-- (Same rows as docs/migrations/2026-11-01b-money-separation.sql.)

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('view_selling_prices', 'See selling prices', 'Unit prices, FOB and order values, and the sales documents that carry them (QT, PI, OC, CI, AMD, Debit Note). D-214: Sales, Finance, management, auditors, CA.', 'money'),
  ('view_buyer_payments', 'See buyer payments', 'What the buyer has paid and still owes: the payment snapshot, the remittances and their screenshots. D-214: Sales, Finance, management, auditors, CA.', 'money'),
  ('view_purchase_prices', 'See purchase prices', 'Supplier PO values, factory and supplier costs in the catalogue, supplier bills and their scans, and the Supplier PO document. D-214: Procurement, Finance, management, auditors, CA.', 'money'),
  ('view_logistics_costs', 'See logistics costs', 'CHA, transport and packing costs in the catalogue, freight rates, and logistics bills and their scans. D-214: Logistics, Finance, management, auditors, CA.', 'money'),
  ('manage_supplier_po', 'Supplier PO and production', 'Create and confirm the Supplier PO and record production status (steps 7a-7d). D-214: Procurement, not Sales.', 'orders'),
  ('settle_orders', 'Mark an order fully settled', 'Mark an order fully settled once every rupee is in and out. D-214: Finance.', 'orders');

-- Who sees which money, by role (all editable in Admin afterwards).
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_selling_prices', 'view_buyer_payments', 'view_purchase_prices', 'view_logistics_costs')
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Accounts Executive', 'Finance Manager', 'Finance Director',
                 'Viewer / Auditor', 'CA / Chartered Accountant');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_selling_prices', 'view_buyer_payments')
  AND r.name IN ('Export Executive', 'Sales/Export Manager', 'Sales Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_purchase_prices', 'manage_supplier_po')
  AND r.name IN ('Procurement Executive', 'Procurement Manager', 'Procurement Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_logistics_costs'
  AND r.name IN ('Logistics Executive', 'Logistics Manager', 'Logistics Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('manage_supplier_po', 'settle_orders')
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'settle_orders'
  AND r.name IN ('Accounts Executive', 'Finance Manager', 'Finance Director');

-- Procurement works on the order (supplier PO, production), generates and
-- downloads its own documents (the Supplier PO; a document carrying money it
-- may not see is refused), and opens only the orders assigned to it.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_orders', 'download_pdf')
  AND r.name IN ('Procurement Executive');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'generate_documents'
  AND r.name IN ('Procurement Executive', 'Procurement Manager', 'Procurement Director');

-- ---------------------------------------------------------------
-- Owner decision D-214, phase 3: Procurement permissions and starting approval limits
-- (same rows as docs/migrations/2026-11-01c-procurement.sql).
INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('raise_purchase_requirements', 'Raise purchase requirements', 'Ask Procurement for what an order needs (product, quantity, needed by) — no prices. D-214: Sales, management.', 'procurement'),
  ('manage_purchasing', 'Work purchase requirements', 'Record supplier quotes, compare and award, follow production, record receipt and the quality check. D-214: Procurement.', 'procurement'),
  ('approve_purchases', 'Approve purchases above a limit', 'Approve an award above the awarding designation''s approval limit (up to this designation''s own limit; no limit = any amount). D-214: Procurement Manager / Director, MD/ED.', 'procurement');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'raise_purchase_requirements'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Export Executive', 'Sales/Export Manager', 'Sales Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_purchasing'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Procurement Executive', 'Procurement Manager', 'Procurement Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'approve_purchases'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Procurement Manager', 'Procurement Director');

-- Starting approval limits (Admin → Departments changes them): every award
-- by a Procurement Executive is approved by a manager; a Procurement Manager
-- approves up to ₹5,00,000; the Director has no limit. Only set where none is set yet.
UPDATE department_designations dd JOIN departments d ON d.id = dd.department_id
   SET dd.approval_limit_inr = CASE dd.title WHEN 'Procurement Executive' THEN 0 WHEN 'Procurement Manager' THEN 500000 END
 WHERE d.code = 'PROC' AND dd.title IN ('Procurement Executive', 'Procurement Manager') AND dd.approval_limit_inr IS NULL;

-- ---------------------------------------------------------------
-- Owner decision D-214, phase 4: task types, coordination permissions
-- (same rows as docs/migrations/2026-11-01d-coordination.sql).
INSERT IGNORE INTO department_task_types (task_key, department_code, title, standard_days, escalate_after_days, sort_order) VALUES
  ('raise_purchase_requirement', 'SALES', 'Raise the purchase requirement (advance cleared)', 1, 2, 10),
  ('quote_and_award', 'PROC', 'Get supplier quotes and award', 3, 3, 20),
  ('approve_purchase', 'PROC', 'Approve the award (above the awarder''s limit)', 1, 2, 30),
  ('chase_supplier', 'PROC', 'Chase the supplier — goods due', 0, 3, 40),
  ('book_freight', 'LOG', 'Book freight and CHA (goods received)', 2, 3, 50),
  ('clear_buyer_payment', 'FIN', 'Clear the buyer''s payment (remittance received)', 1, 2, 60),
  ('pay_supplier_bill', 'FIN', 'Pay the supplier / logistics bill', 3, 4, 70),
  ('send_documents', 'LOG', 'Send the documents to the buyer (balance cleared)', 2, 3, 80);

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('assign_department_owner', 'Assign department owners', 'Choose who in a department owns an order for that department (a manager for their own department; MD/ED and Admin for any). D-214 phase 4.', 'orders'),
  ('view_cockpit', 'Management cockpit', 'Open, overdue and escalated work per department, orders by stage, supplier delays and overdue buyer payments — counts and status only. D-214 phase 4.', 'reports'),
  ('manage_task_types', 'Task due days', 'Set the standard and escalation days of each kind of department task. D-214 phase 4.', 'admin');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'assign_department_owner'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Sales/Export Manager', 'Sales Director', 'Procurement Manager', 'Procurement Director',
                 'Logistics Manager', 'Logistics Director', 'Finance Manager', 'Finance Director', 'Order Coordinator', 'Operations Manager');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_cockpit'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Sales Director', 'Procurement Director', 'Logistics Director', 'Finance Director',
                 'Order Coordinator', 'Operations Manager');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_task_types'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director');

-- The Order Coordinator follows every order's status and tasks — no money.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('view_orders', 'view_all_orders')
  AND r.name IN ('Order Coordinator', 'Operations Manager');

-- ---------------------------------------------------------------
-- Owner decision D-215: the Export Service Invoice (same row as docs/migrations/2026-11-01f-export-service-invoice.sql).
INSERT INTO document_types (code, name, category, ref_format, never_shown_to_buyer, revision_enabled, min_reviewers_default, is_active)
SELECT 'ESI', 'Export Service Invoice', 'customer_facing', 'SC/ESI/{YY}/{DDMM}{NNN}', 0, 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM document_types WHERE code = 'ESI');

-- DOCX for the Export Service Invoice, like the other customer-facing documents.
INSERT INTO docx_generation_settings (document_type_id, is_enabled)
SELECT t.id, 1 FROM document_types t
WHERE t.code = 'ESI' AND NOT EXISTS (SELECT 1 FROM docx_generation_settings s WHERE s.document_type_id = t.id);

-- Owner decision D-215 (5a): Natural Stone, today's products, with Dimensions and Finish (same rows as docs/migrations/2026-11-01h-product-categories.sql).
INSERT INTO product_categories (name, is_system, is_active, sort_order)
SELECT 'Natural Stone', 1, 1, 0 WHERE NOT EXISTS (SELECT 1 FROM product_categories WHERE is_system = 1);

INSERT INTO product_category_attributes (category_id, label, sort_order)
SELECT c.id, a.label, a.sort_order FROM product_categories c
JOIN (SELECT 'Dimensions' AS label, 1 AS sort_order UNION ALL SELECT 'Finish', 2) a
WHERE c.is_system = 1
  AND NOT EXISTS (SELECT 1 FROM product_category_attributes x WHERE x.category_id = c.id AND x.label = a.label);

-- Owner decisions of 1 Nov 2026 (same as docs/migrations/2026-11-01i-owner-decisions.sql):
-- A-8 System Administrator has no full data export; C-8 Procurement Executive manages suppliers;
-- C-4 only the Letter of Credit preset may run without an advance.
DELETE rp FROM role_permissions rp
  JOIN roles r ON r.id = rp.role_id
  JOIN permissions p ON p.id = rp.permission_id
 WHERE r.name = 'System Administrator' AND p.permission_key = 'data_export_run';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'manage_suppliers'
 WHERE r.name = 'Procurement Executive'
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);
UPDATE payment_presets SET allow_zero_advance = 1 WHERE payment_method = 'LC';

-- Owner decision 1 Nov 2026 (associate system): standard ladder, TDS, protection, permissions, upload context
-- (same as docs/migrations/2026-11-02a-associate-system.sql, data part).
-- ---------------------------------------------------------------- settings: TDS, buyer registration, bank wait, and when commission is paid
-- D-245 P3 (4 Nov 2026): the old rung rates are gone (commission levels and ladder replace them); a buyer
-- registration lapses after 6 months with no first PO; commission is due 30 days after full payment, held
-- while a buyer claim is open, paid in the monthly run (D-292, 11 Nov 2026: on the last day of the month); a counting order is invoiced FOB of
-- ₹5 lakh or more; GST 18% on top for GST-registered associates with a tax invoice.
INSERT INTO incentive_settings (setting_key, setting_value)
SELECT k, v FROM (
  SELECT 'associate_tds_pct' AS k, '2.00' AS v UNION ALL SELECT 'associate_protection_months', '6'
  UNION ALL SELECT 'associate_commission_due_days', '30' UNION ALL SELECT 'associate_claim_hold', '1'
  UNION ALL SELECT 'associate_payout_run', 'monthly' UNION ALL SELECT 'associate_payout_day', 'last'
  UNION ALL SELECT 'associate_counting_threshold_inr', '500000' UNION ALL SELECT 'associate_gst_pct', '18.00'
  -- Owner 4 Nov 2026: associate registration links last 7 days (D-253); a personal code may change 3 times a year (D-254).
  UNION ALL SELECT 'associate_registration_link_days', '7' UNION ALL SELECT 'associate_code_changes_per_year', '3') d
WHERE NOT EXISTS (SELECT 1 FROM incentive_settings s WHERE s.setting_key = d.k);

-- ---------------------------------------------------------------- staff permissions (AS-11)
INSERT INTO permissions (permission_key, name, description, category)
SELECT k, n, d, 'Associates' FROM (
  SELECT 'manage_associates' AS k, 'Manage associates' AS n, 'Associate register, agreements, rates, invitations, client links and their changes, portal access' AS d
  UNION ALL SELECT 'view_associates', 'View associates', 'See which client was introduced by which associate (no amounts, bank details or agreements)'
  UNION ALL SELECT 'manage_associate_bank', 'Associate bank & tax details', 'See associates'' full bank details and verify bank changes (once the associate has confirmed them; one or two people — Associates › Commission settings)') p
WHERE NOT EXISTS (SELECT 1 FROM permissions x WHERE x.permission_key = p.k);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE ((p.permission_key IN ('manage_associates', 'view_associates', 'manage_associate_bank') AND r.name IN ('Admin', 'Managing Director'))
     OR (p.permission_key IN ('manage_associates', 'view_associates') AND r.name = 'Executive Director')
     OR (p.permission_key = 'view_associates' AND r.name IN ('Export Executive', 'Sales/Export Manager', 'Sales Director'))
     OR (p.permission_key = 'manage_associate_bank' AND r.name IN ('Finance Manager', 'Finance Director')))
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- ---------------------------------------------------------------- upload context for associate documents (KYC, invoices, bank proof, 15CA/CB, TDS certificates)
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'associate_document', 'pdf,jpg,jpeg,png', 10485760, 'An associate''s own documents (KYC, tax papers, commission invoices, bank proof, 15CA/CB, TDS certificates) — internal; seen by the associate and by manage_associates / manage_associate_bank only.'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'associate_document');


-- Gap list answers 1 Nov 2026 (D-218, G-4): upload context for LC amendment copies (2026-11-02c)
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'order_lc_amendment', 'pdf,jpg,jpeg,png', 10485760, 'A copy of an amendment to the order''s Letter of Credit (order_lc_amendments.file_id).'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'order_lc_amendment');

-- Gap list G-1 / G-3 (owner, 1 Nov 2026): inspection report upload context, override_shipment_gates (docs/migrations/2026-11-02b-gap-shipment-gates.sql).
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'inspection_report', 'pdf,jpg,jpeg,png', 10485760, 'The third-party pre-shipment inspection report (order_inspections.report_file_id) — gap list G-3.'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'inspection_report');

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'override_shipment_gates', 'Override shipment gates', 'Override shipment gates — release the BL step with a written reason when the pre-shipment balance or the inspection is not cleared', 'orders'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'override_shipment_gates');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'override_shipment_gates'
 WHERE r.name IN ('Managing Director', 'Executive Director', 'Admin')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- Gap list G-5: the manager's permission to clear a buyer payment that is less than due.
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'approve_short_payment', 'Approve short payment', 'Approve a short payment — clear a buyer payment that is less than due, with a written reason', 'orders'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'approve_short_payment');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'approve_short_payment'
 WHERE r.name IN ('Managing Director', 'Executive Director', 'Admin', 'Finance Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- Owner decision 1 Nov 2026 (D-221): self-approval defaults — Managing Director and Executive Director on all three rules.
INSERT INTO self_approval_rules (rule_key, role_id)
SELECT k.rule_key, r.id FROM (SELECT 'payment_terms' AS rule_key UNION ALL SELECT 'document_review' UNION ALL SELECT 'email_send') k
JOIN roles r ON r.name IN ('Managing Director', 'Executive Director')
WHERE NOT EXISTS (SELECT 1 FROM self_approval_rules x WHERE x.rule_key = k.rule_key);

-- QA Round 8 (1 Nov 2026): self-approval rules for cancellation / purchase award / amendment / short payment (default MD, ED) and the manage_approval_limits permission (MD, ED). Same as docs/migrations/2026-11-02h-qa-r8-approvals.sql.

INSERT INTO self_approval_rules (rule_key, role_id)
SELECT k.rule_key, r.id
  FROM (SELECT 'cancellation' AS rule_key UNION ALL SELECT 'purchase_award' UNION ALL SELECT 'amendment' UNION ALL SELECT 'short_payment') k
  JOIN roles r ON r.name IN ('Managing Director', 'Executive Director')
 WHERE NOT EXISTS (SELECT 1 FROM self_approval_rules x WHERE x.rule_key = k.rule_key)
   AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.action_type = 'SELF_APPROVAL_RULE_CHANGED' AND a.field_name = k.rule_key);

INSERT INTO permissions (permission_key, name, description, category)
SELECT 'manage_approval_limits', 'Manage approval limits', 'Change a designation''s purchase approval limit or make it a ''director'' (no limit). Never on a designation one holds oneself. QA Round 8, R8-N10.', 'admin'
 WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'manage_approval_limits');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'manage_approval_limits'
 WHERE r.name IN ('Managing Director', 'Executive Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);


-- Owner points 5 / 8 / 9 (2 Nov 2026): Admin → Settings → Document copies clean-up.
-- D-313 (owner, 11 Nov 2026): on by default — drafts 90 days after approval, never-sent superseded
-- revisions after 365 days (the SUPERSEDED copy is kept), CAFIN latest only. Edited on its own page
-- (/settings/document-cleanup), not the generic settings list.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive)
SELECT x.k, x.v, x.t, 'document_cleanup', x.d, 0 FROM (
  SELECT 'doc_cleanup_draft_days' AS k, '90' AS v, 'number' AS t, 'Remove the DRAFT copy of an approved document after this many days (0 = off; 30, 90 or 180).' AS d
  UNION ALL SELECT 'doc_cleanup_superseded_days', '365', 'number', 'Remove the copies of a superseded revision that was never sent to the client after this many days (0 = off; 90, 180 or 365). The SUPERSEDED-stamped copy is kept.'
  UNION ALL SELECT 'doc_cleanup_cafin_mode', 'latest_only', 'string', 'Old CAFIN copies: keep_all, or latest_only (older copies removed 30 days after a newer one was made).'
) x WHERE NOT EXISTS (SELECT 1 FROM company_settings c WHERE c.setting_key = x.k);

-- Owner decision 2 Nov 2026 (point 4): Agreements register permissions, upload context and agreement types. Same as docs/migrations/2026-11-02s-agreements-register.sql.
INSERT INTO permissions (permission_key, name, description, category)
SELECT k, n, d, 'Agreements' FROM (
  SELECT 'manage_agreements' AS k, 'Manage agreements' AS n, 'Add, edit, renew and terminate agreements with clients, suppliers, logistics partners, associates and staff; sees staff NDAs; gets the expiry reminders' AS d
  UNION ALL SELECT 'view_agreements', 'View agreements', 'See the agreements register and download signed copies (not staff agreements)') p
WHERE NOT EXISTS (SELECT 1 FROM permissions x WHERE x.permission_key = p.k);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE ((p.permission_key IN ('manage_agreements', 'view_agreements') AND r.name IN ('Admin', 'Managing Director', 'Executive Director'))
     OR (p.permission_key = 'view_agreements' AND r.name IN ('Sales Director', 'Procurement Director', 'Logistics Director', 'Finance Director')))
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'agreement_file', 'pdf,doc,docx,jpg,jpeg,png', 15728640, 'The signed copy of an agreement in the Agreements register (client, supplier, logistics partner, associate or staff).'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'agreement_file');

-- Agreement types: an admin-managed list (Admin → Dropdown Option Lists, list "agreement_type").
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT t.list_key, t.option_value, t.sort_order, 0, 1 FROM (
  SELECT 'agreement_type' AS list_key, 'Sales & Supply Agreement' AS option_value, 1 AS sort_order
  UNION ALL SELECT 'agreement_type', 'Commercial NDA', 2
  UNION ALL SELECT 'agreement_type', 'Staff NDA', 3
  UNION ALL SELECT 'agreement_type', 'Supply Agreement (Quarry/Factory)', 4
  UNION ALL SELECT 'agreement_type', 'Logistics Services Agreement', 5
  UNION ALL SELECT 'agreement_type', 'Associate Agreement', 6
  UNION ALL SELECT 'agreement_type', 'Other', 7) t
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options d WHERE d.list_key = t.list_key AND d.option_value = t.option_value);

-- ---------------------------------------------------------------
-- Owner decision D-226 (2 Nov 2026): the Export Compliance department
-- (same rows as docs/migrations/2026-11-02r-export-compliance.sql).
INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('manage_compliance_checklist', 'Compliance checklist', 'Work the order''s compliance checklist (ECGC cover, inspection, fumigation, phytosanitary, due diligence …). D-226: Export Compliance, and everyone who could already do it through Close orders.', 'orders'),
  ('manage_export_benefit_claims', 'File export benefit claims', 'Answer which export benefit schemes an order is eligible for and file the claims (scheme, reference no., amount claimed, date). Recording the money received stays with Manage order financials. D-226: Export Compliance.', 'orders'),
  ('generate_compliance_documents', 'Generate compliance documents', 'Generate the COO Prep Sheet (for the Certificate of Origin application) without the general Generate documents permission. D-226: Export Compliance.', 'documents'),
  ('upload_order_documents', 'Attach documents to an order', 'Attach government and agency documents (ECGC policy, Certificate of Origin, phytosanitary / fumigation / inspection certificates, eBRC …) to an order in Step 14, without Manage orders. D-226: Export Compliance.', 'orders');

-- Nothing changes for the roles that worked the checklist through Close orders.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT rp.role_id, pn.id, rp.is_enabled
  FROM role_permissions rp
  JOIN permissions po ON po.id = rp.permission_id AND po.permission_key = 'close_orders'
  JOIN permissions pn ON pn.permission_key = 'manage_compliance_checklist';

-- Management holds every permission.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key IN ('manage_compliance_checklist', 'manage_export_benefit_claims', 'generate_compliance_documents', 'upload_order_documents')
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director');

-- Finance already records benefits through Manage order financials; filing a claim follows.
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_export_benefit_claims'
  AND r.name IN ('Finance Manager', 'Finance Director');

-- The department and its ladder.
INSERT IGNORE INTO departments (code, name, color, sees_whole_company, sort_order, is_active) VALUES
  ('EXPORT_COMPLIANCE', 'Export Compliance', '#1d7f8c', 0, 45, 1);

INSERT IGNORE INTO roles (name, description, is_system_role) VALUES
  ('Export Compliance Executive', 'Export Compliance department — Executive', 0),
  ('Export Compliance Manager',   'Export Compliance department — Manager', 0),
  ('Export Compliance Director',  'Export Compliance department — Director', 0);

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r JOIN permissions p ON (
     (r.name IN ('Export Compliance Executive', 'Export Compliance Manager', 'Export Compliance Director')
      AND p.permission_key IN ('view_orders', 'download_pdf', 'view_selling_prices', 'view_own_incentives',
                               'manage_compliance_checklist', 'manage_export_benefit_claims',
                               'generate_compliance_documents', 'upload_order_documents'))
  OR (r.name IN ('Export Compliance Manager', 'Export Compliance Director')
      AND p.permission_key IN ('view_all_orders', 'assign_order_owner', 'assign_department_owner', 'approve_documents',
                               'manage_compliance_task_types'))
  OR (r.name = 'Export Compliance Director' AND p.permission_key IN ('view_cockpit'))
);

INSERT IGNORE INTO department_designations (department_id, title, level, role_id, sort_order)
SELECT d.id, x.title, x.level, r.id, x.sort_order FROM (
  SELECT 'Export Compliance Executive' AS title, 'executive' AS level, 1 AS sort_order
  UNION ALL SELECT 'Export Compliance Manager', 'manager', 2
  UNION ALL SELECT 'Export Compliance Director', 'director', 3
) x JOIN departments d ON d.code = 'EXPORT_COMPLIANCE' JOIN roles r ON r.name = x.title;

-- Their work queue (built from the order's own state, like the other departments').
INSERT IGNORE INTO department_task_types (task_key, department_code, title, standard_days, escalate_after_days, sort_order) VALUES
  ('compliance_checklist_item', 'EXPORT_COMPLIANCE', 'Compliance checklist item (ECGC, inspection, fumigation, phytosanitary …)', 5, 3, 90),
  ('apply_coo', 'EXPORT_COMPLIANCE', 'Apply for the Certificate of Origin (COO Prep Sheet)', 2, 2, 91),
  ('answer_benefit_eligibility', 'EXPORT_COMPLIANCE', 'Answer export benefit eligibility (BL issued)', 3, 3, 92),
  ('file_export_benefit_claim', 'EXPORT_COMPLIANCE', 'File the export benefit claim', 7, 7, 93),
  ('ebrc_followup', 'EXPORT_COMPLIANCE', 'Get the eBRC / FIRC from the bank (payment cleared)', 7, 7, 94);

-- Owner follow-up on point 18 (2 Nov 2026): the usual "Why is it different?" reasons (Admin → Remittance difference reasons).
INSERT INTO remittance_difference_reasons (label, treatment, sort_order) VALUES
  ('Intermediary / correspondent bank charges deducted', 'bank_charges', 10),
  ('Buyer''s bank (remitting) charges deducted', 'bank_charges', 20),
  ('Our bank deducted its charges from the amount credited', 'bank_charges', 30),
  ('Currency conversion difference (small)', 'bank_charges', 40),
  ('Buyer deducted an agreed discount', 'short', 50),
  ('Buyer deducted a quality / quantity claim', 'short', 60),
  ('Buyer paid in instalments — rest to follow', 'short', 70),
  ('Buyer disputes part of the amount', 'short', 80),
  ('Buyer paid extra — adjust against balance', 'excess', 90),
  ('Buyer included next order''s advance', 'excess', 100),
  ('Buyer rounded up the amount', 'excess', 110);

-- Owner decision (2 Nov 2026): Logistics enters the logistics partners' bills, Finance pays them.
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'manage_logistics_bills', 'Add logistics partner bills',
       'Add a CHA, transport or freight-forwarder bill to an order and remove one that has no payment on it. Paying it needs Manage supplier payments.',
       'orders'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'manage_logistics_bills');
INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.permission_key = 'manage_logistics_bills'
 WHERE (r.name IN ('Logistics Executive', 'Logistics Manager', 'Logistics Director')
        OR r.id IN (SELECT rp.role_id FROM role_permissions rp
                      JOIN permissions sp ON sp.id = rp.permission_id
                     WHERE sp.permission_key = 'manage_supplier_payments'))
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- Owner decision (2 Nov 2026, gap-list Q-6): ECGC policy and incentive claim deadlines (deadlines to be confirmed with the CA).
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description)
SELECT * FROM (
  SELECT 'ecgc_policy_no' AS k, '' AS v, 'string' AS t, 'compliance' AS c, 'ECGC policy number of the company (Shipments Comprehensive Risk policy). Leave empty if there is no ECGC policy.' AS d
  UNION ALL SELECT 'ecgc_policy_cover_pct', '90', 'number', 'compliance', 'ECGC cover percentage of the policy (shown on the buyer and order pages).'
  UNION ALL SELECT 'ecgc_policy_valid_to', '', 'date', 'compliance', 'ECGC policy valid until (YYYY-MM-DD). A reminder goes out 30 days before.'
  UNION ALL SELECT 'ecgc_declaration_day', '10', 'number', 'compliance', 'Day of the month by which last month''s shipments must be declared to ECGC.'
  UNION ALL SELECT 'benefit_deadline_days_rodtep', '365', 'number', 'compliance', 'RoDTEP: days after the shipping bill (LEO) date to file the claim — default 365 (1 year); 0 = no deadline. To be confirmed with the CA.'
  UNION ALL SELECT 'benefit_deadline_days_rosctl', '365', 'number', 'compliance', 'RoSCTL: days after the shipping bill (LEO) date to file the claim — default 365; 0 = no deadline. To be confirmed with the CA.'
  UNION ALL SELECT 'benefit_deadline_days_duty_drawback', '0', 'number', 'compliance', 'Duty Drawback: 0 = no deadline (paid automatically through the shipping bill). To be confirmed with the CA.'
  UNION ALL SELECT 'benefit_deadline_days_other', '0', 'number', 'compliance', 'Any other scheme: days after the shipping bill (LEO) date to file; 0 = no deadline.'
) x WHERE NOT EXISTS (SELECT 1 FROM company_settings s WHERE s.setting_key = x.k);
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'ecgc_limit_letter', 'pdf,jpg,jpeg,png', 10485760, 'ECGC credit-limit approval letter for a buyer (clients.ecgc_limit_file_id).'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'ecgc_limit_letter');

-- Owner decision 2 Nov 2026 (D-235): TDS on associate commission — settings (migration 2026-11-02x-associate-tds.sql).
INSERT INTO incentive_settings (setting_key, setting_value)
SELECT k, v FROM (
  SELECT 'associate_tds_section' AS k, '194H' AS v UNION ALL
  SELECT 'associate_tds_threshold_inr', '20000' UNION ALL
  SELECT 'associate_tds_no_pan_pct', '20.00' UNION ALL
  SELECT 'associate_tds_foreign', '0' UNION ALL
  SELECT 'associate_15cb_required', '1' UNION ALL
  SELECT 'zoho_tds_tax_id', ''
) d WHERE NOT EXISTS (SELECT 1 FROM incentive_settings s WHERE s.setting_key = d.k);

-- Owner decision D-256 (5 Nov 2026): the Zoho Books GST tax booked on an associate's commission bill
-- when GST is paid on top on their tax invoice (empty = the sync refuses such a bill with a clear error).
INSERT INTO incentive_settings (setting_key, setting_value)
SELECT 'zoho_gst_commission_tax_id', '' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM incentive_settings s WHERE s.setting_key = 'zoho_gst_commission_tax_id');

-- Owner decision 2 Nov 2026 (D-238, migration 2026-11-02za): client / product sync to Zoho Books, both off by default.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('zoho_sync_clients', '0', 'boolean', 'zoho_books', 'Send clients to Zoho Books as customers on their own (one way, app → Zoho, at the hourly sync / Sync now): new and changed clients are created or updated, inactive ones marked inactive. A client goes once its first order has set its currency. Off by default.', 0),
  ('zoho_sync_products', '0', 'boolean', 'zoho_books', 'Send the products of the Products menu to Zoho Books as items (one way, app → Zoho, at the hourly sync / Sync now): name, specifications, HS code, rate 0 — no cost is ever sent. Inactive or deleted products are marked inactive in Zoho. Off by default.', 0);

-- Owner decision 2 Nov 2026 (D-239, migration 2026-11-02zb): this app's Zoho API call allowance.
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('zoho_api_daily_limit', '800', 'number', 'zoho_books', 'Max Zoho calls per day for this app (India time). Every Zoho Books call counts — reads, the login token and sends. When used up, syncing pauses until midnight. Zoho''s own daily limit is per organisation and shared with the company''s other Zoho apps, so keep this below it.', 0),
  ('zoho_api_minute_limit', '30', 'number', 'zoho_books', 'Max Zoho calls per minute for this app. The scheduled sync waits for the next minute; Sync now and the order buttons stop and ask to try again in a minute. Zoho allows about 100 per minute per organisation, shared with the company''s other Zoho apps.', 0);

-- Owner decision D-245 P2 (4 Nov 2026): Admin → Self-approval rules for an order line price below the floor
-- set by the approver themselves (only the MD, who holds "Manage pricing", can approve such a price).
INSERT INTO self_approval_rules (rule_key, role_id)
SELECT 'price_discount', r.id FROM roles r WHERE r.name IN ('Managing Director', 'Executive Director');

-- ---------------------------------------------------------------
-- Owner decision D-245 (4 Nov 2026), phase P1: pricing settings (MD changes them in
-- Admin → Pricing), the three pricing permissions, and the starting commission
-- levels and ladder. Example values; the MD sets the real ones before go-live.
INSERT IGNORE INTO pricing_settings (setting_key, setting_value) VALUES
  ('margin_pct', '20.00'),          -- minimum price = cost ÷ (1 − margin %): margin on the selling price, not markup
  ('other_cost_pct', '2.50'),       -- other costs to FOB, % of the supplier price
  ('pricing_fx_rate', '82.0000'),   -- ₹ per USD used to turn supplier prices into USD
  ('buffer_pct', '3.00'),           -- quote price = floor × (1 + buffer %)
  ('incentive_mode', 'pct'),        -- team-incentive reserve: % of (minimum price − cost), or USD per unit
  ('incentive_value', '15.0000'),
  ('stale_days', '60'),             -- a supplier price older than this is marked stale
  ('require_product_pick', '1');    -- D-245 P2: a goods line on a USD order must be picked from Products

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('record_market_prices', 'Record supplier / market prices', 'Record supplier and market prices for products (dated, valid until, history) and mark the one used for pricing. Shows no selling prices, margins or floors. D-245: Procurement.', 'catalog'),
  ('view_price_buildup', 'See the full price build-up', 'See a product''s cost, margin, minimum price, team-incentive reserve, every floor and quote price, and the pricing settings. D-245: MD, ED. Never reaches the client.', 'catalog'),
  ('manage_pricing', 'Manage pricing', 'Change the pricing settings, a product''s pricing overrides, the associate commission levels and ladder, and an associate''s commission level. D-245: MD only.', 'catalog');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'record_market_prices'
  AND r.name IN ('Admin', 'Managing Director', 'Procurement Executive', 'Procurement Manager', 'Procurement Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'view_price_buildup'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_pricing'
  AND r.name IN ('Admin', 'Managing Director');

INSERT IGNORE INTO commission_levels (name, kind, value, is_active, sort_order) VALUES
  ('Level 1', 'pct', 2.0000, 1, 10),
  ('Level 2', 'pct', 5.0000, 1, 20),
  ('Level 3', 'pct', 7.0000, 1, 30),
  ('Level 4', 'pct', 10.0000, 1, 40);

INSERT IGNORE INTO commission_ladder_steps (from_order, to_order, level_id)
SELECT 1, 5, id FROM commission_levels WHERE name = 'Level 1';
INSERT IGNORE INTO commission_ladder_steps (from_order, to_order, level_id)
SELECT 6, 10, id FROM commission_levels WHERE name = 'Level 2';
INSERT IGNORE INTO commission_ladder_steps (from_order, to_order, level_id)
SELECT 11, 20, id FROM commission_levels WHERE name = 'Level 3';
INSERT IGNORE INTO commission_ladder_steps (from_order, to_order, level_id)
SELECT 21, NULL, id FROM commission_levels WHERE name = 'Level 4';

-- Owner decision D-245 P4 (4 Nov 2026): the team incentive roles and their usual shares (add up to 100%).
INSERT INTO incentive_roles (role_key, label, share_pct, is_active, sort_order)
SELECT k, l, p, 1, o FROM (
  SELECT 'sales' AS k, 'Sales' AS l, 30.00 AS p, 1 AS o UNION ALL
  SELECT 'sales_manager', 'Sales Manager', 10.00, 2 UNION ALL
  SELECT 'export', 'Export Documentation', 15.00, 3 UNION ALL
  SELECT 'export_compliance', 'Export Compliance', 5.00, 4 UNION ALL
  SELECT 'marketing', 'Marketing', 15.00, 5 UNION ALL
  SELECT 'logistics', 'Logistics', 15.00, 6 UNION ALL
  SELECT 'accounts', 'Accounts', 10.00, 7
) d WHERE NOT EXISTS (SELECT 1 FROM incentive_roles r WHERE r.role_key = d.k);

-- Owner decision D-255 (5 Nov 2026): specification settings, the new permission, and the
-- starting specifications for Natural Stone (the MD edits them in Admin → Pricing → Specifications).
INSERT IGNORE INTO pricing_settings (setting_key, setting_value) VALUES
  ('cbm_from_mm', '50'),                -- 50 mm and above is priced per CBM
  ('price_request_daily_limit', '10'),  -- an associate's automatic price requests per day
  ('price_request_valid_days', '7');    -- how long an associate's price stays valid

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('manage_product_specifications', 'Manage a product''s specifications', 'Change one product''s specifications: hide a specification or option, change an option''s value, add options or specifications for that product only, reset to the category. D-255: privileged roles (Admin, MD to start).', 'catalog');
INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_product_specifications'
  AND r.name IN ('Admin', 'Managing Director');

INSERT INTO spec_definitions (category_id, name, kind, applies_to, sort_order)
SELECT c.id, d.n, d.k, d.a, d.o FROM product_categories c
JOIN (SELECT 'Grade / quality' AS n, 'normal' AS k, NULL AS a, 1 AS o UNION ALL
      SELECT 'Thickness', 'thickness', 'slab,tile', 2 UNION ALL
      SELECT 'Surface finish', 'normal', 'slab,tile,kerb,step', 3 UNION ALL
      SELECT 'Size / format', 'normal', 'slab,tile,block', 4 UNION ALL
      SELECT 'Edge work', 'normal', 'tile,step,kerb', 5 UNION ALL
      SELECT 'Quantity', 'quantity', NULL, 6 UNION ALL
      SELECT 'Packing', 'normal', NULL, 7 UNION ALL
      SELECT 'Treatment', 'normal', 'slab', 8 UNION ALL
      SELECT 'Calibration', 'normal', 'tile', 9) d
WHERE c.is_system = 1
  AND NOT EXISTS (SELECT 1 FROM spec_definitions s WHERE s.category_id = c.id AND s.name = d.n AND s.product_id IS NULL);

INSERT INTO spec_options (spec_id, name, is_base, change_type, value, thickness_mm, sort_order)
SELECT s.id, d.n, d.b, d.t, d.v, d.mm, d.o FROM spec_definitions s
JOIN product_categories c ON c.id = s.category_id AND c.is_system = 1
JOIN (SELECT 'Grade / quality' AS sn, 'Premium' AS n, 1 AS b, 'none' AS t, NULL AS v, NULL AS mm, 1 AS o UNION ALL
      SELECT 'Grade / quality', 'Standard', 0, 'pct', -8, NULL, 2 UNION ALL
      SELECT 'Grade / quality', 'Commercial', 0, 'pct', -15, NULL, 3 UNION ALL
      SELECT 'Thickness', '20 mm', 1, 'none', NULL, 20, 1 UNION ALL
      SELECT 'Thickness', '15 mm', 0, 'pct', -20, 15, 2 UNION ALL
      SELECT 'Thickness', '18 mm', 0, 'pct', -5, 18, 3 UNION ALL
      SELECT 'Thickness', '30 mm', 0, 'pct', 35, 30, 4 UNION ALL
      SELECT 'Thickness', '40 mm', 0, 'pct', 70, 40, 5 UNION ALL
      SELECT 'Thickness', '50 mm', 0, 'cbm', NULL, 50, 6 UNION ALL
      SELECT 'Thickness', '60 mm', 0, 'cbm', NULL, 60, 7 UNION ALL
      SELECT 'Thickness', '80 mm', 0, 'cbm', NULL, 80, 8 UNION ALL
      SELECT 'Thickness', '100 mm', 0, 'cbm', NULL, 100, 9 UNION ALL
      SELECT 'Thickness', 'Other, 50 mm or more', 0, 'cbm', NULL, NULL, 10 UNION ALL
      SELECT 'Surface finish', 'Polished', 1, 'none', NULL, NULL, 1 UNION ALL
      SELECT 'Surface finish', 'Honed', 0, 'usd_m2', 1, NULL, 2 UNION ALL
      SELECT 'Surface finish', 'Flamed', 0, 'usd_m2', 2, NULL, 3 UNION ALL
      SELECT 'Surface finish', 'Sandblasted', 0, 'usd_m2', 3, NULL, 4 UNION ALL
      SELECT 'Surface finish', 'Leather', 0, 'usd_m2', 4, NULL, 5 UNION ALL
      SELECT 'Surface finish', 'Brushed', 0, 'usd_m2', 4.5, NULL, 6 UNION ALL
      SELECT 'Size / format', 'Gangsaw slab', 1, 'none', NULL, NULL, 1 UNION ALL
      SELECT 'Size / format', 'Cutter slab', 0, 'pct', -10, NULL, 2 UNION ALL
      SELECT 'Size / format', 'Tiles 30×30', 0, 'pct', 5, NULL, 3 UNION ALL
      SELECT 'Size / format', 'Tiles 60×30', 0, 'pct', 6, NULL, 4 UNION ALL
      SELECT 'Size / format', 'Tiles 60×60', 0, 'pct', 8, NULL, 5 UNION ALL
      SELECT 'Size / format', 'Cut-to-size', 0, 'pct', 12, NULL, 6 UNION ALL
      SELECT 'Size / format', 'Block: gangsaw size', 0, 'none', NULL, NULL, 7 UNION ALL
      SELECT 'Size / format', 'Block: cutter size', 0, 'pct', -12, NULL, 8 UNION ALL
      SELECT 'Edge work', 'None', 1, 'none', NULL, NULL, 1 UNION ALL
      SELECT 'Edge work', 'Chamfer', 0, 'usd_rm', 1.5, NULL, 2 UNION ALL
      SELECT 'Edge work', 'Bullnose', 0, 'usd_rm', 4, NULL, 3 UNION ALL
      SELECT 'Quantity', '1 container', 1, 'none', NULL, NULL, 1 UNION ALL
      SELECT 'Quantity', '2–4 containers', 0, 'pct', -2, NULL, 2 UNION ALL
      SELECT 'Quantity', '5+ containers', 0, 'pct', -4, NULL, 3 UNION ALL
      SELECT 'Packing', 'Standard crate', 1, 'none', NULL, NULL, 1 UNION ALL
      SELECT 'Packing', 'ISPM-15 fumigated', 0, 'usd_m2', 0.5, NULL, 2 UNION ALL
      SELECT 'Packing', 'A-frame for slabs', 0, 'usd_container', 150, NULL, 3 UNION ALL
      SELECT 'Treatment', 'None', 1, 'none', NULL, NULL, 1 UNION ALL
      SELECT 'Treatment', 'Resin / epoxy', 0, 'usd_m2', 3, NULL, 2 UNION ALL
      SELECT 'Treatment', 'Mesh backing', 0, 'usd_m2', 2, NULL, 3 UNION ALL
      SELECT 'Calibration', 'Not calibrated', 1, 'none', NULL, NULL, 1 UNION ALL
      SELECT 'Calibration', 'Calibrated', 0, 'pct', 4, NULL, 2) d ON d.sn = s.name
WHERE s.product_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM spec_options o WHERE o.spec_id = s.id AND o.name = d.n AND o.product_id IS NULL);

-- ================================================================
-- Owner decision D-257 (5 Nov 2026): divisions. Export uses every module; the
-- planned divisions start with the modules their trade needs (owner can change
-- them in Admin > Divisions before activating). Company-level departments and
-- people have no division; everyone else works in Export.
-- ================================================================
INSERT IGNORE INTO division_modules (division_id, module_key, is_enabled)
SELECT d.id, m.k,
       CASE WHEN d.code = 'EXP' THEN 1
            WHEN m.k IN ('sales_documents','supplier_purchase','associates','team_incentive') THEN 1
            WHEN d.code = 'MTT' AND m.k IN ('firc_fema','ecgc') THEN 1
            ELSE 0 END
FROM divisions d
JOIN (SELECT 'sales_documents' AS k UNION ALL SELECT 'supplier_purchase' UNION ALL SELECT 'export_shipping'
      UNION ALL SELECT 'lut_igst' UNION ALL SELECT 'export_benefits' UNION ALL SELECT 'firc_fema'
      UNION ALL SELECT 'ecgc' UNION ALL SELECT 'associates' UNION ALL SELECT 'team_incentive') m;

UPDATE departments SET division_id = 1 WHERE code IN ('SALES','PROC','LOG','OPS','EXPORT_COMPLIANCE');
UPDATE departments SET division_id = NULL WHERE code IN ('MGMT','FIN','AUDIT','ADMIN');

INSERT IGNORE INTO client_divisions (client_id, division_id) SELECT id, 1 FROM clients;

INSERT IGNORE INTO permissions (permission_key, name, description, category) VALUES
  ('see_all_divisions', 'See all divisions', 'Company level: see every division''s orders, clients and reports, and pick one division or "All divisions" in the header (the picker shows only when two or more divisions are active). D-257.', 'admin'),
  ('manage_divisions',  'Manage divisions',  'Admin > Divisions: modules, number formats, division settings and activating a planned division. D-257.', 'admin');

INSERT IGNORE INTO roles (name, description, is_system_role) VALUES
  ('Investor (view only)', 'Dashboards and reports across all divisions; no editing (D-257).', 0);

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'see_all_divisions'
  AND r.name IN ('Admin', 'Managing Director', 'Executive Director', 'CA / Chartered Accountant', 'Viewer / Auditor',
                 'Accounts Executive', 'Finance Manager', 'Finance Director', 'System Administrator', 'Investor (view only)');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE p.permission_key = 'manage_divisions' AND r.name IN ('Admin', 'Managing Director');

INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Investor (view only)' AND p.permission_key IN ('view_reports', 'view_cockpit');

-- Company-level people (no division): founders, management, finance, audit, administration, CA.
UPDATE users u JOIN roles r ON r.id = u.role_id SET u.division_id = NULL
WHERE u.is_super_admin = 1
   OR r.name IN ('Admin', 'Managing Director', 'Executive Director', 'CA / Chartered Accountant', 'Viewer / Auditor',
                 'Accounts Executive', 'Finance Manager', 'Finance Director', 'System Administrator', 'Investor (view only)');


-- Owner decision D-257 Phase 0B (5 Nov 2026): product-neutral groundwork.
-- Natural Stone keeps exactly today's behaviour: any unit (default as today),
-- HS 680293, wooden crates, the natural-stone clauses, no extra quality documents.
UPDATE product_categories SET default_hs_code = '680293', packing_type = 'Wooden Crates', tc_clause_set = 'natural_stone'
WHERE is_system = 1 AND default_hs_code IS NULL;

-- Owner decision D-257 Phase 1 (5 Nov 2026): T&C clause sets on the documents. The
-- stone-only clauses print only for stone orders (as today: every order so far is
-- stone); other categories get their own starting clauses, to be reviewed by the
-- lawyer before the first non-stone sale. Admin › Overrides edits their wording.
UPDATE tc_clauses SET clause_set = 'natural_stone'
WHERE clause_group = 'standard' AND clause_title IN ('Natural Stone Characteristics', 'Quantity Tolerance', 'Grade A Only', 'Colour Consistency');
INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked, is_protected, clause_set) VALUES
  (10, 'Product Characteristics', 'Product Characteristics: Goods are supplied to the specification agreed in this document. Minor variation within the manufacturer''s stated tolerances is not a defect. Products will conform to the agreed specification.', 'active', 1, 1, 'general_goods'),
  (10, 'Specification & Drawings', 'Specification & Drawings: Goods are made to the buyer-approved drawing and the specification in this document. Dimensions and tolerances are as per the approved drawing. Any change after approval needs the written agreement of both parties and may change the price and the delivery date.', 'active', 1, 1, 'engineering'),
  (20, 'Quantity Tolerance (Goods)', 'Quantity Tolerance: Actual quantity shipped will not exceed the ordered quantity. A shortfall of up to {tolerance}% may occur due to production yield and will be invoiced at actual quantity shipped. Buyer will be notified in writing before shipment of any shortfall exceeding {tolerance}%, and shipment will require buyer''s written approval.', 'active', 1, 1, 'general_goods,engineering'),
  (25, 'Quality Documents', 'Quality Documents: The quality documents listed in this document (for example a Mill Test Certificate EN 10204 3.1) are supplied with the shipment, and each item can be traced to them by its heat or serial number.', 'active', 1, 1, 'engineering');
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title IN ('Product Characteristics', 'Specification & Drawings', 'Quantity Tolerance (Goods)', 'Quality Documents') AND dt.code IN ('QT','PI','OC');
INSERT INTO tc_clauses (clause_order, clause_title, clause_text, status, is_locked, is_protected, clause_set) VALUES
  (10, 'Conformity to Specification', 'All items must conform exactly to the specification and drawing given in Section 3. No substitution of material or grade without NexaCrest''s written approval.', 'active', 1, 1, 'general_goods,engineering'),
  (40, 'Traceability & Test Certificates', 'Each item must be traceable by its heat or serial number to the quality documents supplied with it (for example a Mill Test Certificate EN 10204 3.1). Goods without the required documents will not be accepted.', 'active', 1, 1, 'engineering');
INSERT INTO tc_clause_documents (clause_id, document_type_id)
SELECT c.id, dt.id FROM tc_clauses c CROSS JOIN document_types dt
WHERE c.clause_title IN ('Conformity to Specification', 'Traceability & Test Certificates') AND dt.code = 'SUPPO';
-- More units and supplier types for products other than stone (only those missing).
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT 'unit', d.v, d.s, 0, 1 FROM (
  SELECT 'MTR' AS v, 10 AS s UNION ALL SELECT 'LTR', 11 UNION ALL SELECT 'HOUR', 12 UNION ALL SELECT 'DAY', 13
) d WHERE NOT EXISTS (SELECT 1 FROM dropdown_options o WHERE o.list_key = 'unit' AND o.option_value = d.v);
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT 'supplier_type', d.v, d.s, 0, 1 FROM (
  SELECT 'Manufacturer' AS v, 5 AS s UNION ALL SELECT 'Machine Shop', 6 UNION ALL SELECT 'Forging / Casting Unit', 7
) d WHERE NOT EXISTS (SELECT 1 FROM dropdown_options o WHERE o.list_key = 'supplier_type' AND o.option_value = d.v);
-- Quality documents a category asks for (MTC, drawing approval …).
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'quality_document', 'pdf,jpg,jpeg,png,docx,xlsx', 10485760, 'A quality document a product category asks for on an order (e.g. mill test certificate, drawing approval) — internal. D-257 Phase 0B.'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'quality_document');

-- ================================================================
-- Owner decision D-257 Phase 2 (5 Nov 2026): Domestic Trade (DOM). DOM stays
-- "planned" — nothing here is visible until the owner activates it.
-- GST rates, the e-way bill threshold and e-invoicing are standard Indian
-- rules as starting values, TO BE CONFIRMED BY THE CA.
-- ================================================================
INSERT INTO indian_states (code, name, abbr, sort_order) VALUES
  ('01', 'Jammu and Kashmir', 'JK', 1), ('02', 'Himachal Pradesh', 'HP', 2), ('03', 'Punjab', 'PB', 3),
  ('04', 'Chandigarh', 'CH', 4), ('05', 'Uttarakhand', 'UK', 5), ('06', 'Haryana', 'HR', 6),
  ('07', 'Delhi', 'DL', 7), ('08', 'Rajasthan', 'RJ', 8), ('09', 'Uttar Pradesh', 'UP', 9),
  ('10', 'Bihar', 'BR', 10), ('11', 'Sikkim', 'SK', 11), ('12', 'Arunachal Pradesh', 'AR', 12),
  ('13', 'Nagaland', 'NL', 13), ('14', 'Manipur', 'MN', 14), ('15', 'Mizoram', 'MZ', 15),
  ('16', 'Tripura', 'TR', 16), ('17', 'Meghalaya', 'ML', 17), ('18', 'Assam', 'AS', 18),
  ('19', 'West Bengal', 'WB', 19), ('20', 'Jharkhand', 'JH', 20), ('21', 'Odisha', 'OR', 21),
  ('22', 'Chhattisgarh', 'CG', 22), ('23', 'Madhya Pradesh', 'MP', 23), ('24', 'Gujarat', 'GJ', 24),
  ('26', 'Dadra and Nagar Haveli and Daman and Diu', 'DN', 26), ('27', 'Maharashtra', 'MH', 27),
  ('29', 'Karnataka', 'KA', 29), ('30', 'Goa', 'GA', 30), ('31', 'Lakshadweep', 'LD', 31),
  ('32', 'Kerala', 'KL', 32), ('33', 'Tamil Nadu', 'TN', 33), ('34', 'Puducherry', 'PY', 34),
  ('35', 'Andaman and Nicobar Islands', 'AN', 35), ('36', 'Telangana', 'TS', 36),
  ('37', 'Andhra Pradesh', 'AP', 37), ('38', 'Ladakh', 'LA', 38), ('97', 'Other Territory', 'OT', 97);

-- INR is the currency of DOM orders only: inactive, so it is never offered on an Export order.
INSERT INTO currencies (code, name, is_default, is_active)
SELECT 'INR', 'Indian Rupee', 0, 0 WHERE NOT EXISTS (SELECT 1 FROM currencies WHERE code = 'INR');

-- GST on worked building / monumental stone (polished granite and marble slabs and tiles).
INSERT INTO gst_rates (hsn_prefix, category_id, rate_pct, note)
SELECT '6802', NULL, 18.00, 'Worked building and monumental stone (HSN 6802) — standard rate, to be confirmed by the CA.'
WHERE NOT EXISTS (SELECT 1 FROM gst_rates WHERE hsn_prefix = '6802');

-- DOM's own settings (Admin > Divisions > Domestic Trade). Prices are worked
-- out in rupees (exchange rate 1), DOM's own team-incentive programme starts
-- off, and the GST rules below are starting values for the CA to confirm.
INSERT IGNORE INTO division_settings (division_id, scope, setting_key, setting_value)
SELECT d.id, s.scope, s.k, s.v FROM divisions d
JOIN (SELECT 'company' AS scope, 'dom_default_gst_rate' AS k, '18' AS v
      UNION ALL SELECT 'company', 'dom_eway_threshold_inr', '50000'
      UNION ALL SELECT 'company', 'dom_e_invoice_applicable', '0'
      UNION ALL SELECT 'company', 'dom_payment_terms_days', '30'
      UNION ALL SELECT 'pricing', 'pricing_fx_rate', '1.0000'
      UNION ALL SELECT 'incentive', 'program_enabled', '0') s
WHERE d.code = 'DOM';

-- ================================================================
-- Owner decision D-257 Phase 3 (5 Nov 2026): Import & Distribution (IMP).
-- IMP stays "planned" — nothing here is visible until the owner activates it.
-- Duty rates and the margin are starting values, TO BE CONFIRMED BY THE CA
-- AND THE CUSTOMS AGENT.
-- ================================================================
-- The import, stock and landed-cost module: on for IMP only.
INSERT IGNORE INTO division_modules (division_id, module_key, is_enabled)
SELECT d.id, 'import_stock', CASE WHEN d.code = 'IMP' THEN 1 ELSE 0 END FROM divisions d;

-- Worked building / monumental stone (HSN 6802): basic customs duty, social
-- welfare surcharge (10 % of the duty) and IGST on import.
INSERT INTO import_duty_rates (hsn_prefix, bcd_pct, sws_pct, igst_pct, note)
SELECT '6802', 20.00, 10.00, 18.00, 'Worked building and monumental stone (HSN 6802) — starting value, to be confirmed by the CA and the customs agent.'
WHERE NOT EXISTS (SELECT 1 FROM import_duty_rates WHERE hsn_prefix = '6802');

-- IMP's own settings (Admin > Divisions > Import & Distribution). The local
-- sale follows the same GST rules as Domestic Trade (rate, e-way bill,
-- e-invoicing); prices are worked out in rupees; IMP's own team-incentive
-- programme starts off. The LC bank and the customs agent start empty: the
-- activation check asks for them.
INSERT IGNORE INTO division_settings (division_id, scope, setting_key, setting_value)
SELECT d.id, s.scope, s.k, s.v FROM divisions d
JOIN (SELECT 'company' AS scope, 'imp_default_bcd_pct' AS k, '10' AS v
      UNION ALL SELECT 'company', 'imp_default_sws_pct', '10'
      UNION ALL SELECT 'company', 'imp_default_igst_pct', '18'
      UNION ALL SELECT 'company', 'imp_margin_pct', '20'
      UNION ALL SELECT 'pricing', 'pricing_fx_rate', '1.0000'
      UNION ALL SELECT 'incentive', 'program_enabled', '0') s
WHERE d.code = 'IMP';

-- ================================================================
-- Owner decision D-257 Phase 4 (5 Nov 2026): Merchant Trade (MTT).
-- MTT stays "planned" — nothing here is visible until the owner activates
-- it. The RBI limits and the bank's document list are starting values,
-- TO BE CONFIRMED WITH THE AD BANK AND THE CA.
-- ================================================================
-- The merchant-trade module: on for MTT only.
INSERT IGNORE INTO division_modules (division_id, module_key, is_enabled)
SELECT d.id, 'merchant_trade', CASE WHEN d.code = 'MTT' THEN 1 ELSE 0 END FROM divisions d;

-- MTT's own settings (Admin > Divisions > Merchant Trade): the whole deal
-- within 9 months, foreign-exchange outlay within 4 months, an advance to
-- the supplier above USD 5,00,000 only against an LC or bank guarantee, the
-- ₹ per USD used to compare a non-USD advance with that limit, and the
-- documents the AD bank asks for. The AD bank's merchanting approval starts
-- empty: the activation check asks for it. MTT's own team-incentive
-- programme starts off.
INSERT IGNORE INTO division_settings (division_id, scope, setting_key, setting_value)
SELECT d.id, s.scope, s.k, s.v FROM divisions d
JOIN (SELECT 'company' AS scope, 'mtt_max_months_total' AS k, '9' AS v
      UNION ALL SELECT 'company', 'mtt_max_outlay_months', '4'
      UNION ALL SELECT 'company', 'mtt_advance_limit_usd', '500000'
      UNION ALL SELECT 'company', 'mtt_usd_inr_rate', '84.0000'
      UNION ALL SELECT 'company', 'mtt_bank_documents', 'Sale contract or buyer''s order|Purchase contract or supplier''s proforma|Supplier''s invoice|Bill of lading|Our sale invoice|Inward remittance advice (sell leg)|Outward remittance SWIFT copy (buy leg)'
      UNION ALL SELECT 'incentive', 'program_enabled', '0') s
WHERE d.code = 'MTT';

-- ---------------------------------------------------------------------------
-- D-261 (owner approval 6 Nov 2026): associate access levels — the recommended
-- defaults of mockup 6. Starter: clients, orders and stage, commission per
-- order (amount only), registration links, messages, TDS certificates.
-- Quoting adds the catalogue, Get price, price requests, the FOB value, the
-- commission % and the statement download. Full adds order documents and
-- amounts in notices / e-mails. "Profile, bank & agreement" is always on.
-- After 3 completed, fully paid orders the MD is asked "Ready for Quoting?".
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO associate_access_level_settings (level, feature_key, allowed) VALUES
  ('starter','clients',1),('starter','orders',1),('starter','fob_value',0),('starter','commission',1),('starter','commission_pct',0),
  ('starter','registration_links',1),('starter','catalogue',0),('starter','get_price',0),('starter','price_requests',0),('starter','messages',1),
  ('starter','order_documents',0),('starter','tds_certificates',1),('starter','commission_statement',0),('starter','notice_amounts',0),
  ('quoting','clients',1),('quoting','orders',1),('quoting','fob_value',1),('quoting','commission',1),('quoting','commission_pct',1),
  ('quoting','registration_links',1),('quoting','catalogue',1),('quoting','get_price',1),('quoting','price_requests',1),('quoting','messages',1),
  ('quoting','order_documents',0),('quoting','tds_certificates',1),('quoting','commission_statement',1),('quoting','notice_amounts',0),
  ('full','clients',1),('full','orders',1),('full','fob_value',1),('full','commission',1),('full','commission_pct',1),
  ('full','registration_links',1),('full','catalogue',1),('full','get_price',1),('full','price_requests',1),('full','messages',1),
  ('full','order_documents',1),('full','tds_certificates',1),('full','commission_statement',1),('full','notice_amounts',1);
INSERT IGNORE INTO incentive_settings (setting_key, setting_value) VALUES ('associate_upgrade_after_orders', '3');
-- D-263 (owner 6 Nov 2026): how many months of commission an associate sees in the portal (3/6/9/12/15/18/24).
INSERT IGNORE INTO incentive_settings (setting_key, setting_value) VALUES ('associate_portal_commission_months', '12');
-- Owner decision D-264: abroad transfers — NexaCrest pays its own and the intermediary bank's charges (OUR) by default;
-- an associate abroad is paid once the amount due reaches USD 100 (smaller amounts carry to the next run).
INSERT IGNORE INTO incentive_settings (setting_key, setting_value) VALUES ('associate_abroad_charges', 'OUR'), ('associate_abroad_min_payout_usd', '100'), ('zoho_payout_costs_account_id', '');

-- ================================================================
-- Owner decision D-266 (7 Nov 2026): who sees which SOP chapter. Library switch on; every shipped
-- chapter configured and on; generous role defaults (Admin changes them in Admin > SOP access).
-- Management (Admin, MD, ED, System Administrator) also always see everything through manage_company_settings.
-- ================================================================
INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('sop_library_enabled', '1', 'boolean', 'sop_access', 'D-266: the SOP library (menu, chapters, screenshots, Gallery) for staff. Off = only MD and Admin see it; Getting Started stays open. Switched on Admin > SOP access.', 0);
INSERT INTO sop_chapters (slug, is_enabled) VALUES
  ('00-getting-started', 1),
  ('00-introduction', 1),
  ('01-stage1-enquiry-quotation', 1),
  ('02-stage2-buyer-po', 1),
  ('03-stage3-pi-advance', 1),
  ('04-stage4-order-confirmation', 1),
  ('05-stage5-supplier-po', 1),
  ('06-stage6-freight', 1),
  ('07-stage7-packing-bl', 1),
  ('08-stage8-ci-balance', 1),
  ('09-stage9-despatch-closure', 1),
  ('10-amendments', 1),
  ('11-disputes', 1),
  ('12-client-portal', 1),
  ('13-document-review-approval', 1),
  ('14-reports', 1),
  ('15-admin-settings', 1),
  ('16-test-mode', 1),
  ('17-order-edit-duplication', 1),
  ('18-data-export-migration', 1),
  ('19-complaints', 1),
  ('20-incentives', 1),
  ('21-departments', 1),
  ('22-procurement', 1),
  ('23-coordination', 1),
  ('24-associates', 1),
  ('25-export-compliance', 1),
  ('26-agreements', 1),
  ('27-pricing', 1),
  ('28-divisions', 1),
  ('29-domestic-trade', 1),
  ('30-import-distribution', 1),
  ('31-merchant-trade', 1),
  ('32-backups-restore', 1),
  ('ca-01-overview', 1),
  ('ca-02-revenue-reports', 1),
  ('ca-03-zoho-sync', 1),
  ('ca-04-expenses', 1),
  ('ca-05-bank-reconciliation', 1),
  ('ca-06-fy-lock', 1),
  ('ca-07-export-benefits', 1),
  ('ca-08-order-financials', 1),
  ('ca-09-gst-refunds', 1);
-- Management and Admin see every chapter
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '01-stage1-enquiry-quotation' AS slug UNION ALL SELECT '02-stage2-buyer-po' AS slug UNION ALL SELECT '03-stage3-pi-advance' AS slug UNION ALL SELECT '04-stage4-order-confirmation' AS slug UNION ALL SELECT '05-stage5-supplier-po' AS slug UNION ALL SELECT '06-stage6-freight' AS slug UNION ALL SELECT '07-stage7-packing-bl' AS slug UNION ALL SELECT '08-stage8-ci-balance' AS slug UNION ALL SELECT '09-stage9-despatch-closure' AS slug UNION ALL SELECT '10-amendments' AS slug UNION ALL SELECT '11-disputes' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '13-document-review-approval' AS slug UNION ALL SELECT '14-reports' AS slug UNION ALL SELECT '15-admin-settings' AS slug UNION ALL SELECT '16-test-mode' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '18-data-export-migration' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '20-incentives' AS slug UNION ALL SELECT '21-departments' AS slug UNION ALL SELECT '22-procurement' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '24-associates' AS slug UNION ALL SELECT '25-export-compliance' AS slug UNION ALL SELECT '26-agreements' AS slug UNION ALL SELECT '27-pricing' AS slug UNION ALL SELECT '28-divisions' AS slug UNION ALL SELECT '29-domestic-trade' AS slug UNION ALL SELECT '30-import-distribution' AS slug UNION ALL SELECT '31-merchant-trade' AS slug UNION ALL SELECT '32-backups-restore' AS slug UNION ALL SELECT 'ca-01-overview' AS slug UNION ALL SELECT 'ca-02-revenue-reports' AS slug UNION ALL SELECT 'ca-03-zoho-sync' AS slug UNION ALL SELECT 'ca-04-expenses' AS slug UNION ALL SELECT 'ca-05-bank-reconciliation' AS slug UNION ALL SELECT 'ca-06-fy-lock' AS slug UNION ALL SELECT 'ca-07-export-benefits' AS slug UNION ALL SELECT 'ca-08-order-financials' AS slug UNION ALL SELECT 'ca-09-gst-refunds' AS slug) c
  WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director', 'System Administrator');
-- Sales
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '01-stage1-enquiry-quotation' AS slug UNION ALL SELECT '02-stage2-buyer-po' AS slug UNION ALL SELECT '03-stage3-pi-advance' AS slug UNION ALL SELECT '04-stage4-order-confirmation' AS slug UNION ALL SELECT '10-amendments' AS slug UNION ALL SELECT '11-disputes' AS slug UNION ALL SELECT '13-document-review-approval' AS slug UNION ALL SELECT '24-associates' AS slug) c
  WHERE r.name IN ('Export Executive', 'Sales/Export Manager', 'Sales Director');
-- Procurement
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '05-stage5-supplier-po' AS slug UNION ALL SELECT '22-procurement' AS slug) c
  WHERE r.name IN ('Procurement Executive', 'Procurement Manager', 'Procurement Director');
-- Logistics
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '06-stage6-freight' AS slug UNION ALL SELECT '07-stage7-packing-bl' AS slug UNION ALL SELECT '09-stage9-despatch-closure' AS slug) c
  WHERE r.name IN ('Logistics Executive', 'Logistics Manager', 'Logistics Director');
-- Accounts and Finance
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '03-stage3-pi-advance' AS slug UNION ALL SELECT '08-stage8-ci-balance' AS slug UNION ALL SELECT '14-reports' AS slug UNION ALL SELECT '20-incentives' AS slug UNION ALL SELECT 'ca-01-overview' AS slug UNION ALL SELECT 'ca-02-revenue-reports' AS slug UNION ALL SELECT 'ca-03-zoho-sync' AS slug UNION ALL SELECT 'ca-04-expenses' AS slug UNION ALL SELECT 'ca-05-bank-reconciliation' AS slug UNION ALL SELECT 'ca-06-fy-lock' AS slug UNION ALL SELECT 'ca-07-export-benefits' AS slug UNION ALL SELECT 'ca-08-order-financials' AS slug UNION ALL SELECT 'ca-09-gst-refunds' AS slug) c
  WHERE r.name IN ('Accounts Executive', 'Finance Manager', 'Finance Director');
-- Export Compliance
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '07-stage7-packing-bl' AS slug UNION ALL SELECT '25-export-compliance' AS slug UNION ALL SELECT '26-agreements' AS slug) c
  WHERE r.name IN ('Export Compliance Executive', 'Export Compliance Manager', 'Export Compliance Director');
-- Order coordination (follows every stage)
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '01-stage1-enquiry-quotation' AS slug UNION ALL SELECT '02-stage2-buyer-po' AS slug UNION ALL SELECT '03-stage3-pi-advance' AS slug UNION ALL SELECT '04-stage4-order-confirmation' AS slug UNION ALL SELECT '05-stage5-supplier-po' AS slug UNION ALL SELECT '06-stage6-freight' AS slug UNION ALL SELECT '07-stage7-packing-bl' AS slug UNION ALL SELECT '08-stage8-ci-balance' AS slug UNION ALL SELECT '09-stage9-despatch-closure' AS slug UNION ALL SELECT '13-document-review-approval' AS slug) c
  WHERE r.name IN ('Order Coordinator', 'Operations Manager');
-- Viewer / Auditor (read-only)
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '01-stage1-enquiry-quotation' AS slug UNION ALL SELECT '02-stage2-buyer-po' AS slug UNION ALL SELECT '03-stage3-pi-advance' AS slug UNION ALL SELECT '04-stage4-order-confirmation' AS slug UNION ALL SELECT '05-stage5-supplier-po' AS slug UNION ALL SELECT '06-stage6-freight' AS slug UNION ALL SELECT '07-stage7-packing-bl' AS slug UNION ALL SELECT '08-stage8-ci-balance' AS slug UNION ALL SELECT '09-stage9-despatch-closure' AS slug UNION ALL SELECT '14-reports' AS slug) c
  WHERE r.name IN ('Viewer / Auditor');
-- CA / Chartered Accountant
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '08-stage8-ci-balance' AS slug UNION ALL SELECT '14-reports' AS slug UNION ALL SELECT 'ca-01-overview' AS slug UNION ALL SELECT 'ca-02-revenue-reports' AS slug UNION ALL SELECT 'ca-03-zoho-sync' AS slug UNION ALL SELECT 'ca-04-expenses' AS slug UNION ALL SELECT 'ca-05-bank-reconciliation' AS slug UNION ALL SELECT 'ca-06-fy-lock' AS slug UNION ALL SELECT 'ca-07-export-benefits' AS slug UNION ALL SELECT 'ca-08-order-financials' AS slug UNION ALL SELECT 'ca-09-gst-refunds' AS slug) c
  WHERE r.name IN ('CA / Chartered Accountant');
-- Investor (view only)
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, c.slug FROM roles r CROSS JOIN (SELECT '00-getting-started' AS slug UNION ALL SELECT '00-introduction' AS slug UNION ALL SELECT '12-client-portal' AS slug UNION ALL SELECT '17-order-edit-duplication' AS slug UNION ALL SELECT '19-complaints' AS slug UNION ALL SELECT '23-coordination' AS slug UNION ALL SELECT '14-reports' AS slug UNION ALL SELECT '28-divisions' AS slug) c
  WHERE r.name IN ('Investor (view only)');
-- Sales: 27 Pricing only for a sales role that holds the product-pricing permission
INSERT INTO sop_role_chapters (role_id, slug)
  SELECT r.id, '27-pricing' FROM roles r
  WHERE r.name IN ('Export Executive', 'Sales/Export Manager', 'Sales Director')
    AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.role_id = r.id AND rp.is_enabled = 1 AND p.permission_key = 'view_product_pricing');

-- D-270 (owner decision 11 Nov 2026, point 25): Natural Stone's order-line units —
-- CBM, m² (SQM), sq ft (SQFT), PCS, Ton (TON) and running metre (RMT) — and its
-- weight per CBM (2.7 t) for lines priced per Ton. Set and Lot can be added in
-- Products › Categories; such lines cannot be converted and go to the MD.
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active)
SELECT 'unit', u.v, u.s, 0, 1 FROM (SELECT 'TON' AS v, 14 AS s UNION ALL SELECT 'RMT', 15) u
WHERE NOT EXISTS (SELECT 1 FROM dropdown_options d WHERE d.list_key = 'unit' AND d.option_value = u.v);
UPDATE product_categories SET allowed_units = 'CBM,SQM,SQFT,PCS,TON,RMT', weight_t_per_cbm = 2.700 WHERE is_system = 1 AND allowed_units IS NULL;
-- ---------------------------------------------------------------------------
-- Owner decisions 11 Nov 2026 (D-291 … D-294): associates — commission rule
-- permission, payout day, bank details checks.
-- ---------------------------------------------------------------------------
-- D-292: who sees an associate's commission rule, level and progress (others see "Set by the MD").
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'view_associate_commission_rules', 'See associate commission rules', 'See an associate''s commission rule (standard ladder, own ladder or fixed), the level and % of their next order, how many orders have counted and the ladder steps. Without it staff see "Set by the MD". Changing the rule needs Manage pricing (the MD).', 'Associates'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'view_associate_commission_rules');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'view_associate_commission_rules'
 WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director', 'Finance Director', 'Finance Manager')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- D-292: payout day — monthly 1–28 or "last" (last day of the month), weekly 1–7 (Monday–Sunday);
-- a payout day on a Sunday or a Holiday Calendar day moves to the working day before (default) or after.
-- D-293: bank details — wait before a FIRST account is used (0 days) and before a CHANGED account is used (15 days),
-- both 0–90; checked by one person (default) or two; the associate has 7 days to confirm.
INSERT INTO incentive_settings (setting_key, setting_value)
SELECT k, v FROM (
  SELECT 'associate_payout_holiday_rule' AS k, 'before' AS v
  UNION ALL SELECT 'associate_bank_wait_first_days', '0'
  UNION ALL SELECT 'associate_bank_wait_change_days', '15'
  UNION ALL SELECT 'bank_details_check_people', 'one') d
WHERE NOT EXISTS (SELECT 1 FROM incentive_settings s WHERE s.setting_key = d.k);

-- D-293: Admin › Self-approval rules — MD and ED may verify bank details they entered themselves (with a reason).
INSERT INTO self_approval_rules (rule_key, role_id)
SELECT 'bank_details', r.id FROM roles r
 WHERE r.name IN ('Managing Director', 'Executive Director')
   AND NOT EXISTS (SELECT 1 FROM self_approval_rules x WHERE x.rule_key = 'bank_details');

-- ================================================================
-- Owner decisions D-280 / D-281 (11 Nov 2026, point 3): Admin › E-mail settings.
-- One row per e-mail kind × audience that can be switched (MUST e-mails are
-- always on and have no row). SHOULD = on, OPTIONAL = off when installed.
-- The kinds are listed in EmailPolicy::KINDS (PHP) / emailPolicy.KINDS (Node).
-- ================================================================
INSERT INTO email_settings (email_kind, audience, is_enabled) VALUES
  ('buyer_documents', 'buyer', 1),
  ('buyer_documents', 'associate', 0),
  ('buyer_links', 'buyer', 1),
  ('buyer_messages', 'buyer', 1),
  ('buyer_followups', 'buyer', 1),
  ('supplier_links', 'supplier', 1),
  ('associate_clients', 'associate', 1),
  ('associate_commission', 'associate', 1),
  ('associate_kyc_price_message', 'associate', 1),
  ('associate_registration_declined', 'associate', 1),
  ('associate_code_status', 'associate', 1),
  ('staff_approvals', 'staff', 1),
  ('staff_counterparty', 'staff', 1),
  ('staff_compliance', 'staff', 1),
  ('staff_zoho', 'staff', 1),
  ('staff_daily_summary', 'staff', 1),
  ('staff_assigned', 'staff', 1),
  ('staff_other', 'staff', 1);

-- D-281: company-level compliance alerts — the responsible person and the backup
-- are chosen at installation in Admin › E-mail settings (empty = the department's
-- manager, then the Managing Director).
INSERT INTO compliance_alert_owners (item_key) VALUES
  ('lut'), ('rcmc'), ('agreements'), ('tds'), ('merchant_trade_rbi'), ('ecgc_policy'), ('ebrc_monthly');

-- Owner decision D-313 (point 4, 11 Nov 2026): the Indian national holidays for the
-- year of installation and the next one. State and festival holidays are added by the
-- company (Admin › Holiday Calendar); Admin › Setup checklist reminds every year.
INSERT INTO company_holidays (holiday_date, description)
SELECT h.d, h.n FROM (
  SELECT CAST(CONCAT(YEAR(CURDATE()), '-01-26') AS DATE) AS d, 'Republic Day' AS n
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()), '-08-15') AS DATE), 'Independence Day'
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()), '-10-02') AS DATE), 'Gandhi Jayanti'
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()) + 1, '-01-26') AS DATE), 'Republic Day'
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()) + 1, '-08-15') AS DATE), 'Independence Day'
  UNION ALL SELECT CAST(CONCAT(YEAR(CURDATE()) + 1, '-10-02') AS DATE), 'Gandhi Jayanti'
) h WHERE NOT EXISTS (SELECT 1 FROM company_holidays c WHERE c.holiday_date = h.d);
