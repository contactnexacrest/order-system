-- ================================================================
-- DB MIGRATION — 2026-10-24f
-- 1. Renames the existing 'Extended Terms — 30/70' preset (30/70,
--    Cash Advance, After scanned BL copy) to 'Extended Terms — Post-BL'
--    — matching the existing 'Established Buyer — Post-BL' naming
--    convention, now that it has a Before-Shipment sibling (below) and
--    a bare "30/70" name alone no longer distinguishes the two.
-- 2. Adds a new preset 'Extended Terms — Before Shipment' — the same
--    30/70 split, Cash Advance, but mirroring 'Standard — New Buyer''s
--    Before Shipment trigger (3 days) instead of After-BL (7 days).
--    Gives the 30/70 bucket the same Before-Shipment/After-BL pair the
--    existing 40/60 bucket already has (Standard — New Buyer /
--    Established Buyer — Post-BL).
-- 3. Changes the Letter of Credit preset's split from 30/70 to 0/100 —
--    a sight LC has no separate cash advance; the full amount is paid
--    only once shipping documents are presented to the bank. The
--    existing "Record LC Details / Confirm LC Usable" leg is unaffected
--    (LC Amount there is a free-typed field, never derived from
--    advance_pct) — this only changes what prints as the advance/
--    balance split on generated documents and the admin screen.
-- 4. Protects all five presets (is_protected = 1) — previously only the
--    two foundational ones (Standard — New Buyer, Established Buyer —
--    Post-BL) shipped protected; the later-added Extended Terms/LC
--    presets were deliberately left unprotected when first added, but
--    are now considered equally load-bearing.
--
-- docs/seed.sql (both stacks) is updated separately to seed a fresh
-- database directly in this end state; this migration is only for a
-- database that already seeded the earlier 4-preset state. Requires
-- the companion code fix to PaymentPresetController.php /
-- paymentPresetController.js (relaxes the admin form's "Advance % must
-- be between 0 and 100" check to allow exactly 0) — without it, step 3
-- above is safe to store, but re-saving that preset from the admin
-- screen afterward would be refused.
--
-- Run this ONCE against each production database this app uses (the
-- PHP stack's DB and/or the Node stack's DB — whichever are actually in
-- production use; they use the same schema). Must run after
-- 2026-10-24e-rename-lc-preset.sql (renames the LC preset this
-- migration's step 3 then updates by its new name).
--
-- Idempotent (safe to re-run): the rename UPDATE matches by LIKE on the
-- ASCII parts of the name rather than an exact em-dash match — a prior
-- database that received the original 2026-10-21 migration through a
-- connection/tool not set to utf8mb4 can end up with that row's em-dash
-- double-encoded (mojibake: "Extended Terms â€” 30/70" instead of
-- "Extended Terms — 30/70"), which an exact-string match would silently
-- miss (0 rows updated, no error) and this migration's later INSERT
-- would then wrongly add a second, differently-named row alongside the
-- never-renamed original. LIKE on 'Extended Terms%30/70%' matches either
-- byte form, since only the dash itself is affected. A second run
-- matches nothing (the name has already changed) and does nothing; the
-- INSERT is guarded by NOT EXISTS; the LC split UPDATE and the blanket
-- is_protected UPDATE are both no-ops when re-applied to already-updated
-- rows.
-- ================================================================

SET NAMES utf8mb4;

-- 1. Rename the existing 30/70 preset — LIKE, not an exact match (see
-- above): guards against a mis-encoded em-dash from an earlier import.
UPDATE payment_presets
SET preset_name = 'Extended Terms — Post-BL'
WHERE preset_name LIKE 'Extended Terms%30/70%';

-- 2. Add the new Before-Shipment 30/70 preset.
INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, currency_id, requires_md_approval, show_bl_instructions, is_protected, is_active)
SELECT 'Extended Terms — Before Shipment', 0, 30.00, 'against Proforma Invoice before production commences', 'CASH_ADVANCE',
       70.00, 'A_BEFORE_SHIPMENT', 3,
       'Payable before shipment — within {days} Calendar Days of receiving Shipment Readiness Confirmation from NexaCrest.',
       c.id, 1, 1, 1, 1
FROM currencies c
WHERE c.code = 'USD'
  AND NOT EXISTS (SELECT 1 FROM payment_presets p WHERE p.preset_name = 'Extended Terms — Before Shipment');

-- 3. LC preset: 30/70 -> 0/100 (no separate advance under a sight LC).
UPDATE payment_presets
SET advance_pct = 0.00, balance_pct = 100.00
WHERE preset_name = 'Irrevocable Letter of Credit at Sight';

-- 4. Protect all presets.
UPDATE payment_presets SET is_protected = 1;
