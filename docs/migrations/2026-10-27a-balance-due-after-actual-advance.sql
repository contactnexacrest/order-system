-- Owner report (2026-10-27, blocker): when the advance was cleared, the
-- balance due was stored as FOB × balance % — ignoring what the bank actually
-- received — so the Order Confirmation printed an advance and a balance that
-- did not add up to the order value. From now on the balance due is
-- FOB − advance actually received. This one-off fix corrects orders whose
-- balance has not been received yet. Safe to re-run.
SET NAMES utf8mb4;

UPDATE order_payment_status ps
JOIN (
  SELECT order_id, COALESCE(SUM(fob_value), 0) AS fob
  FROM order_products
  WHERE is_active = 1
  GROUP BY order_id
) t ON t.order_id = ps.order_id
SET ps.balance_amount = GREATEST(ROUND(t.fob - ps.advance_amount, 2), 0)
WHERE ps.advance_amount IS NOT NULL
  AND ps.balance_amount IS NOT NULL
  AND ps.balance_remittance_received_at IS NULL;
