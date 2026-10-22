-- 90-item backlog "standalone order": an order's incoterm/currency/port/
-- payment-preset terms were only ever a live JOIN against incoterms/
-- currencies/ports/payment_presets — editing or retiring one of those
-- rows later silently rewrote what every order that ever referenced it
-- displayed and printed, even an order closed years ago. This adds
-- snapshot columns to `orders`, backfills them from whatever the live
-- join currently resolves to (accurate as of today — nothing has
-- drifted yet), and retires the old amendment *_override columns in
-- favor of writing the base columns directly. Apply to both the PHP and
-- Node MySQL databases — same schema.
--
-- The old incoterm_id/currency_id/port_of_loading_id/port_of_discharge_id/
-- payment_preset_id columns are NOT dropped — they stay for admin
-- traceability/reporting only ("which orders used preset X"), never read
-- for display or document content again.

-- ================================================================
-- STEP 1 — add the new snapshot columns
-- ================================================================

SET NAMES utf8mb4;
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS incoterm_code           VARCHAR(10) NULL AFTER port_of_discharge_text,
  ADD COLUMN IF NOT EXISTS currency_code           VARCHAR(10) NULL AFTER incoterm_code,
  ADD COLUMN IF NOT EXISTS port_of_loading_name    VARCHAR(150) NULL AFTER currency_code,
  ADD COLUMN IF NOT EXISTS port_of_loading_city    VARCHAR(100) NULL AFTER port_of_loading_name,
  ADD COLUMN IF NOT EXISTS port_of_discharge_name  VARCHAR(150) NULL AFTER port_of_loading_city,
  ADD COLUMN IF NOT EXISTS port_of_discharge_city  VARCHAR(100) NULL AFTER port_of_discharge_name,
  ADD COLUMN IF NOT EXISTS preset_name             VARCHAR(100) NULL AFTER port_of_discharge_city,
  ADD COLUMN IF NOT EXISTS advance_pct             DECIMAL(5,2) NULL AFTER preset_name,
  ADD COLUMN IF NOT EXISTS advance_trigger_text    VARCHAR(255) NULL AFTER advance_pct,
  ADD COLUMN IF NOT EXISTS balance_pct             DECIMAL(5,2) NULL AFTER advance_trigger_text,
  ADD COLUMN IF NOT EXISTS balance_trigger_option  ENUM('A_BEFORE_SHIPMENT','B_AGAINST_BL') NULL AFTER balance_pct,
  ADD COLUMN IF NOT EXISTS balance_days            INT NULL AFTER balance_trigger_option,
  ADD COLUMN IF NOT EXISTS balance_trigger_wording TEXT NULL AFTER balance_days,
  ADD COLUMN IF NOT EXISTS show_bl_instructions    TINYINT(1) NOT NULL DEFAULT 1 AFTER balance_trigger_wording;

-- ================================================================
-- STEP 2 — backfill every existing order from whatever the live join
-- currently resolves to. Safe and accurate: nothing has drifted yet as
-- of this migration running, so "today's live-join answer" IS the
-- correct historical record for every order that exists right now.
-- advance_pct/balance_pct/balance_trigger_option/balance_days use the
-- OLD COALESCE(override, preset) logic one last time, to carry forward
-- whatever an already-activated amendment had already overridden.
--
-- STEP 3 — drop the now-superseded amendment override columns. An
-- activated amendment's override value has already been carried into
-- the base columns above (via the COALESCE in STEP 2), so nothing is
-- lost — going forward, OrderRepository::applyAmendmentOverride() /
-- orderRepository.js's equivalent write the base columns directly.
--
-- External QA report L-32: STEP 2 reads *_override columns that STEP 3
-- then drops, so a bare re-run (after a first run already completed)
-- would fail STEP 2 with "Unknown column 'advance_pct_override'" the
-- instant it tried to read a column that no longer exists. Wrapped in
-- a procedure that checks whether advance_pct_override still exists
-- first — if it's already gone, this whole step was already applied
-- (or there was never anything to migrate), so skip straight to a
-- no-op; a real DROP COLUMN IF EXISTS on STEP 3 alone can't fix this,
-- since the hazard is in STEP 2 running against an already-dropped
-- column, not in STEP 3 itself.
-- ================================================================
DELIMITER $$
CREATE PROCEDURE qa_l32_migrate_order_snapshot_step2_3()
BEGIN
  IF EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'advance_pct_override'
  ) THEN
    UPDATE orders o
      JOIN incoterms i ON i.id = o.incoterm_id
      JOIN currencies cur ON cur.id = o.currency_id
      JOIN payment_presets pp ON pp.id = o.payment_preset_id
      LEFT JOIN ports pl ON pl.id = o.port_of_loading_id
      LEFT JOIN ports pd ON pd.id = o.port_of_discharge_id
    SET
      o.incoterm_code           = i.code,
      o.currency_code           = cur.code,
      o.port_of_loading_name    = pl.name,
      o.port_of_loading_city    = pl.city,
      o.port_of_discharge_name  = pd.name,
      o.port_of_discharge_city  = pd.city,
      o.preset_name             = pp.preset_name,
      o.advance_pct             = COALESCE(o.advance_pct_override, pp.advance_pct),
      o.advance_trigger_text    = pp.advance_trigger_text,
      o.balance_pct             = COALESCE(o.balance_pct_override, pp.balance_pct),
      o.balance_trigger_option  = COALESCE(o.balance_trigger_option_override, pp.balance_trigger_option),
      o.balance_days            = COALESCE(o.balance_days_override, pp.balance_days),
      o.balance_trigger_wording = pp.balance_trigger_wording,
      o.show_bl_instructions    = pp.show_bl_instructions;

    ALTER TABLE orders
      DROP COLUMN advance_pct_override,
      DROP COLUMN balance_pct_override,
      DROP COLUMN balance_trigger_option_override,
      DROP COLUMN balance_days_override;
  END IF;
END$$
DELIMITER ;
CALL qa_l32_migrate_order_snapshot_step2_3();
DROP PROCEDURE qa_l32_migrate_order_snapshot_step2_3;
