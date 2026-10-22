-- Phase 3 item 15: port-to-port sailing-days reference document, plus a
-- separate sailing lead-time pair on orders (split out from the existing
-- combined lead_time_min_days/max_days, which stays as "Production Lead
-- Time"). Apply to both the PHP and Node MySQL databases — same schema.


SET NAMES utf8mb4;
-- External QA report L-32: IF NOT EXISTS makes both statements below safe to re-run.
CREATE TABLE IF NOT EXISTS port_routes (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  port_of_loading_id      BIGINT UNSIGNED NOT NULL,
  port_of_discharge_id    BIGINT UNSIGNED NOT NULL,
  sailing_days_min        INT UNSIGNED NOT NULL,
  sailing_days_max        INT UNSIGNED NOT NULL,
  notes                   VARCHAR(255) NULL,
  is_active               TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_port_routes_pair (port_of_loading_id, port_of_discharge_id),
  FOREIGN KEY (port_of_loading_id) REFERENCES ports(id),
  FOREIGN KEY (port_of_discharge_id) REFERENCES ports(id)
) ENGINE=InnoDB;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS sailing_lead_time_min_days INT UNSIGNED NULL AFTER lead_time_max_days,
  ADD COLUMN IF NOT EXISTS sailing_lead_time_max_days INT UNSIGNED NULL AFTER sailing_lead_time_min_days;
