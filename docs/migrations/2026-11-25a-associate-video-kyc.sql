-- 2026-11-25a — Owner decision D-417 (25 Nov 2026): live video KYC for associates.
--  - A new KYC item "Live video KYC (ID proof and address proof clearly visible)": staff record the date of the
--    call, who saw it and what was seen (associates.video_kyc_at / video_kyc_by / video_kyc_note).
--  - An associate who already exists when this runs gets 60 days (associates.video_kyc_due_on) before payouts
--    wait for it — the same grace as the address proof (D-290). New associates need it before their first payout.
-- PLAIN ALTER (owner D-371, 13 Nov 2026): the ALTER comes first, then the UPDATE; a second run stops at the ALTER
-- with "#1060 Duplicate column name", which is harmless (the UPDATE has already run).
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
ALTER TABLE associates ADD COLUMN video_kyc_at DATETIME NULL, ADD COLUMN video_kyc_by BIGINT UNSIGNED NULL,
  ADD COLUMN video_kyc_note VARCHAR(300) NULL, ADD COLUMN video_kyc_due_on DATE NULL;
UPDATE associates SET video_kyc_due_on = DATE_ADD(CURDATE(), INTERVAL 60 DAY) WHERE video_kyc_due_on IS NULL AND video_kyc_at IS NULL;
