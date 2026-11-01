-- QA Round 8 (R8-N05), owner decision AS-17: "client no. N" for an associate is the Nth client they
-- brought, in the order the clients were linked, fixed on the client for ever.
--   1. clients.associate_client_number (new column).
--   2. Existing linked clients are numbered per associate by their registration date, then client id.
--   3. Open orders (not cancelled / lost, commission not yet locked) of a linked client get the client's
--      number and the rate for that number (personal ladder with carry-forward D-221, else the
--      standard ladder; "no commission" associates 0).
-- Safe to run more than once: step 2 only numbers clients without a number, step 3 only touches orders
-- whose number differs from their client's.
SET NAMES utf8mb4;

-- 1. The column.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'associate_client_number');
SET @s := IF(@c = 0, 'ALTER TABLE clients ADD COLUMN associate_client_number INT NULL', 'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Number existing linked clients (registration date, then client id). Worked out in a helper
--    table first, so the UPDATE never reads the table it writes.
DROP TEMPORARY TABLE IF EXISTS tmp_r8_client_numbers;
CREATE TEMPORARY TABLE tmp_r8_client_numbers (client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB;
INSERT INTO tmp_r8_client_numbers (client_id, n)
SELECT c1.id,
       (SELECT COUNT(*) FROM clients c2
         WHERE c2.associate_id = c1.associate_id
           AND (COALESCE(c2.associate_registered_on, '9999-12-31') < COALESCE(c1.associate_registered_on, '9999-12-31')
                OR (COALESCE(c2.associate_registered_on, '9999-12-31') = COALESCE(c1.associate_registered_on, '9999-12-31') AND c2.id <= c1.id)))
  FROM clients c1
 WHERE c1.associate_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM clients x WHERE x.associate_id = c1.associate_id AND x.associate_client_number IS NOT NULL);
UPDATE clients c JOIN tmp_r8_client_numbers t ON t.client_id = c.id
   SET c.associate_client_number = t.n
 WHERE c.associate_client_number IS NULL;
DROP TEMPORARY TABLE IF EXISTS tmp_r8_client_numbers;

-- 3. Open orders: the client's number and the rate for it.
SET @g15  := COALESCE((SELECT NULLIF(setting_value, '') FROM incentive_settings WHERE setting_key = 'associate_rate_1_5'), '0.70');
SET @g610 := COALESCE((SELECT NULLIF(setting_value, '') FROM incentive_settings WHERE setting_key = 'associate_rate_6_10'), '1.00');
SET @g1120 := COALESCE((SELECT NULLIF(setting_value, '') FROM incentive_settings WHERE setting_key = 'associate_rate_11_20'), '1.50');
SET @g2140 := COALESCE((SELECT NULLIF(setting_value, '') FROM incentive_settings WHERE setting_key = 'associate_rate_21_40'), '1.75');
SET @g41  := COALESCE((SELECT NULLIF(setting_value, '') FROM incentive_settings WHERE setting_key = 'associate_rate_41_plus'), '2.00');

DROP TEMPORARY TABLE IF EXISTS tmp_r8_order_rates;
CREATE TEMPORARY TABLE tmp_r8_order_rates (order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, n INT NOT NULL, personal DECIMAL(5,2) NULL, global_rate DECIMAL(5,2) NOT NULL, no_commission TINYINT(1) NOT NULL) ENGINE=InnoDB;
INSERT INTO tmp_r8_order_rates (order_id, n, personal, global_rate, no_commission)
SELECT o.id, c.associate_client_number,
       CASE
         WHEN c.associate_client_number <= 5 THEN NULLIF(a.rate_1_5, 0)
         WHEN c.associate_client_number <= 10 THEN COALESCE(NULLIF(a.rate_6_10, 0), NULLIF(a.rate_1_5, 0))
         WHEN c.associate_client_number <= 20 THEN COALESCE(NULLIF(a.rate_11_20, 0), NULLIF(a.rate_6_10, 0), NULLIF(a.rate_1_5, 0))
         WHEN c.associate_client_number <= 40 THEN COALESCE(NULLIF(a.rate_21_40, 0), NULLIF(a.rate_11_20, 0), NULLIF(a.rate_6_10, 0), NULLIF(a.rate_1_5, 0))
         ELSE COALESCE(NULLIF(a.rate_41_plus, 0), NULLIF(a.rate_21_40, 0), NULLIF(a.rate_11_20, 0), NULLIF(a.rate_6_10, 0), NULLIF(a.rate_1_5, 0))
       END,
       CASE
         WHEN c.associate_client_number <= 5 THEN @g15
         WHEN c.associate_client_number <= 10 THEN @g610
         WHEN c.associate_client_number <= 20 THEN @g1120
         WHEN c.associate_client_number <= 40 THEN @g2140
         ELSE @g41
       END,
       COALESCE(a.no_commission, 0)
  FROM orders o
  JOIN clients c ON c.id = o.client_id
  JOIN associates a ON a.id = c.associate_id
  LEFT JOIN order_incentives oi ON oi.order_id = o.id
 WHERE o.associate_id = c.associate_id
   AND c.associate_client_number IS NOT NULL
   AND o.status NOT IN ('cancelled', 'lost')
   AND oi.locked_at IS NULL
   AND (o.associate_client_number IS NULL OR o.associate_client_number <> c.associate_client_number);

UPDATE orders o JOIN tmp_r8_order_rates t ON t.order_id = o.id
   SET o.associate_client_number = t.n,
       o.associate_rate_pct = CASE WHEN t.no_commission = 1 THEN 0 WHEN t.personal IS NOT NULL THEN t.personal ELSE t.global_rate END,
       o.associate_rate_source = CASE WHEN t.no_commission = 1 THEN 'none' WHEN t.personal IS NOT NULL THEN 'personal' ELSE 'global' END;
UPDATE order_incentives oi JOIN tmp_r8_order_rates t ON t.order_id = oi.order_id
   SET oi.associate_order_number = t.n
 WHERE oi.locked_at IS NULL AND oi.associate_order_number IS NOT NULL;
DROP TEMPORARY TABLE IF EXISTS tmp_r8_order_rates;
