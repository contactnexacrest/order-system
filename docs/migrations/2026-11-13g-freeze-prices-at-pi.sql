-- 2026-11-13g — Prices fixed at the first approved Proforma Invoice when no Quotation was approved first
-- Owner decision D-370 (13 Nov 2026, owner "go"; closes the D-342 gap found in QA as HNT-L05).
--
-- What changes:
--   * D-342 fixed an order's prices, products and exchange rate at its first approved Quotation.
--     An order could reach an approved / sent Proforma Invoice with its Quotation still a draft,
--     and then nothing was fixed. From D-370 the app fixes them at the first approved Quotation
--     OR, if none was approved, at the first approved Proforma Invoice — whichever comes first.
--     The same freeze as the Quotation: the rate, each line's price, the time, and the document
--     that fixed them (here the PI).
--   * No schema change. This file only fixes EXISTING orders that have an approved Proforma
--     Invoice (status approved / sent / superseded) — or, failing a PI, an approved Order
--     Confirmation, Buyer PO or Commercial Invoice — but are not fixed yet
--     (orders.prices_fixed_at IS NULL), exactly as the app now does at the PI's approval:
--       - prices_fixed_at              = that document's last reviewer approval (else its generation time)
--       - prices_fixed_by_document_id  = that document (the first approved PI)
--       - pricing_fx_rate_at_qt        = the rate kept at the PI (orders.pricing_fx_rate_at_pi) when
--                                        stored; else the pricing rate in use on that day (daily rate
--                                        history minus the cushion; Manual mode: the typed rate); else
--                                        the pricing rate in use when this file runs
--       - order_products.qt_fixed_unit_price = each active line's unit price now
--     From then on their lines change only through an Amendment (Products & prices). Lines keep
--     their price check figures as they are (fixed at the PI where issued).
--   * No document (PDF / Word) changes; nothing printed changes.
--
-- READ-ONLY CHECK FIRST (changes nothing) — the orders this file will fix:
--
--   SELECT o.order_reference AS `Order no`, c.company_legal_name AS `Client`,
--          COALESCE(sm.stage_name, '—') AS `Stage`,
--          (SELECT CONCAT(dt.code, ' ', COALESCE(d.document_reference, CONCAT('#', d.id)), ' (', d.status, ')')
--             FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
--            WHERE d.order_id = o.id AND dt.code IN ('PI', 'OC', 'BUYERPO', 'CI')
--              AND d.status IN ('approved', 'sent', 'superseded')
--            ORDER BY (dt.code = 'PI') DESC, d.id LIMIT 1) AS `PI number`,
--          (SELECT COUNT(*) FROM order_products op WHERE op.order_id = o.id AND op.is_active = 1) AS `Lines`
--     FROM orders o
--     JOIN clients c ON c.id = o.client_id
--     LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
--    WHERE o.prices_fixed_at IS NULL
--      AND EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
--                   WHERE d.order_id = o.id AND dt.code IN ('PI', 'OC', 'BUYERPO', 'CI')
--                     AND d.status IN ('approved', 'sent', 'superseded'))
--    ORDER BY o.id;
--
-- Safe to run twice: only orders not fixed yet are touched, so a second run changes nothing.
-- MySQL-safe and ONLY_FULL_GROUP_BY-safe. Plain statements only: no information_schema query and no
-- PREPARE, so phpMyAdmin runs it in the selected database without switching (see 13d, 13 Nov 2026).
-- The last line shows how many orders are fixed by a PI (or later document).
-- Run after 2026-11-13f, after a backup:
--   mysql -u <user> -p <database> < docs/migrations/2026-11-13g-freeze-prices-at-pi.sql
SET NAMES utf8mb4;

-- The pricing rate in use now: Manual = the typed rate; Automatic = newest rate in use × (1 − cushion %).
SET @fx_mode := NULL, @fx_manual := NULL, @fx_cushion := NULL, @fx_market := NULL;
SELECT setting_value INTO @fx_mode FROM pricing_settings WHERE setting_key = 'fx_mode' LIMIT 1;
SELECT CAST(setting_value AS DECIMAL(12,4)) INTO @fx_manual FROM pricing_settings WHERE setting_key = 'pricing_fx_rate' LIMIT 1;
SELECT LEAST(GREATEST(CAST(setting_value AS DECIMAL(6,3)), 0), 5) INTO @fx_cushion FROM pricing_settings WHERE setting_key = 'fx_cushion_pct' LIMIT 1;
SELECT market_rate INTO @fx_market FROM fx_rates WHERE status = 'in_use' AND market_rate IS NOT NULL ORDER BY rate_date DESC, id DESC LIMIT 1;
SET @fx_now := IF(COALESCE(@fx_mode, 'auto') = 'manual' OR @fx_market IS NULL,
  COALESCE(@fx_manual, 82.0000),
  ROUND(@fx_market * (1 - COALESCE(@fx_cushion, 2.0) / 100), 4));

