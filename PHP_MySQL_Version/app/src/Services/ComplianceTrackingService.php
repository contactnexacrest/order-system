<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;

/**
 * Owner decision (2 Nov 2026, gap-list Q-6 — "go with your recommendation"):
 *  (a) eBRC / FIRC never blocks Mark Fully Settled — a warning on 13h, a
 *      monthly "Missing eBRC / FIRC" report and a monthly reminder instead;
 *  (b) ECGC — the buyer's ECGC-approved credit limit, a warning when the
 *      buyer's open exposure is above it, and the monthly shipment
 *      declaration (by the 10th of the month after the BL);
 *  (c) export incentives — sanctioned amount, claim deadline (default per
 *      scheme from company settings, to be confirmed with the CA) and a claim
 *      status, with reminders 30 and 7 days before the deadline.
 * Node twin: src/services/complianceTrackingService.js (same rules, same wording).
 */
final class ComplianceTrackingService
{
    public const LEGS = ['advance' => 'Advance', 'freight' => 'Freight & insurance (Debit Note)', 'balance' => 'Balance'];
    public const CLAIM_STATUSES = ['filed' => 'Filed', 'sanctioned' => 'Sanctioned', 'received' => 'Received', 'rejected' => 'Rejected'];

    // ------------------------------------------------------------------ (a)

    /**
     * Every leg of one order whose money has landed but has no FIRC / eBRC reference yet.
     *
     * @return list<array{order_id:int,leg:string,label:string,amount:float,currency:string,received_at:string,days:int}>
     */
    public static function missingFircLegs(int $orderId): array
    {
        return self::missingFirc('WHERE o.id = :id', ['id' => $orderId]);
    }

    /**
     * The monthly report: every order (not lost, not test data) with a leg received and no FIRC / eBRC.
     *
     * @return list<array<string,mixed>>
     */
    public static function missingFircReport(): array
    {
        return self::missingFirc("WHERE o.status <> 'lost' AND COALESCE(o.is_test_data, 0) = 0", []);
    }

