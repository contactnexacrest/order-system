-- Gap list answers (owner, 1 Nov 2026), register D-218 — part B.
-- G-2: a balance paid after the BL (balance option "against BL", and LC
--      orders) has no due date until the scanned BL is sent to the buyer
--      (Step 11b); then it is that day + the order's balance days.
--      Existing orders are corrected here.
-- G-4: Letter of Credit dates (issue, expiry, last shipment, deadline to
--      present documents) and the list of LC amendments.
-- MySQL-safe (no ADD COLUMN IF NOT EXISTS), safe to run twice.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------- G-2: correct existing balance due dates
UPDATE order_payment_status ops
  JOIN orders o ON o.id = ops.order_id
  LEFT JOIN order_shipping sh ON sh.order_id = o.id
   SET ops.balance_due_date = CASE
         WHEN sh.scanned_bl_sent_to_buyer_at IS NULL THEN NULL
         ELSE DATE_ADD(DATE(sh.scanned_bl_sent_to_buyer_at), INTERVAL COALESCE(o.balance_days, 7) DAY)
       END
 WHERE (o.balance_trigger_option = 'B_AGAINST_BL' OR o.payment_method = 'LC')
   AND ops.balance_cleared_at IS NULL;

-- ---------------------------------------------------------------- G-4: LC dates
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_lc_details' AND COLUMN_NAME = 'lc_expiry_date');
SET @s := IF(@c = 0, 'ALTER TABLE order_lc_details
  ADD COLUMN lc_issue_date DATE NULL,
  ADD COLUMN lc_expiry_date DATE NULL,
  ADD COLUMN lc_last_shipment_date DATE NULL,
  ADD COLUMN lc_presentation_deadline DATE NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------- G-4: LC amendments
CREATE TABLE IF NOT EXISTS order_lc_amendments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  amended_on DATE NOT NULL,
  reference VARCHAR(100) NOT NULL,
  change_text VARCHAR(500) NOT NULL,
  file_id BIGINT UNSIGNED NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order_lc_amendments_order (order_id, amended_on),
  CONSTRAINT fk_order_lc_amendments_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_order_lc_amendments_file FOREIGN KEY (file_id) REFERENCES file_store(id),
  CONSTRAINT fk_order_lc_amendments_user FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'order_lc_amendment', 'pdf,jpg,jpeg,png', 10485760, 'A copy of an amendment to the order''s Letter of Credit (order_lc_amendments.file_id).'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'order_lc_amendment');
