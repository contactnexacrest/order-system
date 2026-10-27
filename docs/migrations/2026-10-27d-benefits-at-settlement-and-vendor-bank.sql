-- Owner decisions (2026-10-27). Safe to run more than once.
SET NAMES utf8mb4;

-- Government benefits are recorded at Step 13g, just before Mark Fully
-- Settled; an eligible scheme the company won't claim is skipped with a
-- reason, so the order can still be settled.
ALTER TABLE order_export_benefit_schemes
  ADD COLUMN IF NOT EXISTS claim_skipped_at  DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS claim_skipped_by  BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS claim_skip_reason VARCHAR(500)    NULL;

-- Suppliers and logistics partners (both directories): bank details to pay them, filled by
-- staff or by the supplier through a no-login Supplier Details Link. The
-- account number is stored encrypted; a supplier's own submission clears
-- the "verified" mark until staff check it again.
ALTER TABLE suppliers
  ADD COLUMN IF NOT EXISTS bank_account_name        VARCHAR(255)    NULL,
  ADD COLUMN IF NOT EXISTS bank_name                VARCHAR(150)    NULL,
  ADD COLUMN IF NOT EXISTS bank_branch              VARCHAR(150)    NULL,
  ADD COLUMN IF NOT EXISTS bank_account_number      TEXT            NULL,
  ADD COLUMN IF NOT EXISTS bank_ifsc                VARCHAR(20)     NULL,
  ADD COLUMN IF NOT EXISTS bank_account_type        VARCHAR(30)     NULL,
  ADD COLUMN IF NOT EXISTS bank_upi_id              VARCHAR(100)    NULL,
  ADD COLUMN IF NOT EXISTS bank_updated_at          DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS bank_updated_source      VARCHAR(20)     NULL,
  ADD COLUMN IF NOT EXISTS bank_verified_at         DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS bank_verified_by         BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS details_token_hash       CHAR(64)        NULL,
  ADD COLUMN IF NOT EXISTS details_token_plain      TEXT            NULL,
  ADD COLUMN IF NOT EXISTS details_token_expires_at DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS details_submitted_at     DATETIME        NULL,
  ADD INDEX IF NOT EXISTS idx_suppliers_details_token (details_token_hash);

ALTER TABLE logistics_partners
  ADD COLUMN IF NOT EXISTS bank_account_name        VARCHAR(255)    NULL,
  ADD COLUMN IF NOT EXISTS bank_name                VARCHAR(150)    NULL,
  ADD COLUMN IF NOT EXISTS bank_branch              VARCHAR(150)    NULL,
  ADD COLUMN IF NOT EXISTS bank_account_number      TEXT            NULL,
  ADD COLUMN IF NOT EXISTS bank_ifsc                VARCHAR(20)     NULL,
  ADD COLUMN IF NOT EXISTS bank_account_type        VARCHAR(30)     NULL,
  ADD COLUMN IF NOT EXISTS bank_upi_id              VARCHAR(100)    NULL,
  ADD COLUMN IF NOT EXISTS bank_updated_at          DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS bank_updated_source      VARCHAR(20)     NULL,
  ADD COLUMN IF NOT EXISTS bank_verified_at         DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS bank_verified_by         BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS details_token_hash       CHAR(64)        NULL,
  ADD COLUMN IF NOT EXISTS details_token_plain      TEXT            NULL,
  ADD COLUMN IF NOT EXISTS details_token_expires_at DATETIME        NULL,
  ADD COLUMN IF NOT EXISTS details_submitted_at     DATETIME        NULL,
  ADD INDEX IF NOT EXISTS idx_logistics_partners_details_token (details_token_hash);
