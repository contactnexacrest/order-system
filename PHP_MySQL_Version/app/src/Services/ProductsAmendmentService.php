<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\ReasonValidator;
use App\Repositories\AmendmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PermissionRepository;

/**
 * Owner decision D-342 (12 Nov 2026, owner "confirm 1" and "confirm 2"): the
 * "Products & prices" amendment — the only way to change an order's lines once its
 * Quotation is approved (PriceLock).
 *
 *  - The draft: each line's pieces / quantity, size and price (a price only goes
 *    DOWN — a higher price is refused: the buyer accepted the Quotation's price),
 *    lines added (a copy of an order line's product with its own size, quantity and
 *    price) and lines removed. Totals old → new and what happens to the advance.
 *  - It then follows the amendment flow (AmendmentService): the MD approves it (the
 *    maker-checker rule "amendment" — a lowered price is approved with it), the AMD
 *    document is generated, the buyer signs, the signed copy is uploaded.
 *  - activate(): the amended lines are applied to the order (changed, added,
 *    removed), each keeps its new fixed price, the payment figures follow (the
 *    advance received stays credited; the difference goes to the balance; more than
 *    the new total shows as excess). The order's stage and the fixed exchange rate do
 *    not change.
 *  - checklist(): what to re-issue afterwards (mockup screen 2).
 *
 * Node twin: src/services/productsAmendmentService.js (same wording).
 */
final class ProductsAmendmentService
{
    /** Up to this many lines can be added in one amendment. */
    public const NEW_LINE_SLOTS = 3;

    public const CHANGE_LABELS = [
        'none' => 'no change', 'quantity' => 'Quantity changed', 'size' => 'Size changed',
        'price' => 'Price lowered (MD)', 'add' => 'Line added', 'remove' => 'Line removed',
    ];

    public const DOC_CHANGE_LABELS = [
        'none' => '—', 'quantity' => 'Quantity', 'size' => 'Size', 'price' => 'Price', 'add' => 'New line', 'remove' => 'Removed',
    ];

