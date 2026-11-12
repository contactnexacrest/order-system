<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderAnnexureTermsRepository;
use App\Repositories\OrderRepository;

/**
 * Owner decision D-336 (12 Nov 2026, owner confirmed twice): an order has two
 * annexures — Annexure A (as quoted: printed with the Quotation, Buyer PO, PI and
 * OC, exactly as before) and Annexure B (as shipped: the same screen and content,
 * printed only with the Commercial Invoice and Packing List). orders.ci_annexure
 * chooses which one the CI and PL carry (A / B / NONE); NULL means the default —
 * Annexure B when it is included and filled in, else Annexure A when it is
 * included and filled in, else none.
 *
 * An order line ticked "As per annexure" (order_products.as_per_annexure) prints
 * "Dimensions: As per Annexure X" and "<qty> (as per Annexure X)"; X is A on the
 * QT / Buyer PO / PI / OC and the chosen annexure on the CI / PL. No such document
 * is generated while that annexure has no product entry (blockReason()).
 *
 * Twin of Node src/services/annexureChoice.js — same rules, same wording.
 */
final class AnnexureChoice
{
    /** Documents that print Annexure A (and say "As per Annexure A" on a ticked line). */
    public const QUOTED_DOCUMENTS = ['QT', 'BUYERPO', 'PI', 'OC'];

    /** Documents that print the annexure chosen for the Commercial Invoice. */
    public const SHIPPED_DOCUMENTS = ['CI', 'PL'];

    public const CHOICES = ['A', 'B', 'NONE'];

    /** The order's content mode for that annexure (SPEC / TERMS / BOTH). */
    public static function mode(array $order, string $which): string
    {
        $mode = (string) ($which === 'B' ? ($order['annexure_b_mode'] ?? 'SPEC') : ($order['annexure_mode'] ?? 'SPEC'));
        return in_array($mode, ['SPEC', 'TERMS', 'BOTH'], true) ? $mode : 'SPEC';
    }

    public static function included(array $order, string $which): bool
    {
        return !empty($which === 'B' ? ($order['include_annexure_b'] ?? 0) : ($order['include_annexure_a'] ?? 0));
    }

    /** The annexure's Additional Terms, sanitized ('' when none). */
    public static function termsHtml(int $orderId, string $which): string
    {
        $row = OrderAnnexureTermsRepository::find($orderId, $which);
        return $row && $row['content_html'] ? AnnexureTermsSanitizer::sanitize((string) $row['content_html']) : '';
    }

    /** Same rule as the documents' annexure_has_content: something to print in the chosen mode. */
    public static function hasContent(array $order, string $which): bool
    {
        $orderId = (int) $order['id'];
        $mode = self::mode($order, $which);
        $showSpec = $mode !== 'TERMS';
        $showTerms = $mode !== 'SPEC';
        return ($showSpec && OrderAnnexureRepository::countFor($orderId, $which) > 0)
            || ($showTerms && self::termsHtml($orderId, $which) !== '');
    }

    /** The default for the Commercial Invoice / Packing List when nothing was chosen. */
    public static function ciDefault(array $order): string
    {
        foreach (['B', 'A'] as $which) {
            if (self::included($order, $which) && self::hasContent($order, $which)) {
                return $which;
            }
        }
        return 'NONE';
    }

    /** What the CI / PL print: the saved choice, else the default. */
    public static function ciChoice(array $order): string
    {
        $saved = (string) ($order['ci_annexure'] ?? '');
        return in_array($saved, self::CHOICES, true) ? $saved : self::ciDefault($order);
    }

    /** The annexure a document prints: 'A' / 'B', or null for none (or a document without one). */
    public static function forDocument(array $order, string $documentTypeCode): ?string
    {
        $code = strtoupper($documentTypeCode);
        if (in_array($code, self::SHIPPED_DOCUMENTS, true)) {
            $choice = self::ciChoice($order);
            return $choice === 'NONE' ? null : $choice;
        }
        if (in_array($code, self::QUOTED_DOCUMENTS, true) || $code === 'ANNEXA') {
            return 'A';
        }
        return null;
    }

    /** The letter the "As per annexure" wording uses on that document (A when none is printed). */
    public static function letterFor(array $order, string $documentTypeCode): string
    {
        return self::forDocument($order, $documentTypeCode) ?? 'A';
    }

    /** "8.801 (as per Annexure A)". */
    public static function quantityText(string $formattedQuantity, string $letter): string
    {
        return $formattedQuantity . ' (as per Annexure ' . $letter . ')';
    }

    /** "As per Annexure A". */
    public static function dimensionsText(string $letter): string
    {
        return 'As per Annexure ' . $letter;
    }

