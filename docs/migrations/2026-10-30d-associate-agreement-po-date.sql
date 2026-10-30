-- Owner decision 2026-10-30 (items 10 and 11 of the incentive follow-ups).
-- 10. The signed associate agreement is kept in the app with its date
--     (associates.agreement_file_id), downloadable only by those who manage
--     incentives, every download audit-logged.
-- 11. The date the buyer's PO was received (orders.buyer_po_received_on),
--     recorded at Step 4d (or the day the buyer confirms it themselves).
--     The associate agreement's Day 1 is the client's first PO received,
--     so the 10-day rule uses it; orders confirmed before this change have
--     no date and keep using the order date.
-- Safe to run more than once.
SET NAMES utf8mb4;

INSERT INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description)
SELECT 'associate_agreement', 'pdf', 15728640, 'The signed Commission Structure and Associate Agreement of an associate (associates.agreement_file_id) — internal, manage_incentives only.'
WHERE NOT EXISTS (SELECT 1 FROM file_upload_contexts WHERE context_key = 'associate_agreement');

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'associates' AND COLUMN_NAME = 'agreement_file_id') = 0, 'ALTER TABLE associates ADD COLUMN agreement_file_id BIGINT UNSIGNED NULL AFTER agreement_signed_on', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'associates' AND CONSTRAINT_NAME = 'fk_associates_agreement_file') = 0, 'ALTER TABLE associates ADD CONSTRAINT fk_associates_agreement_file FOREIGN KEY (agreement_file_id) REFERENCES file_store(id)', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'buyer_po_received_on') = 0, 'ALTER TABLE orders ADD COLUMN buyer_po_received_on DATE NULL AFTER buyers_po_ref', 'DO 0');
PREPARE nx_stmt FROM @nx_sql; EXECUTE nx_stmt; DEALLOCATE PREPARE nx_stmt;
