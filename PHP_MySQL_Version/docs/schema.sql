-- ================================================================
-- NEXACREST EXPORT OPERATIONS WEBAPP — MySQL SCHEMA (PROPOSAL)
-- Version: 1.0 draft — for review before any code is written
-- Engine: InnoDB throughout (FK support, transactions)
-- Charset: utf8mb4 (handles diacritics in buyer names/addresses,
--          the ⬛/⬜/☐ style markers, and multi-currency symbols)
-- ================================================================
-- Conventions:
--   - Every table: id BIGINT UNSIGNED AUTO_INCREMENT PK
--   - created_at / updated_at on every mutable table
--   - *_by columns reference users.id (nullable where system-generated)
--   - Soft delete via is_active, never hard delete (spec: files, client
--     contact data, audit log all explicitly non-deletable)
--   - Money stored as DECIMAL(14,2); percentages as DECIMAL(5,2)
-- ================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- SECTION A — CONFIGURATION / COMPANY (Spec Section 1, 12, "everything
-- from DB" rule). company_settings is a key-value table on purpose:
-- new config values can be added by Admin without a schema change,
-- matching "if Admin changes any value in DB, behaviour changes
-- immediately without any code change."
-- ================================================================

CREATE TABLE company_settings (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key       VARCHAR(100) NOT NULL UNIQUE,
  setting_value     TEXT NULL,
  value_type        ENUM('string','number','boolean','date','json') NOT NULL DEFAULT 'string',
  category          VARCHAR(50) NOT NULL,     -- e.g. 'company','bank','lut','security','tolerances','formats'
  description       TEXT NULL,       -- widened from VARCHAR(255): several seeded settings carry a longer
                                      -- explanatory note (e.g. why a value is a placeholder, or why a key
                                      -- was added beyond the spec's named list) that runs past 255 chars
  is_sensitive      TINYINT(1) NOT NULL DEFAULT 0,  -- true for bank/RBI fields -> extra confirm on edit
  is_protected      TINYINT(1) NOT NULL DEFAULT 0,  -- true = cannot be edited without the unlock gesture
                                                     -- (reason + unlock + confirm) and cannot be blanked;
                                                     -- flipping this flag itself requires a peer-approved
                                                     -- request (see field_protection_requests, Section L)
  updated_by        BIGINT UNSIGNED NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cs_category (category)
) ENGINE=InnoDB;
-- Seed keys (see seed.sql): legal_name, registered_office, corporate_office,
-- gstin, iec_pan, md_name, md_title, director_name, director_title, phone,
-- email, bank_name, bank_branch (added Phase B — the source PI template
-- shows Branch as its own row, separate from bank_address), bank_account_no, swift_bic, ifsc, bank_address,
-- bank_pincode, lut_number, lut_valid_fy, lut_expiry_date,
-- rbi_purpose_code_advance, rbi_purpose_code_balance, rbi_purpose_code_freight,
-- default_currency, storage_base_path, quantity_shortfall_tolerance_pct,
-- lut_alert_days_x, lut_escalation_days_y, rcmc_alert_days_a,
-- rcmc_escalation_days_b, fdn_overdue_days_c, session_timeout_minutes,
-- failed_login_lockout_count, password_min_length, password_complexity_json,
-- password_expiry_days, non_usd_price_buffer_pct, revision_start_number,
-- master_tracking_ref_format, client_number_format, order_ref_format,
-- dispute_response_days_n, weekly_off_days, show_generated_document_disclaimer,
-- generated_document_disclaimer_text

CREATE TABLE ports (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  country       VARCHAR(100) NULL,
  port_role     ENUM('loading','discharge','both') NOT NULL DEFAULT 'both',
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE incoterms (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(10) NOT NULL,        -- FOB / CFR / CIF
  label_template       VARCHAR(255) NOT NULL,       -- "{code} {port} — Incoterms® 2020"
  requires_port_role   ENUM('loading','discharge') NOT NULL,
  is_default           TINYINT(1) NOT NULL DEFAULT 0,
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  sort_order           INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE currencies (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(10) NOT NULL UNIQUE,   -- USD, EUR, GBP...
  name          VARCHAR(50) NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Payment presets: this table is the fix for the Tier1/Tier2 template
-- contamination found in the source document set. There is ONE OC
-- template and ONE CI template; each order carries a preset_id, and
-- the preset's balance_trigger_option (A = before shipment / B =
-- against BL) drives which wording and which day-count renders on
-- every document. No more copy-pasted terms that can drift.
CREATE TABLE payment_presets (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  preset_name             VARCHAR(100) NOT NULL,     -- "Standard — New Buyer", "Established Buyer — Post-BL"
  is_default              TINYINT(1) NOT NULL DEFAULT 0,
  advance_pct             DECIMAL(5,2) NOT NULL DEFAULT 40.00,
  advance_trigger_text    VARCHAR(255) NOT NULL DEFAULT 'against Proforma Invoice before production commences',
  balance_pct             DECIMAL(5,2) NOT NULL DEFAULT 60.00,
  balance_trigger_option  ENUM('A_BEFORE_SHIPMENT','B_AGAINST_BL') NOT NULL DEFAULT 'A_BEFORE_SHIPMENT',
  balance_days            INT NOT NULL DEFAULT 3,    -- 3 for option A, 7 for option B (both configurable)
  currency_id             BIGINT UNSIGNED NOT NULL,
  requires_md_approval    TINYINT(1) NOT NULL DEFAULT 0,  -- true for "established buyer" style presets
  is_active               TINYINT(1) NOT NULL DEFAULT 1,
  is_protected            TINYINT(1) NOT NULL DEFAULT 0,  -- true = cannot be edited/deactivated without
                                                           -- the unlock gesture; flag itself needs a
                                                           -- peer-approved request (Section L)
  created_by              BIGINT UNSIGNED NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB;

CREATE TABLE document_types (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(20) NOT NULL UNIQUE,  -- QT, PI, OC, PL, CI, FDN, BLI, BUYERPO, SUPPO, ANNEXA,
                                                       -- COOPREP, CHECKLIST, CHECKLIST_2_FINANCE,
                                                       -- CHECKLIST_3_PACKING, CHECKLIST_4_SHIPPING, AMD,
                                                       -- SOP_A_SALES, SOP_B_SALES, STAGEGATE, WALLREF
  name                  VARCHAR(150) NOT NULL,
  category              ENUM('customer_facing','internal','procurement') NOT NULL,
  ref_format            VARCHAR(100) NULL,            -- e.g. 'SC/QT/{YYYY}/{DDMM}{NNN}' ; NULL for AMD-style internal-only or no-ref docs
  never_shown_to_buyer  TINYINT(1) NOT NULL DEFAULT 0, -- hard flag for AMD (Business Rule #20)
  revision_enabled      TINYINT(1) NOT NULL DEFAULT 1,
  min_reviewers_default INT NOT NULL DEFAULT 1,
  is_active             TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE document_sections (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_type_id  BIGINT UNSIGNED NOT NULL,
  section_key       VARCHAR(100) NOT NULL,
  section_name      VARCHAR(150) NOT NULL,
  section_order     INT NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (document_type_id) REFERENCES document_types(id),
  UNIQUE KEY uq_doc_section (document_type_id, section_key)
) ENGINE=InnoDB;

CREATE TABLE tc_clauses (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clause_number   VARCHAR(20) NULL,
  clause_order    INT NOT NULL DEFAULT 0,
  clause_title    VARCHAR(255) NOT NULL,
  clause_text     TEXT NOT NULL,
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  is_locked       TINYINT(1) NOT NULL DEFAULT 0,  -- legacy flag, superseded by is_protected below
                                                   -- (kept, unused by app logic, for historical reference)
  is_protected    TINYINT(1) NOT NULL DEFAULT 0,  -- true = cannot be edited/deactivated/deleted without
                                                   -- the unlock gesture (reason + unlock + confirm), and
                                                   -- cannot be blanked; flag itself needs a peer-approved
                                                   -- request (see field_protection_requests, Section L)
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  modified_by     BIGINT UNSIGNED NULL
) ENGINE=InnoDB;

CREATE TABLE tc_clause_documents (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clause_id         BIGINT UNSIGNED NOT NULL,
  document_type_id  BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (clause_id) REFERENCES tc_clauses(id),
  FOREIGN KEY (document_type_id) REFERENCES document_types(id),
  UNIQUE KEY uq_clause_doc (clause_id, document_type_id)
) ENGINE=InnoDB;

CREATE TABLE email_templates (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key  VARCHAR(100) NOT NULL UNIQUE, -- 'send_qt','send_pi','send_oc','send_ci','send_fdn',
                                               -- 'payment_followup','shipment_readiness','bl_copy_sent',
                                               -- 'balance_receipt_confirmation'
  subject       VARCHAR(255) NOT NULL,
  body          TEXT NOT NULL,
  footer        TEXT NULL,
  updated_by    BIGINT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE watermark_settings (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope             ENUM('global','document_type','document') NOT NULL,
  document_type_id  BIGINT UNSIGNED NULL,
  document_id       BIGINT UNSIGNED NULL,      -- FK added after documents table (below) via ALTER
  is_draft_mode      TINYINT(1) NOT NULL DEFAULT 0, -- DRAFT watermark vs final client watermark
  mode              ENUM('text','image','both') NOT NULL DEFAULT 'text',
  text_content      VARCHAR(255) NULL,
  font              VARCHAR(100) NULL,
  font_size         INT NULL,
  color             VARCHAR(20) NULL,
  opacity           DECIMAL(4,2) NULL,          -- 0.00 - 1.00
  angle             INT NULL,                   -- degrees
  image_asset_id    BIGINT UNSIGNED NULL,       -- FK added after assets table
  image_opacity     DECIMAL(4,2) NULL,
  image_position    VARCHAR(30) NULL DEFAULT 'center',
  created_by        BIGINT UNSIGNED NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (document_type_id) REFERENCES document_types(id)
) ENGINE=InnoDB;

CREATE TABLE docx_generation_settings (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_type_id  BIGINT UNSIGNED NOT NULL UNIQUE,
  is_enabled        TINYINT(1) NOT NULL DEFAULT 1,
  updated_by        BIGINT UNSIGNED NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (document_type_id) REFERENCES document_types(id)
) ENGINE=InnoDB;

CREATE TABLE file_upload_contexts (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  context_key           VARCHAR(100) NOT NULL UNIQUE, -- 'received_remittance','draft_bl','fumigation_cert','product_image','signature_asset',...
  allowed_extensions    VARCHAR(255) NOT NULL,          -- csv e.g. 'pdf,jpg,png'
  max_size_bytes        BIGINT UNSIGNED NOT NULL,
  description           VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE dropdown_options (
  -- generic small-option-list table for every "options from DB, Admin can
  -- add/edit" dropdown that isn't already its own table (COO type,
  -- container type, supplier type, dispute status, upload document-type
  -- labels, "received from" list, etc.) — avoids 15 near-identical
  -- one-column tables.
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  list_key      VARCHAR(100) NOT NULL,   -- 'coo_type','container_type','supplier_type','dispute_status',
                                          -- 'upload_doc_type_generated','upload_doc_type_received',
                                          -- 'received_from','freight_terms'
  option_value  VARCHAR(150) NOT NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  INDEX idx_list_key (list_key)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION B — AUTH / RBAC (Spec Section 5, 14)
-- ================================================================

CREATE TABLE roles (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL UNIQUE,
  description   VARCHAR(255) NULL,
  is_system_role TINYINT(1) NOT NULL DEFAULT 0,  -- true for ADMIN — cannot be deleted
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_key  VARCHAR(100) NOT NULL UNIQUE,  -- 'view_client_email_full','download_pdf','edit_locked_data',...
  name            VARCHAR(150) NOT NULL,
  description     VARCHAR(255) NULL,
  category        VARCHAR(50) NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id         BIGINT UNSIGNED NOT NULL,
  permission_id   BIGINT UNSIGNED NOT NULL,
  is_enabled      TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  FOREIGN KEY (permission_id) REFERENCES permissions(id),
  UNIQUE KEY uq_role_perm (role_id, permission_id)
) ENGINE=InnoDB;

CREATE TABLE users (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                  VARCHAR(150) NOT NULL,
  email                 VARCHAR(190) NOT NULL UNIQUE,
  phone                 VARCHAR(30) NULL,
  password_hash         VARCHAR(255) NOT NULL,
  role_id               BIGINT UNSIGNED NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  force_password_change TINYINT(1) NOT NULL DEFAULT 1,
  two_fa_enabled        TINYINT(1) NOT NULL DEFAULT 0,
  two_fa_method         ENUM('email','sms') NULL,   -- user's chosen 2FA delivery method; NULL when two_fa_enabled = 0.
                                                     -- 'sms' is only offered by the app when an SMS gateway API key exists in .env —
                                                     -- SMS is optional/pluggable, never a hard dependency; email 2FA always works.
  two_fa_secret         VARCHAR(255) NULL,          -- OTP/session secret used to validate the emailed or texted code
  failed_login_count    INT NOT NULL DEFAULT 0,
  locked_until          TIMESTAMP NULL,
  last_login_at         TIMESTAMP NULL,
  password_changed_at   TIMESTAMP NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE user_permissions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  permission_id   BIGINT UNSIGNED NOT NULL,
  is_enabled      TINYINT(1) NOT NULL,   -- individual override: 1 = force-enable, 0 = force-disable
  granted_by      BIGINT UNSIGNED NULL,
  granted_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason          VARCHAR(255) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (permission_id) REFERENCES permissions(id),
  UNIQUE KEY uq_user_perm (user_id, permission_id)
) ENGINE=InnoDB;

-- Phase E follow-up — self-service "forgot password" (Section 14). Only
-- the SHA-256 hash of the reset token is ever stored, never the raw token
-- itself (mirrors password_hash — a DB leak alone must never be enough to
-- hand out a working reset link). expires_at is short-lived (45 minutes,
-- set by the application) and used_at is set the moment a token is
-- consumed, so it can never be replayed even inside its expiry window.
CREATE TABLE password_reset_tokens (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  token_hash      CHAR(64) NOT NULL,     -- SHA-256 hex digest of the raw token mailed to the user
  requested_ip    VARCHAR(45) NULL,
  expires_at      TIMESTAMP NOT NULL,
  used_at         TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_token_hash (token_hash),
  INDEX idx_user_active (user_id, used_at)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NULL,
  email_attempted VARCHAR(190) NULL,
  ip_address      VARCHAR(45) NULL,
  success         TINYINT(1) NOT NULL,
  attempted_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_user (user_id),
  INDEX idx_la_time (attempted_at)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION C — ASSETS & FILES (Spec Section 3, 12)
-- ================================================================

CREATE TABLE assets (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asset_type    ENUM('logo','signature','seal','watermark','email_header') NOT NULL,
  name          VARCHAR(150) NOT NULL,
  server_path   VARCHAR(500) NOT NULL,
  mime_type     VARCHAR(100) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  uploaded_by   BIGINT UNSIGNED NULL,
  uploaded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE watermark_settings
  ADD CONSTRAINT fk_ws_image_asset FOREIGN KEY (image_asset_id) REFERENCES assets(id);

CREATE TABLE clients (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_unique_number  VARCHAR(50) NOT NULL UNIQUE,   -- NC/SC/YYYY/DDMMNNN
  company_legal_name    VARCHAR(255) NOT NULL,
  billing_address       TEXT NOT NULL,
  consignee_name        VARCHAR(255) NULL,             -- 'SAME' or explicit
  consignee_address     TEXT NULL,
  vat_eori_tax_no       VARCHAR(100) NULL,
  contact_person        VARCHAR(150) NULL,
  email                 VARCHAR(190) NULL,
  phone                 VARCHAR(30) NULL,
  country_of_destination VARCHAR(100) NULL,
  coo_type              VARCHAR(50) NULL,               -- from dropdown_options('coo_type')
  notify_party          VARCHAR(255) NULL,
  duplicate_of_client_id BIGINT UNSIGNED NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,  -- contact fields never hard-deleted; this soft-flags whole record
  is_sample_data        TINYINT(1) NOT NULL DEFAULT 0,  -- Phase E follow-up: 1 = Sample Data Playground record, hard-deletable via /sample-data — never set on a real client
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modified_by           BIGINT UNSIGNED NULL,
  modified_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (duplicate_of_client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

CREATE TABLE suppliers (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_legal_name VARCHAR(255) NOT NULL,
  address             TEXT NULL,
  gstin               VARCHAR(20) NULL,
  pan                 VARCHAR(20) NULL,
  contact_person      VARCHAR(150) NULL,
  phone               VARCHAR(30) NULL,
  supplier_type       VARCHAR(50) NULL,    -- from dropdown_options('supplier_type')
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_code          VARCHAR(50) NOT NULL UNIQUE,   -- e.g. SCI-AB-MS-001
  name                  VARCHAR(255) NOT NULL,
  product_type          VARCHAR(100) NULL,
  standard_finish       VARCHAR(150) NULL,
  standard_dimensions   VARCHAR(150) NULL,
  description           TEXT NULL,
  hs_code               VARCHAR(20) NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ================================================================
-- SECTION D — STAGES MASTER (Spec Section 6)
-- ================================================================

CREATE TABLE stages_master (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stage_number  INT NOT NULL UNIQUE,
  stage_slug    VARCHAR(50) NOT NULL UNIQUE,   -- 'quotation','pi','oc_production','packing','freight',
                                                -- 'bl_instruction','commercial_invoice','balance_bl_endorsement','closure'
  stage_name    VARCHAR(150) NOT NULL,
  sequence      INT NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ================================================================
-- SECTION E — ORDERS (Spec Section 6, 7)
-- ================================================================

CREATE TABLE orders (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_reference         VARCHAR(60) NOT NULL UNIQUE,  -- NC-SC-2026-0409001-001 (display form uses /)
  client_id               BIGINT UNSIGNED NOT NULL,
  sequence_no             INT NOT NULL,                  -- per-client order sequence
  buyer_inquiry_ref        VARCHAR(50) NOT NULL,          -- NC/SC/YYYY/DDMMNNN — master tracking ref
  payment_preset_id       BIGINT UNSIGNED NOT NULL,
  incoterm_id             BIGINT UNSIGNED NOT NULL,
  port_of_loading_id      BIGINT UNSIGNED NULL,
  port_of_discharge_id    BIGINT UNSIGNED NULL,
  port_of_discharge_text  VARCHAR(150) NULL,             -- free-text fallback if Admin allows non-dropdown
  currency_id             BIGINT UNSIGNED NOT NULL,
  coo_type                VARCHAR(50) NULL,               -- can be 'TBC' — blocks stage 1 gate until confirmed
  include_annexure_a      TINYINT(1) NOT NULL DEFAULT 0,
  special_requirements    TEXT NULL,

  -- Added during Phase B, reading the actual QT/PI/OC templates: the
  -- "Weight & Volume (Estimated)" and shipping-estimate fields those
  -- documents show at Stage 1-4 have no home in the spec's 28-table
  -- minimum or the 51-table schema built in Phase A — order_packing (Stage
  -- 7) only holds the FINAL confirmed figures, not the earlier estimate.
  -- Nullable throughout — "TBD at packing" is a valid, expected state.
  container_type          VARCHAR(50) NULL,               -- from dropdown_options('container_type')
  estimated_total_cbm      DECIMAL(10,3) NULL,
  estimated_gross_weight_kg DECIMAL(14,2) NULL,
  estimated_net_weight_kg  DECIMAL(14,2) NULL,
  estimated_package_count  VARCHAR(50) NULL,               -- e.g. '4' or 'TBD at packing'
  estimated_package_type   VARCHAR(100) NULL DEFAULT 'Wooden Crates',
  est_lead_time_text       VARCHAR(255) NULL,              -- e.g. '4-6 weeks from advance payment receipt'
  indicative_freight_low   DECIMAL(14,2) NULL,             -- CFR/CIF only
  indicative_freight_high  DECIMAL(14,2) NULL,
  indicative_insurance_amount DECIMAL(14,2) NULL,
  buyers_po_ref            VARCHAR(100) NULL,              -- buyer's own internal PO/ref — 'NIL' if none

  -- Per-document dates/refs the QT/PI/OC templates show explicitly.
  quotation_date           DATE NULL,
  quotation_valid_until    DATE NULL,                      -- quotation_date + 30 days (company_settings-driven span)
  pi_date                  DATE NULL,
  pi_valid_until           DATE NULL,                       -- pi_date + 15 days
  production_status_text   VARCHAR(255) NULL DEFAULT 'Not yet commenced',
  est_shipment_date_text   VARCHAR(255) NULL,

  status                  ENUM('active','complete','disputed','lost') NOT NULL DEFAULT 'active',
  current_stage_id        BIGINT UNSIGNED NULL,
  is_locked               TINYINT(1) NOT NULL DEFAULT 0,   -- true once status IN ('complete','lost') (Business Rule #17, extended for reporting — added 2026-09-19)

  -- Added 2026-09-19 — reporting could not distinguish "still in play" from
  -- "buyer walked away" (no such state existed at all before this). Reason
  -- is mandatory at the controller level, matching every other override
  -- action in this app; lost_by/lost_at give the audit trail its own quick
  -- columns instead of forcing every report to join audit_log.
  lost_reason             VARCHAR(500) NULL,
  lost_at                 TIMESTAMP NULL,
  lost_by                 BIGINT UNSIGNED NULL,

  -- Added in Phase D (Section 8 — Payment Terms Amendment System). An
  -- order's advance/balance % normally come from its payment_preset_id
  -- (joined live in OrderRepository::find()) — these three columns let an
  -- ACTIVATED amendment override just that one order's terms without
  -- touching the shared preset or fabricating a one-off preset row. NULL
  -- means "no override — use the preset as normal", which is every order
  -- until its first amendment is signed and activated. active_amendment_id
  -- is set at the same time, purely for traceability (which amendment is
  -- currently in force) — the FK is added by ALTER TABLE after the
  -- amendments table exists, below, since amendments itself references
  -- orders(id).
  -- balance_trigger_option/balance_days (not free text) because the PI/CI
  -- templates hardcode the sentence per enum value and only substitute the
  -- day count — see app/templates/PI/proforma_invoice.html.twig.
  advance_pct_override    DECIMAL(5,2) NULL,
  balance_pct_override    DECIMAL(5,2) NULL,
  balance_trigger_option_override ENUM('A_BEFORE_SHIPMENT','B_AGAINST_BL') NULL,
  balance_days_override   INT NULL,
  active_amendment_id     BIGINT UNSIGNED NULL,

  is_sample_data          TINYINT(1) NOT NULL DEFAULT 0,  -- Phase E follow-up: 1 = Sample Data Playground record, hard-deletable via /sample-data — never set on a real order
  created_by              BIGINT UNSIGNED NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (payment_preset_id) REFERENCES payment_presets(id),
  FOREIGN KEY (incoterm_id) REFERENCES incoterms(id),
  FOREIGN KEY (port_of_loading_id) REFERENCES ports(id),
  FOREIGN KEY (port_of_discharge_id) REFERENCES ports(id),
  FOREIGN KEY (currency_id) REFERENCES currencies(id),
  FOREIGN KEY (current_stage_id) REFERENCES stages_master(id),
  UNIQUE KEY uq_client_sequence (client_id, sequence_no)
) ENGINE=InnoDB;

CREATE TABLE order_stages (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          BIGINT UNSIGNED NOT NULL,
  stage_id          BIGINT UNSIGNED NOT NULL,
  status            ENUM('locked','unlocked','in_progress','gate_passed','skipped') NOT NULL DEFAULT 'locked',
  unlocked_at       TIMESTAMP NULL,
  gate_passed_at    TIMESTAMP NULL,
  gate_passed_by    BIGINT UNSIGNED NULL,
  skip_reason       VARCHAR(255) NULL,   -- e.g. 'FOB — freight stage auto-skipped'
  is_locked_data    TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (stage_id) REFERENCES stages_master(id),
  UNIQUE KEY uq_order_stage (order_id, stage_id)
) ENGINE=InnoDB;

CREATE TABLE order_products (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      BIGINT UNSIGNED NOT NULL,
  line_no       INT NOT NULL,
  product_id    BIGINT UNSIGNED NULL,
  description   VARCHAR(500) NOT NULL,
  material      VARCHAR(150) NULL,
  finish        VARCHAR(150) NULL,
  dimensions    VARCHAR(150) NULL,
  quantity      DECIMAL(14,3) NULL,          -- nullable/TBC allowed at Stage 1
  quantity_is_tbc TINYINT(1) NOT NULL DEFAULT 0,
  unit          VARCHAR(20) NULL,
  unit_price    DECIMAL(14,2) NULL,
  fob_value     DECIMAL(14,2) NULL,
  hs_code       VARCHAR(20) NOT NULL DEFAULT '6802.93',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE order_payment_status (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                    BIGINT UNSIGNED NOT NULL UNIQUE,
  advance_amount              DECIMAL(14,2) NULL,
  advance_remittance_received_at TIMESTAMP NULL,
  advance_cleared_at          TIMESTAMP NULL,
  advance_cleared_by          BIGINT UNSIGNED NULL,
  freight_amount              DECIMAL(14,2) NULL,
  freight_remittance_received_at TIMESTAMP NULL,
  freight_cleared_at          TIMESTAMP NULL,
  freight_cleared_by          BIGINT UNSIGNED NULL,
  balance_amount              DECIMAL(14,2) NULL,
  balance_remittance_received_at TIMESTAMP NULL,
  balance_cleared_at          TIMESTAMP NULL,
  balance_cleared_by          BIGINT UNSIGNED NULL,
  balance_due_date            DATE NULL,     -- computed: BL date + balance_days (option B) or shipment-readiness + balance_days (option A)
  followup_sent_at            TIMESTAMP NULL,
  escalated_to_md_at          TIMESTAMP NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

CREATE TABLE order_production (
  id                                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                          BIGINT UNSIGNED NOT NULL UNIQUE,
  supplier_id                       BIGINT UNSIGNED NULL,
  production_start_date             DATE NULL,
  expected_completion_date          DATE NULL,
  production_complete_confirmed_at  TIMESTAMP NULL,
  production_complete_confirmed_by  BIGINT UNSIGNED NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB;

CREATE TABLE order_supplier_po (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                      BIGINT UNSIGNED NOT NULL,
  supplier_id                   BIGINT UNSIGNED NOT NULL,
  supplier_po_reference         VARCHAR(60) NOT NULL,   -- SC/SPO/YYYY/DDMMNNN
  material_stone_type           VARCHAR(150) NULL,
  grade                         VARCHAR(50) NOT NULL DEFAULT 'Grade A',
  surface_finish                VARCHAR(150) NULL,
  dimensions                    VARCHAR(150) NULL,
  dimensional_tolerance         VARCHAR(100) NULL,
  quantity                      DECIMAL(14,3) NULL,
  unit                          VARCHAR(20) NULL,
  colour_reference              VARCHAR(150) NULL,
  special_requirements          VARCHAR(255) NULL,
  unit_price_inr                DECIMAL(14,2) NULL,
  basic_value_inr               DECIMAL(14,2) NULL,
  gst_rate_pct                  DECIMAL(5,2) NULL,
  gst_amount_inr                DECIMAL(14,2) NULL,
  total_payable_inr             DECIMAL(14,2) NULL,
  advance_pct                   DECIMAL(5,2) NULL,
  advance_amount_inr            DECIMAL(14,2) NULL,
  balance_amount_inr            DECIMAL(14,2) NULL,
  delivery_location              VARCHAR(255) NULL,
  required_delivery_date         DATE NULL,
  delivery_confirmation_due_date DATE NULL,
  packing_requirement            VARCHAR(255) NULL,
  status                         ENUM('draft','issued','signed','delivered','rejected','closed') NOT NULL DEFAULT 'draft',
  created_at                     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB;

CREATE TABLE order_packing (
  id                             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                       BIGINT UNSIGNED NOT NULL UNIQUE,
  actual_quantity_packed         DECIMAL(14,3) NULL,
  crate_count                   INT NULL,
  total_net_weight_kg            DECIMAL(14,2) NULL,
  total_gross_weight_kg          DECIMAL(14,2) NULL,
  total_cbm                     DECIMAL(10,3) NULL,
  fumigation_cert_file_id        BIGINT UNSIGNED NULL,   -- FK added after file_store table
  packing_date                   DATE NULL,
  shortfall_pct                  DECIMAL(5,2) NULL,
  shortfall_notice_recorded_at   TIMESTAMP NULL,          -- buyer notified in writing, recorded
  buyer_approval_file_id         BIGINT UNSIGNED NULL,    -- required if shortfall > tolerance
  packing_complete_confirmed_at  TIMESTAMP NULL,
  packing_complete_confirmed_by  BIGINT UNSIGNED NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

CREATE TABLE order_crates (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id              BIGINT UNSIGNED NOT NULL,
  crate_no              VARCHAR(20) NOT NULL,   -- 'C-001/010'
  marks_numbers         VARCHAR(500) NULL,
  product_description   VARCHAR(500) NULL,
  dimensions_lwh_cm     VARCHAR(100) NULL,
  pcs                   DECIMAL(10,2) NULL,
  net_weight_kg         DECIMAL(10,2) NULL,
  gross_weight_kg       DECIMAL(10,2) NULL,
  cbm                   DECIMAL(10,4) NULL,
  hs_code               VARCHAR(20) NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

CREATE TABLE order_freight (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                  BIGINT UNSIGNED NOT NULL UNIQUE,
  confirmed_freight_rate    DECIMAL(14,2) NULL,
  insurance_amount          DECIMAL(14,2) NULL,
  freight_forwarder_name    VARCHAR(255) NULL,
  freight_forwarder_contact VARCHAR(255) NULL,
  gst_treatment             ENUM('NIL','IGST_18') NULL,
  fdn_document_id           BIGINT UNSIGNED NULL,  -- FK added after documents table
  freight_cleared_at        TIMESTAMP NULL,
  freight_cleared_by        BIGINT UNSIGNED NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

CREATE TABLE order_shipping (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                  BIGINT UNSIGNED NOT NULL UNIQUE,
  shipping_line             VARCHAR(150) NULL,
  vessel_name               VARCHAR(150) NULL,
  voyage_number             VARCHAR(50) NULL,
  etd                       DATE NULL,
  eta                       DATE NULL,
  container_type            VARCHAR(50) NULL,
  container_no              VARCHAR(50) NULL,
  seal_no                   VARCHAR(50) NULL,
  bl_number                 VARCHAR(60) NULL,
  bl_date                   DATE NULL,
  draft_bl_file_id          BIGINT UNSIGNED NULL,
  draft_bl_uploaded_at      TIMESTAMP NULL,
  draft_bl_approved_at      TIMESTAMP NULL,
  draft_bl_approved_by      BIGINT UNSIGNED NULL,
  bl_originals_received_at  TIMESTAMP NULL,
  bl_originals_received_count INT NULL,
  bl_endorsed_at            TIMESTAMP NULL,
  bl_endorsed_by            BIGINT UNSIGNED NULL,
  scanned_bl_sent_to_buyer_at   TIMESTAMP NULL,
  scanned_bl_sent_to_accounts_at TIMESTAMP NULL,
  courier_tracking_number   VARCHAR(100) NULL,
  courier_sent_at           TIMESTAMP NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

CREATE TABLE order_annexure_products (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id              BIGINT UNSIGNED NOT NULL,
  product_id            BIGINT UNSIGNED NULL,
  product_code          VARCHAR(50) NULL,
  name                  VARCHAR(255) NOT NULL,
  description           TEXT NULL,
  dimensions            VARCHAR(150) NULL,
  finish                VARCHAR(150) NULL,
  components            TEXT NULL,
  technical_notes       TEXT NULL,
  sort_order            INT NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE product_images (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    BIGINT UNSIGNED NOT NULL,
  file_id       BIGINT UNSIGNED NOT NULL,  -- FK added after file_store table
  sort_order    INT NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE order_annexure_images (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_annexure_product_id  BIGINT UNSIGNED NOT NULL,
  file_id                    BIGINT UNSIGNED NOT NULL,
  sort_order                 INT NOT NULL DEFAULT 0,
  FOREIGN KEY (order_annexure_product_id) REFERENCES order_annexure_products(id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION F — DOCUMENTS (Spec Section 4, 9)
-- ================================================================

CREATE TABLE documents (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id              BIGINT UNSIGNED NULL,          -- NULL for non-order internal docs (SOPs, StageGate, WallRef)
  document_type_id      BIGINT UNSIGNED NOT NULL,
  document_reference    VARCHAR(60) NULL,               -- NULL for ref-less docs (Annexure A, SOPs)
  revision_number       INT NOT NULL DEFAULT 0,
  status                ENUM('draft','in_review','approved','sent','superseded') NOT NULL DEFAULT 'draft',
  generated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generated_by          BIGINT UNSIGNED NULL,
  docx_file_id          BIGINT UNSIGNED NULL,
  pdf_file_id           BIGINT UNSIGNED NULL,
  is_locked             TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (document_type_id) REFERENCES document_types(id)
) ENGINE=InnoDB;

ALTER TABLE watermark_settings
  ADD CONSTRAINT fk_ws_document FOREIGN KEY (document_id) REFERENCES documents(id);
ALTER TABLE order_freight
  ADD CONSTRAINT fk_of_fdn_doc FOREIGN KEY (fdn_document_id) REFERENCES documents(id);

CREATE TABLE document_revisions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id       BIGINT UNSIGNED NOT NULL,
  revision_number   INT NOT NULL,
  reason_for_revision VARCHAR(500) NULL,
  data_snapshot     JSON NULL,             -- full field values used to generate this revision — immutable history
  docx_file_id      BIGINT UNSIGNED NULL,
  pdf_file_id       BIGINT UNSIGNED NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_current        TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (document_id) REFERENCES documents(id),
  UNIQUE KEY uq_doc_rev (document_id, revision_number)
) ENGINE=InnoDB;

CREATE TABLE document_reviews (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id   BIGINT UNSIGNED NOT NULL,
  reviewer_id   BIGINT UNSIGNED NOT NULL,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  comments      TEXT NULL,
  reviewed_at   TIMESTAMP NULL,
  assigned_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (document_id) REFERENCES documents(id),
  FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE document_cross_verifications (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id   BIGINT UNSIGNED NOT NULL,
  verified_by   BIGINT UNSIGNED NOT NULL,
  result        ENUM('pass','fail') NOT NULL,
  comments      TEXT NULL,
  verified_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (document_id) REFERENCES documents(id),
  FOREIGN KEY (verified_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE amendments (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  amendment_reference   VARCHAR(60) NOT NULL UNIQUE,  -- SC/AMD/YYYY/DDMMNNN — never on buyer-facing docs
  order_id              BIGINT UNSIGNED NOT NULL,
  reason                TEXT NOT NULL,
  requested_by          ENUM('importer','exporter') NOT NULL,
  md_approved_by        BIGINT UNSIGNED NULL,
  md_approved_at        TIMESTAMP NULL,
  original_terms_snapshot JSON NOT NULL,   -- copied character-for-character from the PI at time of amendment
  amended_advance_pct   DECIMAL(5,2) NULL,
  amended_advance_amount DECIMAL(14,2) NULL,
  amended_balance_terms  VARCHAR(500) NULL,   -- free-text prose for the AMD legal document's Section 4 only
  amended_balance_trigger_option ENUM('A_BEFORE_SHIPMENT','B_AGAINST_BL') NULL,  -- structured value that actually drives future PI/CI rendering
  amended_balance_days   INT NULL,
  amended_balance_amount DECIMAL(14,2) NULL,
  effective_from        DATE NULL,
  signed_copy_file_id   BIGINT UNSIGNED NULL,
  document_id           BIGINT UNSIGNED NULL,   -- the generated SC/AMD document record
  status                ENUM('pending','md_approved','signed','active','rejected') NOT NULL DEFAULT 'pending',
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB;

ALTER TABLE orders
  ADD CONSTRAINT fk_orders_active_amendment FOREIGN KEY (active_amendment_id) REFERENCES amendments(id);

-- ================================================================
-- SECTION G — FILES (Spec Section 3)
-- ================================================================

CREATE TABLE file_store (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id           BIGINT UNSIGNED NULL,
  order_id            BIGINT UNSIGNED NULL,
  stage_id            BIGINT UNSIGNED NULL,
  file_origin         ENUM('GENERATED_AUTO','GENERATED_MANUAL','RECEIVED') NOT NULL,
  received_from       VARCHAR(50) NULL,     -- Buyer/CHA/Shipping Line/Supplier/Bank/Government Authority/Internal/Other
  document_type_label VARCHAR(100) NULL,    -- confirmation-popup dropdown value, not FK to document_types
                                             -- (covers "Payment Remittance", "Signed Buyer PO", etc. — non-generated artifacts)
  generation_method   VARCHAR(50) NULL,
  linked_document_id  BIGINT UNSIGNED NULL,
  sent_to_client      TINYINT(1) NOT NULL DEFAULT 0,
  sent_at             TIMESTAMP NULL,
  internal_only       TINYINT(1) NOT NULL DEFAULT 0,
  server_path         VARCHAR(500) NOT NULL,
  uuid_filename       VARCHAR(255) NOT NULL,
  original_filename   VARCHAR(255) NOT NULL,
  file_size_bytes     BIGINT UNSIGNED NOT NULL,
  mime_type           VARCHAR(100) NULL,
  notes               TEXT NULL,
  uploaded_by         BIGINT UNSIGNED NULL,
  uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,   -- soft delete only — never hard delete
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (stage_id) REFERENCES stages_master(id),
  FOREIGN KEY (linked_document_id) REFERENCES documents(id)
) ENGINE=InnoDB;

ALTER TABLE documents
  ADD CONSTRAINT fk_doc_docx FOREIGN KEY (docx_file_id) REFERENCES file_store(id),
  ADD CONSTRAINT fk_doc_pdf  FOREIGN KEY (pdf_file_id)  REFERENCES file_store(id);
ALTER TABLE document_revisions
  ADD CONSTRAINT fk_dr_docx FOREIGN KEY (docx_file_id) REFERENCES file_store(id),
  ADD CONSTRAINT fk_dr_pdf  FOREIGN KEY (pdf_file_id)  REFERENCES file_store(id);
ALTER TABLE order_packing
  ADD CONSTRAINT fk_op_fumigation FOREIGN KEY (fumigation_cert_file_id) REFERENCES file_store(id),
  ADD CONSTRAINT fk_op_buyer_approval FOREIGN KEY (buyer_approval_file_id) REFERENCES file_store(id);
ALTER TABLE order_shipping
  ADD CONSTRAINT fk_os_draft_bl FOREIGN KEY (draft_bl_file_id) REFERENCES file_store(id);
ALTER TABLE amendments
  ADD CONSTRAINT fk_amd_signed FOREIGN KEY (signed_copy_file_id) REFERENCES file_store(id);
ALTER TABLE product_images
  ADD CONSTRAINT fk_pi_file FOREIGN KEY (file_id) REFERENCES file_store(id);
ALTER TABLE order_annexure_images
  ADD CONSTRAINT fk_oai_file FOREIGN KEY (file_id) REFERENCES file_store(id);

-- ================================================================
-- SECTION H — EMAIL / NOTIFICATIONS (Spec Section 10)
-- ================================================================

CREATE TABLE email_log (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          BIGINT UNSIGNED NULL,
  document_id       BIGINT UNSIGNED NULL,
  template_key      VARCHAR(100) NULL,
  recipient_email   VARCHAR(190) NOT NULL,
  subject           VARCHAR(255) NOT NULL,
  body_snapshot     TEXT NOT NULL,
  scheduled_at      TIMESTAMP NULL,
  sent_at           TIMESTAMP NULL,
  status            ENUM('pending_approval','approved','rejected','sent','failed','cancelled') NOT NULL DEFAULT 'pending_approval',
  requested_by      BIGINT UNSIGNED NULL,
  approved_by       BIGINT UNSIGNED NULL,
  approved_at       TIMESTAMP NULL,
  rejection_reason  VARCHAR(500) NULL,
  cancelled_by      BIGINT UNSIGNED NULL,
  cancelled_at      TIMESTAMP NULL,
  cancellation_reason VARCHAR(500) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NULL,
  role_id           BIGINT UNSIGNED NULL,
  type              VARCHAR(100) NOT NULL,   -- 'review_assigned','balance_overdue','lut_expiry',...
  related_order_id  BIGINT UNSIGNED NULL,
  message           VARCHAR(500) NOT NULL,
  is_read           TINYINT(1) NOT NULL DEFAULT 0,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (role_id) REFERENCES roles(id),
  FOREIGN KEY (related_order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION I — DISPUTES (Spec Section 16)
-- ================================================================

CREATE TABLE disputes (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id            BIGINT UNSIGNED NOT NULL,
  notice_date         DATE NOT NULL,
  from_party          VARCHAR(150) NULL,
  description         TEXT NOT NULL,
  assigned_to         BIGINT UNSIGNED NULL,
  response_due_date   DATE NULL,             -- notice_date + N working days (N from company_settings)
  status              VARCHAR(30) NOT NULL DEFAULT 'Open',  -- from dropdown_options('dispute_status')
  resolved_at         TIMESTAMP NULL,
  resolution_notes    TEXT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE dispute_documents (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dispute_id    BIGINT UNSIGNED NOT NULL,
  file_id       BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (dispute_id) REFERENCES disputes(id),
  FOREIGN KEY (file_id) REFERENCES file_store(id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION J — AUDIT LOG (Spec Section 11, 13, 14 — immutable, no delete)
-- ================================================================

CREATE TABLE audit_log (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NULL,
  action_type   VARCHAR(100) NOT NULL,   -- 'FIELD_EDIT','LOCK_OVERRIDE','DOCUMENT_GENERATED','LOGIN_FAILED',...
  entity_type   VARCHAR(100) NULL,        -- 'orders','clients','company_settings',...
  entity_id     BIGINT UNSIGNED NULL,
  field_name    VARCHAR(150) NULL,
  old_value     TEXT NULL,
  new_value     TEXT NULL,
  reason        VARCHAR(500) NULL,
  ip_address    VARCHAR(45) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_time (created_at)
  -- No update/delete grants at the application DB-user level for this table.
) ENGINE=InnoDB;

-- ================================================================
-- SECTION K — 2FA BACKUP CODES & SAVED REPORT DEFINITIONS
-- (Resolved 2026-09-18 — see ARCHITECTURE.md "Open questions", now closed)
-- ================================================================

CREATE TABLE two_fa_backup_codes (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  code_hash     VARCHAR(255) NOT NULL,   -- one-time recovery code, hashed same as password_hash
  used_at       TIMESTAMP NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_2fa_backup_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE report_definitions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150) NOT NULL,           -- e.g. "Weekly Overdue — Tier 2"
  report_type     VARCHAR(50) NOT NULL,            -- 'orders','payments','clients','disputes',... (drives which base query runs)
  owner_user_id   BIGINT UNSIGNED NOT NULL,
  visibility      ENUM('private','shared') NOT NULL DEFAULT 'private',
  filters_json    JSON NOT NULL,                    -- saved filter criteria (stage, date range, tier, currency, status, ...)
  columns_json    JSON NOT NULL,                    -- saved column selection + order
  last_run_at     TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_user_id) REFERENCES users(id),
  INDEX idx_report_owner (owner_user_id),
  INDEX idx_report_type (report_type)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION L — PROTECTED FIELDS (peer-approved lock/unlock governance)
-- Added 2026-09-19. `is_protected` (see company_settings, tc_clauses,
-- payment_presets above) is one flag/mechanism reused across all three
-- tables: a protected row cannot be edited without the app-layer unlock
-- gesture (reason + explicit unlock + confirmation, all logged to
-- audit_log), and cannot be hard-deleted or blanked even via direct SQL —
-- the triggers below are the DB-level backstop for that second part.
-- Toggling is_protected itself is never done by a single admin acting
-- alone: it goes through this request/approve table, and a second,
-- different privileged user (permission manage_field_protection) must
-- approve before the flag actually flips — enforced in the app layer
-- (fieldProtectionController / FieldProtectionController), because a
-- database trigger cannot distinguish "who" issued a given UPDATE.
-- ================================================================

CREATE TABLE field_protection_requests (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_name        VARCHAR(50) NOT NULL,    -- 'company_settings' | 'tc_clauses' | 'payment_presets'
  record_id         BIGINT UNSIGNED NOT NULL,
  record_label      VARCHAR(255) NOT NULL,   -- human-readable snapshot (setting_key / clause_title /
                                              -- preset_name) taken at request time, so the request stays
                                              -- readable even if the underlying row is later renamed
  requested_action  ENUM('lock','unlock') NOT NULL,
  reason            VARCHAR(500) NOT NULL,
  requested_by      BIGINT UNSIGNED NOT NULL,
  status            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  resolved_by       BIGINT UNSIGNED NULL,
  resolved_reason   VARCHAR(500) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at       TIMESTAMP NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (resolved_by) REFERENCES users(id),
  INDEX idx_fpr_status (status),
  INDEX idx_fpr_table_record (table_name, record_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ---- DB-level backstop: block hard-delete and blanking of protected rows,
-- ---- independent of and beneath any application-layer bug. These fire
-- ---- regardless of which DB user issues the statement.
DELIMITER $$

CREATE TRIGGER trg_company_settings_bd BEFORE DELETE ON company_settings
FOR EACH ROW
BEGIN
  IF OLD.is_protected = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete a protected company setting.';
  END IF;
END$$

CREATE TRIGGER trg_company_settings_bu BEFORE UPDATE ON company_settings
FOR EACH ROW
BEGIN
  IF OLD.is_protected = 1 AND (NEW.setting_value IS NULL OR NEW.setting_value = '') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot blank a protected company setting.';
  END IF;
END$$

CREATE TRIGGER trg_tc_clauses_bd BEFORE DELETE ON tc_clauses
FOR EACH ROW
BEGIN
  IF OLD.is_protected = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete a protected T&C clause.';
  END IF;
END$$

CREATE TRIGGER trg_tc_clauses_bu BEFORE UPDATE ON tc_clauses
FOR EACH ROW
BEGIN
  IF OLD.is_protected = 1 AND (NEW.clause_text IS NULL OR NEW.clause_text = '' OR NEW.status = 'inactive') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot blank or deactivate a protected T&C clause.';
  END IF;
END$$

CREATE TRIGGER trg_payment_presets_bd BEFORE DELETE ON payment_presets
FOR EACH ROW
BEGIN
  IF OLD.is_protected = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete a protected payment preset.';
  END IF;
END$$

CREATE TRIGGER trg_payment_presets_bu BEFORE UPDATE ON payment_presets
FOR EACH ROW
BEGIN
  IF OLD.is_protected = 1 AND NEW.is_active = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot deactivate a protected payment preset.';
  END IF;
END$$

DELIMITER ;

-- ================================================================
-- SECTION M — SIGNATORIES & DESIGNATIONS (added 2026-09-20)
-- ================================================================
-- The company seal stays exactly as it was: a single shared company asset
-- in the existing `assets` table (asset_type='seal'), unchanged. What's new
-- here is per-signatory identity: more than one Director can exist, each
-- with their own signature image and their own personal designation seal
-- (confirmed real from the source Word documents — both are embossed
-- circular seals reading "DIRECTOR / <name>", distinct from the plain
-- company seal). Kept as a separate table rather than overloading `assets`
-- so the existing single-active-row-per-type semantics in
-- AssetRepository::replace() (logo/watermark/email_header/seal) are not
-- touched at all — this is purely additive.

CREATE TABLE designations (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(100) NOT NULL UNIQUE,   -- e.g. "Director", "Founder & Managing Director"
  is_active   TINYINT(1) NOT NULL DEFAULT 1,  -- deactivate, never hard-delete — a user/document may still reference it
  created_by  BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE user_signature_assets (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id                 BIGINT UNSIGNED NOT NULL,
  asset_kind              ENUM('signature','designation_seal') NOT NULL,
  label                   VARCHAR(100) NOT NULL,   -- e.g. "Default", "Formal", lets one person hold several signature variants
  server_path             VARCHAR(500) NOT NULL,
  mime_type               VARCHAR(100) NULL,
  is_default_for_kind     TINYINT(1) NOT NULL DEFAULT 0,  -- which one of this user's own variants is their default
  is_active                TINYINT(1) NOT NULL DEFAULT 1,
  uploaded_by             BIGINT UNSIGNED NULL,
  uploaded_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE users
  ADD COLUMN designation_id          BIGINT UNSIGNED NULL AFTER role_id,
  ADD COLUMN is_signatory_eligible   TINYINT(1) NOT NULL DEFAULT 0 AFTER designation_id,
  ADD CONSTRAINT fk_users_designation FOREIGN KEY (designation_id) REFERENCES designations(id);

-- Global fallback signatory (Business Rule: lowest-precedence layer).
-- Single row by design — id is always 1.
CREATE TABLE company_default_signatory (
  id          TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  user_id     BIGINT UNSIGNED NOT NULL,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT chk_cds_single_row CHECK (id = 1)
) ENGINE=InnoDB;

-- Per-document-type default (middle-precedence layer). Admin-editable;
-- falls back to company_default_signatory when no row exists for a type.
CREATE TABLE document_type_signatories (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_type_id     BIGINT UNSIGNED NOT NULL UNIQUE,
  user_id               BIGINT UNSIGNED NOT NULL,
  use_designation_seal  TINYINT(1) NOT NULL DEFAULT 0,  -- 0 = company seal, 1 = this signatory's own designation seal
  updated_by            BIGINT UNSIGNED NULL,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (document_type_id) REFERENCES document_types(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Every generated document snapshots which signatory/seal/signature was
-- actually used (highest-precedence layer is the per-instance override
-- passed at generation time and captured here) — never a live lookup, so
-- changing a default later never alters how a past document reads.
ALTER TABLE documents
  ADD COLUMN signatory_user_id             BIGINT UNSIGNED NULL,
  ADD COLUMN signatory_name_snapshot       VARCHAR(150) NULL,
  ADD COLUMN signatory_designation_snapshot VARCHAR(100) NULL,
  ADD COLUMN signature_asset_id_snapshot   BIGINT UNSIGNED NULL,
  ADD COLUMN seal_asset_id_snapshot        BIGINT UNSIGNED NULL,   -- either the company seal (assets.id) or a designation seal (user_signature_assets.id) — see used_designation_seal
  ADD COLUMN used_designation_seal         TINYINT(1) NOT NULL DEFAULT 0,
  ADD CONSTRAINT fk_documents_signatory FOREIGN KEY (signatory_user_id) REFERENCES users(id);

-- ================================================================
-- SECTION N — SUPER ADMIN TIER (added 2026-09-20)
-- ================================================================
-- A tier above Admin/Managing Director: unrestricted everywhere, including
-- self-approval on every gate that otherwise requires a *different*
-- privileged user (Field Protection being the first such gate — see
-- Section L). Deliberately a flag on `users`, not a role: role/permission
-- rows describe a bundle of capabilities; Super Admin is an orthogonal
-- override checked directly by PermissionService::can() and by name at
-- every explicit self-approval gate, not something that composes through
-- role_permissions/user_permissions.
ALTER TABLE users
  ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0;

-- Temporary, revocable delegation: a Super Admin can grant an Admin the
-- ability to *act as* Super Admin for a period, without changing that
-- Admin's actual role_id or is_super_admin flag — a capability loan, not a
-- promotion. SuperAdminService::isEffective() checks both this table and
-- the permanent flag, so every gate only ever needs one call.
CREATE TABLE super_admin_delegations (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  delegate_user_id  BIGINT UNSIGNED NOT NULL,
  granted_by        BIGINT UNSIGNED NOT NULL,
  reason            VARCHAR(255) NOT NULL,   -- mandatory, minimum length enforced in the application (Section 13 gap: every reason field needs one)
  granted_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at        TIMESTAMP NULL,          -- NULL = no fixed expiry; still revocable at any time by any Super Admin
  revoked_at        TIMESTAMP NULL,
  revoked_by        BIGINT UNSIGNED NULL,
  revoked_reason    VARCHAR(255) NULL,
  FOREIGN KEY (delegate_user_id) REFERENCES users(id),
  FOREIGN KEY (granted_by) REFERENCES users(id),
  FOREIGN KEY (revoked_by) REFERENCES users(id),
  INDEX idx_sad_active (delegate_user_id, revoked_at, expires_at)
) ENGINE=InnoDB;

-- DB-level backstop, independent of the application: a Super Admin account
-- can never be deleted, and can never be deactivated (is_active set to 0),
-- full stop — matching the business rule literally ("nobody can delete the
-- Super Admin", "Super Admin never gets deactivated, at any condition").
-- Demoting/promoting the is_super_admin flag itself is a separate, allowed,
-- audited action (App\Controllers\SuperAdminController) — distinct from
-- delete/deactivate, so a genuine handover (e.g. the account's email
-- changes hands) is never permanently blocked, only delete/deactivate is.
DELIMITER $$

CREATE TRIGGER trg_users_super_admin_bd BEFORE DELETE ON users
FOR EACH ROW
BEGIN
  IF OLD.is_super_admin = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete a Super Admin account.';
  END IF;
END$$

CREATE TRIGGER trg_users_super_admin_bu BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
  IF OLD.is_super_admin = 1 AND NEW.is_active = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot deactivate a Super Admin account.';
  END IF;
END$$

DELIMITER ;

-- ================================================================
-- SECTION O — CLIENT PORTAL (added 2026-09-20)
-- ================================================================
-- Scope, as confirmed: this system starts the moment a Zoho-qualified
-- prospect is sent the quotation-stage form link below — filling it out IS
-- their onboarding into this system, landing in a staff review queue
-- (never auto-creating a client/order). The client gets no login at all
-- until their first order's advance payment is marked CLEARED — at that
-- point client_logins is provisioned automatically and a set-password link
-- is emailed. From then on they see every client-facing document for
-- every one of their orders, retroactively from the Quotation onward,
-- read-only, and can change only their own password.

-- Public, unauthenticated quotation-stage intake. Never auto-creates a
-- client — a member of staff reviews every submission (see
-- ClientIntakeReviewController) and, if accepted, converts it into a real
-- `clients` row using the existing ClientRepository::create() path (same
-- code a manually-entered walk-in client goes through), never a separate
-- fake-data path.
CREATE TABLE client_intake_submissions (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_legal_name      VARCHAR(255) NOT NULL,
  billing_address         TEXT NOT NULL,
  vat_eori_tax_no         VARCHAR(100) NULL,
  contact_person          VARCHAR(150) NOT NULL,
  email                   VARCHAR(190) NOT NULL,
  phone                   VARCHAR(30) NULL,
  country_of_destination  VARCHAR(100) NOT NULL,
  port_of_discharge_text  VARCHAR(150) NULL,
  coo_type                VARCHAR(50) NULL,
  incoterm_preference     VARCHAR(100) NULL,   -- free text per source Quotation Form — "if unsure, write FOB"
  container_type_text     VARCHAR(100) NULL,
  buyer_own_reference     VARCHAR(100) NULL,   -- buyer's own internal reference, if any — "write NIL if none"
  notes                   TEXT NULL,
  status                  ENUM('pending','converted','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by             BIGINT UNSIGNED NULL,
  reviewed_at             TIMESTAMP NULL,
  rejection_reason        VARCHAR(500) NULL,
  converted_client_id     BIGINT UNSIGNED NULL,
  submitted_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_ip            VARCHAR(45) NULL,
  FOREIGN KEY (reviewed_by) REFERENCES users(id),
  FOREIGN KEY (converted_client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

-- One login per client, created ONLY by the Stage 3 advance-cleared gate
-- (OrderController::clearAdvancePayment() / ClientPortalService) — never
-- earlier, never manually. No row here = that client cannot log in, full
-- stop, matching the business rule literally. Login identifier is the
-- client's own clients.email — a separate client-portal auth surface
-- entirely from `users`/staff sessions (structurally separate, per the
-- explicit requirement that client and company-user surfaces never mix).
CREATE TABLE client_logins (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id             BIGINT UNSIGNED NOT NULL UNIQUE,
  password_hash         VARCHAR(255) NOT NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  force_password_change TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_count    INT NOT NULL DEFAULT 0,
  locked_until          TIMESTAMP NULL,
  last_login_at         TIMESTAMP NULL,
  password_changed_at   TIMESTAMP NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_order_id   BIGINT UNSIGNED NULL,   -- which order's advance-cleared gate triggered creation
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (created_by_order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

-- Single-use "set your password" link, emailed the moment client_logins is
-- first created (never a raw password by email) — mirrors
-- password_reset_tokens exactly: only the SHA-256 hash is ever stored.
CREATE TABLE client_password_reset_tokens (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id       BIGINT UNSIGNED NOT NULL,
  token_hash      CHAR(64) NOT NULL,
  requested_ip    VARCHAR(45) NULL,
  expires_at      TIMESTAMP NOT NULL,
  used_at         TIMESTAMP NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id),
  UNIQUE KEY uq_client_token_hash (token_hash),
  INDEX idx_client_token_active (client_id, used_at)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION P — DOCUMENT DATA-INTEGRITY SNAPSHOT (added 2026-09-21)
-- ================================================================
-- Same reasoning as the signatory snapshot columns above, extended to the
-- rest of what DocumentDataAssembler::companyBlock() reads: company
-- identity (legal name, registered office, GSTIN, IEC/PAN), bank details,
-- LUT/RCMC numbers, and the RBI purpose codes are all company_settings
-- rows that can legitimately change at any time — a bank account switch,
-- an LUT renewal — completely independent of any single order. Real bug
-- this closes: DocumentGenerationService::finalizeApproval() (the
-- draft -> final watermark-swap re-render, which its own docblock
-- correctly assumes is safe because "nothing legitimately changes an
-- order's data" between generation and approval) re-ran
-- DocumentDataAssembler::assemble() from scratch, which re-read
-- companyBlock() LIVE — so if the company's bank account or LUT number
-- changed during the review window, the buyer-facing FINAL PDF would
-- silently show different bank/LUT details than the DRAFT the reviewer
-- actually approved. company_snapshot_json is captured once, at
-- generate() time, and finalizeApproval() renders from it instead of a
-- fresh companyBlock() call — a past document's company/bank/LUT details
-- can never drift after the fact, matching the signatory snapshot's exact
-- guarantee. (document_revisions.data_snapshot, above, was clearly
-- reserved for this same purpose but assumes one `documents` row persists
-- across every revision; the app instead creates a new `documents` row
-- per revision — see DocumentGenerationService::generate() — so a column
-- on `documents` itself, not a second table, is the fix that actually
-- matches how revisions work today.)
ALTER TABLE documents
  ADD COLUMN company_snapshot_json JSON NULL;

-- ================================================================
-- SECTION Q — WORKING-DAYS CALCULATOR & HOLIDAY CALENDAR (added 2026-09-21)
-- ================================================================
-- Real bug this closes: disputes.response_due_date is documented right
-- above (see its column comment) as "notice_date + N working days (N from
-- company_settings)" — and the Dispute Resolution & Public Communications
-- clause seeded into tc_clauses is a legally binding contractual term
-- stating the receiving party "shall respond within ten (10) working days
-- of deemed delivery". DisputeController::create() computed this due date
-- with plain calendar-day arithmetic (DateTimeImmutable::modify("+N
-- days")), silently counting Sundays as if they were business days — a
-- due date printed on a legal notice that doesn't match what "working
-- days" actually means is exactly the kind of inconsistency that
-- undermines the company's credibility in a dispute. There was also no
-- holiday calendar anywhere in the schema, so even a correct weekly-off
-- skip would still have counted national holidays as working days.
--
-- company_holidays is a flat, admin-managed list of specific dates (not a
-- recurring rule engine — a public holiday's date changes every year
-- anyway, e.g. Diwali, so re-entering each year's actual dates is both
-- simpler and more correct than a "same day every year" rule). The weekly
-- off-day(s) are a company_settings row (see seed.sql) rather than a
-- second table, since NexaCrest's Mon-Sat working week is a single fixed
-- fact, not a list that grows over time.
CREATE TABLE company_holidays (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE NOT NULL,
  description  VARCHAR(255) NOT NULL,
  created_by   BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
  UNIQUE KEY uq_company_holidays_date (holiday_date)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION R — INTERNAL REFERENCE LIBRARY (added 2026-09-21)
-- ================================================================
-- Gap this closes: document_types already seeded five internal,
-- never_shown_to_buyer reference types — CHECKLIST (Cross-Verification
-- Checklist), SOP_A_SALES / SOP_B_SALES (Sales Process tier reference),
-- STAGEGATE (Stage Gate Reference), WALLREF (Wall Reference) — but
-- nothing anywhere in the app ever generated, stored, or displayed
-- content for any of them; they existed only as rows nobody read. Unlike
-- every other document_types row, these five are not order-scoped and
-- have no revision/review workflow that makes sense for them
-- (revision_enabled=0 on three of the five, min_reviewers_default is
-- meaningless with no order to review against) — they're evergreen,
-- admin-editable staff reference content, closer in shape to
-- tc_clauses than to a generated document. One row per document_type_id
-- (not per-order) holds that content; a plain in-app viewer renders it,
-- and an edit screen (gated the same as company_settings) lets Admin/MD
-- keep it current as the real process changes.
CREATE TABLE internal_reference_docs (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_type_id  BIGINT UNSIGNED NOT NULL,
  content           LONGTEXT NOT NULL,
  updated_by        BIGINT UNSIGNED NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (document_type_id) REFERENCES document_types(id),
  FOREIGN KEY (updated_by) REFERENCES users(id),
  UNIQUE KEY uq_internal_reference_docs_type (document_type_id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION S — BUYER PO / SUPPLIER PO ACKNOWLEDGMENT EVIDENCE (added 2026-09-21)
-- ================================================================
-- Gap this closes: recordBuyerPo() (Stage 1->2: "buyer's signed PO
-- received") and confirmSupplierSigned() (Stage 5->6: "supplier PO
-- signature confirmed") each flip a gate on nothing but a text field or a
-- button click — unlike every other counterparty-evidence flow in this
-- app (amendment_signed_copy, dispute_document, buyer_approval), neither
-- ever captures the actual signed document as proof. Two join tables,
-- exactly mirroring dispute_documents' proven shape: file_store already
-- gets a new row per upload (never overwritten), so a plain
-- (parent_id, file_id) join table gives real version history for free —
-- re-uploading a corrected copy adds a row, it never replaces one.
-- order_supplier_po_documents keys off the specific order_supplier_po row
-- (not order_id) since an order can have more than one Supplier PO
-- version (OrderSupplierPoRepository::findLatestForOrder implies exactly
-- that), and the acknowledgment belongs to the PO version it was signed
-- against.
CREATE TABLE order_buyer_po_documents (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   BIGINT UNSIGNED NOT NULL,
  file_id    BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (file_id) REFERENCES file_store(id)
) ENGINE=InnoDB;

CREATE TABLE order_supplier_po_documents (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_supplier_po_id  BIGINT UNSIGNED NOT NULL,
  file_id               BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (order_supplier_po_id) REFERENCES order_supplier_po(id),
  FOREIGN KEY (file_id) REFERENCES file_store(id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION T — SUPPLIER SAMPLE-DATA FLAG (added 2026-09-21)
-- ================================================================
-- Task #17 (expanded Sample Data Playground) adds a third sample order
-- that reaches Stage 5 (Supplier PO), which means SampleDataService needs
-- a supplier row to attach it to. clients and orders already have
-- is_sample_data for exactly this — SampleDataRepository::clearAll()
-- hard-deletes by that flag, never by name — but suppliers never needed
-- the same flag before now, since no sample order previously went past
-- Stage 4. Without it, a supplier created for the sample order would
-- leak into the real, global supplier dropdown permanently: clearAll()
-- already deletes every order_supplier_po row before this point (it's
-- one of the order-scoped tables in that method's cleanup loop), so by
-- the time suppliers are deleted here nothing still references them.
ALTER TABLE suppliers
  ADD COLUMN is_sample_data TINYINT(1) NOT NULL DEFAULT 0;

-- ================================================================
-- SECTION U — PRODUCT INTERFACE / INTERNAL PRODUCT CATALOG (added 2026-09-21)
-- ================================================================
-- Internal staff reference catalog (specs, HS code, cost, suppliers) that
-- staff consult while manually preparing quotations. Deliberately and
-- completely independent of the order pipeline — no foreign keys to
-- orders/order_products, and nothing here is ever selected into an order
-- or shown to a client/buyer; it is an internal-only reference tool.
--
-- NAMING NOTE: Section C already defines a `products` table used by the
-- order pipeline (order_products/buyer_po_line_items key off it via
-- product_id -> products(id)). This catalog is a completely separate,
-- unrelated concept (see above) that happens to share the word
-- "product" — so these four tables are prefixed `catalog_` to avoid
-- colliding with that existing table and to make the independence
-- explicit at the schema level, not just in application code.
CREATE TABLE catalog_products (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                        VARCHAR(255) NOT NULL,
  specifications              TEXT NOT NULL,
  hs_code                     VARCHAR(20) NOT NULL,        -- mandatory, no default
  origin                      VARCHAR(150) NULL,
  default_factory_cost        DECIMAL(14,2) NULL,
  default_transportation_cost DECIMAL(14,2) NULL,
  default_packing_cost        DECIMAL(14,2) NULL,
  default_loading_cost        DECIMAL(14,2) NULL,
  default_cha_cost            DECIMAL(14,2) NULL,
  is_active                   TINYINT(1) NOT NULL DEFAULT 1,
  created_by                  BIGINT UNSIGNED NULL,
  updated_by                  BIGINT UNSIGNED NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (updated_by) REFERENCES users(id),
  INDEX idx_catalog_products_hs_code (hs_code),
  INDEX idx_catalog_products_name (name)
) ENGINE=InnoDB;

CREATE TABLE catalog_product_images (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    BIGINT UNSIGNED NOT NULL,
  server_path   VARCHAR(500) NOT NULL,
  mime_type     VARCHAR(100) NULL,
  uploaded_by   BIGINT UNSIGNED NULL,
  uploaded_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES catalog_products(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Same product can be sourced from more than one supplier/location (e.g.
-- Karnataka vs Telangana) at different costs — one row per source.
-- fob_value is only meaningful when fob_source='direct'; for 'computed'
-- suppliers it stays NULL forever and the effective FOB is assembled at
-- read time by ProductService::effectiveFobForSupplier() from the cost
-- component fields below (falling back to the parent product's defaults
-- via PHP's ?? operator — NULL falls back, a real 0 does not) so the
-- number can never go stale the way a persisted computed value could.
-- "only one primary supplier per product" (is_primary) is enforced in
-- ProductService::setPrimarySupplier(), not by a DB constraint, since it
-- requires clearing the other rows for the same product_id in the same
-- transaction before setting the new one.
CREATE TABLE catalog_product_suppliers (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id            BIGINT UNSIGNED NOT NULL,
  supplier_name         VARCHAR(255) NOT NULL,
  location              VARCHAR(150) NULL,       -- e.g. "Karnataka" vs "Telangana" — same product, different source
  contact_person        VARCHAR(150) NULL,
  contact_phone         VARCHAR(50) NULL,
  contact_email         VARCHAR(150) NULL,
  fob_source            ENUM('direct','computed') NOT NULL DEFAULT 'direct',
  fob_value             DECIMAL(14,2) NULL,       -- required/used when fob_source='direct'; NULL when 'computed' (computed dynamically, see above — never store a value here that could go stale)
  factory_cost          DECIMAL(14,2) NULL,       -- overrides catalog_products.default_factory_cost when set; NULL = inherit
  transportation_cost   DECIMAL(14,2) NULL,       -- overrides catalog_products.default_transportation_cost when set; NULL = inherit
  packing_cost          DECIMAL(14,2) NULL,       -- overrides catalog_products.default_packing_cost when set; NULL = inherit
  loading_cost          DECIMAL(14,2) NULL,       -- overrides catalog_products.default_loading_cost when set; NULL = inherit
  cha_cost              DECIMAL(14,2) NULL,       -- overrides catalog_products.default_cha_cost when set; NULL = inherit
  is_primary            TINYINT(1) NOT NULL DEFAULT 0,   -- which supplier's FOB is the headline price in the product list; enforced "only one primary per product" in the SERVICE layer (see above)
  notes                 TEXT NULL,
  created_by            BIGINT UNSIGNED NULL,
  updated_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES catalog_products(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Open-ended list of miscellaneous charges (e.g. "Bank charges", "LC
-- charges", "Exchange rate fluctuation buffer") per explicit requirement
-- that this NOT be a fixed set of columns. Informational reference data
-- only, shown only to roles holding view_product_pricing — NEVER summed
-- into any FOB calculation (see ProductService::effectiveFobForSupplier,
-- which never reads this table at all).
CREATE TABLE catalog_product_misc_charges (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    BIGINT UNSIGNED NOT NULL,
  label         VARCHAR(150) NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  notes         VARCHAR(500) NULL,
  created_by    BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES catalog_products(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION V — TEST MODE (added 2026-09-22)
-- ================================================================
-- A global, system-wide switch (single row here, id=1) distinct from the
-- Sample Data Playground (is_sample_data, Section T note): Sample Data is a
-- one-shot canned demo dataset; Test Mode is a live toggle staff flip on to
-- manually walk arbitrary orders through the REAL UI/pipeline — with
-- production access suspended (client portal + quotation-details form) and
-- every business email redirected to test_email — then bulk-delete
-- afterward. The two flags coexist independently on the same tables; a row
-- can never be both, since Sample Data is always loaded with Test Mode off
-- and nothing here forces otherwise, but that's an operational convention,
-- not a constraint.
--
-- is_enabled cannot be flipped 1->0 while any is_test_data=1 row exists
-- anywhere (TestModeService::disable() enforces this in application code,
-- not a DB trigger, matching this codebase's convention of keeping
-- business rules in the service layer). test_email is editable at any
-- time, on or off, so it's ready before the next time Test Mode is
-- enabled.
CREATE TABLE test_mode_settings (
  id           TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,  -- singleton row, same convention would apply if this table ever needed more than one — it never does
  is_enabled   TINYINT(1) NOT NULL DEFAULT 0,
  test_email   VARCHAR(190) NULL,
  enabled_at   TIMESTAMP NULL,
  enabled_by   BIGINT UNSIGNED NULL,
  disabled_at  TIMESTAMP NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (enabled_by) REFERENCES users(id),
  CONSTRAINT chk_test_mode_singleton CHECK (id = 1)
) ENGINE=InnoDB;
INSERT INTO test_mode_settings (id, is_enabled) VALUES (1, 0);

-- Root-aggregate test flag. Everything hanging off an order/client
-- (documents, amendments, disputes, email_log, file_store, client_logins,
-- etc.) derives its test status by joining up to orders.is_test_data /
-- clients.is_test_data — deliberately NOT duplicated onto every child
-- table, so it can never drift out of sync with its parent. suppliers gets
-- its own flag because, like the existing is_sample_data precedent (see
-- Section T), a test Supplier PO can create a brand-new supplier row that
-- isn't hung off any single order. catalog_products/catalog_product_*
-- (Section U) deliberately do NOT get this flag — that catalog is
-- reference data staff use identically in and out of Test Mode.
ALTER TABLE clients ADD COLUMN is_test_data TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN is_test_data TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE suppliers ADD COLUMN is_test_data TINYINT(1) NOT NULL DEFAULT 0;
CREATE INDEX idx_clients_is_test_data ON clients (is_test_data);
CREATE INDEX idx_orders_is_test_data ON orders (is_test_data);

-- ================================================================
-- SECTION W — ORDER ARCHIVING (added 2026-09-23)
-- ================================================================
-- Visibility only — never a deletion mechanism. Per explicit user
-- direction: no order data file or folder may ever be deleted by this
-- app, at any point, archived or not — government audits can require
-- production of records up to 7 years back, so retention is unconditional
-- and any space-saving backup/offload is something staff do themselves,
-- outside the app. Archiving just moves an order out of the default
-- /orders listing; nothing referencing it (documents, file_store rows,
-- audit_log, ...) is touched, and it remains fully viewable at its normal
-- URL by anyone with the new view_archived_orders permission (or Super
-- Admin, via the existing blanket bypass) — see OrderRepository::all()
-- (excludes archived) vs. ::allArchived() (archived only).
ALTER TABLE orders ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN archived_at TIMESTAMP NULL;
ALTER TABLE orders ADD COLUMN archived_by BIGINT UNSIGNED NULL;
CREATE INDEX idx_orders_is_archived ON orders (is_archived);

-- ================================================================
-- SECTION X — ROLE & PERMISSION MANAGEMENT (added 2026-09-23)
-- ================================================================
-- Turns roles/permissions from seed-managed-only into a real admin
-- screen (create/edit/delete roles, create/edit/delete permission
-- definitions, and edit which permissions a role has — not just the
-- existing per-user grant-only override in user_permissions).
--
-- is_system_permission mirrors roles.is_system_role: every permission_key
-- that shipped in seed.sql is also a string literal a route/controller
-- checks directly (PermissionCheck::requires('manage_orders') and its
-- ~25 siblings) — deleting one of those would silently lock everyone out
-- of whatever it gates, with no error, since nothing could ever hold that
-- key again. A permission created fresh through this new admin screen is
-- NOT marked system (is_system_permission = 0 by default) since nothing
-- in code depends on its key yet — it's inert until a developer wires a
-- requirePermission()/PermissionCheck::requires() call to it, same as any
-- new permission always has been. permission_key itself is immutable
-- once created, system or not, for the same string-literal reason — only
-- name/description/category can be edited. See
-- PermissionRepository::delete() (blocks system rows, and any row still
-- referenced by role_permissions/user_permissions) and RoleRepository::
-- delete() (blocks is_system_role, and any role still assigned to a user).
ALTER TABLE permissions ADD COLUMN is_system_permission TINYINT(1) NOT NULL DEFAULT 0;
UPDATE permissions SET is_system_permission = 1;

-- ================================================================
-- SECTION Y — CUSTOM REFERENCE LIBRARY ENTRIES (added 2026-09-23)
-- ================================================================
-- The original Reference Library (Section R) is 8 fixed entries tied 1:1
-- to a document_types row each — deliberately not a general-purpose CMS,
-- since those 8 are specific named documents (the checklists, the two SOP
-- tiers, Stage Gate Reference, Wall Reference) that the app itself refers
-- to by code (e.g. WALLREF's placeholder substitution). Real gap this
-- closes: staff have no way to add a NEW reference document (add/delete,
-- not just edit) or attach an actual source file (a PDF/DOCX) rather than
-- typed content — this table is a second, independent, freely add/
-- delete-able list of arbitrary reference material for exactly that,
-- alongside the fixed 8. It never touches document_types.
--
-- file_path is nullable — an entry can be text-only, file-only, or both.
-- Re-uploading a new file only ever changes file_path/file_original_name/
-- file_mime_type to point at the new one; the previous file already on
-- disk under storage/internal/reference_library/ is never deleted (same
-- "nothing gets deleted" rule the rest of the app follows for order data —
-- see Section W's order-archiving note). Deleting the whole row likewise
-- never deletes its file from disk, only the row that pointed to it.
CREATE TABLE reference_library_documents (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title               VARCHAR(200) NOT NULL,
  content             LONGTEXT NULL,
  file_path           VARCHAR(500) NULL,
  file_original_name  VARCHAR(255) NULL,
  file_mime_type      VARCHAR(100) NULL,
  created_by          BIGINT UNSIGNED NULL,
  updated_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION Z — PROTECTED FOUNDER ACCOUNTS (added 2026-09-23)
-- ================================================================
-- A stronger tier above the ordinary Super Admin: is_super_admin lets an
-- account act with unrestricted permission, but any Super Admin (including
-- one only temporarily delegated) can still edit or promote/demote any
-- OTHER account, including another Super Admin's identity fields, via the
-- Users and Super Admin screens — nothing before this stopped that.
-- is_protected_account marks the company's founder accounts, whose name,
-- login email, role, designation, signatory eligibility, active/Super
-- Admin status can never change through the application again once set,
-- by anyone — not even another Super Admin, and not even themselves,
-- since there's no self-service profile edit distinct from the admin
-- Users screen. Enforced twice, deliberately: the application layer gives
-- a clean flash-message refusal at each call site that could touch these
-- fields (UserController::update/toggleActive/forceResetPassword,
-- SuperAdminService::setPermanentFlag, SignatoryController::setEligibility)
-- and the trigger below is the real, unbypassable backstop — it fires
-- regardless of which code path or DB user issues the statement, exactly
-- like Section N's trg_users_super_admin_bd/bu.
--
-- Deliberately NOT covered (both by design): password_hash and its
-- sibling columns (force_password_change, password_changed_at,
-- failed_login_count, locked_until, two_fa_*) — the protected person can
-- always recover their own login via the existing self-service
-- /forgot-password email flow, which is what UserController's
-- forceResetPassword refusal message points them to; and
-- user_signature_assets (their uploaded signature/seal image files) —
-- refreshing a scanned signature is a legitimate, expected maintenance
-- action, not an identity change, and stays available to anyone holding
-- manage_signatories.
ALTER TABLE users ADD COLUMN is_protected_account TINYINT(1) NOT NULL DEFAULT 0 AFTER is_super_admin;

DELIMITER $$

CREATE TRIGGER trg_users_protected_bd BEFORE DELETE ON users
FOR EACH ROW
BEGIN
  IF OLD.is_protected_account = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete a protected founder account.';
  END IF;
END$$

CREATE TRIGGER trg_users_protected_bu BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
  IF OLD.is_protected_account = 1 AND (
       NOT (OLD.name <=> NEW.name) OR
       NOT (OLD.email <=> NEW.email) OR
       NOT (OLD.phone <=> NEW.phone) OR
       NOT (OLD.role_id <=> NEW.role_id) OR
       NOT (OLD.designation_id <=> NEW.designation_id) OR
       NOT (OLD.is_signatory_eligible <=> NEW.is_signatory_eligible) OR
       NOT (OLD.is_super_admin <=> NEW.is_super_admin) OR
       NOT (OLD.is_active <=> NEW.is_active) OR
       NOT (OLD.is_protected_account <=> NEW.is_protected_account)
     ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot change a protected founder account — its identity, role, designation, eligibility and Super Admin/active status can never be edited through the application.';
  END IF;
END$$

DELIMITER ;

-- ================================================================
-- SECTION AA — CLIENT SELF-CORRECTION + PI-STAGE INTAKE (added 2026-09-23)
-- ================================================================
-- Two gaps closed together, both from the same real business need: the
-- client-facing "Client_Forms.xlsx" spec provided directly by the
-- business defines a Quotation Form (the existing /quotation-details
-- intake) and a SEPARATE PI Form the client fills in after accepting the
-- Quotation and before staff issue the Proforma Invoice — confirming
-- details "exactly as they appear on official documents" and adding
-- fields the Quotation stage never asked for (consignee, notify party,
-- payment-terms confirmation, formal acceptance of the quotation number).
--
-- 1. Quotation-stage self-correction: a client who mistyped something on
--    /quotation-details can fix it themselves via a one-time emailed
--    link, but ONLY while their submission is still status='pending' —
--    once staff act on it (converted/rejected) the link stops working,
--    and once a client has portal login (post-advance-payment) further
--    changes go through Amendments, never a self-edit, per explicit
--    instruction. The raw token is never stored — only its SHA-256 hash,
--    exactly like ClientPasswordResetTokenRepository — so a DB leak alone
--    can't be used to edit someone's pending submission.
ALTER TABLE client_intake_submissions
  ADD COLUMN access_token_hash VARCHAR(64) NULL AFTER submitted_ip,
  ADD COLUMN access_token_expires_at DATETIME NULL AFTER access_token_hash;

-- 2. PI-stage intake: staff generate a per-order link (button on the
--    order screen) once the Quotation is out; the client fills this
--    SEPARATE form; it lands in its own staff review queue
--    (pi_intake_submissions), never auto-applied. Accepting copies the
--    client-identity fields (consignee/notify-party/VAT-EORI/phone/
--    confirmed contact+billing) onto the live `clients` row — the PI
--    form is explicitly the "must match exactly as they appear on
--    official documents" checkpoint, so this IS the authoritative
--    correction point for that data — plus buyers_po_ref onto the order.
--    Everything else the sheet asks for (payment-terms confirmation,
--    formal quotation-acceptance reference, confirmed Incoterm/port/COO,
--    changes from quotation, special document requirements) is kept
--    permanently on this row as the record staff actually read before
--    generating the PI, rather than auto-written onto the order's own
--    structured/FK-driven columns (incoterm_id, port_of_discharge_id) —
--    those stay staff-reconciled, the same way quotation-intake data was
--    never auto-written into a client/order row without a staff step.
CREATE TABLE pi_intake_submissions (
  id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                        BIGINT UNSIGNED NOT NULL,
  access_token_hash               VARCHAR(64) NOT NULL,
  access_token_expires_at         DATETIME NOT NULL,
  company_legal_name              VARCHAR(255) NULL,
  billing_address                 TEXT NULL,
  consignee_name                  VARCHAR(255) NULL,
  consignee_address               TEXT NULL,
  vat_eori_tax_no                 VARCHAR(100) NULL,
  contact_person                  VARCHAR(150) NULL,
  email                           VARCHAR(190) NULL,
  phone                           VARCHAR(30) NULL,
  notify_party                    VARCHAR(255) NULL,
  port_of_discharge_text          VARCHAR(150) NULL,
  country_of_destination          VARCHAR(100) NULL,
  incoterm_confirmed              VARCHAR(100) NULL,
  container_type_text             VARCHAR(100) NULL,
  payment_terms_confirmation      TEXT NULL,
  quotation_acceptance_reference  TEXT NULL,
  coo_type                        VARCHAR(50) NULL,
  buyer_po_ref                    VARCHAR(100) NULL,
  changes_from_quotation          TEXT NULL,
  special_document_requirements   TEXT NULL,
  status                          ENUM('awaiting_client','pending_review','applied','rejected') NOT NULL DEFAULT 'awaiting_client',
  submitted_at                    TIMESTAMP NULL,
  submitted_ip                    VARCHAR(45) NULL,
  reviewed_by                     BIGINT UNSIGNED NULL,
  reviewed_at                     TIMESTAMP NULL,
  rejection_reason                VARCHAR(500) NULL,
  created_by                      BIGINT UNSIGNED NULL,
  created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE INDEX idx_pi_intake_order ON pi_intake_submissions(order_id);

-- ================================================================
-- SECTION AB — HS CODE MASTER LIST (added 2026-09-24)
-- Order creation previously let staff type any HS code freehand (even the
-- built-in default, '6802.93', was wrong — real Indian HS codes are 6 or
-- 8 plain digits, no dot). This closes that gap: HS codes now live in one
-- permission-gated master table, and the order-creation form can only
-- pick a code that already exists here — a brand new code has to go
-- through this table first, under a privileged person's eye, so a typo
-- can never silently end up on an order.
-- ================================================================
CREATE TABLE hs_codes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(8) NOT NULL UNIQUE,   -- 6 or 8 digits, enforced in the app layer
  description  VARCHAR(255) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_by   BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION AC — CLIENT DATA LOCK (added 2026-09-24)
-- Once a client's own details have been consented to (via the PI-details
-- form) or real money is in motion (staff records the advance
-- remittance, whichever happens first), those details are locked for
-- life — enforced at the application layer (ClientController::update()),
-- the same way every other business rule in this app is enforced, not by
-- a rigid DB trigger. Matches the existing peer-approved protected-field
-- pattern (Section L) rather than the founder-account hard-lock pattern
-- (Section Z) — this needs a narrow, logged Super-Admin override for a
-- genuine staff data-entry error, which an unconditional DB trigger can't
-- distinguish from an ordinary edit attempt.
-- ================================================================
ALTER TABLE clients
  ADD COLUMN is_data_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
  ADD COLUMN data_locked_at TIMESTAMP NULL AFTER is_data_locked,
  ADD COLUMN data_locked_reason VARCHAR(255) NULL AFTER data_locked_at;

-- ================================================================
-- SECTION AD — CLIENT PAYMENT SELF-REPORT (added 2026-09-24)
-- Lets a client tell staff "I've paid" straight from their portal — a
-- transaction reference plus optional screenshot of the remittance advice,
-- for any leg (advance/balance/freight) of an order. Purely informational:
-- staff still verify the real bank statement by hand before calling
-- OrderPaymentStatusRepository::recordAdvanceReceived() (or balance/
-- freight) as before — a self-report never writes to order_payment_status
-- and never gates a stage on its own.
-- ================================================================
CREATE TABLE client_payment_reports (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id            BIGINT UNSIGNED NOT NULL,
  payment_type        ENUM('advance','balance','freight') NOT NULL,
  transaction_ref     VARCHAR(150) NOT NULL,
  payer_bank_details  VARCHAR(255) NULL,
  amount              DECIMAL(14,2) NULL,
  payment_date        DATE NULL,
  screenshot_file_id  BIGINT UNSIGNED NULL,
  status              ENUM('new','reviewed') NOT NULL DEFAULT 'new',
  reviewed_by         BIGINT UNSIGNED NULL,
  reviewed_at         TIMESTAMP NULL,
  reported_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (screenshot_file_id) REFERENCES file_store(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION AE — BUYER OC ACKNOWLEDGMENT (added 2026-09-24)
-- Replaces the old staff-only "Confirm Buyer Acknowledged Order" button at
-- the Stage 4->5 gate with a real acknowledgment: the buyer sees a
-- read-only recap of the sent Order Confirmation in their portal with a
-- single "I acknowledge and confirm to proceed" button (no decline/dispute
-- option shown, so as not to plant doubt at this stage); replying to the
-- email is equally valid evidence, which staff record with a mandatory
-- note; and if the buyer does neither within 48 hours of the OC being
-- emailed, it auto-confirms (app/cron/auto_confirm_oc_acknowledgments.php).
-- One row per order (UNIQUE order_id) — a resend of the OC (e.g. after a
-- stage-regeneration cascade) upserts sent_at/due_at/document_id and clears
-- any earlier acknowledgment, since the buyer is being asked to confirm
-- the newly-sent version, not the one they may have already acknowledged.
-- ================================================================
CREATE TABLE order_oc_acknowledgments (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id           BIGINT UNSIGNED NOT NULL UNIQUE,
  document_id        BIGINT UNSIGNED NOT NULL,
  sent_at            TIMESTAMP NOT NULL,
  due_at             TIMESTAMP NOT NULL,
  acknowledged_at    TIMESTAMP NULL,
  acknowledged_via   ENUM('client_portal','staff_recorded_email','auto_48h') NULL,
  acknowledged_note  VARCHAR(500) NULL,
  recorded_by        BIGINT UNSIGNED NULL,   -- NULL for client_portal/auto_48h; the staff user for staff_recorded_email
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (document_id) REFERENCES documents(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ================================================================
-- SECTION AF — PER-ORDER DISPUTE VISIBILITY TOGGLE (added 2026-09-24)
-- Whether the client portal shows a "Raise a Dispute" button for THIS
-- order — default off, so an already-happy order never gets one and the
-- button never invites a dispute where nothing prompted it. Staff flip it
-- on (manage_orders, the same permission that already gates every other
-- order-level action) once there's a real reason to give the buyer a
-- direct channel. Deliberately a plain per-order flag, not a role/
-- permission gate on the client side — the client portal has no
-- permission system of its own, and this decision is always made per
-- order, by staff, not per client.
-- ================================================================
ALTER TABLE orders
  ADD COLUMN dispute_button_visible_to_client TINYINT(1) NOT NULL DEFAULT 0 AFTER is_archived;

-- ================================================================
-- SECTION AG — REFERENCE NUMBER SEQUENCE COUNTER (added 2026-09-24)
-- ReferenceNumberService previously derived {NNN} from
-- `SELECT COUNT(*) ... WHERE DATE(created_at) = CURDATE()` against the
-- live clients/documents/amendments tables. That undercounts as soon as
-- any same-day row is deleted — e.g. loading the Sample Data Playground,
-- clearing it, then loading it again — and can then re-mint a number
-- that's still held by a surviving row (a real client created earlier
-- that same day), raising a duplicate-key error on client_unique_number
-- or amendment_reference. A persistent, monotonic counter row per
-- (scope, day) fixes this: it only ever goes up, so a delete elsewhere
-- can never cause a number to be reissued.
-- ================================================================
CREATE TABLE reference_sequences (
  scope_key   VARCHAR(150) NOT NULL,
  last_seq    INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time backfill for a database that already has same-day rows at the
-- moment this migration is applied (a fresh install has none, so this is a
-- no-op there) — seeds each scope's counter from the highest {NNN} already
-- in use that day, so the very first number minted after upgrading can
-- never collide with one already on a real row. {NNN} is always the last 3
-- characters of the rendered reference (see ReferenceNumberService::render).
INSERT INTO reference_sequences (scope_key, last_seq)
SELECT CONCAT('client_unique:', DATE_FORMAT(created_at, '%Y%m%d')), MAX(CAST(RIGHT(client_unique_number, 3) AS UNSIGNED))
FROM clients
GROUP BY DATE_FORMAT(created_at, '%Y%m%d')
ON DUPLICATE KEY UPDATE last_seq = GREATEST(last_seq, VALUES(last_seq));

INSERT INTO reference_sequences (scope_key, last_seq)
SELECT CONCAT('document:', document_type_id, ':', DATE_FORMAT(generated_at, '%Y%m%d')), MAX(CAST(RIGHT(document_reference, 3) AS UNSIGNED))
FROM documents
WHERE document_reference IS NOT NULL
GROUP BY document_type_id, DATE_FORMAT(generated_at, '%Y%m%d')
ON DUPLICATE KEY UPDATE last_seq = GREATEST(last_seq, VALUES(last_seq));

INSERT INTO reference_sequences (scope_key, last_seq)
SELECT CONCAT('amendment:', DATE_FORMAT(created_at, '%Y%m%d')), MAX(CAST(RIGHT(amendment_reference, 3) AS UNSIGNED))
FROM amendments
GROUP BY DATE_FORMAT(created_at, '%Y%m%d')
ON DUPLICATE KEY UPDATE last_seq = GREATEST(last_seq, VALUES(last_seq));

-- ================================================================
-- SECTION AH — CLIENT-FACING DOCUMENT REVISION NUMBER (added 2026-09-24)
-- documents.revision_number increments on every regeneration for any
-- reason, including an internal staff correction the client never sees —
-- exactly the number that should stay purely internal. client_revision_number
-- is a second, independent count: how many times a document of this
-- (order, document_type) had already been actually SENT to the client at
-- the moment this row was generated. It only grows when a prior document
-- of the same order+type reached status 'sent' or 'superseded' — an
-- internal-only correction never bumps it, however many times staff
-- regenerate before the first real send. This is the number printed as
-- "Rev.NN" inside the buyer-facing PDF body and used in the PDF's
-- download filename (DocumentGenerationService::generate()); the internal
-- documents.revision_number is unaffected and keeps showing on staff-only
-- surfaces (order detail page, review notifications, reports) for full
-- internal traceability.
-- ================================================================
ALTER TABLE documents
  ADD COLUMN client_revision_number INT NULL AFTER revision_number;

-- ================================================================
-- SECTION AI — ORDER PROGRESS CHAT, EMAIL TEMPLATE CRUD, PER-USER
-- SIGNATURE, ZOHO MAIL INTEGRATION (added 2026-09-24)
--
-- order_comments / order_comment_attachments: a two-way chat thread per
-- order. A staff post triggers an immediate email to the client carrying
-- the comment text and, where practical, the attachments (an oversized
-- attachment gets a portal download link instead — see
-- OrderCommentService::MAX_DIRECT_ATTACH_BYTES). A client post never
-- emails the client back (they're already in their own portal) — it
-- raises an in-app notification to staff instead, the same pattern
-- already used for client-side dispute/payment-report events.
--
-- email_templates gains is_active/created_by/created_at so a template can
-- be added or edited (governed by manage_email_templates) but never
-- deleted — email_log already freezes subject/body at send time
-- (body_snapshot), so a template's current state is irrelevant to what
-- was actually sent two years ago; is_active only controls whether it's
-- still offered for a NEW send.
--
-- users.email_signature: one plain-text signature per user, appended to
-- every email that user sends through the system (matches the existing
-- plain-text email convention — no HTML emails introduced).
--
-- company_settings gains the Zoho Mail integration's configuration.
-- zoho_mail_enabled is a plain on/off switch (default off, since no
-- credentials exist yet); when on, MailSenderService tries the Zoho Mail
-- API first and, on ANY failure at all (bad/missing credentials, network
-- error, malformed response), falls straight through to the existing
-- SMTP path with no error ever surfaced to the caller — Zoho is strictly
-- an optional enhancement layer, never a dependency the app can be
-- blocked by.
-- ================================================================
CREATE TABLE order_comments (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id                BIGINT UNSIGNED NOT NULL,
  author_type             ENUM('staff','client') NOT NULL,
  author_user_id          BIGINT UNSIGNED NULL,   -- set when author_type = 'staff'
  author_client_id        BIGINT UNSIGNED NULL,   -- set when author_type = 'client'
  body                    TEXT NULL,              -- comment text; NULL allowed for an attachment-only post
  email_sent              TINYINT(1) NOT NULL DEFAULT 0,  -- whether this staff comment's client email actually went out
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (author_user_id) REFERENCES users(id),
  FOREIGN KEY (author_client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

CREATE TABLE order_comment_attachments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id    BIGINT UNSIGNED NOT NULL,
  file_store_id BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (comment_id) REFERENCES order_comments(id),
  FOREIGN KEY (file_store_id) REFERENCES file_store(id)
) ENGINE=InnoDB;

INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description) VALUES
  ('order_comment_media', 'jpg,jpeg,png,gif,webp,mp4,mov,webm,pdf', 52428800, 'Images/videos/files attached to an order progress chat comment (50 MB per file). Attachments over MailSenderService''s direct-attach cap are sent to the client as a portal download link instead of an email attachment.');

ALTER TABLE email_templates
  ADD COLUMN is_active  TINYINT(1) NOT NULL DEFAULT 1 AFTER footer,
  ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER is_active,
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by;

ALTER TABLE users
  ADD COLUMN email_signature TEXT NULL AFTER phone;

INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
  ('zoho_mail_enabled',   '0', 'boolean', 'zoho', 'Master on/off switch for sending through the Zoho Mail API. Off by default (no credentials configured yet) — when off, or on any Zoho send failure, mail goes out through the existing SMTP path instead. Never a hard dependency.', 0),
  ('zoho_client_id',      '', 'string',  'zoho', 'Zoho API Console self-client OAuth Client ID.', 1),
  ('zoho_client_secret',  '', 'string',  'zoho', 'Zoho API Console self-client OAuth Client Secret.', 1),
  ('zoho_refresh_token',  '', 'string',  'zoho', 'Zoho OAuth refresh token (mail.send scope) — exchanged for a short-lived access token on each send.', 1),
  ('zoho_account_id',     '', 'string',  'zoho', 'Zoho Mail account ID (from Zoho Mail API''s accounts endpoint) that mail is sent from.', 1),
  ('zoho_from_address',   '', 'string',  'zoho', 'The Zoho mailbox address mail is sent from — must be one of the Zoho account''s own verified addresses.', 0),
  ('zoho_accounts_domain', 'accounts.zoho.com', 'string', 'zoho', 'Zoho OAuth token endpoint domain — Zoho is region-specific (accounts.zoho.com / .eu / .in / .com.cn / .com.au, matching whichever data center the Zoho One account lives in).', 0),
  ('zoho_api_domain',      'mail.zoho.com', 'string', 'zoho', 'Zoho Mail API domain — matches zoho_accounts_domain''s region (mail.zoho.com / .eu / .in / .com.cn / .com.au).', 0);

INSERT INTO permissions (permission_key, name, description, category) VALUES
  ('manage_email_templates', 'Manage email templates', 'Add or edit email templates used when composing a send (never delete — every past send keeps its own frozen copy in the email log regardless).', 'admin');

-- ================================================================
-- END OF SCHEMA — 71 tables. All open schema questions resolved
-- 2026-09-18 (see ARCHITECTURE.md). Ready for Phase A build.
-- Section L (protected fields) added 2026-09-19.
-- Section M (signatories & designations) added 2026-09-20.
-- Section N (Super Admin tier) added 2026-09-20.
-- Section O (client portal) added 2026-09-20.
-- Section P (document data-integrity snapshot) added 2026-09-21.
-- Section Q (working-days calculator & holiday calendar) added 2026-09-21.
-- Section R (internal reference library) added 2026-09-21.
-- Section S (buyer PO / supplier PO acknowledgment evidence) added 2026-09-21.
-- Section T (supplier sample-data flag) added 2026-09-21.
-- Section U (product interface / internal product catalog) added 2026-09-21.
-- Section V (test mode) added 2026-09-22.
-- Section W (order archiving) added 2026-09-23.
-- Section X (role & permission management) added 2026-09-23.
-- Section Y (custom reference library entries) added 2026-09-23.
-- Section Z (protected founder accounts) added 2026-09-23.
-- Section AA (client self-correction + PI-stage intake) added 2026-09-23.
-- Section AB (HS code master list) added 2026-09-24.
-- Section AC (client data lock) added 2026-09-24.
-- Section AD (client payment self-report) added 2026-09-24.
-- Section AE (buyer OC acknowledgment) added 2026-09-24.
-- Section AF (per-order dispute visibility toggle) added 2026-09-24.
-- Section AG (reference number sequence counter) added 2026-09-24.
-- Section AH (client-facing document revision number) added 2026-09-24.
-- Section AI (order progress chat, email template CRUD, per-user
-- signature, Zoho Mail integration) added 2026-09-24.
-- ================================================================
