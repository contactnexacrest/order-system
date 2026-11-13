<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;

/**
 * Owner decision D-342 (12 Nov 2026, owner "confirm 1" and "confirm 2"): once the
 * order's first Quotation is approved, the same exchange rate and the same prices
 * hold for every other document — "not a single change, not even because of the
 * exchange rate". No change until it goes through an amendment.
 *
 *  - fixAtQtApproval(): when the first Quotation is approved the order keeps the
 *    pricing rate in use then (orders.pricing_fx_rate_at_qt; the PI keeps the same
 *    rate), and each line keeps the price the buyer accepted
 *    (order_products.qt_fixed_unit_price) and its price check figures
 *    (price_snapshot, at that rate).
 *  - refusal(): from then on no line is added, changed, copied or removed on the
 *    order page — not with "Override", not by the Super Admin. Only an activated
 *    "Products & prices" amendment changes lines (ProductsAmendmentService).
 *  - documentGuard(): the safety net — PI, OC, Buyer PO and CI are refused while a
 *    goods line is priced above the fixed price, or is not in the approved
 *    Quotation (e.g. data changed by a script).
 *
 * D-370 (owner "go", 13 Nov 2026; QA HNT-L05): when no Quotation was approved before it,
 * the first approved Proforma Invoice fixes them instead — the same rate, the same line
 * prices, the same lock (fixAtPiApproval()). Whichever of the two is approved first.
 *
 * Node twin: src/services/priceLock.js.
 */
final class PriceLock
{
    /** The documents the guard checks. */
    public const GUARDED_DOCUMENTS = ['PI' => 'the Proforma Invoice', 'OC' => 'the Order Confirmation', 'BUYERPO' => 'the Buyer PO', 'CI' => 'the Commercial Invoice'];

    public const AMENDMENT_PATH = 'Order page › Amendments › Products & prices';

    /** D-370: the document that fixed the prices, as named on screen. */
    public const FIXED_BY_LABELS = ['QT' => 'Quotation', 'PI' => 'Proforma Invoice', 'OC' => 'Order Confirmation', 'BUYERPO' => 'Buyer PO', 'CI' => 'Commercial Invoice'];

    /** The SQL (correlated on o.prices_fixed_by_document_id) giving the type code of the document that fixed the prices. */
    public const FIXED_BY_TYPE_SQL = '(SELECT dt_pl.code FROM documents d_pl JOIN document_types dt_pl ON dt_pl.id = d_pl.document_type_id WHERE d_pl.id = o.prices_fixed_by_document_id)';

    public static function isFixed(array $order): bool
    {
        return !empty($order['prices_fixed_at']);
    }

