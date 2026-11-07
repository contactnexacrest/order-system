<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\IncentiveRepository;

/**
 * Owner decision D-245 P5: the incentive & commission reports — one row per
 * order × payee (a staff member's share or an associate's commission).
 *
 * - Locked orders: the payouts written when the MD locked the order
 *   (state locked / held / paid, with due date and payout run).
 * - Open orders (not locked, not cancelled or lost, with a Proforma Invoice or
 *   a buyer payment): the ESTIMATED shares from the same calculation the order
 *   page shows (IncentiveService::calculate) — state "estimated", or "ready"
 *   once the buyer has paid in full and no claim is open.
 *
 * Every total is the plain sum of its rows, so a report can never disagree
 * with the orders behind it (tested).
 */
final class IncentiveReportService
{
    public const STATES = [
        'estimated' => 'Estimated (not locked)',
        'ready' => 'Ready to lock',
        'locked' => 'Locked, not paid',
        'held' => 'Held',
        'paid' => 'Paid',
    ];

    /** D-245 P6: rows per page on the register (totals are always over the whole filtered set). */
    public const PER_PAGE = 100;

    public const HEADERS = ['Order', 'Client', 'Order date', 'Payee', 'Payee type', 'Role', 'Amount (INR)', 'GST (INR)', 'TDS (INR)', 'State', 'Due on', 'Payout run', 'Paid on', 'Kept — not eligible (INR)', 'Kept — capped (INR)'];

    private static function testFlag(): int
    {
        return TestModeService::isEnabled() ? 1 : 0;
    }

