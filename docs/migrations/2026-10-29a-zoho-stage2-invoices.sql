-- Zoho integration Stage 2 (2026-10-29): the Commercial Invoice and the
-- freight Debit Note go to Zoho Books as export invoices, and the buyer's
-- payments are applied to them. order_zoho_invoices remembers which Books
-- invoice each order's CI / Debit Note became (and a fingerprint of what
-- was sent, so a revised CI updates it). *_zoho_applied_at records when a
-- payment leg was applied to its invoice in Books: the advance is held in
-- Books as an unapplied payment until the CI is there. Safe to run more
-- than once.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_zoho_invoices (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id             BIGINT UNSIGNED NOT NULL,
  kind                 VARCHAR(10)   NOT NULL,      -- 'ci' (Commercial Invoice) | 'dn' (freight Debit Note)
  document_id          BIGINT UNSIGNED NOT NULL,    -- the documents row last sent
  zoho_invoice_id      VARCHAR(100)  NOT NULL,
  zoho_invoice_number  VARCHAR(100)  NOT NULL,
  currency_code        CHAR(3)       NOT NULL,
  total                DECIMAL(14,2) NOT NULL,      -- in the order's currency
  exchange_rate        DECIMAL(14,6) NOT NULL,
  pushed_hash          CHAR(64)      NOT NULL,      -- SHA-256 of what was sent; a change means "update it in Books"
  pushed_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP     NULL,
  UNIQUE KEY uq_order_zoho_invoice (order_id, kind),
  CONSTRAINT fk_order_zoho_invoice_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_order_zoho_invoice_document FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_payment_status' AND COLUMN_NAME = 'advance_zoho_applied_at') = 0, 'ALTER TABLE order_payment_status ADD COLUMN advance_zoho_applied_at TIMESTAMP NULL AFTER advance_zoho_reference', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_payment_status' AND COLUMN_NAME = 'balance_zoho_applied_at') = 0, 'ALTER TABLE order_payment_status ADD COLUMN balance_zoho_applied_at TIMESTAMP NULL AFTER balance_zoho_reference', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;

SET @nx_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_payment_status' AND COLUMN_NAME = 'freight_zoho_applied_at') = 0, 'ALTER TABLE order_payment_status ADD COLUMN freight_zoho_applied_at TIMESTAMP NULL AFTER freight_zoho_reference', 'DO 0');
PREPARE nx_stmt FROM @nx_sql;
EXECUTE nx_stmt;
DEALLOCATE PREPARE nx_stmt;
