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
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'drift_flagged_at') = 0, 'ALTER TABLE documents ADD COLUMN drift_flagged_at TIMESTAMP NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'drift_flagged_reason') = 0, 'ALTER TABLE documents ADD COLUMN drift_flagged_reason VARCHAR(255) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
