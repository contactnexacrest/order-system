SET NAMES utf8mb4;
-- Owner decisions (2026-10-27): vendor bills (supplier + logistics partners)
-- with the payment against each bill, and the order's actual expenses with
-- receipts. See docs/schema.sql Section CE.
CREATE TABLE IF NOT EXISTS order_vendor_bills (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id             BIGINT UNSIGNED NOT NULL,
  vendor_kind          ENUM('supplier','logistics') NOT NULL,
  supplier_po_id       BIGINT UNSIGNED NULL,
  logistics_partner_id BIGINT UNSIGNED NULL,
  bill_for             VARCHAR(30)  NOT NULL,
  bill_no              VARCHAR(100) NOT NULL,
  bill_date            DATE         NOT NULL,
  taxable_inr          DECIMAL(14,2) NOT NULL,
  gst_inr              DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_inr            DECIMAL(14,2) NOT NULL,
  note                 VARCHAR(500) NULL,
  bill_file_id         BIGINT UNSIGNED NULL,
  paid_at              DATE NULL,
  paid_amount_inr      DECIMAL(14,2) NULL,
  payment_mode         VARCHAR(30)  NULL,
  payment_ref          VARCHAR(100) NULL,
  paid_by              BIGINT UNSIGNED NULL,
  created_by           BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (supplier_po_id) REFERENCES order_supplier_po(id),
  FOREIGN KEY (logistics_partner_id) REFERENCES logistics_partners(id),
  FOREIGN KEY (bill_file_id) REFERENCES file_store(id),
  FOREIGN KEY (paid_by) REFERENCES users(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_order_vendor_bills_order (order_id, vendor_kind)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_expenses (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          BIGINT UNSIGNED NOT NULL,
  expense_date      DATE          NOT NULL,
  category          VARCHAR(40)   NOT NULL,
  details           VARCHAR(255)  NOT NULL,
  amount_inr        DECIMAL(14,2) NOT NULL,
  paid_by           ENUM('company_account','company_card','staff') NOT NULL,
  staff_name        VARCHAR(150)  NULL,
  receipt_file_id   BIGINT UNSIGNED NULL,
  no_receipt_reason VARCHAR(255)  NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (receipt_file_id) REFERENCES file_store(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_order_expenses_order (order_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO file_upload_contexts (context_key, allowed_extensions, max_size_bytes, description) VALUES
  ('vendor_bill',     'pdf,jpg,jpeg,png', 10485760, 'A bill / invoice from the supplier or a logistics partner (CHA, transport, freight forwarder) — Steps 7e/10a and the logistics bills card.'),
  ('expense_receipt', 'pdf,jpg,jpeg,png', 10485760, 'Receipt or bill for an order expense (travel, hotel, courier, ...) — Step 12a.');
