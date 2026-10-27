-- External QA report L-19 (MIG-02): this ALTER exists in schema.sql
-- (SECTION BJ, 90-item backlog #67) but was never captured as its own
-- migration file, so a production DB upgraded by applying docs/migrations
-- in sequence never gets it — every order-create request then fails with
-- HTTP 500 "Unknown column 'indicative_freight_amount'" the moment any
-- code path written against the new single-value column runs against the
-- old indicative_freight_low/high schema.
--
-- indicative_freight_low/high asked staff for a two-figure range at New
-- Order creation, before freight is ever actually booked — but nothing
-- downstream (the QT/PI wording, the Freight Debit Note) ever used both
-- ends independently; only ONE "indicative, subject to confirmation at
-- booking" figure is ever shown either way, so the range read as asking
-- for two real quotes rather than one placeholder estimate. Consolidated
-- to a single indicative_freight_amount column. Existing
-- indicative_freight_low values are preserved as the new single figure
-- (the one every pre-existing order had populated first, and the more
-- conservative of the two); indicative_freight_high is dropped. Apply to
-- both the PHP and Node MySQL databases — same schema.

SET NAMES utf8mb4;
-- External QA report L-32: IF EXISTS makes this safe to re-run.
SET @nx_sql := IF((SELECT 1 - LEAST(1, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'indicative_freight_low'))) = 0, 'ALTER TABLE orders CHANGE COLUMN indicative_freight_low indicative_freight_amount DECIMAL(14,2) NULL', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT 1 - LEAST(1, (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'indicative_freight_high'))) = 0, 'ALTER TABLE orders DROP COLUMN indicative_freight_high', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