    /** The pricing rate (₹ per USD) fixed at the Quotation's approval, or null. */
    public static function fixedRate(array $order): ?float
    {
        $v = $order['pricing_fx_rate_at_qt'] ?? null;
        return self::isFixed($order) && $v !== null && is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    /** "12 Nov 2026" — the day the Quotation fixed the prices. */
    public static function fixedDate(array $order): string
    {
        $t = strtotime((string) ($order['prices_fixed_at'] ?? ''));
        return $t === false ? '' : date('j M Y', $t);
    }

    /**
     * D-370: "Quotation" or "Proforma Invoice" — the document that fixed the prices.
     * Orders read through OrderRepository::find() carry prices_fixed_by_type.
     */
    public static function fixedByLabel(array $order): string
    {
        return self::FIXED_BY_LABELS[(string) ($order['prices_fixed_by_type'] ?? '')] ?? 'Quotation';
    }

    public static function message(array $order): string
    {
        return 'Prices and products are fixed by the approved ' . self::fixedByLabel($order) . ' of ' . self::fixedDate($order)
            . ' — change them through an Amendment (' . self::AMENDMENT_PATH . ').';
    }

    /** Why the order's lines cannot be changed on the order page, or null while they can. */
    public static function refusal(?array $order): ?string
    {
        return $order !== null && self::isFixed($order) ? self::message($order) : null;
    }

    /**
     * The order's first Quotation is approved: fix the rate, each line's price and its
     * check figures. A second call does nothing. Returns true when this call fixed them.
     */
    public static function fixAtQtApproval(int $orderId, int $documentId): bool
    {
        return self::fixAt($orderId, $documentId, 'QT');
    }

    /**
     * D-370 (owner "go", 13 Nov 2026; QA HNT-L05): the order's first Proforma Invoice is
     * approved and no Quotation fixed the prices before it — the PI fixes them, exactly as
     * the Quotation would have: the rate (the one the PI keeps, pricing_fx_rate_at_pi), each
     * line's price and its check figures, the time and the document (the PI). A second
     * call, or an order a Quotation already fixed, does nothing.
     */
    public static function fixAtPiApproval(int $orderId, int $documentId): bool
    {
        return self::fixAt($orderId, $documentId, 'PI');
    }

    private static function fixAt(int $orderId, int $documentId, string $typeCode): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, prices_fixed_at, pricing_fx_rate_at_pi FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || $row['prices_fixed_at'] !== null) {
            return false;
        }
        // A PI approved before already fixed a rate (D-245): the order keeps the same one.
        $rate = $row['pricing_fx_rate_at_pi'] !== null && (float) $row['pricing_fx_rate_at_pi'] > 0
            ? (float) $row['pricing_fx_rate_at_pi'] : FxRateService::rate();
        $upd = $pdo->prepare(
            'UPDATE orders SET prices_fixed_at = NOW(), prices_fixed_by_document_id = :d, pricing_fx_rate_at_qt = :fx
              WHERE id = :id AND prices_fixed_at IS NULL'
        );
        $upd->execute(['d' => $documentId, 'fx' => number_format($rate, 4, '.', ''), 'id' => $orderId]);
        if ($upd->rowCount() === 0) {
            return false;
        }
        $pdo->prepare('UPDATE order_products SET qt_fixed_unit_price = unit_price WHERE order_id = :o AND is_active = 1 AND unit_price IS NOT NULL')
            ->execute(['o' => $orderId]);
        OrderLinePricing::snapshotLines($orderId);
        AuditLogRepository::log(null, 'PRICES_FIXED_AT_' . $typeCode, 'orders', $orderId, 'pricing_fx_rate_at_qt', null, number_format($rate, 4, '.', ''),
            'Prices and products fixed by the approved ' . self::FIXED_BY_LABELS[$typeCode] . ' (document #' . $documentId . ') at ₹' . number_format($rate, 2) . ' per USD.');
        return true;
    }

    /**
     * Why $typeCode (PI, OC, BUYERPO, CI) cannot be generated: a goods line priced above
     * the price fixed by the approved Quotation — or, D-370, the approved PI that fixed them
     * (or by an activated amendment) — or a goods line that is not in it. Null when it can.
     */
    public static function documentGuard(int $orderId, string $typeCode): ?string
    {
        if (!isset(self::GUARDED_DOCUMENTS[$typeCode])) {
            return null;
        }
        $pdo = Database::connection();
        $o = $pdo->prepare('SELECT o.prices_fixed_at, o.currency_code, ' . self::FIXED_BY_TYPE_SQL . ' AS prices_fixed_by_type FROM orders o WHERE o.id = :id');
        $o->execute(['id' => $orderId]);
        $order = $o->fetch(\PDO::FETCH_ASSOC);
        if (!$order || $order['prices_fixed_at'] === null) {
            return null;
        }
        $doc = self::GUARDED_DOCUMENTS[$typeCode];
        $fixedBy = self::fixedByLabel($order);
        $ccy = (string) ($order['currency_code'] ?? '');
        $lines = $pdo->prepare("SELECT line_no, unit_price, qt_fixed_unit_price FROM order_products
                                 WHERE order_id = :o AND is_active = 1 AND line_type = 'goods' ORDER BY line_no");
        $lines->execute(['o' => $orderId]);
        foreach ($lines->fetchAll(\PDO::FETCH_ASSOC) as $l) {
            $no = (int) $l['line_no'];
            if ($l['qt_fixed_unit_price'] === null) {
                return "Line {$no} is not in the approved {$fixedBy} — {$doc} cannot be generated. Lines are added only through an Amendment (" . self::AMENDMENT_PATH . ').';
            }
            if ($l['unit_price'] !== null && (float) $l['unit_price'] > (float) $l['qt_fixed_unit_price'] + 0.004) {
                return "Line {$no}: {$ccy} " . number_format((float) $l['unit_price'], 2) . ' is higher than ' . $ccy . ' ' . number_format((float) $l['qt_fixed_unit_price'], 2)
                    . ", the price the buyer accepted in the approved {$fixedBy} — {$doc} cannot be generated. Prices change only through an Amendment (" . self::AMENDMENT_PATH . ').';
            }
        }
        return null;
    }
}
