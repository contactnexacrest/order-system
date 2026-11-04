<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;

/**
 * Owner decision D-245, phase P2 (4 Nov 2026): prices on order lines.
 *
 * A goods line picked from Products is checked against the floor of whoever
 * sells it: the staff floor, or — when the order's client has an associate
 * (and the order is one the associate earns on) — that associate's floor at
 * their commission level. Below the floor the line needs the MD's approval,
 * which may go down to the minimum price and never below it; until then the
 * Proforma Invoice cannot be issued. When the PI is issued the figures are
 * fixed on the line (price_snapshot), so later changes to the settings or the
 * supplier price do not move that order's check. An approval holds only while
 * the line keeps the approved price.
 *
 * Lines typed by hand (not picked from Products), service and charge lines,
 * lines without a price yet (To Be Confirmed) and orders not in USD are not
 * checked — prices are built in USD per unit (FOB).
 *
 * Node twin: src/services/orderLinePricing.js.
 */
final class OrderLinePricing
{
    public const OK = 'ok';
    public const BELOW = 'below';
    public const BELOW_MINIMUM = 'below_minimum';
    public const NO_PRICE = 'no_price';
    public const APPROVED = 'approved';
    public const UNCHECKED = 'unchecked';

    /** Statuses that keep the Proforma Invoice back. */
    public const BLOCKING = [self::BELOW, self::BELOW_MINIMUM, self::NO_PRICE];

    /**
     * Who sells this order: the client's associate (when the order is one the
     * associate earns on) at their commission level, else staff.
     *
     * @return array{associate: ?array, level: ?array, key: string, label: string, ladder_count: ?int, usd: bool}
     */
    public static function context(array $order): array
    {
        $ctx = ['associate' => null, 'level' => null, 'key' => 'staff', 'label' => 'Staff', 'ladder_count' => null,
            'usd' => strtoupper((string) ($order['currency_code'] ?? '')) === 'USD'];
        $client = !empty($order['client_id']) ? ClientRepository::find((int) $order['client_id']) : null;
        if ($client === null || empty($client['associate_id'])) {
            return $ctx;
        }
        $associate = AssociateRepository::find((int) $client['associate_id']);
        if ($associate === null || !self::associateEarnsOn($order)) {
            return $ctx;
        }
        $orderId = isset($order['id']) ? (int) $order['id'] : null;
        [$level, $count] = self::levelForAssociate($associate, $orderId);
        $ctx['associate'] = $associate;
        $ctx['ladder_count'] = $count;
        if ($level !== null) {
            $ctx['level'] = $level;
            $ctx['key'] = 'L' . (int) $level['id'];
            $ctx['label'] = (string) $associate['name'] . ' (' . PricingService::levelLabel($level) . ')';
        }
        return $ctx;
    }

    /** The order is one the client's associate earns on: forced "yes", or within the first-order window and not forced "no". */
    private static function associateEarnsOn(array $order): bool
    {
        $mode = 'auto';
        if (!empty($order['id'])) {
            $mode = (string) (IncentiveRepository::forOrder((int) $order['id'])['associate_mode'] ?? 'auto');
        }
        if ($mode === 'no') {
            return false;
        }
        if ($mode === 'yes' || empty($order['id'])) {
            return true;
        }
        return IncentiveService::withinFirstTenDays($order);
    }

    /**
     * The associate's commission level: their fixed level, or the ladder step
     * for their next order.
     *
     * @return array{0: ?array, 1: ?int} [level, previous orders counted (ladder only)]
     */
    public static function levelForAssociate(array $associate, ?int $excludeOrderId): array
    {
        if (($associate['commission_mode'] ?? 'ladder') === 'fixed') {
            $level = !empty($associate['commission_level_id']) ? PricingRepository::findLevel((int) $associate['commission_level_id']) : null;
            return [$level, null];
        }
        $count = self::associatePiOrderCount((int) $associate['id'], $excludeOrderId);
        $next = $count + 1;
        foreach (PricingRepository::ladder() as $step) {
            if ($next >= (int) $step['from_order'] && ($step['to_order'] === null || $next <= (int) $step['to_order'])) {
                return [PricingRepository::findLevel((int) $step['level_id']), $count];
            }
        }
        return [null, $count];
    }

