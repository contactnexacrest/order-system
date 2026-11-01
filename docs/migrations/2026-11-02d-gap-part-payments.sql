-- Gap list G-5 (owner, 1 Nov 2026: "G-1 to G-5 yes"): part payments and short
-- payments from the buyer. Every remittance of the advance, the freight Debit
-- Note, the balance and each payment milestone is kept as its own receipt;
-- the leg's existing columns keep the total. A leg is cleared while money is
-- still owed only with a manager's written reason ("Approve short payment"),
-- and the shortfall is recorded.
-- MySQL-safe, safe to run twice.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_payment_receipts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  leg ENUM('advance','freight','balance','milestone') NOT NULL,
  milestone_id BIGINT UNSIGNED NULL,
  amount DECIMAL(14,2) NOT NULL,
  received_on DATE NOT NULL,
  exchange_rate DECIMAL(12,4) NULL,
  bank_reference VARCHAR(100) NULL,
  staff_note VARCHAR(255) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payment_receipts_leg (order_id, leg, milestone_id),
  CONSTRAINT fk_payment_receipts_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_payment_shortfalls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  leg ENUM('advance','freight','balance','milestone') NOT NULL,
  milestone_id BIGINT UNSIGNED NULL,
  expected_amount DECIMAL(14,2) NOT NULL,
  received_amount DECIMAL(14,2) NOT NULL,
  shortfall_amount DECIMAL(14,2) NOT NULL,
  treatment ENUM('written_off','carried_to_balance') NOT NULL,
  reason VARCHAR(500) NOT NULL,
  approved_by BIGINT UNSIGNED NOT NULL,
  approved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payment_shortfalls_leg (order_id, leg, milestone_id),
  CONSTRAINT fk_payment_shortfalls_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB;

-- Gap list G-5: the manager's permission to clear a buyer payment that is less than due.
INSERT INTO permissions (permission_key, name, description, category)
SELECT 'approve_short_payment', 'Approve short payment', 'Approve a short payment — clear a buyer payment that is less than due, with a written reason', 'orders'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE permission_key = 'approve_short_payment');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'approve_short_payment'
 WHERE r.name IN ('Managing Director', 'Executive Director', 'Admin', 'Finance Director')
   AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id);

-- Existing orders: one receipt per remittance already recorded, so the history shows (only once).
INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, staff_note)
SELECT ops.order_id, 'advance', ops.advance_amount, DATE(ops.advance_remittance_received_at), ops.advance_exchange_rate, ops.advance_bank_reference, 'Recorded before part payments were kept separately'
  FROM order_payment_status ops
 WHERE ops.advance_amount IS NOT NULL AND ops.advance_remittance_received_at IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM order_payment_receipts r WHERE r.order_id = ops.order_id AND r.leg = 'advance');
INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, staff_note)
SELECT ops.order_id, 'freight', ops.freight_amount, DATE(ops.freight_remittance_received_at), ops.freight_exchange_rate, ops.freight_bank_reference, 'Recorded before part payments were kept separately'
  FROM order_payment_status ops
 WHERE ops.freight_amount IS NOT NULL AND ops.freight_remittance_received_at IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM order_payment_receipts r WHERE r.order_id = ops.order_id AND r.leg = 'freight');
INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, staff_note)
SELECT ops.order_id, 'balance', ops.balance_amount, DATE(ops.balance_remittance_received_at), ops.balance_exchange_rate, ops.balance_bank_reference, 'Recorded before part payments were kept separately'
  FROM order_payment_status ops
 WHERE ops.balance_amount IS NOT NULL AND ops.balance_remittance_received_at IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM order_payment_receipts r WHERE r.order_id = ops.order_id AND r.leg = 'balance');
INSERT INTO order_payment_receipts (order_id, leg, milestone_id, amount, received_on, exchange_rate, bank_reference, staff_note, recorded_by)
SELECT m.order_id, 'milestone', m.id, m.received_amount, m.received_on, m.exchange_rate, m.bank_reference, m.staff_note, m.recorded_by
  FROM order_payment_milestones m
 WHERE m.received_amount IS NOT NULL AND m.received_on IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM order_payment_receipts r WHERE r.order_id = m.order_id AND r.leg = 'milestone' AND r.milestone_id = m.id);