    /** @return array<int, int> line numbers of the order's lines ticked "As per annexure" */
    public static function annexureLineNumbers(int $orderId, bool $goodsOnly = false): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT line_no FROM order_products WHERE order_id = :o AND is_active = 1 AND as_per_annexure = 1'
            . ($goodsOnly ? " AND COALESCE(line_type, 'goods') <> 'service'" : '')
            . ' ORDER BY line_no'
        );
        $stmt->execute(['o' => $orderId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Why the document cannot be generated yet (null = it can): a line says "As per
     * annexure" but the annexure that document prints has no product entry.
     */
    public static function blockReason(int $orderId, string $documentTypeCode, ?string $ciChoiceOverride = null): ?string
    {
        $code = strtoupper($documentTypeCode);
        $quoted = in_array($code, self::QUOTED_DOCUMENTS, true);
        $shipped = in_array($code, self::SHIPPED_DOCUMENTS, true);
        if (!$quoted && !$shipped) {
            return null;
        }
        $lines = self::annexureLineNumbers($orderId, $shipped);
        if ($lines === []) {
            return null;
        }
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return null;
        }
        $line = $lines[0];
        if ($quoted) {
            if (self::included($order, 'A') && OrderAnnexureRepository::countFor($orderId, 'A') > 0) {
                return null;
            }
            return "Line {$line} says \"As per annexure\" but Annexure A has no product yet — add it in Annexure A (order page › Annexure A), then generate again.";
        }
        $choice = $ciChoiceOverride ?? self::ciChoice($order);
        if ($choice === 'NONE') {
            return "Line {$line} says \"As per annexure\" but \"No annexure\" is chosen for the Commercial Invoice and Packing List — choose Annexure B (as shipped) or Annexure A (as quoted), then generate again.";
        }
        if (OrderAnnexureRepository::countFor($orderId, $choice) > 0) {
            return null;
        }
        return $choice === 'B'
            ? "Line {$line} says \"As per annexure\" but Annexure B (as shipped) has no product yet — add it, or choose Annexure A, then generate again."
            : "Line {$line} says \"As per annexure\" but Annexure A (as quoted) has no product yet — add it, or choose Annexure B, then generate again.";
    }

    /**
     * Owner decision D-338 (1a): a reorder request line's "As per annexure" tick box —
     * never together with the quantity-not-decided tick box ($tbcLabel names it as the
     * form shows it), and the quantity stays required. Null when the line is fine.
     */
    public static function reorderLineError(int $lineNo, bool $perAnnexure, bool $quantityIsTbc, string $quantity, string $tbcLabel): ?string
    {
        if (!$perAnnexure) {
            return null;
        }
        if ($quantityIsTbc) {
            return "Line {$lineNo}: tick either \"{$tbcLabel}\" or \"As per annexure\", not both.";
        }
        if (trim($quantity) === '') {
            return "Line {$lineNo}: a line \"As per annexure\" still needs its quantity — enter the Qty.";
        }
        return null;
    }

    /**
     * Owner decision D-338 (1b): a duplicated (or reordered) order gets a copy of the
     * source order's Annexure A — its product entries (pointing at the same image
     * files, exactly like "Copy everything from Annexure A"), its Additional Terms,
     * whether it is included and its content mode. Annexure B and the choice for the
     * Commercial Invoice are NOT copied (they describe what was shipped on the source
     * order). Returns the number of product entries copied.
     */
    public static function copyAnnexureAToOrder(int $sourceOrderId, int $newOrderId, int $userId): int
    {
        $source = OrderRepository::find($sourceOrderId);
        if ($source === null) {
            return 0;
        }
        $count = OrderAnnexureRepository::copyToOrder($sourceOrderId, $newOrderId, 'A');
        $termsA = OrderAnnexureTermsRepository::find($sourceOrderId, 'A');
        if ($termsA !== null && trim((string) ($termsA['content_html'] ?? '')) !== '') {
            OrderAnnexureTermsRepository::upsert($newOrderId, (string) $termsA['content_html'], $userId, 'A');
        }
        OrderRepository::setAnnexureMode($newOrderId, self::mode($source, 'A'));
        OrderRepository::setIncludeAnnexureA($newOrderId, self::included($source, 'A'));
        return $count;
    }

    /**
     * "Copy everything from Annexure A": Annexure B becomes a copy of A — product
     * entries (same image files), Additional Terms and content mode. Returns the
     * number of product entries copied.
     */
    public static function copyAToB(array $order, int $userId): int
    {
        $orderId = (int) $order['id'];
        $count = OrderAnnexureRepository::replaceWithCopy($orderId, 'A', 'B');
        $termsA = OrderAnnexureTermsRepository::find($orderId, 'A');
        if ($termsA !== null || OrderAnnexureTermsRepository::find($orderId, 'B') !== null) {
            OrderAnnexureTermsRepository::upsert($orderId, (string) ($termsA['content_html'] ?? ''), $userId, 'B');
        }
        OrderRepository::setAnnexureBMode($orderId, self::mode($order, 'A'));
        return $count;
    }

    /** When Annexure B was last saved (from the audit log), or null. */
    public static function lastSaved(int $orderId, string $which): ?string
    {
        $label = 'Annexure ' . $which;
        $ids = array_map(static fn (array $p): int => (int) $p['id'], OrderAnnexureRepository::forOrder($orderId, $which));
        $sql = "SELECT MAX(created_at) FROM audit_log WHERE reason = :r AND ((entity_type IN ('order','orders') AND entity_id = :o)"
            . ($ids !== [] ? " OR (entity_type = 'order_annexure_products' AND entity_id IN (" . implode(',', $ids) . '))' : '')
            . ')';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['r' => $label, 'o' => $orderId]);
        $value = $stmt->fetchColumn();
        return $value ? (string) $value : null;
    }

    /** D-341: the note shown instead of Annexure B before the Commercial Invoice step. */
    public const B_NOT_YET_NOTE = 'Annexure B can be made at the Commercial Invoice step.';

    /**
     * Owner decision D-341: the order has reached the shipping-documents part of the
     * workflow — the Commercial Invoice step (Stage 8) is open or passed. The order
     * page shows the Commercial Invoice step on exactly this condition.
     */
    public static function ciStageReached(int $orderId): bool
    {
        $stage = \App\Repositories\OrderStageRepository::findByOrderAndStageNumber($orderId, 8);
        return $stage !== null && $stage['status'] !== 'locked';
    }

    /** D-341: Annexure B holds something (a product entry or Additional Terms), whatever its content mode. */
    public static function bMade(int $orderId): bool
    {
        return OrderAnnexureRepository::countFor($orderId, 'B') > 0 || self::termsHtml($orderId, 'B') !== '';
    }

    /** D-341: Annexure B can be opened — from the Commercial Invoice step on, or whenever it already has content. */
    public static function bAvailable(int $orderId): bool
    {
        return self::bMade($orderId) || self::ciStageReached($orderId);
    }

    /**
     * D-341: when Annexure A's content (product entries, images, Additional Terms or
     * content mode) was last changed, from the audit log — or null. A removed entry or
     * image is found through its audit row, which names the order (field "order_id").
     */
    public static function lastChangeA(int $orderId): ?string
    {
        $productIds = [];
        $imageIds = [];
        foreach (OrderAnnexureRepository::forOrder($orderId, 'A') as $p) {
            $productIds[] = (int) $p['id'];
            foreach ($p['images'] as $img) {
                $imageIds[] = (int) $img['id'];
            }
        }
        $sql = "SELECT MAX(created_at) FROM audit_log WHERE (reason IS NULL OR reason <> 'Annexure B') AND ("
            . "(entity_type = 'order' AND entity_id = :o AND action_type IN ('ANNEXURE_MODE_UPDATED','ANNEXURE_TERMS_UPDATED'))"
            . " OR (action_type IN ('ANNEXURE_PRODUCT_DELETED','ANNEXURE_IMAGE_REMOVED') AND field_name = 'order_id' AND old_value = :os)"
            . ($productIds !== [] ? " OR (entity_type = 'order_annexure_products' AND entity_id IN (" . implode(',', $productIds) . '))' : '')
            . ($imageIds !== [] ? " OR (entity_type = 'order_annexure_images' AND entity_id IN (" . implode(',', $imageIds) . '))' : '')
            . ')';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['o' => $orderId, 'os' => (string) $orderId]);
        $value = $stmt->fetchColumn();
        return $value ? (string) $value : null;
    }

    /**
     * D-341: the latest Annexure A document is approved or sent and Annexure A was
     * changed after that document was generated — the Annexure A page asks for a new
     * revision. Returns that document, or null.
     */
    public static function staleApprovedAnnexA(int $orderId): ?array
    {
        $doc = \App\Repositories\DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'ANNEXA');
        if ($doc === null || !in_array($doc['status'], ['approved', 'sent'], true)) {
            return null;
        }
        $changed = self::lastChangeA($orderId);
        return $changed !== null && strtotime($changed) > strtotime((string) $doc['generated_at']) ? $doc : null;
    }

    /**
     * The order page's Commercial Invoice step: the three choices, which is selected,
     * which can be chosen, and whether "No annexure" is refused.
     *
     * @return array{choice:string, default:string, a:array{count:int, has:bool}, b:array{count:int, has:bool, saved:?string, made:bool}, lines:array<int,int>}
     */
    public static function ciStep(array $order): array
    {
        $orderId = (int) $order['id'];
        $savedB = self::lastSaved($orderId, 'B');
        return [
            'choice' => self::ciChoice($order),
            'default' => self::ciDefault($order),
            'a' => ['count' => OrderAnnexureRepository::countFor($orderId, 'A'), 'has' => self::hasContent($order, 'A')],
            'b' => ['count' => OrderAnnexureRepository::countFor($orderId, 'B'), 'has' => self::hasContent($order, 'B'),
                'saved' => $savedB !== null ? date('j M', strtotime($savedB)) : null,
                // D-341: "Annexure B not made — Annexure A will be printed." under the radios.
                'made' => self::bMade($orderId)],
            'lines' => self::annexureLineNumbers($orderId, true),
        ];
    }
}