    /** @param array<string,mixed> $params */
    private static function missingFirc(string $where, array $params): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT o.id, o.order_reference, o.currency_code, c.company_legal_name,
                    ps.advance_amount, ps.advance_remittance_received_at, ps.advance_firc_reference,
                    ps.freight_amount, ps.freight_remittance_received_at, ps.freight_firc_reference,
                    ps.balance_amount, ps.balance_remittance_received_at, ps.balance_firc_reference,
                    lc.lc_received_at, lc.lc_received_amount, lc.lc_firc_reference,
                    (SELECT GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ') FROM order_assignees oa JOIN users u ON u.id = oa.user_id WHERE oa.order_id = o.id) AS owners
               FROM orders o
               JOIN clients c ON c.id = o.client_id
               LEFT JOIN order_payment_status ps ON ps.order_id = o.id
               LEFT JOIN order_lc_details lc ON lc.order_id = o.id
             {$where}
              ORDER BY o.id"
        );
        $stmt->execute($params);
        $today = new \DateTimeImmutable('today');
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $legs = [];
            foreach (self::LEGS as $leg => $label) {
                if (!empty($r[$leg . '_remittance_received_at']) && trim((string) ($r[$leg . '_firc_reference'] ?? '')) === '') {
                    $legs[] = [$leg, $label, (float) ($r[$leg . '_amount'] ?? 0), (string) $r[$leg . '_remittance_received_at']];
                }
            }
            if (!empty($r['lc_received_at']) && trim((string) ($r['lc_firc_reference'] ?? '')) === '') {
                $legs[] = ['lc', 'Letter of Credit', (float) ($r['lc_received_amount'] ?? 0), (string) $r['lc_received_at']];
            }
            foreach ($legs as [$leg, $label, $amount, $receivedAt]) {
                $received = new \DateTimeImmutable(substr($receivedAt, 0, 10));
                $out[] = [
                    'order_id' => (int) $r['id'],
                    'order_reference' => (string) $r['order_reference'],
                    'client' => (string) $r['company_legal_name'],
                    'leg' => $leg,
                    'label' => $label,
                    'amount' => $amount,
                    'currency' => (string) ($r['currency_code'] ?? ''),
                    'received_at' => $received->format('Y-m-d'),
                    'days' => (int) $received->diff($today)->days * ($received > $today ? -1 : 1),
                    'owners' => (string) ($r['owners'] ?? ''),
                ];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ (b)

    /**
     * The buyer's ECGC position: the approved limit and the open exposure.
     *
     * @return array{policy_no:string,cover_pct:string,limit:?float,currency:?string,valid_to:?string,approved_on:?string,ref:?string,file_id:?int,expired:bool,exposure:array<string,float>,open_orders:int,over_limit:bool,over_by:float,warning:?string}
     */
    public static function ecgcPosition(int $clientId): array
    {
        $pdo = Database::connection();
        $c = $pdo->prepare('SELECT ecgc_limit_amount, ecgc_limit_currency, ecgc_limit_approved_on, ecgc_limit_valid_to, ecgc_limit_ref, ecgc_limit_file_id FROM clients WHERE id = :id');
        $c->execute(['id' => $clientId]);
        $client = $c->fetch() ?: [];

        $o = $pdo->prepare(
            "SELECT o.id, o.currency_code,
                    (SELECT COALESCE(SUM(p.fob_value), 0) FROM order_products p WHERE p.order_id = o.id AND p.is_active = 1 AND p.line_type <> 'service') AS fob,
                    ps.advance_amount, ps.advance_remittance_received_at, ps.balance_amount, ps.balance_remittance_received_at
               FROM orders o
               LEFT JOIN order_payment_status ps ON ps.order_id = o.id
              WHERE o.client_id = :c AND o.status = 'active' AND COALESCE(o.is_test_data, 0) = 0"
        );
        $o->execute(['c' => $clientId]);
        $exposure = [];
        $open = 0;
        foreach ($o->fetchAll() as $row) {
            $received = (!empty($row['advance_remittance_received_at']) ? (float) $row['advance_amount'] : 0.0)
                + (!empty($row['balance_remittance_received_at']) ? (float) $row['balance_amount'] : 0.0);
            $outstanding = max(0.0, round((float) $row['fob'] - $received, 2));
            $ccy = (string) ($row['currency_code'] ?? '');
            $exposure[$ccy] = round(($exposure[$ccy] ?? 0.0) + $outstanding, 2);
            $open++;
        }

        $limit = isset($client['ecgc_limit_amount']) && $client['ecgc_limit_amount'] !== null ? (float) $client['ecgc_limit_amount'] : null;
        $currency = $client['ecgc_limit_currency'] ?? null;
        $validTo = $client['ecgc_limit_valid_to'] ?? null;
        $expired = $validTo !== null && $validTo !== '' && $validTo < date('Y-m-d');
        $policyNo = trim((string) (CompanySettingsRepository::get('ecgc_policy_no') ?? ''));

        $overBy = 0.0;
        $warning = null;
        if ($limit !== null) {
            $inLimitCcy = $exposure[(string) $currency] ?? 0.0;
            $overBy = round($inLimitCcy - $limit, 2);
            if ($expired) {
                $warning = 'The ECGC credit limit for this buyer expired on ' . $validTo . ' — ask ECGC to renew it before shipping.';
            } elseif ($overBy > 0) {
                $warning = 'Open orders for this buyer (' . $currency . ' ' . number_format($inLimitCcy, 2) . ') are above the ECGC-approved credit limit (' . $currency . ' ' . number_format($limit, 2) . ') by ' . $currency . ' ' . number_format($overBy, 2) . ' — the amount above the limit is not covered.';
            }
            foreach ($exposure as $ccy => $amt) {
                if ($ccy !== $currency && $amt > 0 && $warning === null) {
                    $warning = 'Some open orders are in ' . $ccy . ', but the ECGC limit is in ' . $currency . ' — check the cover with ECGC.';
                }
            }
        } elseif ($policyNo !== '' && $open > 0) {
            $warning = 'No ECGC credit limit is recorded for this buyer — shipments to this buyer are not covered until ECGC approves a limit.';
        }

        return [
            'policy_no' => $policyNo,
            'cover_pct' => (string) (CompanySettingsRepository::get('ecgc_policy_cover_pct') ?? ''),
            'limit' => $limit,
            'currency' => $currency,
            'valid_to' => $validTo,
            'approved_on' => $client['ecgc_limit_approved_on'] ?? null,
            'ref' => $client['ecgc_limit_ref'] ?? null,
            'file_id' => isset($client['ecgc_limit_file_id']) && $client['ecgc_limit_file_id'] !== null ? (int) $client['ecgc_limit_file_id'] : null,
            'expired' => $expired,
            'exposure' => $exposure,
            'open_orders' => $open,
            'over_limit' => $limit !== null && ($expired || $overBy > 0),
            'over_by' => max(0.0, $overBy),
            'warning' => $warning,
        ];
    }

    /**
     * The order's ECGC shipment declaration: due by the Nth of the month after the BL date.
     *
     * @return array{state:string,due:?string,declared_on:?string,ref:?string,bl_date:?string,text:string}
     */
    public static function declarationStatus(int $orderId, ?string $today = null): array
    {
        $stmt = Database::connection()->prepare('SELECT o.ecgc_declared_on, o.ecgc_declaration_ref, s.bl_date FROM orders o LEFT JOIN order_shipping s ON s.order_id = o.id WHERE o.id = :id');
        $stmt->execute(['id' => $orderId]);
        $r = $stmt->fetch() ?: [];
        $today ??= date('Y-m-d');
        $declared = $r['ecgc_declared_on'] ?? null;
        $bl = $r['bl_date'] ?? null;
        $due = $bl ? self::declarationDue((string) $bl) : null;
        if ($declared) {
            return ['state' => 'declared', 'due' => $due, 'declared_on' => $declared, 'ref' => $r['ecgc_declaration_ref'] ?? null, 'bl_date' => $bl,
                'text' => 'Declared to ECGC on ' . $declared . ($r['ecgc_declaration_ref'] ? ' (' . $r['ecgc_declaration_ref'] . ')' : '') . '.'];
        }
        if (!$bl) {
            return ['state' => 'not_due', 'due' => null, 'declared_on' => null, 'ref' => null, 'bl_date' => null, 'text' => 'To be declared to ECGC once the BL is issued.'];
        }
        $state = $today > $due ? 'overdue' : 'due';
        return ['state' => $state, 'due' => $due, 'declared_on' => null, 'ref' => null, 'bl_date' => $bl,
            'text' => $state === 'overdue'
                ? 'Not declared to ECGC — it was due by ' . $due . '. A shipment not declared on time is not covered.'
                : 'Declare this shipment to ECGC by ' . $due . ' (BL dated ' . $bl . ').'];
    }

    public static function declarationDue(string $blDate): string
    {
        $day = max(1, min(28, (int) (CompanySettingsRepository::get('ecgc_declaration_day') ?? '10')));
        $first = (new \DateTimeImmutable(substr($blDate, 0, 10)))->modify('first day of next month');
        return $first->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ (c)

    public static function schemeSettingKey(string $scheme): string
    {
        $s = strtolower(trim($scheme));
        return match (true) {
            $s === 'rodtep' => 'benefit_deadline_days_rodtep',
            $s === 'rosctl' => 'benefit_deadline_days_rosctl',
            str_contains($s, 'drawback') => 'benefit_deadline_days_duty_drawback',
            default => 'benefit_deadline_days_other',
        };
    }

    /** Default claim deadline: shipping bill (LEO) date — else the BL date — plus the scheme's days; null when the scheme has none. */
    public static function defaultDeadline(int $orderId, string $scheme): ?string
    {
        $days = (int) (CompanySettingsRepository::get(self::schemeSettingKey($scheme)) ?? '0');
        if ($days <= 0) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT shipping_bill_date, bl_date FROM order_shipping WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $s = $stmt->fetch() ?: [];
        $base = !empty($s['shipping_bill_date']) ? $s['shipping_bill_date'] : ($s['bl_date'] ?? null);
        return $base ? (new \DateTimeImmutable(substr((string) $base, 0, 10)))->modify('+' . $days . ' days')->format('Y-m-d') : null;
    }

    /** A claim with its effective status and deadline. */
    public static function claimView(array $claim, ?string $today = null): array
    {
        $status = $claim['received_amount'] !== null ? 'received' : ((string) ($claim['claim_status'] ?? 'filed') ?: 'filed');
        if ($status === 'received' && $claim['received_amount'] === null) {
            $status = 'filed';
        }
        $deadline = $claim['claim_deadline'] ?? null;
        $isDefault = false;
        if (($deadline === null || $deadline === '') && !empty($claim['order_id'])) {
            $deadline = self::defaultDeadline((int) $claim['order_id'], (string) $claim['scheme_name']);
            $isDefault = $deadline !== null;
        }
        $daysLeft = $deadline ? (int) floor((strtotime($deadline) - strtotime($today ?? date('Y-m-d'))) / 86400) : null;
        return $claim + [
            'effective_status' => $status,
            'status_label' => self::CLAIM_STATUSES[$status] ?? ucfirst($status),
            'effective_deadline' => $deadline,
            'deadline_is_default' => $isDefault,
            'days_left' => $daysLeft,
        ];
    }

    /**
     * Save sanctioned amount / date, deadline and status on one claim.
     *
     * @return ?string an error message, or null when saved
     */
    public static function saveClaimTracking(array $claim, array $input): ?string
    {
        $status = (string) ($input['claim_status'] ?? '');
        if (!in_array($status, ['filed', 'sanctioned', 'rejected'], true)) {
            return 'Choose the claim status (Filed, Sanctioned or Rejected) — "Received" is set when the money is recorded.';
        }
        if ($claim['received_amount'] !== null && $status !== 'sanctioned' && $status !== 'filed') {
            return 'This claim has already been received — it cannot be marked rejected.';
        }
        $sanctioned = trim((string) ($input['sanctioned_amount'] ?? ''));
        $sanctionedAt = trim((string) ($input['sanctioned_at'] ?? ''));
        $deadline = trim((string) ($input['claim_deadline'] ?? ''));
        $reason = trim((string) ($input['rejected_reason'] ?? ''));
        if ($sanctioned !== '' && (!is_numeric($sanctioned) || (float) $sanctioned < 0 || (float) $sanctioned > 999999999)) {
            return 'Enter the sanctioned amount as a number (₹), 0 or more.';
        }
        if ($status === 'sanctioned' && $sanctioned === '') {
            return 'Enter the amount sanctioned.';
        }
        foreach (['sanctioned date' => $sanctionedAt, 'claim deadline' => $deadline] as $what => $d) {
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return 'Enter the ' . $what . ' as a date.';
            }
        }
        if ($status === 'rejected' && mb_strlen($reason) < 10) {
            return 'Say why the claim was rejected (at least 10 characters).';
        }
        Database::connection()->prepare(
            'UPDATE ca_export_benefits SET claim_status = :st, sanctioned_amount = :sa, sanctioned_at = :sd, claim_deadline = :dl, rejected_reason = :rr WHERE id = :id'
        )->execute([
            'st' => $claim['received_amount'] !== null ? 'received' : $status,
            'sa' => $sanctioned === '' ? null : round((float) $sanctioned, 2),
            'sd' => $sanctionedAt === '' ? null : $sanctionedAt,
            'dl' => $deadline === '' ? null : $deadline,
            'rr' => $status === 'rejected' ? mb_substr($reason, 0, 500) : null,
            'id' => (int) $claim['id'],
        ]);
        return null;
    }

    // ------------------------------------------------------------- reminders

    /** @return list<int> Export Compliance Managers and Directors (by main role or a current membership). */
    public static function exportComplianceManagers(): array
    {
        $pdo = Database::connection();
        $roleIds = array_map('intval', $pdo->query("SELECT id FROM roles WHERE name IN ('Export Compliance Manager', 'Export Compliance Director')")->fetchAll(\PDO::FETCH_COLUMN));
        if ($roleIds === []) {
            return [];
        }
        $out = [];
        foreach ($pdo->query('SELECT id, role_id FROM users WHERE is_active = 1')->fetchAll() as $u) {
            $ids = DepartmentRepository::activeRoleIdsForUser((int) $u['id']);
            if ($ids === [] && $u['role_id'] !== null) {
                $ids = [(int) $u['role_id']];
            }
            if (array_intersect($ids, $roleIds) !== []) {
                $out[] = (int) $u['id'];
            }
        }
        return $out;
    }

    private static function once(string $kind, string $ref, string $threshold, string $today): bool
    {
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO compliance_alerts_sent (alert_kind, ref_key, threshold, sent_on) VALUES (:k, :r, :t, :d)');
        $stmt->execute(['k' => $kind, 'r' => $ref, 't' => $threshold, 'd' => $today]);
        return $stmt->rowCount() > 0;
    }

    /**
     * D-281: everyone in $userIds gets the in-app notice; the e-mail goes to $emailIds
     * (an order alert: the department on the order; a company item: its responsible
     * person and backup). Null = everyone, as before.
     *
     * @param list<int> $userIds
     * @param list<int>|null $emailIds
     */
    private static function notify(array $userIds, string $type, ?int $orderId, string $message, ?array $emailIds = null): int
    {
        if ($emailIds !== null) {
            return count(NotificationRepository::notifyRouted($userIds, $emailIds, $type, $orderId, $message));
        }
        $n = 0;
        foreach (array_values(array_unique($userIds)) as $u) {
            NotificationRepository::create($u, null, $type, $orderId, $message);
            $n++;
        }
        return $n;
    }

    /** Daily, from cron/check_alerts.php. Every reminder goes out once per thing and threshold. */
    public static function runReminders(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $finance = PermissionRepository::usersWithPermission('ca_module_manage');
        $managers = self::exportComplianceManagers();
        $created = 0;

        // (a) monthly: missing eBRC / FIRC
        $month = substr($today, 0, 7);
        $missing = self::missingFircReport();
        if ($missing !== [] && self::once('ebrc_monthly', $month, 'monthly', $today)) {
            $orders = count(array_unique(array_column($missing, 'order_id')));
            $created += self::notify(array_merge($finance, $managers), 'ebrc_missing_monthly', null,
                'Missing eBRC / FIRC: ' . count($missing) . ' received payment(s) on ' . $orders . ' order(s) still have no FIRC / eBRC reference — see Reports → Missing eBRC / FIRC.', StaffRouting::companyItem('ebrc_monthly'));
        }

        // (b) ECGC shipment declarations
        $rows = Database::connection()->query(
            "SELECT o.id, o.order_reference FROM orders o JOIN order_shipping s ON s.order_id = o.id
              WHERE s.bl_date IS NOT NULL AND o.ecgc_declared_on IS NULL AND o.status <> 'lost' AND COALESCE(o.is_test_data, 0) = 0"
        )->fetchAll();
        $policyNo = trim((string) (CompanySettingsRepository::get('ecgc_policy_no') ?? ''));
        if ($policyNo !== '') {
            foreach ($rows as $r) {
                $st = self::declarationStatus((int) $r['id'], $today);
                $due = (string) $st['due'];
                $firstOfMonth = substr($due, 0, 8) . '01';
                if ($today < $firstOfMonth) {
                    continue; // BL month not over yet
                }
                $threshold = $st['state'] === 'overdue' ? 'overdue' : 'due';
                if (self::once('ecgc_declaration', (string) $r['id'], $threshold, $today)) {
                    $created += self::notify($managers !== [] ? $managers : $finance, 'ecgc_declaration_' . $threshold, (int) $r['id'],
                        $threshold === 'overdue'
                            ? 'Order ' . $r['order_reference'] . ': the shipment was not declared to ECGC by ' . $due . ' — declare it now (a late declaration may not be covered).'
                            : 'Order ' . $r['order_reference'] . ': declare the shipment to ECGC by ' . $due . '.',
                        StaffRouting::forOrder((int) $r['id'], 'EXPORT_COMPLIANCE')['email']);
                }
            }
            // ECGC policy expiry, 30 days ahead
            $policyTo = (string) (CompanySettingsRepository::get('ecgc_policy_valid_to') ?? '');
            if ($policyTo !== '' && $policyTo >= $today && $policyTo <= date('Y-m-d', strtotime($today . ' +30 days'))
                && self::once('ecgc_policy', $policyTo, '30', $today)) {
                $created += self::notify(array_merge(PermissionRepository::usersWithPermission('manage_company_settings'), $managers), 'ecgc_policy_expiring', null,
                    'The ECGC policy ' . $policyNo . ' ends on ' . $policyTo . ' — renew it so new shipments stay covered.', StaffRouting::companyItem('ecgc_policy'));
            }
        }

        // (c) incentive claim deadlines — 30 and 7 days before
        $claims = Database::connection()->query(
            "SELECT b.*, o.order_reference FROM ca_export_benefits b JOIN orders o ON o.id = b.order_id
              WHERE b.received_amount IS NULL AND COALESCE(b.claim_status, 'filed') NOT IN ('rejected', 'received')"
        )->fetchAll();
        foreach ($claims as $claim) {
            $v = self::claimView($claim, $today);
            if ($v['effective_deadline'] === null || $v['days_left'] === null || $v['days_left'] < 0) {
                continue;
            }
            foreach ([7, 30] as $t) {
                if ($v['days_left'] <= $t) {
                    if (self::once('benefit_deadline', (string) $claim['id'] . '@' . $v['effective_deadline'], (string) $t, $today)) {
                        $created += self::notify(array_merge($managers, $finance), 'benefit_claim_deadline', (int) $claim['order_id'],
                            'Order ' . $claim['order_reference'] . ': the ' . $claim['scheme_name'] . ' claim must be completed by ' . $v['effective_deadline'] . ' (' . $v['days_left'] . ' day(s) left).', StaffRouting::forOrder((int) $claim['order_id'], 'EXPORT_COMPLIANCE')['email']);
                    }
                    break;
                }
            }
        }
        return $created;
    }
}
