<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;

/**
 * Owner decision 2 Nov 2026 ("reference price yes with approval", D-240).
 *
 * A Products-menu product may carry a reference SELLING price (currency +
 * unit). An order line picked from that product remembers it
 * (order_products.catalog_product_id); its own unit price stays freely
 * editable and is what every document prints. This class works out how far
 * a line is from its reference, the note staff see (only people who may see
 * selling prices, never on a document or in the client portal), and the
 * MD/ED approval a discount at or beyond the warning level needs before the
 * Proforma Invoice can be generated. Node twin: src/services/referencePrice.js.
 */
final class ReferencePrice
{
    public const RULE = 'price_discount';

    /** @return array{enabled: bool, note_pct: float, warning_pct: float} */
    public static function settings(): array
    {
        $enabled = CompanySettingsRepository::get('ref_price_note_enabled');
        $note = CompanySettingsRepository::get('ref_price_note_pct');
        $warning = CompanySettingsRepository::get('ref_price_warning_pct');
        return [
            'enabled' => $enabled === null ? true : (string) $enabled === '1',
            'note_pct' => is_numeric($note) ? max(0.0, (float) $note) : 5.0,
            'warning_pct' => is_numeric($warning) ? max(0.0, (float) $warning) : 10.0,
        ];
    }

    /** "USD 45.00 per SQFT" (unit optional). */
    public static function label(array $product): string
    {
        $text = trim((string) ($product['reference_currency'] ?? '')) . ' ' . number_format((float) ($product['reference_price'] ?? 0), 2);
        $unit = trim((string) ($product['reference_unit'] ?? ''));
        return trim($text) . ($unit !== '' ? ' per ' . $unit : '');
    }

    /**
     * How far a line's price is from its product's reference price, in % of the
     * reference (negative = below). Null when there is nothing to compare: no
     * product, no reference price, no line price, or a different currency.
     *
     * @return array{pct: float, reference: float, price: float, label: string}|null
     */
    public static function lineDifference(array $line, ?array $product, ?string $orderCurrency): ?array
    {
        if ($product === null || $product['reference_price'] === null || (float) $product['reference_price'] <= 0) {
            return null;
        }
        if (($line['unit_price'] ?? null) === null || $line['unit_price'] === '') {
            return null;
        }
        if (strtoupper((string) ($product['reference_currency'] ?? '')) !== strtoupper((string) $orderCurrency)) {
            return null;
        }
        $reference = (float) $product['reference_price'];
        $price = (float) $line['unit_price'];
        return [
            'pct' => round(($price - $reference) / $reference * 100, 1),
            'reference' => $reference,
            'price' => $price,
            'label' => self::label($product),
        ];
    }

    /**
     * The note for one line: null (nothing to show), or
     * ['kind' => 'note'|'warning'|'above', 'text' => …]. Follows the settings.
     *
     * @return array{kind: string, text: string}|null
     */
    public static function note(?array $difference, ?array $settings = null): ?array
    {
        $settings ??= self::settings();
        if ($difference === null || !$settings['enabled']) {
            return null;
        }
        $pct = $difference['pct'];
        if ($pct < 0) {
            $below = -$pct;
            if ($below >= $settings['warning_pct']) {
                return ['kind' => 'warning', 'text' => '⚠ ' . self::pct($below) . '% below reference price (' . $difference['label'] . ')'];
            }
            if ($below >= $settings['note_pct']) {
                return ['kind' => 'note', 'text' => self::pct($below) . '% below reference price (' . $difference['label'] . ')'];
            }
            return null;
        }
        if ($pct > 0) {
            return ['kind' => 'above', 'text' => self::pct($pct) . '% above reference price (' . $difference['label'] . ')'];
        }
        return null;
    }

    public static function pct(float $value): string
    {
        $text = number_format($value, 1);
        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }

