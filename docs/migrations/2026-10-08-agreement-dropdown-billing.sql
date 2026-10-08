-- ================================================================
-- DB MIGRATION — 2026-10-08
-- Corresponds to: schema.sql Sections BD, BE, BF (commit 59453f2)
-- Covers items: 2 (Agreement file/expiry/renew), 7 (Dropdown Option
-- Lists admin screen), 8 (structured QT billing address)
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Already applied to both local dev databases (nexacrest,
-- nexacrest_node_test) during this session. NOT yet applied anywhere
-- else. Safe to run top-to-bottom in one transaction-less pass; each
-- statement is a one-time structural change, not re-runnable (running
-- it twice will error on "column already exists" / duplicate key —
-- that's the signal it's already been applied).
-- ================================================================


-- ----------------------------------------------------------------
-- Section BD — Dropdown Options admin screen permission
-- ----------------------------------------------------------------
INSERT INTO permissions (permission_key, name, description, category) VALUES
  ('manage_dropdown_options', 'Manage dropdown option lists', 'Add, edit, and deactivate the admin-editable option lists used across the app (Container Type, Certificate of Origin Type, etc. — see dropdown_options.list_key). Same tier as manage_hs_codes/manage_logistics_partners: Admin/MD/ED and Super Admin only.', 'catalog');

-- Grants the new permission to the same roles that already hold every
-- other permission at this tier (Admin/Managing Director/Executive
-- Director). Super Admin bypasses permission checks entirely and needs
-- no row here. On a FRESH install, seed.sql's own blanket grant does
-- this automatically — this INSERT is only needed because an existing
-- database already ran that blanket grant once, against the
-- permissions table as it stood before this permission existed.
INSERT INTO role_permissions (role_id, permission_id, is_enabled)
SELECT r.id, p.id, 1 FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('Admin', 'Managing Director', 'Executive Director')
  AND p.permission_key = 'manage_dropdown_options';


-- ----------------------------------------------------------------
-- Section BE — Client Agreement: file upload + expiry
-- ----------------------------------------------------------------
ALTER TABLE clients
  ADD COLUMN agreement_file_path VARCHAR(500) NULL AFTER agreement_footer_text,
  ADD COLUMN agreement_file_original_name VARCHAR(255) NULL AFTER agreement_file_path,
  ADD COLUMN agreement_uploaded_at TIMESTAMP NULL AFTER agreement_file_original_name,
  ADD COLUMN agreement_expiry_date DATE NULL AFTER agreement_uploaded_at,
  ADD COLUMN agreement_force_expired TINYINT(1) NOT NULL DEFAULT 0 AFTER agreement_expiry_date;

-- New upload context for the agreement file itself (type/size validation).
INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description) VALUES
  ('client_agreement_file', 'pdf,doc,docx', 15728640, 'Item 2 — the actual signed copy of a client''s commercial agreement, tied to that client''s expiry/renew tracking (clients.agreement_file_path etc — see schema.sql Section BE).');


-- ----------------------------------------------------------------
-- Section BF — QT intake: structured billing address
-- ----------------------------------------------------------------
ALTER TABLE client_intake_submissions
  ADD COLUMN billing_address_line1 VARCHAR(255) NULL AFTER billing_address,
  ADD COLUMN billing_address_line2 VARCHAR(255) NULL AFTER billing_address_line1,
  ADD COLUMN billing_city VARCHAR(100) NULL AFTER billing_address_line2,
  ADD COLUMN billing_postcode VARCHAR(30) NULL AFTER billing_city,
  ADD COLUMN billing_country VARCHAR(100) NULL AFTER billing_postcode;

-- Backfills a gap in the original clients table rollout (Section BA
-- added billing_address_line1/2/city/postcode but not country).
ALTER TABLE clients
  ADD COLUMN billing_country VARCHAR(100) NULL AFTER billing_postcode;


-- ----------------------------------------------------------------
-- Seed data — 3 additional Container Type options (admin can add more
-- later from Admin > Dropdown Option Lists; these were a starting
-- suggestion, not yet explicitly confirmed by the business).
-- ----------------------------------------------------------------
INSERT INTO dropdown_options (list_key, option_value, sort_order, is_default, is_active) VALUES
  ('container_type', '20ft Flat Rack', 4, 0, 1),
  ('container_type', '40ft Flat Rack', 5, 0, 1),
  ('container_type', 'LCL (Less than Container Load)', 6, 0, 1);
