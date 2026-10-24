-- ================================================================
-- DB MIGRATION — 2026-10-24e
-- Pure display-name rename of the Letter of Credit payment preset:
-- 'Letter of Credit — 30/70' -> 'Irrevocable Letter of Credit at Sight'.
-- payment_method = 'LC' (the actual conditional-rendering gate the order
-- detail page's Step 5 fork and the LC & Bank Loan reference card key
-- off) is completely untouched — this only changes how the preset's own
-- name displays on the Payment Presets admin screen and on any order
-- created with it going forward.
--
-- docs/seed.sql's own INSERT for this preset is updated separately to
-- the new name (so a fresh database is seeded correctly); this
-- migration is only for a database that already seeded the old name —
-- the historical migration that originally inserted this preset
-- (2026-10-24-lc-payment-leg.sql) is left untouched, since it's already-
-- applied history.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema).
--
-- Idempotent (safe to re-run): a plain UPDATE to the same already-
-- applied value is a no-op on every subsequent run.
-- ================================================================

SET NAMES utf8mb4;

UPDATE payment_presets
SET preset_name = 'Irrevocable Letter of Credit at Sight'
WHERE preset_name = 'Letter of Credit — 30/70';