    public static function money(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2);
    }

    /** "Line 1: USD 1,950.00 is the price the buyer accepted in the Quotation — a price can only go down, with the MD's approval." */
    public static function priceUpMessage(int $lineNo, string $currency, float $fixed): string
    {
        return "Line {$lineNo}: {$currency} " . number_format($fixed, 2)
            . ' is the price the buyer accepted in the Quotation — a price can only go down, with the MD\'s approval.';
    }

    /** Why a products amendment cannot be raised on this order now, or null. */
    public static function cannotStart(array $order): ?string
    {
        if (!PriceLock::isFixed($order)) {
            return 'This order\'s Quotation is not approved yet — change its lines on the order page (Products).';
        }
        $open = AmendmentRepository::openProductsAmendment((int) $order['id']);
        if ($open !== null) {
            return 'Amendment ' . $open['amendment_reference'] . ' (Products & prices) is still open — finish it or reject it before raising another.';
        }
        return null;
    }

    /** @return list<array<string,mixed>> the order's active lines, as the amendment screen starts from them */
    public static function currentLines(int $orderId): array
    {
        $out = [];
        foreach (OrderProductRepository::forOrder($orderId) as $l) {
            $out[] = $l + ['fixed_price' => $l['qt_fixed_unit_price'] !== null ? (float) $l['qt_fixed_unit_price'] : ($l['unit_price'] !== null ? (float) $l['unit_price'] : null)];
        }
        return $out;
    }

    private static function f(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }

    private static function sameNum(?float $a, ?float $b, float $eps = 0.0005): bool
    {
        return ($a === null && $b === null) || ($a !== null && $b !== null && abs($a - $b) < $eps);
    }

    /**
     * Reads one line's posted figures: size, pieces, quantity, price.
     *
     * @return array{0: array<string,mixed>, 1: ?string} [figures, error]
     */
    private static function readFigures(array $in, ?string $unit, bool $perAnnexure, string $label, ?float $oldQuantity): array
    {
        $size = ['piece_length_cm' => null, 'piece_width_cm' => null, 'piece_thickness_cm' => null];
        if (!$perAnnexure) {
            [$size, $sizeError] = LineUnits::readSize([
                'piece_length_cm' => $in['length_cm'] ?? '', 'piece_width_cm' => $in['width_cm'] ?? '', 'piece_thickness_cm' => $in['thickness_cm'] ?? '',
            ]);
            if ($sizeError !== null) {
                return [[], "{$label}: {$sizeError}"];
            }
        }
        [$pieces, $piecesError] = LineTable::readPieces($in['pieces'] ?? '');
        if ($piecesError !== null) {
            return [[], "{$label}: {$piecesError}"];
        }
        $l = LineUnits::size($size['piece_length_cm']);
        $w = LineUnits::size($size['piece_width_cm']);
        $t = LineUnits::size($size['piece_thickness_cm']);
        $quantity = null;
        if (!$perAnnexure && LineTable::needsPieces($unit, $l, $w, $t)) {
            if ($pieces === null) {
                return [[], "{$label}: enter the No. of pieces — with the size given, the quantity is worked out from the pieces."];
            }
            $quantity = (float) LineTable::quantityFromPieces($unit, $l, $w, $t, $pieces);
        } elseif (LineTable::docUnit($unit) === 'PCS' && $pieces !== null) {
            $quantity = (float) $pieces;
        } else {
            $raw = trim((string) ($in['quantity'] ?? ''));
            if ($raw === '') {
                if ($oldQuantity !== null) {
                    return [[], "{$label}: enter the quantity (e.g. 40)."];
                }
            } elseif (!preg_match('/^\d{1,11}(\.\d{1,3})?$/', $raw) || (float) $raw <= 0.0) {
                return [[], "{$label}: the quantity must be a number above 0 with at most 3 decimals (e.g. 40 or 4.680)."];
            } else {
                $quantity = (float) $raw;
            }
        }
        $rawPrice = trim((string) ($in['unit_price'] ?? ''));
        if ($rawPrice === '') {
            return [[], "{$label}: enter the unit price (e.g. 1950.00)."];
        }
        if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $rawPrice) || (float) $rawPrice <= 0.0) {
            return [[], "{$label}: the unit price must be a number above 0 with at most 2 decimals (e.g. 1950.00)."];
        }
        return [[
            'new_length_cm' => $l, 'new_width_cm' => $w, 'new_thickness_cm' => $t,
            'new_pieces' => $pieces, 'new_quantity' => $quantity, 'new_unit_price' => (float) $rawPrice,
        ], null];
    }

    /** Below the price NexaCrest can accept (never approvable): the refusal, else null. */
    private static function belowMinimum(array $line, array $figures, array $ctx, string $currency, string $label): ?string
    {
        $candidate = array_merge($line, [
            'unit_price' => number_format((float) $figures['new_unit_price'], 2, '.', ''),
            'piece_length_cm' => $figures['new_length_cm'], 'piece_width_cm' => $figures['new_width_cm'], 'piece_thickness_cm' => $figures['new_thickness_cm'],
            'quantity' => $figures['new_quantity'], 'price_approved_at' => null, 'price_approved_unit_price' => null,
        ]);
        $check = OrderLinePricing::checkLine($candidate, $ctx);
        if ($check['status'] === OrderLinePricing::BELOW_MINIMUM) {
            return "{$label}: {$currency} " . number_format((float) $figures['new_unit_price'], 2) . ' is below the price NexaCrest can accept — not even the MD can approve it.';
        }
        return null;
    }

    private static function lineTotal(?float $qty, ?float $price): ?float
    {
        return $qty === null || $price === null ? null : round($qty * $price, 2);
    }

    /**
     * Reads and checks the posted draft (form fields line[<id>][…] and new[<k>][…]).
     *
     * @return array{lines: list<array<string,mixed>>, totals: array<string,mixed>, errors: list<string>, changed: bool}
     */
    public static function draft(array $order, array $post): array
    {
        $orderId = (int) $order['id'];
        $currency = (string) ($order['currency_code'] ?? '');
        $ctx = OrderLinePricing::context($order);
        $current = self::currentLines($orderId);
        $byId = [];
        $errors = [];
        $lines = [];
        $maxNo = 0;
        foreach ($current as $c) {
            $byId[(int) $c['id']] = $c;
            $maxNo = max($maxNo, (int) $c['line_no']);
        }
        foreach ($current as $c) {
            $id = (int) $c['id'];
            $no = (int) $c['line_no'];
            $in = is_array($post['line'][$id] ?? null) ? $post['line'][$id] : [];
            $old = [
                'old_length_cm' => self::f($c['piece_length_cm'] ?? null), 'old_width_cm' => self::f($c['piece_width_cm'] ?? null),
                'old_thickness_cm' => self::f($c['piece_thickness_cm'] ?? null), 'old_pieces' => isset($c['pieces_count']) && $c['pieces_count'] !== null ? (int) $c['pieces_count'] : null,
                'old_quantity' => !empty($c['quantity_is_tbc']) ? null : self::f($c['quantity']), 'old_unit_price' => self::f($c['unit_price']),
            ];
            $old['old_line_total'] = self::f($c['fob_value']) ?? self::lineTotal($old['old_quantity'], $old['old_unit_price']);
            $row = ['line_no' => $no, 'order_product_id' => $id, 'source_line_id' => null, 'description' => (string) $c['description'],
                'unit' => $c['unit'], 'line_type' => (string) ($c['line_type'] ?? 'goods'), 'as_per_annexure' => !empty($c['as_per_annexure']),
                'fixed_price' => $c['fixed_price'], 'price_lowered' => false, 'changes' => []] + $old;
            if (!empty($in['remove'])) {
                $lines[] = $row + ['change_kind' => 'remove', 'new_length_cm' => null, 'new_width_cm' => null, 'new_thickness_cm' => null,
                    'new_pieces' => null, 'new_quantity' => null, 'new_unit_price' => null, 'new_line_total' => null, 'changes' => ['remove']];
                continue;
            }
            if ($in === []) {
                // Not on the form: kept as it is.
                $fig = ['new_length_cm' => $old['old_length_cm'], 'new_width_cm' => $old['old_width_cm'], 'new_thickness_cm' => $old['old_thickness_cm'],
                    'new_pieces' => $old['old_pieces'], 'new_quantity' => $old['old_quantity'], 'new_unit_price' => $old['old_unit_price']];
            } else {
                [$fig, $err] = self::readFigures($in, $c['unit'], !empty($c['as_per_annexure']), "Line {$no}", $old['old_quantity']);
                if ($err !== null) {
                    $errors[] = $err;
                    $lines[] = $row + ['change_kind' => 'none', 'new_length_cm' => $old['old_length_cm'], 'new_width_cm' => $old['old_width_cm'], 'new_thickness_cm' => $old['old_thickness_cm'],
                        'new_pieces' => $old['old_pieces'], 'new_quantity' => $old['old_quantity'], 'new_unit_price' => $old['old_unit_price'], 'new_line_total' => $old['old_line_total'], 'input' => $in];
                    continue;
                }
            }
            $changes = [];
            if (!self::sameNum($fig['new_quantity'], $old['old_quantity']) || $fig['new_pieces'] !== $old['old_pieces']) {
                $changes[] = 'quantity';
            }
            if (!self::sameNum($fig['new_length_cm'], $old['old_length_cm']) || !self::sameNum($fig['new_width_cm'], $old['old_width_cm']) || !self::sameNum($fig['new_thickness_cm'], $old['old_thickness_cm'])) {
                $changes[] = 'size';
            }
            $fixed = $c['fixed_price'];
            if ($fixed !== null && $fig['new_unit_price'] > $fixed + 0.004) {
                $errors[] = self::priceUpMessage($no, $currency, (float) $fixed);
            } elseif (!self::sameNum($fig['new_unit_price'], $old['old_unit_price'], 0.004)) {
                $changes[] = 'price';
                $row['price_lowered'] = true;
            }
            if (in_array('price', $changes, true) || in_array('size', $changes, true)) {
                if (($belowError = self::belowMinimum($c, $fig, $ctx, $currency, "Line {$no}")) !== null) {
                    $errors[] = $belowError;
                }
            }
            $lines[] = array_merge($row, $fig, [
                'change_kind' => $changes === [] ? 'none' : 'change', 'changes' => $changes,
                'new_line_total' => self::lineTotal($fig['new_quantity'], $fig['new_unit_price']), 'input' => $in,
            ]);
        }
        // Lines added: a copy of an order line's product with its own size, pieces, quantity and price.
        $newNo = $maxNo;
        $newPosted = is_array($post['new'] ?? null) ? $post['new'] : [];
        foreach ($newPosted as $k => $in) {
            if (!is_array($in) || trim((string) ($in['source_line_id'] ?? '')) === '') {
                continue;
            }
            $src = $byId[(int) $in['source_line_id']] ?? null;
            $newNo++;
            if ($src === null) {
                $errors[] = "New line {$newNo}: choose the order line whose product it copies.";
                continue;
            }
            [$fig, $err] = self::readFigures($in, $src['unit'], !empty($src['as_per_annexure']), "New line {$newNo}", 1.0);
            $row = ['line_no' => $newNo, 'order_product_id' => null, 'source_line_id' => (int) $src['id'], 'description' => (string) $src['description'],
                'unit' => $src['unit'], 'line_type' => (string) ($src['line_type'] ?? 'goods'), 'as_per_annexure' => !empty($src['as_per_annexure']),
                'fixed_price' => null, 'price_lowered' => false, 'change_kind' => 'add', 'changes' => ['add'], 'input' => $in, 'slot' => $k,
                'old_length_cm' => null, 'old_width_cm' => null, 'old_thickness_cm' => null, 'old_pieces' => null, 'old_quantity' => null,
                'old_unit_price' => null, 'old_line_total' => null];
            if ($err !== null) {
                $errors[] = $err;
                $lines[] = $row + ['new_length_cm' => null, 'new_width_cm' => null, 'new_thickness_cm' => null, 'new_pieces' => null,
                    'new_quantity' => null, 'new_unit_price' => null, 'new_line_total' => null];
                continue;
            }
            if ($fig['new_quantity'] === null) {
                $errors[] = "New line {$newNo}: enter the quantity (e.g. 40).";
            }
            if (($belowError = self::belowMinimum($src, $fig, $ctx, $currency, "New line {$newNo}")) !== null) {
                $errors[] = $belowError;
            }
            $lines[] = array_merge($row, $fig, ['new_line_total' => self::lineTotal($fig['new_quantity'], $fig['new_unit_price'])]);
        }
        $changed = array_filter($lines, static fn (array $l): bool => $l['change_kind'] !== 'none') !== [];
        $kept = array_filter($lines, static fn (array $l): bool => $l['change_kind'] !== 'remove');
        if ($errors === [] && !$changed) {
            $errors[] = 'Nothing was changed — change a line, add one or remove one first.';
        }
        if ($kept === []) {
            $errors[] = 'At least one line must stay on the order.';
        }
        return ['lines' => $lines, 'totals' => self::totals($order, $lines), 'errors' => $errors, 'changed' => $changed];
    }

    /**
     * Old and new order totals (goods and charges — service lines are invoiced on their own,
     * as OrderProductRepository::totalFobValue()) and what happens to the advance.
     *
     * @return array<string,mixed>
     */
    public static function totals(array $order, array $lines): array
    {
        $old = 0.0;
        $new = 0.0;
        foreach ($lines as $l) {
            if (($l['line_type'] ?? 'goods') === 'service') {
                continue;
            }
            $old += (float) ($l['old_line_total'] ?? 0);
            if ($l['change_kind'] !== 'remove') {
                $new += (float) ($l['new_line_total'] ?? 0);
            }
        }
        $old = round($old, 2);
        $new = round($new, 2);
        $pct = (float) ($order['advance_pct'] ?? 0);
        $payment = OrderPaymentStatusRepository::find((int) $order['id']);
        $received = $payment !== null && $payment['advance_amount'] !== null ? (float) $payment['advance_amount'] : null;
        return [
            'currency' => (string) ($order['currency_code'] ?? ''),
            'old_total' => $old, 'new_total' => $new, 'difference' => round($new - $old, 2),
            'advance_pct' => $pct,
            'old_advance' => round($old * $pct / 100, 2), 'new_advance' => round($new * $pct / 100, 2),
            'advance_received' => $received,
            'old_balance' => OrderPaymentStatusRepository::balanceDue($old, $pct, $payment),
            'new_balance' => OrderPaymentStatusRepository::balanceDue($new, $pct, $payment),
            'excess' => $received !== null ? round(max(0.0, $received - $new), 2) : 0.0,
            'fx_rate' => PriceLock::fixedRate($order),
            'prices_fixed_on' => PriceLock::fixedDate($order),
        ];
    }

    /** "+ USD 4,313.40" / "− USD 120.00" */
    public static function signedDifference(array $t): string
    {
        $d = (float) $t['difference'];
        return ($d < 0 ? '− ' : '+ ') . $t['currency'] . ' ' . number_format(abs($d), 2);
    }

    /** What happens to the advance (the screen's totals box). */
    public static function advanceNote(array $t): string
    {
        $c = $t['currency'];
        if ($t['advance_received'] === null) {
            return 'no advance received yet — the advance due becomes ' . $c . ' ' . number_format((float) $t['new_advance'], 2) . '.';
        }
        $r = (float) $t['advance_received'];
        if ((float) $t['excess'] > 0) {
            return 'advance already received ' . $c . ' ' . number_format($r, 2) . ' is ' . $c . ' ' . number_format((float) $t['excess'], 2) . ' more than the new total — it shows as excess.';
        }
        $d = round((float) $t['new_advance'] - $r, 2);
        if ($d > 0.004) {
            return 'advance already received ' . $c . ' ' . number_format($r, 2) . '; the extra ' . $c . ' ' . number_format($d, 2) . ' is added to the balance.';
        }
        if ($d < -0.004) {
            return 'advance already received ' . $c . ' ' . number_format($r, 2) . ' stays credited; the ' . $c . ' ' . number_format(-$d, 2) . ' received above the new advance is taken off the balance.';
        }
        return 'advance already received ' . $c . ' ' . number_format($r, 2) . ' stays credited.';
    }

    /** Section 2 of the AMD document — "EFFECT ON PAYMENT". */
    public static function paymentEffect(array $t): string
    {
        $c = $t['currency'];
        $tail = ' All other terms of the Quotation / Proforma Invoice remain unchanged.';
        if ($t['advance_received'] === null) {
            return 'Advance payable as per the agreed payment terms: ' . $c . ' ' . number_format((float) $t['new_advance'], 2)
                . ' (' . rtrim(rtrim(number_format((float) $t['advance_pct'], 2, '.', ''), '0'), '.') . '% of ' . $c . ' ' . number_format((float) $t['new_total'], 2) . ').'
                . ' Balance payable as per the agreed payment terms: ' . $c . ' ' . number_format((float) $t['new_balance'], 2) . '.' . $tail;
        }
        $r = $c . ' ' . number_format((float) $t['advance_received'], 2);
        if ((float) $t['excess'] > 0) {
            return 'Advance received ' . $r . ' stays credited. It is ' . $c . ' ' . number_format((float) $t['excess'], 2)
                . ' more than the amended order total of ' . $c . ' ' . number_format((float) $t['new_total'], 2) . '; nothing more is payable and the excess is held to the Importer\'s credit.' . $tail;
        }
        return 'Advance received ' . $r . ' stays credited. Balance payable as per the agreed payment terms: ' . $c . ' ' . number_format((float) $t['new_balance'], 2) . '.' . $tail;
    }

    /**
     * Raises the amendment (status pending, MD approval next).
     *
     * @return array{0: ?int, 1: list<string>, 2: array<string,mixed>} [amendment id, errors, draft]
     */
    public static function create(int $orderId, array $post, string $reason, int $userId): array
    {
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return [null, ['Order not found.'], []];
        }
        if (($why = self::cannotStart($order)) !== null) {
            return [null, [$why], []];
        }
        $draft = self::draft($order, $post);
        $errors = $draft['errors'];
        if (($reasonError = ReasonValidator::check($reason)) !== null) {
            $errors[] = 'Reason for the amendment: ' . $reasonError;
        } elseif (mb_strlen(trim($reason)) > 500) {
            $errors[] = 'Reason for the amendment: keep it under 500 characters.';
        }
        if ($errors !== []) {
            return [null, $errors, $draft];
        }
        $reference = ReferenceNumberService::generateAmendmentRef();
        if ($reference === null) {
            return [null, ['AMD document type has no ref_format configured — cannot mint an amendment reference.'], $draft];
        }
        $pdo = Database::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $id = AmendmentRepository::create($reference, $orderId, trim($reason), 'importer', AmendmentService::originalSnapshot($order),
                null, null, null, null, null, null, null, $userId);
            AmendmentRepository::setProducts($id, $draft['totals']);
            foreach ($draft['lines'] as $l) {
                AmendmentRepository::addProductLine($id, $l);
            }
            AuditLogRepository::log($userId, 'AMENDMENT_REQUESTED', 'amendments', $id, 'products', null, $reference, trim($reason));
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        foreach (PermissionRepository::usersWithPermission('approve_documents') as $mdId) {
            NotificationRepository::create($mdId, null, 'amendment_pending_md_approval', $orderId, "Amendment {$reference} (Products & prices) needs MD approval.");
        }
        return [$id, [], $draft];
    }

    /**
     * The buyer's signed copy is uploaded: the amended lines become the order's lines.
     * Called by AmendmentService::attachSignedCopyAndActivate() after its status checks.
     */
    public static function activate(array $amendment, int $signedCopyFileId, int $userId): void
    {
        $amendmentId = (int) $amendment['id'];
        $orderId = (int) $amendment['order_id'];
        $lines = AmendmentRepository::productLines($amendmentId);
        $current = [];
        foreach (OrderProductRepository::forOrder($orderId) as $c) {
            $current[(int) $c['id']] = $c;
        }
        // The order's lines may not have moved since the draft (they are locked; this is the safety net).
        foreach ($lines as $l) {
            if ($l['order_product_id'] === null) {
                continue;
            }
            $c = $current[(int) $l['order_product_id']] ?? null;
            $cQty = $c === null || !empty($c['quantity_is_tbc']) ? null : self::f($c['quantity']);
            if ($c === null || !self::sameNum(self::f($c['unit_price']), self::f($l['old_unit_price']), 0.004) || !self::sameNum($cQty, self::f($l['old_quantity']))) {
                throw new \RuntimeException('Line ' . (int) $l['line_no'] . ' of the order changed since this amendment was drafted — reject it and draft a new one.');
            }
        }
        $pdo = Database::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            AmendmentRepository::activate($amendmentId, $signedCopyFileId);
            $quantitiesChanged = false;
            $mdId = $amendment['md_approved_by'] !== null ? (int) $amendment['md_approved_by'] : $userId;
            $reference = (string) $amendment['amendment_reference'];
            foreach ($lines as $l) {
                $kind = (string) $l['change_kind'];
                if ($kind === 'none') {
                    continue;
                }
                if ($kind === 'remove') {
                    OrderProductRepository::softDelete((int) $l['order_product_id']);
                    $quantitiesChanged = true;
                    AuditLogRepository::log($userId, 'ORDER_LINE_AMENDED', 'order_products', (int) $l['order_product_id'], 'is_active', '1', '0',
                        "Line {$l['line_no']} removed by Amendment {$reference}.");
                    continue;
                }
                if ($kind === 'add') {
                    $lineId = OrderProductRepository::duplicate((int) $l['source_line_id'], $orderId, (int) $l['line_no']);
                    $before = null;
                    $quantitiesChanged = true;
                } else {
                    $lineId = (int) $l['order_product_id'];
                    $before = $current[$lineId];
                    if (!self::sameNum(self::f($l['old_quantity']), self::f($l['new_quantity'])) || $l['old_pieces'] !== $l['new_pieces']) {
                        $quantitiesChanged = true;
                    }
                }
                self::applyLine($lineId, $l, $mdId, $userId, $reference, $before);
            }
            // A line added by the amendment gets its price check figures at the fixed rate.
            OrderLinePricing::snapshotLines($orderId);
            // The money follows: a balance already set (CI issued, not cleared yet) is worked out again.
            $order = OrderRepository::find($orderId);
            $payment = OrderPaymentStatusRepository::find($orderId);
            if ($order !== null && $payment !== null && $payment['balance_amount'] !== null && $payment['balance_cleared_at'] === null) {
                $newBalance = OrderPaymentStatusRepository::balanceDue(OrderProductRepository::totalFobValue($orderId), (float) $order['advance_pct'], $payment);
                OrderPaymentStatusRepository::setBalanceAmount($orderId, $newBalance, $payment['balance_due_date']);
                AuditLogRepository::log($userId, 'BALANCE_AMOUNT_CHANGED', 'orders', $orderId, 'balance_amount', (string) $payment['balance_amount'],
                    number_format($newBalance, 2, '.', ''), "Balance worked out again after Amendment {$reference} (Products & prices).");
            }
            if ($quantitiesChanged && self::hasSupplierPo($orderId)) {
                AmendmentRepository::setSupplierPoRevisionNeeded($amendmentId, true);
            }
            AuditLogRepository::log($userId, 'AMENDMENT_ACTIVATED', 'amendments', $amendmentId, 'products', null, $reference,
                'Products & prices applied to the order; the stage and the fixed exchange rate do not change.');
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function hasSupplierPo(int $orderId): bool
    {
        // A Supplier PO already issued (one not made yet will use the amended figures).
        return DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'SUPPO') !== null;
    }

    /** Writes one amended line onto the order and keeps its new fixed price. */
    private static function applyLine(int $lineId, array $l, int $mdId, int $userId, string $reference, ?array $before): void
    {
        $line = OrderProductRepository::find($lineId);
        if ($line === null) {
            return;
        }
        $size = ['piece_length_cm' => $l['new_length_cm'], 'piece_width_cm' => $l['new_width_cm'], 'piece_thickness_cm' => $l['new_thickness_cm']];
        $sizeChanged = $before === null
            || !self::sameNum(self::f($l['old_length_cm']), self::f($l['new_length_cm']))
            || !self::sameNum(self::f($l['old_width_cm']), self::f($l['new_width_cm']))
            || !self::sameNum(self::f($l['old_thickness_cm']), self::f($l['new_thickness_cm']));
        $dimensions = $line['dimensions'];
        if ($sizeChanged && empty($line['as_per_annexure'])) {
            $text = LineUnits::dimensionsText(self::f($l['new_length_cm']), self::f($l['new_width_cm']), self::f($l['new_thickness_cm']));
            $dimensions = $text !== '' ? $text : $line['dimensions'];
        }
        $qty = $l['new_quantity'] !== null ? number_format((float) $l['new_quantity'], 3, '.', '') : null;
        OrderProductRepository::update(
            $lineId, (string) $line['description'], $dimensions, $line['finish'], $qty,
            $qty === null ? (bool) $line['quantity_is_tbc'] : false, $line['unit'],
            number_format((float) $l['new_unit_price'], 2, '.', ''), (string) $line['hs_code']
        );
        OrderProductRepository::setPieceSize($lineId, $size);
        OrderProductRepository::setPieces($lineId, $l['new_pieces'] !== null ? (int) $l['new_pieces'] : null, $line['size_note'] ?? null);
        Database::connection()->prepare('UPDATE order_products SET qt_fixed_unit_price = :p WHERE id = :id')
            ->execute(['p' => number_format((float) $l['new_unit_price'], 2, '.', ''), 'id' => $lineId]);
        AuditLogRepository::log($userId, 'ORDER_LINE_AMENDED', 'order_products', $lineId, 'line',
            $before !== null ? json_encode(['quantity' => $l['old_quantity'], 'pieces' => $l['old_pieces'], 'unit_price' => $l['old_unit_price'],
                'size' => [$l['old_length_cm'], $l['old_width_cm'], $l['old_thickness_cm']]]) : null,
            json_encode(['quantity' => $l['new_quantity'], 'pieces' => $l['new_pieces'], 'unit_price' => $l['new_unit_price'],
                'size' => [$l['new_length_cm'], $l['new_width_cm'], $l['new_thickness_cm']]]),
            ($before === null ? "Line {$l['line_no']} added" : "Line {$l['line_no']} changed") . " by Amendment {$reference}.");
        // The MD approved the amendment — a lowered (or new) price below the floor is approved with it.
        $fresh = OrderProductRepository::find($lineId);
        $order = OrderRepository::find((int) $fresh['order_id']);
        $check = OrderLinePricing::checkLine(array_merge($fresh, ['price_approved_at' => null]), OrderLinePricing::context($order ?? []));
        if (in_array($check['status'], OrderLinePricing::APPROVABLE, true)) {
            OrderLinePricing::approveByAmendment($lineId, $mdId, "Agreed with the buyer in Amendment {$reference} (Products & prices), approved by the MD.");
        } else {
            OrderLinePricing::afterSave($lineId, $before, $userId);
        }
    }

    /**
     * The AMD document's "Products & prices" section.
     *
     * @return array<string,mixed>
     */
    public static function documentBlock(array $amendment): array
    {
        $lines = AmendmentRepository::productLines((int) $amendment['id']);
        $t = json_decode((string) ($amendment['products_totals_json'] ?? ''), true) ?: [];
        $currency = (string) ($t['currency'] ?? '');
        $units = [];
        foreach ($lines as $l) {
            $units[LineTable::docUnit($l['unit']) ?? strtoupper(trim((string) $l['unit']))] = true;
        }
        $oneUnit = count($units) === 1 ? (string) array_key_first($units) : null;
        $size = static function (?string $a, ?string $b, ?string $c): string {
            $p = array_map(static fn ($v) => LineUnits::num((float) $v), array_values(array_filter([$a, $b, $c], static fn ($v) => $v !== null && (float) $v > 0)));
            return $p === [] ? '' : implode('×', $p);
        };
        $qtyText = static function (?string $q, ?string $unit): string {
            if ($q === null) {
                return 'To Be Confirmed';
            }
            $dec = LineTable::quantityDecimals($unit) ?? 3;
            return number_format((float) $q, $dec);
        };
        $rows = [];
        foreach ($lines as $l) {
            $kind = (string) $l['change_kind'];
            $removed = $kind === 'remove';
            $added = $kind === 'add';
            $changes = [];
            if ($added) {
                $changes[] = 'add';
            } elseif ($removed) {
                $changes[] = 'remove';
            } else {
                if (!self::sameNum(self::f($l['old_quantity']), self::f($l['new_quantity'])) || $l['old_pieces'] !== $l['new_pieces']) {
                    $changes[] = 'quantity';
                }
                if ($size($l['old_length_cm'], $l['old_width_cm'], $l['old_thickness_cm']) !== $size($l['new_length_cm'], $l['new_width_cm'], $l['new_thickness_cm'])) {
                    $changes[] = 'size';
                }
                if ((int) $l['price_lowered'] === 1) {
                    $changes[] = 'price';
                }
            }
            $unitLabel = LineTable::docUnit($l['unit']) ?? (string) $l['unit'];
            $cell = static function (string $new, string $old, bool $isNew) use ($removed, $added): array {
                if ($removed) {
                    return ['new' => '', 'old' => $old];
                }
                return ['new' => $new, 'old' => (!$added && $old !== $new) ? $old : ''];
            };
            $rows[] = [
                'no' => (int) $l['line_no'],
                'product' => (string) $l['description'],
                'size' => $cell($size($l['new_length_cm'], $l['new_width_cm'], $l['new_thickness_cm']) ?: '—', $size($l['old_length_cm'], $l['old_width_cm'], $l['old_thickness_cm']) ?: '—', true),
                'pieces' => $cell($l['new_pieces'] !== null ? number_format((int) $l['new_pieces']) : '—', $l['old_pieces'] !== null ? number_format((int) $l['old_pieces']) : '—', true),
                'quantity' => $cell($qtyText($l['new_quantity'], $l['unit']) . ($oneUnit === null ? ' ' . $unitLabel : ''), $qtyText($l['old_quantity'], $l['unit']) . ($oneUnit === null ? ' ' . $unitLabel : ''), true),
                'price' => $cell(self::money(self::f($l['new_unit_price'])), self::money(self::f($l['old_unit_price'])), true),
                'total' => $cell(self::money(self::f($l['new_line_total'])), self::money(self::f($l['old_line_total'])), true),
                'change' => $changes === [] ? self::DOC_CHANGE_LABELS['none'] : implode(', ', array_map(static fn ($k) => self::DOC_CHANGE_LABELS[$k], $changes)),
                'removed' => $removed,
            ];
        }
        return [
            'currency' => $currency,
            'unit' => $oneUnit,
            'qty_header' => $oneUnit ?? 'Qty',
            'price_header' => 'Price (' . $currency . ')' . ($oneUnit !== null ? ' per ' . $oneUnit : ''),
            'total_header' => 'Total (' . $currency . ')',
            'rows' => $rows,
            'old_total' => self::money(isset($t['old_total']) ? (float) $t['old_total'] : null),
            'new_total' => self::money(isset($t['new_total']) ? (float) $t['new_total'] : null),
            'total_label' => 'Order total (was ' . $currency . ' ' . self::money(isset($t['old_total']) ? (float) $t['old_total'] : null) . ')',
            'payment_effect' => $t !== [] ? self::paymentEffect($t) : '',
        ];
    }

    /**
     * Mockup screen 2: after an activated products amendment, what to re-issue — one row per
     * document. Null when nothing is left to do (every row done, or no such amendment).
     *
     * @return array<string,mixed>|null
     */
    public static function checklist(array $order): ?array
    {
        $orderId = (int) $order['id'];
        $a = AmendmentRepository::latestActiveProducts($orderId);
        if ($a === null || $a['activated_at'] === null) {
            return null;
        }
        $at = (string) $a['activated_at'];
        $ref = (string) $a['amendment_reference'];
        $rev = static fn (array $d, int $plus = 0): string => 'Rev.' . str_pad((string) ((int) $d['revision_number'] + $plus), 2, '0', STR_PAD_LEFT);
        $day = static fn (?string $ts): string => $ts ? date('j M', (int) strtotime($ts)) : '';
        $after = static fn (?array $d): bool => $d !== null && strcmp((string) $d['generated_at'], $at) >= 0;
        $doc = static fn (string $code): ?array => DocumentRepository::findLatestForOrderAndTypeCode($orderId, $code);
        $items = [];
        $pending = 0;

        $qt = $doc('QT');
        if ($qt !== null) {
            $items[] = ['document' => 'Quotation ' . $rev($qt), 'status' => 'Approved ' . $day($order['prices_fixed_at'] ?? null), 'state' => 'ok',
                'todo' => 'nothing — the signed amendment and the Quotation together are the agreement'];
        }
        foreach (['PI' => 'Proforma Invoice', 'OC' => 'Order Confirmation'] as $code => $name) {
            $d = $doc($code);
            if ($d === null) {
                $items[] = ['document' => $name, 'status' => 'Not made yet', 'state' => 'ok', 'todo' => 'will use the amended figures'];
            } elseif (!$after($d)) {
                $pending++;
                $items[] = ['document' => $name . ' ' . $rev($d), 'status' => 'Outdated by ' . $ref, 'state' => 'todo',
                    'todo' => 'Generate revised ' . $name . ' (' . $rev($d, 1) . ')', 'generate' => $code];
            }
        }
        $poRef = trim((string) ($order['buyers_po_ref'] ?? ''));
        $buyerPoDoc = $doc('BUYERPO');
        if (($poRef !== '' && $poRef !== 'NIL') || $buyerPoDoc !== null) {
            $ticked = (int) ($order['buyer_po_amended_by_amendment_id'] ?? 0) === (int) $a['id'];
            if (!$ticked && !$after($buyerPoDoc)) {
                $pending++;
                $received = $order['buyer_po_received_on'] ?? null;
                $items[] = ['document' => 'Buyer PO (buyer\'s)', 'status' => $received ? 'Received ' . $day((string) $received) : 'Received', 'state' => 'todo',
                    'todo' => 'Ask the buyer for a revised PO, or tick "The signed amendment amends the Buyer PO"', 'tick' => (int) $a['id']];
            }
        }
        $sp = $doc('SUPPO');
        if ($sp === null) {
            $items[] = ['document' => 'Supplier PO', 'status' => 'Not made yet', 'state' => 'ok', 'todo' => 'will use the amended figures'];
        } elseif ((int) $a['supplier_po_revision_needed'] === 1 && !$after($sp)) {
            $pending++;
            $items[] = ['document' => 'Supplier PO', 'status' => 'Quantities changed', 'state' => 'todo',
                'todo' => 'Procurement: revise the Supplier PO (task sent to Procurement)', 'link' => '/orders/' . $orderId . '#step-supplier-po'];
        }
        $ci = $doc('CI');
        $pl = $doc('PL');
        if ($ci === null && $pl === null) {
            $items[] = ['document' => 'Commercial Invoice / Packing List', 'status' => 'Not made yet', 'state' => 'ok', 'todo' => 'will use the amended figures'];
        } elseif (($ci !== null && !$after($ci)) || ($pl !== null && !$after($pl))) {
            $pending++;
            $items[] = ['document' => 'Commercial Invoice / Packing List', 'status' => 'Outdated by ' . $ref, 'state' => 'todo',
                'todo' => 'Generate the revised Commercial Invoice / Packing List — the difference needs a credit / debit note in Zoho Books',
                'generate_ci' => $ci !== null && !$after($ci), 'generate_pl' => $pl !== null && !$after($pl)];
        }
        if ($pending === 0) {
            return null;
        }
        $t = json_decode((string) ($a['products_totals_json'] ?? ''), true) ?: [];
        $payment = OrderPaymentStatusRepository::find($orderId);
        $total = OrderProductRepository::totalFobValue($orderId);
        $received = $payment !== null && $payment['advance_amount'] !== null ? (float) $payment['advance_amount'] : null;
        $balance = OrderPaymentStatusRepository::balanceDue($total, (float) ($order['advance_pct'] ?? 0), $payment);
        $c = (string) ($order['currency_code'] ?? '');
        $money = 'Money worked out again: total ' . $c . ' ' . number_format($total, 2)
            . ($received !== null ? ' · advance received ' . $c . ' ' . number_format($received, 2) : ' · no advance received yet')
            . ' · balance now ' . $c . ' ' . number_format($balance, 2)
            . (isset($t['old_balance']) ? ' (was ' . $c . ' ' . number_format((float) $t['old_balance'], 2) . ')' : '') . '.'
            . ($received !== null && $received > $total ? ' ' . $c . ' ' . number_format($received - $total, 2) . ' received above the new total shows as excess.' : '')
            . ' Commission, incentive and profit follow the amended figures.';
        $signed = $a['activated_at'] ? date('j M Y', (int) strtotime((string) $a['activated_at'])) : '';
        $stage = $order['current_stage_number'] !== null ? 'Stage ' . (int) $order['current_stage_number'] . ' (' . (string) ($order['current_stage_name'] ?? '') . ')' : 'its stage';
        return [
            'amendment' => $a,
            'title' => 'Amendment ' . $ref . ' activated — re-issue checklist',
            'intro' => 'Products & prices as per Amendment ' . $ref . ', signed by the buyer ' . $signed . '. Order stays at ' . $stage . '.',
            'items' => $items,
            'money' => $money,
            'rate' => PriceLock::fixedRate($order) !== null ? 'Exchange rate unchanged: ₹' . number_format((float) PriceLock::fixedRate($order), 2) . ' (fixed at QT approval).' : null,
        ];
    }

    /** The checklist's tick: "The signed amendment amends the Buyer PO" — kept on the order, logged. */
    public static function tickBuyerPo(int $orderId, int $amendmentId, int $userId): ?string
    {
        $a = AmendmentRepository::find($amendmentId);
        if ($a === null || (int) $a['order_id'] !== $orderId || ($a['amendment_type'] ?? '') !== 'products' || $a['status'] !== 'active') {
            return 'Only an activated Products & prices amendment of this order can amend its Buyer PO.';
        }
        Database::connection()->prepare('UPDATE orders SET buyer_po_amended_by_amendment_id = :a, buyer_po_amended_at = NOW(), buyer_po_amended_by = :u WHERE id = :o')
            ->execute(['a' => $amendmentId, 'u' => $userId, 'o' => $orderId]);
        AuditLogRepository::log($userId, 'BUYER_PO_AMENDED_BY_AMENDMENT', 'orders', $orderId, 'buyer_po_amended_by_amendment_id', null, (string) $amendmentId,
            'The signed Amendment ' . $a['amendment_reference'] . ' amends the Buyer PO.');
        return null;
    }
}