    /**
     * How many of the associate's earlier orders already had their Proforma
     * Invoice issued. Phase P2 stand-in for "counting orders": phase P3
     * replaces it with the counting-order rule (invoiced FOB ≥ ₹5 lakh on
     * in-range lines, fully paid).
     */
    public static function associatePiOrderCount(int $associateId, ?int $excludeOrderId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM orders o
             WHERE o.associate_id = :a AND o.id <> :x AND o.is_test_data = 0 AND o.is_sample_data = 0
               AND o.status NOT IN ('cancelled','lost')
               AND EXISTS (SELECT 1 FROM documents d JOIN document_types t ON t.id = d.document_type_id
                           WHERE d.order_id = o.id AND t.code = 'PI')"
        );
        $stmt->execute(['a' => $associateId, 'x' => $excludeOrderId ?? 0]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Live figures for a product in this order's context, or ['missing' => reason].
     *
     * @return array<string,mixed>
     */
    public static function liveFigures(array $product, array $ctx): array
    {
        $p = PricingService::forProduct($product, []);
        if ($p['build'] === null) {
            return ['missing' => (string) $p['missing']];
        }
        $b = $p['build'];
        $floor = $b['staff_floor'];
        $quote = $b['staff_quote'];
        if ($ctx['level'] !== null) {
            $a = PricingEngine::associate($b['raw'], (string) $ctx['level']['kind'], (float) $ctx['level']['value']);
            $floor = $a['floor'];
            $quote = $a['quote'];
        }
        return [
            'cost' => $b['cost'], 'minimum' => $b['minimum'], 'reserve' => $b['reserve'],
            'staff_floor' => $b['staff_floor'], 'staff_quote' => $b['staff_quote'],
            'floor' => $floor, 'quote' => $quote,
            'sold_by' => $ctx['key'], 'sold_by_label' => $ctx['label'],
            'associate_id' => $ctx['associate'] !== null ? (int) $ctx['associate']['id'] : null,
            'level_id' => $ctx['level'] !== null ? (int) $ctx['level']['id'] : null,
            'level_name' => $ctx['level'] !== null ? (string) $ctx['level']['name'] : null,
            'level_kind' => $ctx['level'] !== null ? (string) $ctx['level']['kind'] : null,
            'level_value' => $ctx['level'] !== null ? (float) $ctx['level']['value'] : null,
            'margin_pct' => $p['effective']['margin_pct'], 'buffer_pct' => $p['effective']['buffer_pct'],
            'other_mode' => $p['effective']['other_mode'], 'other_value' => $p['effective']['other_value'],
            'incentive_mode' => $p['effective']['incentive_mode'], 'incentive_value' => $p['effective']['incentive_value'],
            'program_on' => $p['program_on'], 'fx_rate' => $p['settings']['pricing_fx_rate'],
            'supplier_inr' => $p['supplier_inr'] ?? null,
            'supplier_id' => isset($p['supplier']['id']) ? (int) $p['supplier']['id'] : null,
        ];
    }

    /** Whether a line is in scope of the check at all, with the reason when not. */
    public static function scope(array $line, array $ctx): ?string
    {
        if (($line['line_type'] ?? 'goods') !== 'goods') {
            return 'Service and charge lines are not price-checked.';
        }
        if (empty($line['catalog_product_id'])) {
            return 'Typed by hand — pick it from Products for a price check.';
        }
        if (!$ctx['usd']) {
            return 'Prices are built in USD — no price check on orders in another currency.';
        }
        if (($line['unit_price'] ?? null) === null || $line['unit_price'] === '') {
            return 'No price yet.';
        }
        return null;
    }

    public static function approvalHolds(array $line): bool
    {
        return !empty($line['price_approved_at']) && $line['price_approved_unit_price'] !== null
            && $line['unit_price'] !== null && abs((float) $line['unit_price'] - (float) $line['price_approved_unit_price']) < 0.005;
    }

    /**
     * The check of one line.
     *
     * @return array{status: string, figures: ?array, fixed: bool, note: ?string, approval: ?array, requested: bool}
     */
    public static function checkLine(array $line, array $ctx, ?array $product = null): array
    {
        $out = ['status' => self::UNCHECKED, 'figures' => null, 'fixed' => false, 'note' => null, 'approval' => null,
            'requested' => !empty($line['price_approval_requested_at'])];
        if (($reason = self::scope($line, $ctx)) !== null) {
            $out['note'] = $reason;
            return $out;
        }
        $snapshot = !empty($line['price_snapshot']) ? json_decode((string) $line['price_snapshot'], true) : null;
        if (is_array($snapshot)) {
            $figures = $snapshot;
            $out['fixed'] = true;
        } else {
            $product ??= ProductRepository::find((int) $line['catalog_product_id']);
            $figures = $product !== null ? self::liveFigures($product, $ctx) : ['missing' => 'The product is no longer in Products.'];
        }
        if (self::approvalHolds($line)) {
            $out['approval'] = [
                'by' => (int) $line['price_approved_by'], 'at' => (string) $line['price_approved_at'],
                'reason' => (string) $line['price_approval_reason'],
                'commission_zero' => (int) $line['price_approval_commission_zero'] === 1,
                'incentive_cut' => $line['price_approval_incentive_cut'] !== null ? (float) $line['price_approval_incentive_cut'] : null,
            ];
        }
        if (isset($figures['missing'])) {
            $out['note'] = 'No price set — needs MD approval. ' . $figures['missing'];
            $out['status'] = $out['approval'] !== null ? self::APPROVED : self::NO_PRICE;
            return $out;
        }
        $out['figures'] = $figures;
        $price = (float) $line['unit_price'];
        if ($out['approval'] !== null) {
            $out['status'] = self::APPROVED;
        } elseif ($price < (float) $figures['minimum'] - 0.0001) {
            $out['status'] = self::BELOW_MINIMUM;
        } elseif ($price < (float) $figures['floor'] - 0.0001) {
            $out['status'] = self::BELOW;
        } else {
            $out['status'] = self::OK;
        }
        return $out;
    }

    /**
     * Every line of the order with its check, and the lines keeping the PI back.
     *
     * @return array{ctx: array, lines: array<int, array>, blocking: list<array>}
     */
    public static function forOrder(int $orderId): array
    {
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return ['ctx' => [], 'lines' => [], 'blocking' => []];
        }
        $ctx = self::context($order);
        $out = ['ctx' => $ctx, 'lines' => [], 'blocking' => []];
        foreach (OrderProductRepository::forOrder($orderId) as $line) {
            $check = self::checkLine($line, $ctx);
            $check['line'] = $line;
            $out['lines'][(int) $line['id']] = $check;
            if (in_array($check['status'], self::BLOCKING, true)) {
                $out['blocking'][] = $check;
            }
        }
        return $out;
    }

