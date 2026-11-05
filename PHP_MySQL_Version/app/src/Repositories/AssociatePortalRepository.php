<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\AssociateCommission;
use App\Services\IncentiveService;

/**
 * Owner decision 1 Nov 2026 (AS-2 … AS-4): what the associate portal shows —
 * only the associate's own clients and their orders: order reference, a short
 * product description, the product FOB value, the stage in plain words,
 * whether the buyer has paid in full (no amounts), and the commission.
 * Never unit prices, costs, profit, suppliers, documents or buyer payment
 * amounts.
 */
final class AssociatePortalRepository
{
    /** Plain-word stage, as a buyer would see it. */
    public static function stageLabel(array $order): string
    {
        $status = (string) $order['status'];
        if ($status === 'cancelled') {
            return 'Cancelled';
        }
        if ($status === 'lost') {
            return 'Not proceeding';
        }
        if (in_array($status, ['shipped_docs_sent', 'complete'], true)) {
            return 'Shipped';
        }
        // stages_master: 1 quotation · 2 buyer PO · 3 PI · 4 OC/production · 5 supplier PO · 6 freight · 7 BL · 8 commercial invoice · 9 closure
        $n = (int) ($order['current_stage_number'] ?? 1);
        return match (true) {
            $n <= 1 => 'Enquiry & quotation',
            $n <= 3 => 'Order confirmed',
            $n <= 5 => 'In production',
            $n <= 8 => 'Shipping',
            default => 'Shipped',
        };
    }

    /** @return list<array<string,mixed>> the associate's clients with first order and the 10-day window */
    public static function clients(int $associateId): array
    {
        $out = [];
        foreach (AssociateRepository::clients($associateId) as $c) {
            $stmt = Database::connection()->prepare("SELECT MIN(DATE(created_at)) FROM orders WHERE client_id = :c AND status NOT IN ('cancelled','lost')");
            $stmt->execute(['c' => $c['id']]);
            $c['first_order_on'] = $stmt->fetchColumn() ?: null;
            $po = Database::connection()->prepare('SELECT MIN(buyer_po_received_on) FROM orders WHERE client_id = :c AND buyer_po_received_on IS NOT NULL');
            $po->execute(['c' => $c['id']]);
            $first = $po->fetchColumn();
            $c['window_ends_on'] = $first ? date('Y-m-d', strtotime($first . ' +9 days')) : null;
            $c['came_via'] = self::cameVia($c);
            $ord = Database::connection()->prepare("SELECT order_reference FROM orders WHERE client_id = :c AND status NOT IN ('cancelled','lost') AND is_test_data = 0 ORDER BY id");
            $ord->execute(['c' => $c['id']]);
            $c['order_refs'] = array_map('strval', $ord->fetchAll(\PDO::FETCH_COLUMN));
            $out[] = $c;
        }
        return $out;
    }

    /** D-254: how a client came to the associate — which code, a buyer registration link, or linked by NexaCrest. */
    public static function cameVia(array $client): string
    {
        $source = (string) ($client['associate_source'] ?? '');
        $inv = !empty($client['associate_invitation_id']) ? AssociateInvitationRepository::find((int) $client['associate_invitation_id']) : null;
        if ($source === 'code' || ($inv['kind'] ?? '') === 'personal_code') {
            return !empty($inv['code_used']) ? 'Code ' . $inv['code_used'] : 'Personal code';
        }
        if ($source === 'link' || ($inv['kind'] ?? '') === 'client_link') {
            return 'Buyer registration link';
        }
        return ['staff' => 'Linked by NexaCrest', 'md_change' => 'Changed by the MD'][$source] ?? ($source !== '' ? $source : '—');
    }

