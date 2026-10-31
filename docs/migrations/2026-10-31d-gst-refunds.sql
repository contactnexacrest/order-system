-- Owner decision 2026-10-31 (option A, confirmed): GST under LUT. NexaCrest exports
-- under LUT and sells nothing in India, so the GST paid on purchases comes back as a
-- refund, claimed for a whole period (RFD-01), not per order. The order's cost and
-- profit keep the GST (it left the bank); the CA module records each claim and each
-- bank credit (with its RFD-05 / UTR reference), and every credit goes to Zoho Books
-- as a journal (bank received, GST Refund Receivable reduced). New tables only;
-- safe to run more than once.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gst_refund_claims (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_from     DATE NOT NULL,
  period_to       DATE NOT NULL,
  claim_arn       VARCHAR(30) NOT NULL,
  filed_on        DATE NOT NULL,
  amount_claimed  DECIMAL(14,2) NOT NULL,
  amount_refused  DECIMAL(14,2) NOT NULL DEFAULT 0,
  refused_note    VARCHAR(255) NULL,
  recorded_by     BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gst_refund_claims_arn (claim_arn),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gst_refund_credits (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  claim_id        BIGINT UNSIGNED NOT NULL,
  amount          DECIMAL(14,2) NOT NULL,
  credited_on     DATE NOT NULL,
  bank_reference  VARCHAR(100) NOT NULL,
  part            ENUM('provisional','final') NOT NULL,
  zoho_journal_id VARCHAR(40) NULL,
  zoho_synced_at  DATETIME NULL,
  recorded_by     BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (claim_id) REFERENCES gst_refund_claims(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  INDEX idx_gst_refund_credits_claim (claim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