-- 1. Orders with an approved Proforma Invoice: fixed by the first one.
UPDATE orders o
  JOIN (
    SELECT d.order_id, MIN(d.id) AS document_id
      FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
     WHERE dt.code = 'PI' AND d.status IN ('approved', 'sent', 'superseded') AND d.order_id IS NOT NULL
     GROUP BY d.order_id
  ) q ON q.order_id = o.id
  JOIN documents pd ON pd.id = q.document_id
   SET o.prices_fixed_at = COALESCE(
         (SELECT MAX(r.reviewed_at) FROM document_reviews r WHERE r.document_id = q.document_id AND r.status = 'approved'),
         pd.generated_at),
       o.prices_fixed_by_document_id = q.document_id,
       o.pricing_fx_rate_at_qt = o.pricing_fx_rate_at_pi
 WHERE o.prices_fixed_at IS NULL;

-- 2. No approved PI, but an approved later document (Order Confirmation, Buyer PO, Commercial Invoice): the first one.
UPDATE orders o
  JOIN (
    SELECT d.order_id, MIN(d.id) AS document_id
      FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
     WHERE dt.code IN ('OC', 'BUYERPO', 'CI') AND d.status IN ('approved', 'sent', 'superseded') AND d.order_id IS NOT NULL
     GROUP BY d.order_id
  ) q ON q.order_id = o.id
  JOIN documents pd ON pd.id = q.document_id
   SET o.prices_fixed_at = COALESCE(
         (SELECT MAX(r.reviewed_at) FROM document_reviews r WHERE r.document_id = q.document_id AND r.status = 'approved'),
         pd.generated_at),
       o.prices_fixed_by_document_id = q.document_id,
       o.pricing_fx_rate_at_qt = o.pricing_fx_rate_at_pi
 WHERE o.prices_fixed_at IS NULL;

-- 3. No rate kept at the PI: the pricing rate in use on the day it was fixed (rate history minus the
--    cushion); Manual mode or no history on or before that day: the rate in use now.
UPDATE orders o
   SET o.pricing_fx_rate_at_qt = COALESCE(
         IF(COALESCE(@fx_mode, 'auto') = 'manual', NULL,
            (SELECT ROUND(f.market_rate * (1 - COALESCE(@fx_cushion, 2.0) / 100), 4)
               FROM fx_rates f
              WHERE f.status = 'in_use' AND f.market_rate IS NOT NULL AND f.rate_date <= DATE(o.prices_fixed_at)
              ORDER BY f.rate_date DESC, f.id DESC LIMIT 1)),
         @fx_now)
 WHERE o.prices_fixed_at IS NOT NULL AND o.pricing_fx_rate_at_qt IS NULL;

-- 4. Each active line of an order fixed by a PI (or later document) keeps its price now.
UPDATE order_products op
  JOIN orders o ON o.id = op.order_id
  JOIN documents fd ON fd.id = o.prices_fixed_by_document_id
  JOIN document_types fdt ON fdt.id = fd.document_type_id
   SET op.qt_fixed_unit_price = op.unit_price
 WHERE o.prices_fixed_at IS NOT NULL AND fdt.code <> 'QT'
   AND op.is_active = 1 AND op.qt_fixed_unit_price IS NULL AND op.unit_price IS NOT NULL;

SELECT CONCAT('2026-11-13g done: ', COUNT(*), ' order(s) fixed by a Proforma Invoice (or later document); ',
              (SELECT COUNT(*) FROM orders o2 WHERE o2.prices_fixed_at IS NULL AND EXISTS (
                 SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
                  WHERE d.order_id = o2.id AND dt.code IN ('PI', 'OC', 'BUYERPO', 'CI') AND d.status IN ('approved', 'sent', 'superseded'))),
              ' still to fix (must be 0).') AS result
  FROM orders o
  JOIN documents fd ON fd.id = o.prices_fixed_by_document_id
  JOIN document_types fdt ON fdt.id = fd.document_type_id
 WHERE o.prices_fixed_at IS NOT NULL AND fdt.code <> 'QT';