    /** @return list<array<string,mixed>> every order of the associate's clients, newest first */
    public static function orders(int $associateId, ?int $orderId = null): array
    {
        $sql = 'SELECT o.id, o.order_reference, o.status, o.created_at, o.client_id, sm.stage_number AS current_stage_number, o.buyer_po_received_on, o.associate_rate_pct, o.associate_rate_source,
                       c.company_legal_name, o.currency_code, oi.locked_at, oi.fully_paid_on, oi.associate_id AS paid_associate_id, oi.associate_amount_fc,
                       oi.associate_rate_pct AS locked_rate, oi.associate_mode, oi.incentive_month
                  FROM orders o JOIN clients c ON c.id = o.client_id
                  LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
                  LEFT JOIN order_incentives oi ON oi.order_id = o.id
                 WHERE c.associate_id = :a AND o.is_test_data = 0';
        $params = ['a' => $associateId];
        if ($orderId !== null) {
            $sql .= ' AND o.id = :o';
            $params['o'] = $orderId;
        }
        $stmt = Database::connection()->prepare($sql . ' ORDER BY o.created_at DESC, o.id DESC');
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $o) {
            $o['fob_value'] = OrderProductRepository::totalFobValue((int) $o['id']);
            $o['products'] = self::productSummary((int) $o['id']);
            $o['stage'] = self::stageLabel($o);
            $o['buyer_paid_in_full'] = IncentiveService::fullyPaidOn((int) $o['id']) !== null;
            $o['commission'] = self::commission($associateId, $o);
            $rows[] = $o;
        }
        return $rows;
    }

    /** "Granite slabs, 3 lines" — descriptions only, never prices. */
    private static function productSummary(int $orderId): string
    {
        $stmt = Database::connection()->prepare('SELECT description FROM order_products WHERE order_id = :o AND is_active = 1 ORDER BY line_no, id');
        $stmt->execute(['o' => $orderId]);
        $lines = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if ($lines === []) {
            return '—';
        }
        $first = mb_substr((string) $lines[0], 0, 60);
        return count($lines) > 1 ? $first . ' + ' . (count($lines) - 1) . ' more' : $first;
    }

    /**
     * The commission line for one order (owner decision D-245 P3: on the invoiced FOB
     * of the goods, line by line, at the level fixed at PI, full payment only).
     * @return array{state:string, label:string, rate:?float, source:?string, amount:?float, level:?string, detail:?array}
     */
    public static function commission(int $associateId, array $o): array
    {
        $none = static fn (string $label): array => ['state' => 'none', 'label' => $label, 'rate' => null, 'source' => null, 'amount' => null, 'level' => null, 'detail' => null];
        if (in_array($o['status'], ['cancelled', 'lost'], true)) {
            return $none('No commission — order cancelled');
        }
        $repeat = 'No commission — repeat order (outside the 10 days from the first Buyer PO)';
        if (!empty($o['locked_at'])) {
            if ((int) ($o['paid_associate_id'] ?? 0) !== $associateId) {
                return $none($repeat);
            }
            if ((float) $o['associate_amount_fc'] <= 0) {
                return $none('No commission — the buyer did not pay this order in full');
            }
            $payout = Database::connection()->prepare("SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND associate_id = :a AND clawback_of_payout_id IS NULL ORDER BY id LIMIT 1");
            $payout->execute(['o' => $o['id'], 'a' => $associateId]);
            $p = $payout->fetch() ?: ['status' => 'due', 'paid_on' => null, 'due_on' => null, 'payout_run_on' => null, 'order_id' => $o['id'], 'payee_kind' => 'associate', 'amount_inr' => 0];
            $claim = !empty($p['due_on']) && AssociateCommission::settings()['claim_hold'] && AssociateCommission::claimOpen((int) $o['id'], (string) $p['due_on']);
            $label = match (true) {
                $p['status'] === 'paid' => 'Paid on ' . \App\Helpers\Dates::short((string) $p['paid_on']),
                $p['status'] === 'held' => 'On hold',
                $claim => 'Earned — waiting while a buyer claim on the order is open',
                !empty($p['due_on']) => 'Earned — due ' . \App\Helpers\Dates::short((string) $p['due_on']) . ', paid in the ' . \App\Helpers\Dates::short((string) $p['payout_run_on']) . ' payout run',
                default => 'Earned — to be paid in the ' . IncentiveService::monthLabel((string) $o['incentive_month']) . ' payout',
            };
            return ['state' => $p['status'] === 'paid' ? 'paid' : ($p['status'] === 'held' || $claim ? 'held' : 'earned'), 'label' => $label,
                'rate' => $o['locked_rate'] !== null ? (float) $o['locked_rate'] : null, 'source' => $o['associate_rate_source'] ?? null, 'amount' => (float) $o['associate_amount_fc'],
                'level' => self::levelText($o), 'detail' => AssociateCommission::forOrder((int) $o['id'])];
        }
        if (($o['associate_mode'] ?? 'auto') === 'no') {
            return $none('No commission on this order (decided by NexaCrest)');
        }
        if (($o['associate_rate_source'] ?? null) === 'none') {
            return $none('No commission under your agreement');
        }
        $order = OrderRepository::find((int) $o['id']) ?? [];
        $c = AssociateCommission::forOrder((int) $o['id'], $order);
        if ($c === null) {
            return $none($repeat);
        }
        $label = IncentiveService::fullyPaidOn((int) $o['id']) !== null
            ? 'Buyer has paid in full — NexaCrest is confirming your commission'
            : 'Not yet earned — waiting for the buyer\'s full payment';
        return ['state' => 'expected', 'label' => $label, 'rate' => $c['rate'], 'source' => $o['associate_rate_source'] ?? null, 'amount' => $c['amount_fc'],
            'level' => $c['level_label'] . ($c['level_fixed'] ? '' : ' (for now — fixed when the Proforma Invoice is issued)'), 'detail' => $c];
    }

    /** "Level 2 — 5%", as fixed on the order (or the rate kept on it). */
    private static function levelText(array $o): ?string
    {
        $order = OrderRepository::find((int) $o['id']);
        $fixed = $order !== null ? AssociateCommission::fixedLevel($order) : null;
        if ($fixed !== null) {
            return AssociateCommission::levelLabel($fixed);
        }
        return $o['locked_rate'] !== null ? rtrim(rtrim(number_format((float) $o['locked_rate'], 2), '0'), '.') . '%' : null;
    }

    /** @return list<array<string,mixed>> the associate's payouts (commission lines), newest first */
    public static function payouts(int $associateId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT p.*, o.order_reference, c.company_legal_name FROM incentive_payouts p JOIN orders o ON o.id = p.order_id JOIN clients c ON c.id = o.client_id
              WHERE p.payee_kind = 'associate' AND p.associate_id = :a ORDER BY p.incentive_month DESC, p.id DESC"
        );
        $stmt->execute(['a' => $associateId]);
        return $stmt->fetchAll();
    }

    /** @return array{earned_year: float, waiting: float, paid_year: float, held: float} INR totals for the home page */
    public static function totals(int $associateId): array
    {
        $year = date('Y');
        $t = ['earned_year' => 0.0, 'waiting' => 0.0, 'paid_year' => 0.0, 'held' => 0.0];
        foreach (self::payouts($associateId) as $p) {
            $amount = (float) $p['amount_inr'];
            if (str_starts_with((string) $p['incentive_month'], $year)) {
                $t['earned_year'] += $amount;
            }
            if ($p['status'] === 'due') {
                $t['waiting'] += $amount;
            } elseif ($p['status'] === 'held') {
                $t['held'] += $amount;
            } elseif ($p['status'] === 'paid' && str_starts_with((string) $p['paid_on'], $year)) {
                $t['paid_year'] += (float) $p['paid_inr'];
            }
        }
        return array_map(static fn (float $v): float => round($v, 2), $t);
    }
}