    /** Why the PI cannot be issued yet, or null when it can. */
    public static function piBlockReason(int $orderId): ?string
    {
        return self::documentBlockReason($orderId, 'PI');
    }

    /**
     * Owner decision D-245 P2: why the Quotation (QT) or the Proforma Invoice (PI)
     * cannot be generated yet — a line below its lowest price, below the minimum
     * price, or with no price set, not approved by the MD — or null when it can.
     */
    public static function documentBlockReason(int $orderId, string $typeCode): ?string
    {
        $first = self::forOrder($orderId)['blocking'][0] ?? null;
        if ($first === null) {
            return null;
        }
        $doc = $typeCode === 'QT' ? 'the Quotation' : 'the Proforma Invoice';
        $no = (int) $first['line']['line_no'];
        return match ($first['status']) {
            self::NO_PRICE => "Line {$no} has no price set for its product — the MD must approve it before {$doc} (order page → Products).",
            self::BELOW_MINIMUM => "Line {$no} is below the price NexaCrest can accept — raise it before {$doc} (order page → Products).",
            default => "Line {$no} is below the lowest price for this order — raise it, or ask the MD to approve it, before {$doc} (order page → Products).",
        };
    }

    /** Owner decision D-245 P2: whether goods lines on this currency must be picked from Products (Admin › Pricing). */
    public static function productPickRequired(?string $currencyCode): bool
    {
        return strtoupper((string) $currencyCode) === 'USD' && PricingService::settings()['require_product_pick'];
    }

