-- External QA report M-09: "Formal order cancellation workflow (reason,
-- approval, money/refund handling, audit) distinct from 'mark lost'."
-- markLost() already exists but only for a pre-money walk-away (status
-- must still be 'active', no BL on file yet) — this adds a separate,
-- fuller workflow for cancelling an order that's already further along
-- (money collected, supplier committed), closest in shape to the
-- existing amendments table's own request/MD-approve/reject pattern.
-- Apply to both the PHP and Node MySQL databases — same schema.

SET NAMES utf8mb4;

-- MODIFY COLUMN is naturally safe to re-run (re-stating the same ENUM is
-- a no-op); CREATE TABLE IF NOT EXISTS below is the same External QA
-- report L-32 convention for the new table.
--
-- Includes 'shipped_docs_sent' (added later by
-- 2026-10-23i-two-phase-order-closure.sql / docs/schema.sql Section BU)
-- so that re-running THIS file after that one has gone live never
-- narrows the column back down and truncates real data using that
-- value — MigrationIdempotencyTest replays every migration file in
-- order against the already-fully-migrated database, which is exactly
-- that scenario.
ALTER TABLE orders
  MODIFY COLUMN status ENUM('active','shipped_docs_sent','complete','disputed','lost','cancelled') NOT NULL DEFAULT 'active';

CREATE TABLE IF NOT EXISTS order_cancellations (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id            BIGINT UNSIGNED NOT NULL,
  reason              TEXT NOT NULL,
  requested_by        BIGINT UNSIGNED NOT NULL,
  requested_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by          BIGINT UNSIGNED NULL,
  decided_at          TIMESTAMP NULL,
  decision_notes      VARCHAR(500) NULL,       -- rejection reason, or approval notes
  -- Refund amount is in the order's own currency_code — no separate
  -- currency column, same "derive from the order" convention already
  -- used elsewhere (e.g. order_payment_status) rather than duplicating it.
  refund_amount       DECIMAL(14,2) NULL,
  refund_status       ENUM('not_applicable','pending','completed') NOT NULL DEFAULT 'not_applicable',
  refund_notes        VARCHAR(500) NULL,
  refund_recorded_by  BIGINT UNSIGNED NULL,
  refund_recorded_at  TIMESTAMP NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (decided_by) REFERENCES users(id),
  FOREIGN KEY (refund_recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;