    /** @return list<array<string,mixed>> payout rows of locked orders */
    private static function lockedRows(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, o.order_reference, o.buyer_inquiry_ref, o.company_legal_name, o.created_at AS order_created_at,
                    u.name AS user_name, a.name AS associate_name
             FROM incentive_payouts p
             JOIN orders o ON o.id = p.order_id
             LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN associates a ON a.id = p.associate_id
             WHERE o.is_test_data = :t AND o.is_sample_data = 0'
            // D-257 (fresh-eyes review L1): only the orders of the request's division(s).
            . \App\Services\DivisionService::sql('o.division_id') . '
             ORDER BY o.id, p.payee_kind DESC, p.id'
        );
        $stmt->execute(['t' => self::testFlag()]);
        $rows = [];
        $kept = self::lockedKeptCapped();
        foreach ($stmt->fetchAll() as $p) {
            $associate = $p['payee_kind'] === 'associate';
            $state = $p['status'] === 'paid' ? 'paid' : ($p['status'] === 'held' ? 'held' : 'locked');
            $gst = $p['status'] === 'paid' ? (float) ($p['gst_inr'] ?? 0) : AssociateCommission::gstFor($p);
            $rows[] = [
                'order_id' => (int) $p['order_id'],
                'order_reference' => (string) (($p['buyer_inquiry_ref'] ?? '') ?: $p['order_reference']),
                'client' => (string) $p['company_legal_name'],
                'order_date' => substr((string) $p['order_created_at'], 0, 10),
                'payee_kind' => $associate ? 'associate' : 'staff',
                'user_id' => $p['user_id'] !== null ? (int) $p['user_id'] : null,
                'associate_id' => $p['associate_id'] !== null ? (int) $p['associate_id'] : null,
                'payee' => (string) ($associate ? ($p['associate_name'] ?? 'Associate') : ($p['user_name'] ?? 'Staff')),
                'role' => $associate ? 'Associate commission' : IncentiveRepository::roleLabel((string) $p['role']),
                'amount_inr' => round((float) $p['amount_inr'], 2),
                'gst_inr' => round($gst, 2),
                'tds_inr' => $p['tds_inr'] !== null ? round((float) $p['tds_inr'], 2) : 0.0,
                'state' => $state,
                'due_on' => $p['due_on'] !== null ? (string) $p['due_on'] : null,
                'payout_run_on' => $p['payout_run_on'] !== null ? (string) $p['payout_run_on'] : null,
                'locked_on' => $p['locked_on'] !== null ? (string) $p['locked_on'] : null,
                'paid_on' => $p['paid_on'] !== null ? (string) $p['paid_on'] : null,
                'kept_not_eligible_inr' => 0.0,
                'kept_capped_inr' => $associate ? 0.0 : (float) ($kept[$p['order_id'] . ':' . $p['user_id'] . ':' . $p['role']] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * D-259: what the company kept on locked orders, by order:person:role (capped lines sit on the
     * person's payout row; not-eligible lines have no payout and become kept-only register rows).
     *
     * @return array<string, float>
     */
    private static function lockedKeptCapped(): array
    {
        $out = [];
        foreach (Database::connection()->query("SELECT order_id, user_id, role, kept_inr FROM incentive_kept WHERE kept_reason <> 'not_eligible'")->fetchAll() as $k) {
            $out[$k['order_id'] . ':' . $k['user_id'] . ':' . $k['role']] = round((float) $k['kept_inr'], 2);
        }
        return $out;
    }

    /** D-259: kept-only register rows of locked orders (people not eligible: nothing paid, all kept). @return list<array<string,mixed>> */
    private static function lockedKeptOnlyRows(): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT k.*, o.order_reference, o.buyer_inquiry_ref, o.company_legal_name, o.created_at AS order_created_at, u.name AS user_name,
                    oi.locked_at
             FROM incentive_kept k
             JOIN orders o ON o.id = k.order_id
             JOIN users u ON u.id = k.user_id
             LEFT JOIN order_incentives oi ON oi.order_id = k.order_id
             WHERE k.kept_reason = 'not_eligible' AND o.is_test_data = :t AND o.is_sample_data = 0"
            . \App\Services\DivisionService::sql('o.division_id') . ' ORDER BY k.order_id, k.id'
        );
        $stmt->execute(['t' => self::testFlag()]);
        $rows = [];
        foreach ($stmt->fetchAll() as $k) {
            $rows[] = [
                'order_id' => (int) $k['order_id'],
                'order_reference' => (string) (($k['buyer_inquiry_ref'] ?? '') ?: $k['order_reference']),
                'client' => (string) $k['company_legal_name'],
                'order_date' => substr((string) $k['order_created_at'], 0, 10),
                'payee_kind' => 'staff', 'user_id' => (int) $k['user_id'], 'associate_id' => null,
                'payee' => (string) $k['user_name'], 'role' => IncentiveRepository::roleLabel((string) $k['role']),
                'amount_inr' => 0.0, 'gst_inr' => 0.0, 'tds_inr' => 0.0, 'state' => 'locked',
                'due_on' => null, 'payout_run_on' => null, 'locked_on' => (string) $k['locked_on'], 'paid_on' => null,
                'kept_not_eligible_inr' => round((float) $k['kept_inr'], 2), 'kept_capped_inr' => 0.0, 'kept_only' => true,
            ];
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $f the register filters: only the open orders that can match them are estimated
     *        (order date from/to, associate, order ids) — D-245 P6: the register no longer works out every open order.
     * @return list<int> open orders that carry an estimate: not locked, not cancelled/lost, with a PI or a buyer payment
     */
    public static function openOrderIds(array $f = []): array
    {
        $state = (string) ($f['state'] ?? '');
        if (in_array($state, ['locked', 'held', 'paid'], true)) {
            return [];
        }
        // D-257 (fresh-eyes review L1): only the orders of the request's division(s).
        $where = \App\Services\DivisionService::sql('o.division_id');
        $params = ['t' => self::testFlag()];
        if (($f['from'] ?? '') !== '') {
            $where .= ' AND DATE(o.created_at) >= :from';
            $params['from'] = (string) $f['from'];
        }
        if (($f['to'] ?? '') !== '') {
            $where .= ' AND DATE(o.created_at) <= :to';
            $params['to'] = (string) $f['to'];
        }
        if ((int) ($f['associate_id'] ?? 0) > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM clients c WHERE c.id = o.client_id AND c.associate_id = :aid)';
            $params['aid'] = (int) $f['associate_id'];
        }
        if (!empty($f['order_ids'])) {
            $ids = array_map('intval', (array) $f['order_ids']);
            $where .= ' AND o.id IN (' . implode(',', $ids ?: [0]) . ')';
        }
        $stmt = Database::connection()->prepare(
            "SELECT o.id FROM orders o
             LEFT JOIN order_incentives oi ON oi.order_id = o.id
             LEFT JOIN order_payment_status ps ON ps.order_id = o.id
             WHERE oi.locked_at IS NULL AND o.is_test_data = :t AND o.is_sample_data = 0
               AND o.status NOT IN ('cancelled','lost')
               AND (ps.advance_cleared_at IS NOT NULL OR ps.balance_cleared_at IS NOT NULL OR ps.freight_cleared_at IS NOT NULL
                    OR EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE d.order_id = o.id AND dt.code = 'PI'))"
            . $where . ' ORDER BY o.id'
        );
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> estimated rows of one open order */
    public static function estimatedRows(int $orderId): array
    {
        $order = \App\Repositories\OrderRepository::find($orderId);
        if ($order === null) {
            return [];
        }
        $calc = IncentiveService::calculate($orderId);
        // $calc['state'] is 'ready' exactly when readyReason() is null for an unlocked order (D-245 P5: not asked twice).
        $ready = $calc['state'] === 'ready';
        $state = $ready ? 'ready' : 'estimated';
        $staffRun = $ready ? AssociateCommission::payoutRunOn(date('Y-m-d')) : null;
        $base = [
            'order_id' => $orderId,
            'order_reference' => (string) (($order['buyer_inquiry_ref'] ?? '') ?: $order['order_reference']),
            'client' => (string) $order['company_legal_name'],
            'order_date' => substr((string) $order['created_at'], 0, 10),
            'gst_inr' => 0.0, 'tds_inr' => 0.0, 'state' => $state, 'locked_on' => null, 'paid_on' => null,
        ];
        $rows = [];
        // D-259: what the company keeps from each person (not eligible / above a cap).
        $keptNe = [];
        $keptCap = [];
        foreach ($calc['lines'] ?? [] as $l) {
            if ($l['reason'] === 'not_eligible') {
                $keptNe[$l['user_id']] = round(($keptNe[$l['user_id']] ?? 0.0) + $l['kept'], 2);
            } elseif ($l['reason'] !== null) {
                $keptCap[$l['user_id']] = round(($keptCap[$l['user_id']] ?? 0.0) + $l['kept'], 2);
            }
        }
        foreach ($calc['per_person'] as $p) {
            $ne = $keptNe[(int) $p['id']] ?? 0.0;
            $cap = $keptCap[(int) $p['id']] ?? 0.0;
            if ($p['amount'] <= 0 && $ne <= 0 && $cap <= 0) {
                continue;
            }
            $rows[] = $base + ['payee_kind' => 'staff', 'user_id' => (int) $p['id'], 'associate_id' => null, 'payee' => (string) $p['name'],
                'role' => implode(', ', array_unique($p['roles'])), 'amount_inr' => round((float) $p['amount'], 2), 'due_on' => null, 'payout_run_on' => $staffRun,
                'kept_not_eligible_inr' => $ne, 'kept_capped_inr' => $cap, 'kept_only' => $p['amount'] <= 0];
        }
        $a = $calc['associate'];
        // D-264: a commission fixed at full payment already has its payout row (shown with the locked rows).
        if ($a !== null && empty($a['fixed']) && ($a['state'] ?? '') !== 'unpaid' && (float) $a['amount_inr'] > 0) {
            $rows[] = $base + ['payee_kind' => 'associate', 'user_id' => null, 'associate_id' => (int) $a['id'], 'payee' => (string) $a['name'],
                'role' => 'Associate commission', 'amount_inr' => round((float) $a['amount_inr'], 2),
                'due_on' => $a['due_on'] ?? null, 'payout_run_on' => $a['payout_run_on'] ?? null,
                'kept_not_eligible_inr' => 0.0, 'kept_capped_inr' => 0.0];
        }
        return $rows;
    }

    /**
     * The register. Filters: state (estimated|ready|locked|held|paid), month (YYYY-MM of the
     * payout run), user_id, associate_id, from / to (order date).
     *
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    public static function rows(array $f = []): array
    {
        $rows = self::filter(self::allRows($f), $f);
        // D-259: kept-only rows (nothing paid) are for the register only, never "to pay" / "owed".
        return empty($f['with_kept']) ? array_values(array_filter($rows, static fn (array $r): bool => empty($r['kept_only']))) : $rows;
    }

    /**
     * @param array<string,mixed> $f optional filters: only open orders that can match them are estimated
     * @return list<array<string,mixed>> locked rows and estimated rows (before filtering)
     */
    public static function allRows(array $f = []): array
    {
        $rows = array_merge(self::lockedRows(), self::lockedKeptOnlyRows());
        // D-245 P5 (performance): settings and per-order rows read once for the whole run.
        \App\Helpers\BatchCache::within(static function () use (&$rows, $f): void {
            foreach (self::openOrderIds($f) as $orderId) {
                array_push($rows, ...self::estimatedRows($orderId));
            }
        });
        return $rows;
    }

    /**
     * One page of the filtered rows (D-245 P6). Totals stay over the whole set.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{rows: list<array<string,mixed>>, page: int, pages: int}
     */
    public static function page(array $rows, int $page, int $perPage = self::PER_PAGE): array
    {
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $page = min(max(1, $page), $pages);
        return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'page' => $page, 'pages' => $pages];
    }

    /**
     * People and associates to choose from on the register and statement — from the incentive roles,
     * the payouts and the associates table, without working out any estimate.
     *
     * @return array{people: array<int,string>, associates: array<int,string>}
     */
    public static function payeeOptions(): array
    {
        $pdo = Database::connection();
        $people = [];
        foreach ($pdo->query('SELECT u.id, u.name FROM users u WHERE u.id IN (SELECT user_id FROM user_incentive_roles) OR u.id IN (SELECT user_id FROM incentive_payouts WHERE user_id IS NOT NULL) OR u.id IN (SELECT user_id FROM order_incentive_people) ORDER BY u.name')->fetchAll() as $r) {
            $people[(int) $r['id']] = (string) $r['name'];
        }
        $associates = [];
        foreach ($pdo->query('SELECT id, name FROM associates ORDER BY name')->fetchAll() as $r) {
            $associates[(int) $r['id']] = (string) $r['name'];
        }
        return ['people' => $people, 'associates' => $associates];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    public static function filter(array $rows, array $f): array
    {
        $state = (string) ($f['state'] ?? '');
        $month = (string) ($f['month'] ?? '');
        $userId = (int) ($f['user_id'] ?? 0);
        $associateId = (int) ($f['associate_id'] ?? 0);
        $from = (string) ($f['from'] ?? '');
        $to = (string) ($f['to'] ?? '');
        $orderIds = array_map('intval', (array) ($f['order_ids'] ?? []));
        $rows = array_values(array_filter($rows, static function (array $r) use ($state, $month, $userId, $associateId, $from, $to, $orderIds): bool {
            return ($orderIds === [] || in_array($r['order_id'], $orderIds, true))
                && ($state === '' || $r['state'] === $state)
                && ($month === '' || substr((string) ($r['payout_run_on'] ?? ''), 0, 7) === $month)
                && ($userId === 0 || $r['user_id'] === $userId)
                && ($associateId === 0 || $r['associate_id'] === $associateId)
                && ($from === '' || $r['order_date'] >= $from)
                && ($to === '' || $r['order_date'] <= $to);
        }));
        usort($rows, static fn (array $a, array $b): int => [$a['order_id'], $a['payee_kind'] === 'associate' ? 1 : 0, $a['payee']] <=> [$b['order_id'], $b['payee_kind'] === 'associate' ? 1 : 0, $b['payee']]);
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @return array{amount_inr: float, gst_inr: float, tds_inr: float, count: int} */
    public static function totals(array $rows): array
    {
        $t = ['amount_inr' => 0.0, 'gst_inr' => 0.0, 'tds_inr' => 0.0, 'kept_ne' => 0.0, 'kept_cap' => 0.0, 'count' => count($rows)];
        foreach ($rows as $r) {
            $t['amount_inr'] += (float) $r['amount_inr'];
            $t['gst_inr'] += (float) $r['gst_inr'];
            $t['tds_inr'] += (float) $r['tds_inr'];
            $t['kept_ne'] += (float) ($r['kept_not_eligible_inr'] ?? 0);
            $t['kept_cap'] += (float) ($r['kept_capped_inr'] ?? 0);
        }
        return ['amount_inr' => round($t['amount_inr'], 2), 'gst_inr' => round($t['gst_inr'], 2), 'tds_inr' => round($t['tds_inr'], 2), 'count' => $t['count'],
            'kept_not_eligible_inr' => round($t['kept_ne'], 2), 'kept_capped_inr' => round($t['kept_cap'], 2)];
    }

    /**
     * "To pay": what falls in this month's payout run, in next month's, and what is not
     * locked yet (estimated). Payee type: '' (all) | staff | associate.
     *
     * @return array<string, array{label: string, rows: list<array<string,mixed>>, totals: array<string,float|int>}>
     */
    public static function toPay(string $payeeKind = '', ?string $today = null, array $f = []): array
    {
        $today ??= date('Y-m-d');
        $thisMonth = substr($today, 0, 7);
        $nextMonth = date('Y-m', (int) strtotime(substr($today, 0, 7) . '-01 +1 month'));
        $all = array_values(array_filter(self::rows($f), static fn (array $r): bool => $payeeKind === '' || $r['payee_kind'] === $payeeKind));
        $groups = [
            'this' => ['label' => 'This month\'s payout run (' . IncentiveService::monthLabel($thisMonth) . ')', 'rows' => []],
            'next' => ['label' => 'Next month\'s payout run (' . IncentiveService::monthLabel($nextMonth) . ')', 'rows' => []],
            'open' => ['label' => 'Not locked yet (estimated)', 'rows' => []],
        ];
        foreach ($all as $r) {
            if ($r['state'] === 'paid') {
                continue;
            }
            $run = substr((string) ($r['payout_run_on'] ?? ''), 0, 7);
            if (in_array($r['state'], ['locked', 'held'], true) && $run !== '' && $run <= $thisMonth) {
                $groups['this']['rows'][] = $r;
            } elseif (in_array($r['state'], ['locked', 'held'], true) && $run === $nextMonth) {
                $groups['next']['rows'][] = $r;
            } elseif (in_array($r['state'], ['estimated', 'ready'], true)) {
                $groups['open']['rows'][] = $r;
            }
        }
        foreach ($groups as $k => $g) {
            $groups[$k]['totals'] = self::totals($g['rows']);
        }
        return $groups;
    }

    /**
     * What NexaCrest owes staff and associates at a date: payouts locked on or before it
     * and not paid by it; for today or later also the orders ready to lock (estimated).
     *
     * @return list<array<string,mixed>>
     */
    public static function owedAt(string $date, array $f = []): array
    {
        $out = [];
        $orderIds = array_map('intval', (array) ($f['order_ids'] ?? []));
        foreach (self::lockedRows() as $r) {
            if ($orderIds !== [] && !in_array($r['order_id'], $orderIds, true)) {
                continue;
            }
            $lockedBy = $r['locked_on'] === null || $r['locked_on'] <= $date;
            $unpaidThen = $r['paid_on'] === null || $r['paid_on'] > $date;
            if ($lockedBy && $unpaidThen && (float) $r['amount_inr'] > 0) {
                $r['state'] = $r['state'] === 'paid' ? 'locked' : $r['state'];
                $out[] = $r;
            }
        }
        if ($date >= date('Y-m-d')) {
            \App\Helpers\BatchCache::within(static function () use (&$out, $f): void {
                foreach (self::openOrderIds($f) as $orderId) {
                    foreach (self::estimatedRows($orderId) as $r) {
                        if ($r['state'] === 'ready' && empty($r['kept_only'])) {
                            $out[] = $r;
                        }
                    }
                }
            });
        }
        return $out;
    }

    /** @return list<array<string,mixed>> one staff member's or one associate's rows, every state */
    public static function statement(?int $userId, ?int $associateId): array
    {
        if (!$userId && !$associateId) {
            return [];
        }
        return self::rows(['user_id' => $userId ?: 0, 'associate_id' => $associateId ?: 0]);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> exactly the on-screen columns */
    public static function csvRows(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'Order' => $r['order_reference'],
            'Client' => $r['client'],
            'Order date' => $r['order_date'],
            'Payee' => $r['payee'],
            'Payee type' => $r['payee_kind'] === 'associate' ? 'Associate' : 'Staff',
            'Role' => $r['role'],
            'Amount (INR)' => number_format((float) $r['amount_inr'], 2, '.', ''),
            'GST (INR)' => number_format((float) $r['gst_inr'], 2, '.', ''),
            'TDS (INR)' => number_format((float) $r['tds_inr'], 2, '.', ''),
            'State' => self::STATES[$r['state']] ?? $r['state'],
            'Due on' => $r['due_on'] ?? '',
            'Payout run' => $r['payout_run_on'] ?? '',
            'Paid on' => $r['paid_on'] ?? '',
            'Kept — not eligible (INR)' => number_format((float) ($r['kept_not_eligible_inr'] ?? 0), 2, '.', ''),
            'Kept — capped (INR)' => number_format((float) ($r['kept_capped_inr'] ?? 0), 2, '.', ''),
        ], $rows);
    }
}
