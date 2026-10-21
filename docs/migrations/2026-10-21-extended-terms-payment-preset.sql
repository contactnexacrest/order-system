-- ================================================================
-- DB MIGRATION — 2026-10-21
-- Corresponds to: seed.sql payment_presets addition (90-item backlog
-- "30/70 payment preset")
-- Covers item: 30/70 payment preset
--
-- Run this ONCE against each production database this app uses
-- (the PHP stack's DB and/or the Node stack's DB — whichever are
-- actually in production use; they use the same schema).
--
-- Pure DATA change — no ALTER TABLE, no new table, no new permission.
-- Adds a third payment preset, "Extended Terms — 30/70" (30% advance /
-- 70% balance, against scanned BL copy, MD approval required — the
-- same risk profile as the existing "Established Buyer — Post-BL"
-- preset, just with an even lighter upfront commitment), selectable
-- immediately on the New Order form and fully editable afterward via
-- the existing Payment Presets screen (/payment-presets) — no code
-- change needed anywhere else, since every order's advance/balance
-- figures and document wording are already computed purely from
-- whatever preset is assigned (see docs/SOP/15-admin-settings.md's own
-- "Creating a new preset ... needs no further code change anywhere").
--
-- Deliberately left UNPROTECTED (is_protected stays at its default 0)
-- — unlike the two foundational presets (which are protected because
-- they shipped with the app and drive existing historical orders),
-- this one is a fresh addition with nothing historical to guard, so an
-- admin can freely adjust it later without the unlock gesture.
--
-- Idempotent (safe to re-run).
-- ================================================================

INSERT INTO payment_presets
  (preset_name, is_default, advance_pct, advance_trigger_text, balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, currency_id, requires_md_approval, is_active)
SELECT 'Extended Terms — 30/70', 0, 30.00, 'against Proforma Invoice before production commences',
       70.00, 'B_AGAINST_BL', 7,
       'Payable against scanned copy of Bill of Lading, within {days} Calendar Days of the date NexaCrest emails the scanned BL copy.',
       c.id, 1, 1
FROM currencies c
WHERE c.code = 'USD'
  AND NOT EXISTS (SELECT 1 FROM payment_presets WHERE preset_name = 'Extended Terms — 30/70');