    /** The refusal for a goods line typed by hand when picking from Products is required, else null. */
    public static function pickRefusal(?string $currencyCode, ?int $catalogProductId, string $lineType): ?string
    {
        if ($lineType !== 'goods' || $catalogProductId !== null || !self::productPickRequired($currencyCode)) {
            return null;
        }
        return self::PICK_MESSAGE;
    }

    public const PICK_MESSAGE = 'Pick this goods line from Products — on a USD order goods lines cannot be typed by hand (service and charge lines can). The MD can change this in Admin → Pricing.';

    /**
     * Refuses a price below the product's minimum price before it is saved
     * (nobody, not even the MD, goes below it). Null when the price may be saved.
     */
    public static function saveRefusal(array $order, ?int $catalogProductId, string $lineType, ?string $unitPrice, bool $seesBuildup): ?string
    {
        if ($catalogProductId === null || $lineType !== 'goods' || $unitPrice === null || $unitPrice === '') {
            return null;
        }
        $ctx = self::context($order);
        if (!$ctx['usd']) {
            return null;
        }
        $product = ProductRepository::find($catalogProductId);
        if ($product === null) {
            return null;
        }
        $f = self::liveFigures($product, $ctx);
        if (isset($f['missing']) || (float) $unitPrice >= (float) $f['minimum'] - 0.0001) {
            return null;
        }
        return 'This price is below what NexaCrest can accept for ' . $product['name'] . ' — raise it.'
            . ($seesBuildup ? ' Minimum price: USD ' . number_format((float) $f['minimum'], 2) . '; to go lower, change the product\'s margin (Products → pricing).' : '');
    }

