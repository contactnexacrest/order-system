-- 2026-11-24b — Owner decision D-410 point 8 (24 Nov 2026, M14 confirmed twice): the Commission Payment Advice (CPA).
-- One advice per bank transfer to an associate (same associate, same paid-on date, same bank reference), numbered
-- NC/SC/CPA/A{4-digit associate no.}/{YY}/{NNN}, counted per associate per financial year (YY = the year the
-- financial year starts, 1 April). It is made once, when the payouts are marked paid; what it shows is kept in
-- context_json so it never changes. An MD correction makes "Rev 1" (reason kept); the old one is marked superseded.
-- New table associate_payment_advices; new column incentive_payouts.payment_advice_id.
-- PLAIN ALTER (owner D-371, 13 Nov 2026): CREATE TABLE IF NOT EXISTS is safe to repeat; a second run stops at the
-- ALTER with "#1060 Duplicate column name", which is harmless.
-- Run docs/checks/check_all_migrations.sql first: this file's row says NEEDED until it has run.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS associate_payment_advices (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  associate_id     BIGINT UNSIGNED NOT NULL,
  advice_no        VARCHAR(40) NOT NULL,
  fy_yy            CHAR(2) NOT NULL,
  seq              INT UNSIGNED NOT NULL,
  revision         INT UNSIGNED NOT NULL DEFAULT 0,
  supersedes_id    BIGINT UNSIGNED NULL,
  superseded_at    DATETIME NULL,
  revision_reason  VARCHAR(300) NULL,
  paid_on          DATE NOT NULL,
  paid_reference   VARCHAR(100) NULL,
  currency_code    CHAR(3) NOT NULL DEFAULT 'INR',
  total_paid       DECIMAL(14,2) NOT NULL DEFAULT 0,
  context_json     MEDIUMTEXT NOT NULL,
  created_by       BIGINT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cpa_number (associate_id, fy_yy, seq, revision),
  KEY idx_cpa_associate (associate_id, paid_on),
  CONSTRAINT fk_cpa_associate FOREIGN KEY (associate_id) REFERENCES associates(id),
  CONSTRAINT fk_cpa_supersedes FOREIGN KEY (supersedes_id) REFERENCES associate_payment_advices(id),
  CONSTRAINT fk_cpa_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE incentive_payouts ADD COLUMN payment_advice_id BIGINT UNSIGNED NULL;
