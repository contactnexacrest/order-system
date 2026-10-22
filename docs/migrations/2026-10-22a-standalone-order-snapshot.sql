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
ALTER TABLE orders
  ADD COLUMN incoterm_code           VARCHAR(10) NULL AFTER port_of_discharge_text,
  ADD COLUMN currency_code           VARCHAR(10) NULL AFTER incoterm_code,
  ADD COLUMN port_of_loading_name    VARCHAR(150) NULL AFTER currency_code,
  ADD COLUMN port_of_loading_city    VARCHAR(100) NULL AFTER port_of_loading_name,
  ADD COLUMN port_of_discharge_name  VARCHAR(150) NULL AFTER port_of_loading_city,
  ADD COLUMN port_of_discharge_city  VARCHAR(100) NULL AFTER port_of_discharge_name,
  ADD COLUMN preset_name             VARCHAR(100) NULL AFTER port_of_discharge_city,
  ADD COLUMN advance_pct             DECIMAL(5,2) NULL AFTER preset_name,
  ADD COLUMN advance_trigger_text    VARCHAR(255) NULL AFTER advance_pct,
  ADD COLUMN balance_pct             DECIMAL(5,2) NULL AFTER advance_trigger_text,
  ADD COLUMN balance_trigger_option  ENUM('A_BEFORE_SHIPMENT','B_AGAINST_BL') NULL AFTER balance_pct,
  ADD COLUMN balance_days            INT NULL AFTER balance_trigger_option,
  ADD COLUMN balance_trigger_wording TEXT NULL AFTER balance_days,
  ADD COLUMN show_bl_instructions    TINYINT(1) NOT NULL DEFAULT 1 AFTER balance_trigger_wording;

-- ================================================================
-- STEP 2 — backfill every existing order from whatever the live join
-- currently resolves to. Safe and accurate: nothing has drifted yet as
-- of this migration running, so "today's live-join answer" IS the
-- correct historical record for every order that exists right now.
-- advance_pct/balance_pct/balance_trigger_option/balance_days use the
-- OLD COALESCE(override, preset) logic one last time, to carry forward
-- whatever an already-activated amendment had already overridden.
-- ================================================================
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

-- ================================================================
-- STEP 3 — drop the now-superseded amendment override columns. An
-- activated amendment's override value has already been carried into
-- the base columns above (via the COALESCE in STEP 2), so nothing is
-- lost — going forward, OrderRepository::applyAmendmentOverride() /
-- orderRepository.js's equivalent write the base columns directly.
-- ================================================================
ALTER TABLE orders
  DROP COLUMN advance_pct_override,
  DROP COLUMN balance_pct_override,
  DROP COLUMN balance_trigger_option_override,
  DROP COLUMN balance_days_override;
