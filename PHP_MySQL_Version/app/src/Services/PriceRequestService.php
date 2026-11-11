<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\ProductRepository;

/**
 * Owner decision D-255 (5 Nov 2026): associate price requests.
 *
 * Associates see a catalogue without prices (no supplier names or quarry
 * locations). For one product and specification they ask for a price: the app
 * answers at once from the pricing rules — their quote price and the lowest
 * they may agree, at their commission level — valid N days (setting, 7), at most
 * N automatic requests a day (setting, 10; per-associate override). Anything
 * non-standard ("Other", an unlisted thickness, no per-CBM supplier price) goes to
 * staff to price within 24 hours and does not count toward the limit. An order
 * line can be filled from a valid price request; it then keeps the request's prices.
 * Associates and sales staff never see the cost.
 *
 * Node twin: src/services/priceRequestService.js.
 */
final class PriceRequestService
{
    /** A warning on the report when an associate asks this many prices in 90 days and places no order. */
    public const WARN_REQUESTS = 20;

    public static function dailyLimit(array $associate): int
    {
        return $associate['price_request_daily_limit'] !== null && $associate['price_request_daily_limit'] !== ''
            ? max(0, (int) $associate['price_request_daily_limit']) : PricingService::settings()['price_request_daily_limit'];
    }

    public static function validDays(array $associate): int
    {
        return $associate['price_valid_days'] !== null && $associate['price_valid_days'] !== ''
            ? max(1, (int) $associate['price_valid_days']) : PricingService::settings()['price_request_valid_days'];
    }

    /** @return array<int, list<string>> category id => allowed forms ([] = all forms of that category) */
    public static function access(int $associateId): array
    {
        $stmt = Database::connection()->prepare('SELECT category_id, forms FROM associate_price_access WHERE associate_id = :a');
        $stmt->execute(['a' => $associateId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['category_id']] = ProductSpecs::splitForms($r['forms']);
        }
        return $out;
    }