    /**
     * After a line is saved: a changed price voids its approval; a changed product
     * clears its fixed figures; a line now below its floor (or without a price for
     * its product) is sent to the MD for approval.
     */
    public static function afterSave(int $lineId, ?array $before, int $userId): void
    {
        $line = OrderProductRepository::find($lineId);
        if ($line === null) {
            return;
        }
        $pdo = Database::connection();
        $orderId = (int) $line['order_id'];
        if ($before !== null && (int) ($before['catalog_product_id'] ?? 0) !== (int) ($line['catalog_product_id'] ?? 0) && !empty($line['price_snapshot'])) {
            $pdo->prepare('UPDATE order_products SET price_snapshot = NULL WHERE id = :id')->execute(['id' => $lineId]);
            $line['price_snapshot'] = null;
        }
        if (!empty($line['price_approved_at']) && !self::approvalHolds($line)) {
            $pdo->prepare('UPDATE order_products SET price_approved_by = NULL, price_approved_at = NULL, price_approval_reason = NULL, price_approved_unit_price = NULL,
                                  price_approval_commission_zero = 0, price_approval_incentive_cut = NULL WHERE id = :id')->execute(['id' => $lineId]);
            AuditLogRepository::log($userId, 'LINE_PRICE_APPROVAL_VOIDED', 'order_products', $lineId, 'unit_price',
                number_format((float) $line['price_approved_unit_price'], 2, '.', ''), (string) $line['unit_price'],
                "Order line {$line['line_no']}: the price changed after the MD approved it — the approval no longer holds.");
            $line = OrderProductRepository::find($lineId) ?? $line;
        }
        $order = OrderRepository::find($orderId);
        $check = self::checkLine($line, self::context($order ?? []));
        if (in_array($check['status'], self::BLOCKING, true)) {
            self::requestApproval($orderId, $lineId, $userId, true);
        } elseif (!empty($line['price_approval_requested_at'])) {
            $pdo->prepare('UPDATE order_products SET price_approval_requested_at = NULL, price_approval_requested_by = NULL WHERE id = :id')->execute(['id' => $lineId]);
        }
    }

    /**
     * Sends a line to the MD for approval (in-app notification to everyone who
     * manages pricing). Null on success, else the reason it was not sent.
     */
    public static function requestApproval(int $orderId, int $lineId, int $userId, bool $automatic = false): ?string
    {
        $line = OrderProductRepository::find($lineId);
        $order = OrderRepository::find($orderId);
        if ($line === null || $order === null || (int) $line['order_id'] !== $orderId) {
            return 'Order line not found.';
        }
        $check = self::checkLine($line, self::context($order));
        if (!in_array($check['status'], [self::BELOW, self::NO_PRICE], true)) {
            return $check['status'] === self::BELOW_MINIMUM
                ? 'This price is below what NexaCrest can accept — raise it; the MD cannot approve it either.'
                : 'Line ' . (int) $line['line_no'] . ' does not need an approval.';
        }
        if (!empty($line['price_approval_requested_at']) && $automatic) {
            return null;
        }
        Database::connection()->prepare('UPDATE order_products SET price_approval_requested_at = NOW(), price_approval_requested_by = :u WHERE id = :id')
            ->execute(['u' => $userId, 'id' => $lineId]);
        $ref = (string) ($order['order_reference'] ?? "#{$orderId}");
        $message = "Order {$ref}, line {$line['line_no']} ({$line['description']}) needs your price approval: "
            . ($check['status'] === self::NO_PRICE ? 'its product has no price set.' : 'it is below the lowest price for this order.');
        foreach (PermissionRepository::usersWithPermission('manage_pricing') as $mdId) {
            NotificationRepository::createInAppOnly((int) $mdId, 'line_price_approval', $orderId, mb_substr($message, 0, 500));
        }
        AuditLogRepository::log($userId, 'LINE_PRICE_APPROVAL_REQUESTED', 'order_products', $lineId, null, null, $message);
        return null;
    }

    /**
     * What approving this line would mean, for the MD's approval screen.
     *
     * @return array<string,mixed>
     */
    public static function approvalView(int $orderId, int $lineId): ?array
    {
        $order = OrderRepository::find($orderId);
        $line = OrderProductRepository::find($lineId);
        if ($order === null || $line === null || (int) $line['order_id'] !== $orderId) {
            return null;
        }
        $ctx = self::context($order);
        $check = self::checkLine($line, $ctx);
        $view = ['order' => $order, 'line' => $line, 'ctx' => $ctx, 'check' => $check, 'effect' => null];
        $f = $check['figures'];
        if ($f !== null) {
            $price = (float) $line['unit_price'];
            $qty = $line['quantity'] !== null ? (float) $line['quantity'] : null;
            $reservePerUnit = (float) $f['reserve'];
            $cutPerUnit = min($reservePerUnit, max(0.0, (float) $f['staff_floor'] - $price));
            $view['effect'] = [
                'margin_pct' => $price > 0 ? round(($price - (float) $f['cost']) / $price * 100, 1) : null,
                'short_per_unit' => round(max(0.0, (float) $f['floor'] - $price), 2),
                'reserve_per_unit' => $reservePerUnit,
                'cut_per_unit' => round($cutPerUnit, 2),
                'qty' => $qty,
                'reserve_line' => $qty !== null ? round($reservePerUnit * $qty, 2) : null,
                'after_line' => $qty !== null ? round(($reservePerUnit - $cutPerUnit) * $qty, 2) : null,
                'cut_line' => $qty !== null ? round($cutPerUnit * $qty, 2) : null,
                'commission_zero' => $ctx['associate'] !== null,
            ];
        }
        return $view;
    }

    /** The MD approves a line's price. Null on success, else the refusal text. */
    public static function approve(int $orderId, int $lineId, int $userId, string $reason): ?string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return 'Give a reason of at least 10 characters for approving this price.';
        }
        if (mb_strlen($reason) > 500) {
            return 'Keep the reason under 500 characters.';
        }
        $view = self::approvalView($orderId, $lineId);
        if ($view === null) {
            return 'Order line not found.';
        }
        $line = $view['line'];
        $status = $view['check']['status'];
        $no = (int) $line['line_no'];
        if ($status === self::BELOW_MINIMUM) {
            $f = $view['check']['figures'];
            AuditLogRepository::log($userId, 'LINE_PRICE_APPROVAL_REFUSED', 'order_products', $lineId, 'unit_price', null, (string) $line['unit_price'],
                "Order line {$no}: USD " . number_format((float) $line['unit_price'], 2) . ' is below the minimum price USD ' . number_format((float) $f['minimum'], 2) . ' — not approvable.');
            return "Line {$no}: USD " . number_format((float) $line['unit_price'], 2) . ' is below the minimum price USD ' . number_format((float) $f['minimum'], 2)
                . ' — nobody can approve it, not even the MD. To sell lower, change the product\'s margin (Products → pricing; logged).';
        }
        if (!in_array($status, [self::BELOW, self::NO_PRICE], true)) {
            return "Line {$no} does not need an approval.";
        }
        if ((int) ($line['price_set_by'] ?? 0) === $userId && !MakerCheckerGuard::selfApprovalAllowed('price_discount', $userId)) {
            return 'You set this price yourself — Admin → Self-approval rules do not let your role approve its own price.';
        }
        $effect = $view['effect'];
        Database::connection()->prepare(
            'UPDATE order_products SET price_approved_by = :u, price_approved_at = NOW(), price_approval_reason = :r, price_approved_unit_price = :p,
                    price_approval_commission_zero = :cz, price_approval_incentive_cut = :ic, price_approval_requested_at = NULL, price_approval_requested_by = NULL
             WHERE id = :id'
        )->execute([
            'u' => $userId, 'r' => $reason, 'p' => number_format((float) $line['unit_price'], 2, '.', ''),
            'cz' => ($effect['commission_zero'] ?? false) ? 1 : 0,
            'ic' => isset($effect['cut_line']) ? number_format((float) $effect['cut_line'], 2, '.', '') : null,
            'id' => $lineId,
        ]);
        $what = $status === self::NO_PRICE ? 'no price set for its product' : 'USD ' . number_format((float) $line['unit_price'], 2) . ' below the floor USD ' . number_format((float) $view['check']['figures']['floor'], 2);
        AuditLogRepository::log($userId, 'LINE_PRICE_APPROVED', 'order_products', $lineId, 'unit_price', null, (string) $line['unit_price'],
            "Order line {$no}: {$what} — reason: {$reason}");
        if ((int) ($line['price_set_by'] ?? 0) === $userId) {
            AuditLogRepository::log($userId, 'LINE_PRICE_SELF_APPROVED', 'order_products', $lineId, null, null, 'Self-approval allowed by Admin → Self-approval rules (price_discount).');
        }
        return null;
    }

    /** The MD refuses a line's price: the request is closed and whoever asked is told why. Null on success. */
    public static function refuse(int $orderId, int $lineId, int $userId, string $reason): ?string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            return 'Give a reason of at least 10 characters for refusing this price.';
        }
        $view = self::approvalView($orderId, $lineId);
        if ($view === null) {
            return 'Order line not found.';
        }
        $line = $view['line'];
        Database::connection()->prepare('UPDATE order_products SET price_approval_requested_at = NULL, price_approval_requested_by = NULL WHERE id = :id')->execute(['id' => $lineId]);
        $message = 'Order ' . ($view['order']['order_reference'] ?? "#{$orderId}") . ', line ' . (int) $line['line_no'] . ': the MD refused USD '
            . number_format((float) $line['unit_price'], 2) . ' — ' . $reason . ' Raise the price; the Proforma Invoice waits for it.';
        if (!empty($line['price_approval_requested_by']) && (int) $line['price_approval_requested_by'] !== $userId) {
            NotificationRepository::createInAppOnly((int) $line['price_approval_requested_by'], 'line_price_refused', $orderId, mb_substr($message, 0, 500));
        }
        AuditLogRepository::log($userId, 'LINE_PRICE_APPROVAL_REFUSED', 'order_products', $lineId, 'unit_price', null, (string) $line['unit_price'], $message);
        return null;
    }

    /**
     * Fixes the figures on every checked line that has none yet — called when the
     * PI is issued. Lines keep them until their product changes.
     */
    public static function snapshotForPi(int $orderId): void
    {
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return;
        }
        $ctx = self::context($order);
        $stmt = Database::connection()->prepare('UPDATE order_products SET price_snapshot = :s WHERE id = :id AND price_snapshot IS NULL');
        foreach (OrderProductRepository::forOrder($orderId) as $line) {
            if (!empty($line['price_snapshot']) || self::scope($line, $ctx) !== null) {
                continue;
            }
            $product = ProductRepository::find((int) $line['catalog_product_id']);
            if ($product === null) {
                continue;
            }
            $f = self::liveFigures($product, $ctx);
            if (isset($f['missing'])) {
                continue;
            }
            $f['fixed_at'] = date('Y-m-d H:i:s');
            $stmt->execute(['s' => json_encode($f, JSON_PRESERVE_ZERO_FRACTION), 'id' => (int) $line['id']]);
        }
    }

    /**
     * The quote price and floor of every picker product, per seller ("staff" and
     * "L<level id>" for each active level) — what the browser fills in. Only for
     * people who may see selling prices.
     *
     * @return array<int, array<string, array{0: float, 1: float}>>
     */
    public static function pickerPrices(array $pickerProducts): array
    {
        $levels = PricingRepository::levels(true);
        $out = [];
        foreach ($pickerProducts as $pp) {
            $product = ProductRepository::find((int) $pp['id']);
            if ($product === null) {
                continue;
            }
            $p = PricingService::forProduct($product, $levels);
            if ($p['build'] === null) {
                continue;
            }
            $prices = ['staff' => [$p['build']['staff_quote'], $p['build']['staff_floor']]];
            foreach ($p['levels'] as $l) {
                $prices['L' . $l['id']] = [$l['quote'], $l['floor']];
            }
            $out[(int) $pp['id']] = $prices;
        }
        return $out;
    }

    /**
     * For the New Order form: each client's seller key ("staff" or "L<level id>").
     *
     * @return array<int, string>
     */
    public static function clientKeys(array $clients): array
    {
        $byAssociate = [];
        $out = [];
        foreach ($clients as $c) {
            $key = 'staff';
            if (!empty($c['associate_id'])) {
                $aid = (int) $c['associate_id'];
                if (!array_key_exists($aid, $byAssociate)) {
                    $associate = AssociateRepository::find($aid);
                    $byAssociate[$aid] = 'staff';
                    if ($associate !== null) {
                        [$level] = self::levelForAssociate($associate, null);
                        $byAssociate[$aid] = $level !== null ? 'L' . (int) $level['id'] : 'staff';
                    }
                }
                $key = $byAssociate[$aid];
            }
            $out[(int) $c['id']] = $key;
        }
        return $out;
    }
}
