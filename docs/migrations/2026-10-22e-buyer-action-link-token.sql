-- External QA report L-19 (MIG-02) + L-24: this ALTER exists in
-- schema.sql but was never captured as its own migration file, so a
-- production DB upgraded by applying docs/migrations in sequence never
-- gets it — the Buyer Action Link feature (public, no-login link for a
-- buyer to acknowledge the Order Confirmation and/or upload their signed
-- Buyer PO) breaks outright with "Unknown column
-- 'buyer_action_token_hash'".
--
-- A public, no-login link covering both the OC acknowledgement and the
-- client-portal Buyer PO upload — for a buyer who hasn't been provisioned
-- a portal account yet (or doesn't want to log in just for these two
-- actions). Unlike the existing /quotation-details or /pi-details public,
-- tokenized links, this needs no separate submission-row model —
-- OC-ack and Buyer-PO-upload already write directly to the order itself
-- — so the token lives directly on `orders` rather than a new table.
--
-- buyer_action_token_plain holds AES-256-GCM ciphertext (EncryptionService),
-- not the raw token (L-24: a DB/backup read must not be able to use a
-- live link directly) — sized VARCHAR(255) rather than the raw token's
-- own 64 chars to hold the encrypted form (base64(iv . tag . ciphertext)
-- for a 64-char raw token needs ~124 chars). The public /buyer-action/
-- {token} route always validates against the hash, never the plain
-- column — that column exists only so staff can re-view/re-copy the live
-- link from the order page at any time before it expires, decrypted only
-- at that one display point. Apply to both the PHP and Node MySQL
-- databases — same schema.

SET NAMES utf8mb4;
-- External QA report L-32: IF NOT EXISTS makes both statements below safe to re-run.
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'buyer_action_token_hash') = 0, 'ALTER TABLE orders ADD COLUMN buyer_action_token_hash VARCHAR(64) NULL AFTER pi_valid_until', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'buyer_action_token_plain') = 0, 'ALTER TABLE orders ADD COLUMN buyer_action_token_plain VARCHAR(255) NULL AFTER buyer_action_token_hash', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'buyer_action_token_expires_at') = 0, 'ALTER TABLE orders ADD COLUMN buyer_action_token_expires_at DATETIME NULL AFTER buyer_action_token_plain', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;


SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_buyer_action_token_hash') = 0, 'CREATE INDEX idx_orders_buyer_action_token_hash ON orders(buyer_action_token_hash)', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