    /** @return array<int, array<string,mixed>> catalog products by id, for the given lines */
    public static function productsFor(array $lines): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $l): int => (int) ($l['catalog_product_id'] ?? 0), $lines))));
        if ($ids === []) {
            return [];
        }
        $stmt = Database::connection()->prepare('SELECT * FROM catalog_products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /**
     * The order's active lines at or beyond the warning level.
     *
     * @return list<array{line_id: int, line_no: int, description: string, unit_price: float, reference_price: float, pct: float, label: string, price_set_by: ?int}>
     */
    public static function linesNeedingApproval(int $orderId): array
    {
        $order = \App\Repositories\OrderRepository::find($orderId);
        if ($order === null) {
            return [];
        }
        $lines = \App\Repositories\OrderProductRepository::forOrder($orderId);
        $products = self::productsFor($lines);
        $warning = self::settings()['warning_pct'];
        $out = [];
        foreach ($lines as $line) {
            $diff = self::lineDifference($line, $products[(int) ($line['catalog_product_id'] ?? 0)] ?? null, $order['currency_code'] ?? null);
            if ($diff === null || -$diff['pct'] < $warning) {
                continue;
            }
            $out[] = [
                'line_id' => (int) $line['id'],
                'line_no' => (int) $line['line_no'],
                'description' => (string) $line['description'],
                'unit_price' => $diff['price'],
                'reference_price' => $diff['reference'],
                'pct' => $diff['pct'],
                'label' => $diff['label'],
                'price_set_by' => isset($line['price_set_by']) ? (int) $line['price_set_by'] : null,
            ];
        }
        return $out;
    }

    /** The order's current (not lapsed) approval, or null. */
    public static function currentApproval(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_price_approvals WHERE order_id = :o AND lapsed_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute(['o' => $orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** The lines needing approval that the approval does not cover (not in its snapshot, or now cheaper). */
    private static function uncovered(?array $approval, array $needing): array
    {
        if ($approval === null) {
            return $needing;
        }
        $snapshot = [];
        foreach ((array) json_decode((string) $approval['snapshot'], true) as $s) {
            $snapshot[(int) $s['line_id']] = (float) $s['unit_price'];
        }
        return array_values(array_filter($needing, static fn (array $line): bool =>
            !isset($snapshot[$line['line_id']]) || $line['unit_price'] < $snapshot[$line['line_id']] - 0.0001));
    }

    /** True when every line needing approval is in the approval's snapshot at the same or a higher price. */
    private static function covers(array $approval, array $needing): bool
    {
        return self::uncovered($approval, $needing) === [];
    }

    /**
     * Read-only state for the order page: lines needing approval, the current
     * approval and whether it still covers them (nothing is changed here).
     *
     * @return array{needing: list<array<string,mixed>>, approval: ?array, uncovered: list<array<string,mixed>>, covered: bool}
     */
    public static function status(int $orderId): array
    {
        $needing = self::linesNeedingApproval($orderId);
        $approval = self::currentApproval($orderId);
        $uncovered = $needing === [] ? [] : self::uncovered($approval, $needing);
        return [
            'needing' => $needing,
            'approval' => $approval,
            'uncovered' => $uncovered,
            'covered' => $uncovered === [],
        ];
    }

    /**
     * Why the Proforma Invoice cannot be generated yet, or null when it can.
     * A current approval that no longer covers the lines (a price dropped
     * further, or a new line below the warning level) lapses here, audited.
     */
    public static function piBlockReason(int $orderId, ?int $actorId = null): ?string
    {
        $needing = self::linesNeedingApproval($orderId);
        $approval = self::currentApproval($orderId);
        if ($needing === []) {
            return null;
        }
        if ($approval !== null && self::covers($approval, $needing)) {
            return null;
        }
        if ($approval !== null) {
            Database::connection()->prepare('UPDATE order_price_approvals SET lapsed_at = NOW() WHERE id = :id AND lapsed_at IS NULL')->execute(['id' => (int) $approval['id']]);
            AuditLogRepository::log($actorId ?? (int) $approval['approved_by'], 'PRICE_DISCOUNT_LAPSED', 'orders', $orderId, null, null, 'A line price dropped further, or a new line is below the warning level, after the discount was approved.');
        }
        $first = self::uncovered($approval, $needing)[0] ?? $needing[0];
        return 'Line ' . $first['line_no'] . ' is ' . self::pct(-$first['pct']) . '% below its reference price — an MD/ED must approve the discount first (order page → Price check).';
    }

    /**
     * Approve the order's discount. Returns null on success or the refusal text.
     */
    public static function approve(int $orderId, int $userId, string $reason): ?string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return 'Give a reason of at least 10 characters for approving the discount.';
        }
        if (mb_strlen($reason) > 500) {
            return 'Keep the reason under 500 characters.';
        }
        $needing = self::linesNeedingApproval($orderId);
        if ($needing === []) {
            return 'No line on this order is below its reference price by the warning level — nothing to approve.';
        }
        // The person who set a discounted price approves it only when Admin → Self-approval rules allow their role.
        $makers = array_filter(array_map(static fn (array $l): ?int => $l['price_set_by'], $needing));
        if (in_array($userId, $makers, true) && !MakerCheckerGuard::selfApprovalAllowed(self::RULE, $userId)) {
            return 'You set a discounted price on this order — a different MD/ED must approve the discount.';
        }
        $pdo = Database::connection();
        $pdo->prepare('UPDATE order_price_approvals SET lapsed_at = NOW() WHERE order_id = :o AND lapsed_at IS NULL')->execute(['o' => $orderId]);
        $snapshot = array_map(static fn (array $l): array => [
            'line_id' => $l['line_id'], 'line_no' => $l['line_no'], 'unit_price' => $l['unit_price'],
            'reference_price' => $l['reference_price'], 'pct' => $l['pct'],
        ], $needing);
        $pdo->prepare('INSERT INTO order_price_approvals (order_id, approved_by, reason, snapshot) VALUES (:o, :u, :r, :s)')
            ->execute(['o' => $orderId, 'u' => $userId, 'r' => $reason, 's' => json_encode($snapshot)]);
        $summary = implode('; ', array_map(static fn (array $l): string => 'line ' . $l['line_no'] . ' ' . self::pct(-$l['pct']) . '% below ' . $l['label'], $needing));
        AuditLogRepository::log($userId, 'PRICE_DISCOUNT_APPROVED', 'orders', $orderId, null, null, $summary . ' — reason: ' . $reason);
        if (in_array($userId, $makers, true)) {
            AuditLogRepository::log($userId, 'PRICE_DISCOUNT_SELF_APPROVED', 'orders', $orderId, null, null, 'Self-approval allowed by Admin → Self-approval rules (price_discount).');
        }
        return null;
    }
}