    /** @param array<int, list<string>> $access */
    public static function saveAccess(int $associateId, array $access): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM associate_price_access WHERE associate_id = :a')->execute(['a' => $associateId]);
        $ins = $pdo->prepare('INSERT INTO associate_price_access (associate_id, category_id, forms) VALUES (:a, :c, :f)');
        foreach ($access as $categoryId => $forms) {
            $ins->execute(['a' => $associateId, 'c' => $categoryId, 'f' => $forms === [] ? null : implode(',', $forms)]);
        }
    }

    /** The forms of this product the associate may price ([] = none, null = all its forms). */
    public static function allowedForms(array $access, array $product): ?array
    {
        $categoryId = ProductSpecs::categoryIdFor($product);
        if ($categoryId === null || !array_key_exists($categoryId, $access)) {
            return [];
        }
        $allowed = $access[$categoryId];
        $productForms = ProductSpecs::productForms($product);
        if ($allowed === []) {
            return null;
        }
        if ($productForms === []) {
            return $allowed;
        }
        return array_values(array_intersect($productForms, $allowed));
    }

    public static function mayPrice(array $associate, array $product): bool
    {
        if ((int) ($product['is_active'] ?? 0) !== 1) {
            return false;
        }
        $forms = self::allowedForms(self::access((int) $associate['id']), $product);
        return $forms === null || $forms !== [];
    }

    /**
     * The catalogue: products the associate may price — name, description, forms,
     * finishes. No prices, supplier names or quarry locations.
     *
     * @return list<array<string,mixed>>
     */
    public static function catalogue(array $associate): array
    {
        $access = self::access((int) $associate['id']);
        if ($access === []) {
            return [];
        }
        $out = [];
        foreach (ProductRepository::all() as $p) {
            if ((int) ($p['is_active'] ?? 0) !== 1) {
                continue;
            }
            $forms = self::allowedForms($access, $p);
            if ($forms !== null && $forms === []) {
                continue;
            }
            $finishes = [];
            foreach (ProductSpecs::offered($p) as $s) {
                if (stripos($s['name'], 'finish') !== false) {
                    $finishes = array_map(static fn (array $o): string => $o['name'], $s['options']);
                }
            }
            $formNames = array_map(static fn (string $f): string => ProductSpecs::FORMS[$f], $forms ?? ProductSpecs::productForms($p));
            $out[] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'description' => (string) ($p['specifications'] ?? ''),
                'forms' => $formNames, 'finishes' => $finishes];
        }
        return $out;
    }

    public static function requestsToday(int $associateId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM price_requests WHERE associate_id = :a AND kind = 'auto' AND requested_at >= :d"
        );
        $stmt->execute(['a' => $associateId, 'd' => date('Y-m-d') . ' 00:00:00']);
        return (int) $stmt->fetchColumn();
    }

    public static function leftToday(array $associate): int
    {
        return max(0, self::dailyLimit($associate) - self::requestsToday((int) $associate['id']));
    }

    /**
     * The associate's price for a product and choice of options, worked out now.
     *
     * @return array<string,mixed> ok, manual, reason, quote, lowest, unit, per_m2, breakdown (what the associate
     *   sees), charges, figures (full, for the order check — never shown to the associate), text, map, level_name
     */
    public static function work(array $associate, array $product, array $selection): array
    {
        [$level] = AssociateCommission::levelForAssociate($associate, null);
        $p = PricingService::forProduct($product, [], null, null, $selection);
        $out = ['ok' => false, 'manual' => (bool) $p['manual'], 'reason' => $p['missing'], 'map' => $p['spec']['map'] ?? [],
            'text' => $p['spec']['text'] ?? '', 'unit' => $p['unit'], 'level_name' => $level !== null ? (string) $level['name'] . ' — ' . PricingService::levelLabel($level) : null,
            'charges' => $p['spec']['adj']['charges'] ?? []];
        if ($p['build'] === null) {
            if (!$out['manual'] && $p['spec'] !== null && ($p['spec']['errors'] ?? []) === []) {
                // No supplier price at all: staff price it by hand.
                $out['manual'] = true;
            }
            return $out;
        }
        $prices = self::priceFrom($p['build'], $level);
        $adj = $p['spec']['adj'];
        $breakdown = [['label' => 'Base: ' . (ProductSpecs::describe($p['spec']['offered'], self::baseMap($p['spec']['offered']), true) ?: 'standard specification'), 'change' => '—']];
        if (!empty($p['exact'])) {
            $breakdown[] = ['label' => 'Exact price for ' . $p['exact'], 'change' => 'used'];
        }
        foreach ($adj['lines'] as $l) {
            if ($l['kind'] === 'exact') {
                $breakdown[] = ['label' => $l['option'] . ' (' . strtolower($l['spec']) . ')', 'change' => 'Included (in the exact price)'];
            } elseif ($l['kind'] === 'included') {
                $breakdown[] = ['label' => $l['option'] . ' (' . strtolower($l['spec']) . ')', 'change' => 'Included in base price'];
            } elseif ($l['kind'] === 'pct') {
                $breakdown[] = ['label' => $l['option'] . ' (' . strtolower($l['spec']) . ')', 'change' => ($l['value'] < 0 ? '−' : '+') . rtrim(rtrim(number_format(abs($l['value']), 2, '.', ''), '0'), '.') . '%'];
            } else {
                // The effect of this amount on the associate's quote price: worked out with and without it.
                $without = PricingEngine::build(self::engineInput($p, ['pct' => $adj['pct'], 'usd' => $adj['usd'] - (float) $l['usd_effect'], 'qty_pct' => $adj['qty_pct']]));
                $effect = $prices['quote'] - self::priceFrom($without, $level)['quote'];
                $breakdown[] = ['label' => $l['option'] . ' (' . strtolower($l['spec']) . ')', 'change' => ($effect < 0 ? '−' : '+') . 'USD ' . number_format(abs($effect), 2) . ' on this price' . ($adj['basis'] === 'cbm' ? ' per CBM' : '')];
            }
        }
        $b = $p['build'];
        $out['ok'] = true;
        $out['quote'] = $prices['quote'];
        $out['lowest'] = $prices['floor'];
        $out['per_m2'] = $adj['basis'] === 'cbm' && $adj['thickness_mm'] ? PricingEngine::round2($prices['quote'] * $adj['thickness_mm'] / 1000) : null;
        $out['m2_per_cbm'] = $adj['basis'] === 'cbm' && $adj['thickness_mm'] ? round(1000 / $adj['thickness_mm'], 2) : null;
        $out['breakdown'] = $breakdown;
        $out['figures'] = [
            'cost' => $b['cost'], 'minimum' => $b['minimum'], 'reserve' => $b['reserve'], 'staff_floor' => $b['staff_floor'], 'staff_quote' => $b['staff_quote'],
            'floor' => $prices['floor'], 'quote' => $prices['quote'], 'unit' => $p['unit'],
            'level_id' => $level !== null ? (int) $level['id'] : null, 'level_name' => $level['name'] ?? null,
            'level_kind' => $level['kind'] ?? null, 'level_value' => $level !== null ? (float) $level['value'] : null,
            'margin_pct' => $p['effective']['margin_pct'], 'buffer_pct' => $p['effective']['buffer_pct'],
            'spec_text' => $p['spec']['text'], 'from_price_request' => true,
        ];
        return $out;
    }

    /** @return array{floor: float, quote: float} the associate's floor and quote from a build (staff figures with no level) */
    private static function priceFrom(array $build, ?array $level): array
    {
        if ($level === null) {
            return ['floor' => $build['staff_floor'], 'quote' => $build['staff_quote']];
        }
        return PricingEngine::associate($build['raw'], (string) $level['kind'], (float) $level['value']);
    }

    private static function engineInput(array $p, array $adjust): array
    {
        $e = $p['effective'];
        return [
            'supplier_inr' => (float) $p['supplier_inr'], 'fx_rate' => $p['settings']['pricing_fx_rate'],
            'other_mode' => $e['other_mode'], 'other_value' => $e['other_value'], 'margin_pct' => $e['margin_pct'],
            'incentive_mode' => $e['incentive_mode'], 'incentive_value' => $e['incentive_value'], 'program_on' => $p['program_on'],
            'buffer_pct' => $e['buffer_pct'], 'adjust' => $adjust,
        ];
    }

    /** @return array<int,int> each specification's base option */
    private static function baseMap(array $offered): array
    {
        $map = [];
        foreach ($offered as $s) {
            foreach ($s['options'] as $o) {
                if ($o['is_base']) {
                    $map[$s['id']] = $o['id'];
                }
            }
        }
        return $map;
    }

    /** The next request number: PR-YY-DD-NNNN (NNNN counts through the year). */
    private static function nextNumber(): string
    {
        $year = date('Y');
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM price_requests WHERE requested_at >= :a AND requested_at < :b');
        $stmt->execute(['a' => "{$year}-01-01 00:00:00", 'b' => ((int) $year + 1) . '-01-01 00:00:00']);
        $n = (int) $stmt->fetchColumn() + 1;
        return sprintf('PR-%s-%s-%04d', date('y'), date('d'), $n);
    }

    private static function insert(array $row): int
    {
        $pdo = Database::connection();
        for ($try = 0; $try < 5; $try++) {
            $row['number'] = self::nextNumber();
            if ($try > 0) {
                $row['number'] = substr($row['number'], 0, 9) . sprintf('%04d', (int) substr($row['number'], 9) + $try);
            }
            try {
                $pdo->prepare(
                    'INSERT INTO price_requests (number, associate_id, product_id, selection, selection_text, other_note, kind, status, unit, quote, lowest,
                        figures, breakdown, level_name, valid_until, requested_at, due_at)
                     VALUES (:number, :associate_id, :product_id, :selection, :selection_text, :other_note, :kind, :status, :unit, :quote, :lowest,
                        :figures, :breakdown, :level_name, :valid_until, :requested_at, :due_at)'
                )->execute($row);
                $requestId = (int) $pdo->lastInsertId();
                // D-257: a price request belongs to its associate's division.
                $pdo->prepare('UPDATE price_requests r JOIN associates a ON a.id = r.associate_id SET r.division_id = a.division_id WHERE r.id = :id AND r.division_id <> a.division_id')->execute(['id' => $requestId]);
                return $requestId;
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'Duplicate') || $try === 4) {
                    throw $e;
                }
            }
        }
        return 0;
    }

    /**
     * An associate asks for a price. Returns [request id, error].
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function create(array $associate, array $product, array $selection, bool $other, ?string $note): array
    {
        if (!self::mayPrice($associate, $product)) {
            return [null, 'You cannot ask for prices for this product.'];
        }
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 500) {
            return [null, 'Keep the note to 500 characters.'];
        }
        $work = self::work($associate, $product, $selection);
        if (!$work['ok'] && !$work['manual']) {
            return [null, (string) ($work['reason'] ?? 'That choice of options cannot be priced.')];
        }
        $now = date('Y-m-d H:i:s');
        $row = [
            'associate_id' => (int) $associate['id'], 'product_id' => (int) $product['id'],
            'selection' => json_encode((object) $work['map']), 'selection_text' => mb_substr($work['text'] !== '' ? $work['text'] : 'Standard specification', 0, 500),
            'other_note' => $note !== '' ? $note : null, 'requested_at' => $now, 'level_name' => $work['level_name'],
            'unit' => $work['unit'], 'quote' => null, 'lowest' => null, 'figures' => null, 'breakdown' => null, 'valid_until' => null, 'due_at' => null,
        ];
        if ($other || $work['manual'] || !$work['ok']) {
            if ($other && ($note === null || mb_strlen($note) < 10)) {
                return [null, 'Tell our team what the buyer needs (at least 10 characters).'];
            }
            $row['kind'] = 'manual';
            $row['status'] = 'pending';
            $row['due_at'] = date('Y-m-d H:i:s', strtotime($now . ' +24 hours'));
            if (!$other && $work['reason']) {
                $row['other_note'] = trim(($row['other_note'] ?? '') . ' [' . $work['reason'] . ']');
            }
            $id = self::insert($row);
            $number = self::find($id)['number'];
            foreach (PermissionRepository::usersWithPermission('manage_pricing') as $userId) {
                NotificationRepository::create($userId, null, 'price_request', null, // D-280: one e-mail per approval
                    "Price request {$number} from {$associate['name']} needs a manual price: {$product['name']} — {$row['selection_text']}.");
            }
            AuditLogRepository::log(null, 'PRICE_REQUEST_MANUAL', 'price_requests', $id, null, null, "{$number}: {$product['name']} — {$row['selection_text']}");
            return [$id, null];
        }
        $limit = self::dailyLimit($associate);
        $refusal = 'You have used today\'s ' . $limit . ' price requests. Ask NexaCrest if you need more today.';
        if (self::leftToday($associate) <= 0) {
            return [null, $refusal];
        }
        $row['kind'] = 'auto';
        $row['status'] = 'valid';
        $row['quote'] = number_format($work['quote'], 2, '.', '');
        $row['lowest'] = number_format($work['lowest'], 2, '.', '');
        $row['figures'] = json_encode($work['figures'], JSON_PRESERVE_ZERO_FRACTION);
        $row['breakdown'] = json_encode(['lines' => $work['breakdown'], 'charges' => $work['charges'], 'per_m2' => $work['per_m2'], 'm2_per_cbm' => $work['m2_per_cbm']], JSON_PRESERVE_ZERO_FRACTION);
        $row['valid_until'] = date('Y-m-d', strtotime($now . ' +' . self::validDays($associate) . ' days'));
        // Fresh-eyes review L4: the count and the insert run under a lock on the associate's row,
        // so requests fired at the same moment cannot pass the daily limit together.
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $claim = $pdo->prepare('SELECT id FROM associates WHERE id = :a FOR UPDATE');
            $claim->execute(['a' => (int) $associate['id']]);
            $claim->fetchColumn();
            if ($limit - self::requestsToday((int) $associate['id']) <= 0) {
                $pdo->rollBack();
                return [null, $refusal];
            }
            $id = self::insert($row);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        AuditLogRepository::log(null, 'PRICE_REQUEST_AUTO', 'price_requests', $id, null, null, self::find($id)['number'] . ": {$product['name']} — {$row['selection_text']} → {$row['quote']} / {$row['lowest']} per {$row['unit']}");
        return [$id, null];
    }

    /** Manual requests waiting for a price (shown beside the "Price requests" link). */
    public static function pendingCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM price_requests WHERE status = 'pending'" . DivisionService::sql('division_id'))->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM price_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function findByNumber(string $number): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM price_requests WHERE number = :n');
        $stmt->execute(['n' => strtoupper(trim($number))]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** "valid" | "expired" | "pending" | "priced" | "ordered" | "refused" as shown. */
    public static function displayStatus(array $r): string
    {
        if (in_array($r['status'], ['valid', 'priced'], true) && !empty($r['valid_until']) && (string) $r['valid_until'] < date('Y-m-d')) {
            return 'expired';
        }
        return (string) $r['status'];
    }

    /** @return list<array<string,mixed>> an associate's own requests, newest first */
    public static function forAssociate(int $associateId, int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, p.name AS product_name FROM price_requests r JOIN catalog_products p ON p.id = r.product_id
             WHERE r.associate_id = :a ORDER BY r.id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute(['a' => $associateId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> the staff queue: manual ones waiting first, then the newest */
    public static function queue(string $filter = 'all', int $limit = 200): array
    {
        $where = '';
        if ($filter === 'pending') {
            $where = "WHERE r.status = 'pending'";
        } elseif ($filter === 'auto') {
            $where = "WHERE r.kind = 'auto'";
        } elseif ($filter === 'manual') {
            $where = "WHERE r.kind = 'manual'";
        }
        return Database::connection()->query(
            "SELECT r.*, p.name AS product_name, a.name AS associate_name FROM price_requests r
             JOIN catalog_products p ON p.id = r.product_id JOIN associates a ON a.id = r.associate_id
             {$where}" . ($where === '' ? ' WHERE 1 = 1' : '') . DivisionService::sql('r.division_id') . " ORDER BY (r.status = 'pending') DESC, r.id DESC LIMIT " . (int) $limit
        )->fetchAll();
    }

    /**
     * Requests against orders per associate over the last 90 days (orders = orders of the
     * associate's clients created in the same 90 days).
     *
     * @return list<array{associate_id:int, name:string, requests:int, orders:int, warn:bool}>
     */
    public static function report(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $from = date('Y-m-d', strtotime($today . ' -90 days')) . ' 00:00:00';
        $stmt = Database::connection()->prepare(
            'SELECT a.id, a.name,
                    (SELECT COUNT(*) FROM price_requests r WHERE r.associate_id = a.id AND r.requested_at >= :f1) AS requests,
                    (SELECT COUNT(*) FROM orders o JOIN clients c ON c.id = o.client_id
                      WHERE c.associate_id = a.id AND o.created_at >= :f2 AND o.is_test_data = 0' . DivisionService::sql('o.division_id') . ') AS orders_count
               FROM associates a
              WHERE EXISTS (SELECT 1 FROM price_requests r WHERE r.associate_id = a.id AND r.requested_at >= :f3)'
            // D-257 (fresh-eyes review L1): the associates of the request's division(s).
            . DivisionService::sql('a.division_id') . '
              ORDER BY requests DESC, a.name'
        );
        $stmt->execute(['f1' => $from, 'f2' => $from, 'f3' => $from]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $req = (int) $r['requests'];
            $ord = (int) $r['orders_count'];
            $out[] = ['associate_id' => (int) $r['id'], 'name' => (string) $r['name'], 'requests' => $req, 'orders' => $ord,
                'per_order' => $ord > 0 ? round($req / $ord, 1) : null, 'warn' => $ord === 0 && $req >= self::WARN_REQUESTS];
        }
        return $out;
    }

    /**
     * Staff price a manual request. Returns an error or null.
     */
    public static function priceManual(int $id, int $userId, string $quote, string $lowest, string $unit, string $note): ?string
    {
        $r = self::find($id);
        if ($r === null || $r['status'] !== 'pending') {
            return 'That price request is not waiting for a price.';
        }
        $q = filter_var($quote, FILTER_VALIDATE_FLOAT);
        $l = filter_var($lowest, FILTER_VALIDATE_FLOAT);
        if ($q === false || $l === false || $q <= 0 || $l <= 0 || $l > $q || !preg_match('/^\d+(\.\d{1,2})?$/', $quote) || !preg_match('/^\d+(\.\d{1,2})?$/', $lowest)) {
            return 'Enter the quote price and the lowest price in USD (at most 2 decimals, lowest not above the quote).';
        }
        $unit = trim($unit);
        if ($unit === '' || mb_strlen($unit) > 20) {
            return 'Say what unit the price is per (e.g. SQM or CBM).';
        }
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            return 'Keep the note to 500 characters.';
        }
        $associate = \App\Repositories\AssociateRepository::find((int) $r['associate_id']);
        $validUntil = date('Y-m-d', strtotime(date('Y-m-d') . ' +' . self::validDays($associate ?? []) . ' days'));
        $figures = ['quote' => round($q, 2), 'floor' => round($l, 2), 'minimum' => round($l, 2), 'unit' => $unit, 'manual' => true, 'from_price_request' => true];
        Database::connection()->prepare(
            "UPDATE price_requests SET status = 'priced', quote = :q, lowest = :l, unit = :u, price_note = :n, priced_by = :by, priced_at = NOW(),
                    valid_until = :v, figures = :f, breakdown = :b WHERE id = :id AND status = 'pending'"
        )->execute(['q' => number_format($q, 2, '.', ''), 'l' => number_format($l, 2, '.', ''), 'u' => $unit, 'n' => $note !== '' ? $note : null,
            'by' => $userId, 'v' => $validUntil, 'f' => json_encode($figures, JSON_PRESERVE_ZERO_FRACTION),
            'b' => json_encode(['lines' => [['label' => 'Priced by NexaCrest' . ($note !== '' ? ': ' . $note : ''), 'change' => '—']], 'charges' => []]), 'id' => $id]);
        AuditLogRepository::log($userId, 'PRICE_REQUEST_PRICED', 'price_requests', $id, null, null, "{$r['number']}: USD {$quote} / {$lowest} per {$unit}");
        if ($associate !== null) {
            AssociateService::notify($associate, 'price_ready', "Your price request {$r['number']} has a price: quote USD " . number_format($q, 2) . ', lowest USD ' . number_format($l, 2)
                . " per {$unit}, valid until " . \App\Helpers\Dates::short($validUntil) . '.');
        }
        return null;
    }

    /**
     * A price request an order line may use: valid (or priced), not expired, from this
     * order's client's associate. Returns [request, error].
     *
     * @return array{0: ?array, 1: ?string}
     */
    public static function usableForOrder(string $number, array $order): array
    {
        $r = self::findByNumber($number);
        if ($r === null) {
            return [null, 'That price request number was not found.'];
        }
        if (!in_array(self::displayStatus($r), ['valid', 'priced'], true)) {
            return [null, "Price request {$r['number']} cannot be used: it is " . self::displayStatus($r) . '.'];
        }
        $client = !empty($order['client_id']) ? \App\Repositories\ClientRepository::find((int) $order['client_id']) : null;
        if ($client === null || (int) ($client['associate_id'] ?? 0) !== (int) $r['associate_id']) {
            return [null, "Price request {$r['number']} belongs to another associate's buyers."];
        }
        if (strtoupper((string) ($order['currency_code'] ?? '')) !== 'USD') {
            return [null, 'Price requests are in USD — this order is in another currency.'];
        }
        return [$r, null];
    }

    public static function markOrdered(int $id, int $orderId): void
    {
        Database::connection()->prepare("UPDATE price_requests SET status = 'ordered', order_id = :o WHERE id = :id AND status IN ('valid','priced')")
            ->execute(['o' => $orderId, 'id' => $id]);
    }
}
