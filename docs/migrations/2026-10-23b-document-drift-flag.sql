-- External QA report M-08: "Regenerating an earlier-stage document after
-- later stages passed: mandatory confirmation, automatic cancellation of
-- later stages, and 'drift' notices on affected stages. App only warns."
-- This migration adds the persistent drift-notice half (a flag on each
-- already-existing later-stage document that stays visible until that
-- document is itself superseded by a fresh regeneration) and the
-- mandatory-confirmation half (client-side, no schema needed). Apply to
-- both the PHP and Node MySQL databases — same schema.
--
-- "Automatic cancellation of later stages" is deliberately NOT covered by
-- this migration or the accompanying code change — see the commit message
-- for why that specific part needs an owner decision rather than a
-- unilateral guess at what "cancel" should concretely do to an
-- already-gate-passed stage (and, for an already-SENT document, to a
-- buyer who may already have it).

SET NAMES utf8mb4;
-- External QA report L-32: IF NOT EXISTS makes this safe to re-run.
ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS drift_flagged_at TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS drift_flagged_reason VARCHAR(255) NULL;
