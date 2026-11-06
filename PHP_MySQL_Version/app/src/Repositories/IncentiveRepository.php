<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Team incentive program (owner decision 2026-10-30, mockups approved) —
 * the settings, each person's incentive role(s), each order's incentive
 * (the amount set on it, exclusion, and the figures fixed when the buyer
 * has fully paid), the people on each order and the payouts. The
 * calculation lives in IncentiveService. Only holders of
 * manage_incentives see the profit basis and the settings.
 */
final class IncentiveRepository
{
    /** @var array<string, array<string,mixed>>|null cache of incentive_roles by key, for one request */
    private static ?array $roleCache = null;

    /**
     * Owner decision D-245 P4: the incentive roles are an editable list
     * (incentive_roles). Every role, active and not, by key, in screen order.
     *
     * @return array<string, array{id:int,role_key:string,label:string,share_pct:float,is_active:bool,sort_order:int}>
     */
    public static function allRoles(): array
    {
        if (self::$roleCache === null) {
            self::$roleCache = [];
            foreach (Database::connection()->query('SELECT * FROM incentive_roles ORDER BY sort_order, id')->fetchAll() as $r) {
                self::$roleCache[(string) $r['role_key']] = ['id' => (int) $r['id'], 'role_key' => (string) $r['role_key'], 'label' => (string) $r['label'],
                    'share_pct' => (float) $r['share_pct'], 'is_active' => (int) $r['is_active'] === 1, 'sort_order' => (int) $r['sort_order'],
                    // D-259: the most one person gets per order / per calendar month in this role (null = no cap).
                    'cap_per_order' => isset($r['cap_per_order_inr']) && $r['cap_per_order_inr'] !== null ? (float) $r['cap_per_order_inr'] : null,
                    'cap_per_month' => isset($r['cap_per_month_inr']) && $r['cap_per_month_inr'] !== null ? (float) $r['cap_per_month_inr'] : null];
            }
        }
        return self::$roleCache;
    }

    /** Active roles: key => label, in screen order. @return array<string, string> */
    public static function roles(): array
    {
        $out = [];
        foreach (self::allRoles() as $k => $r) {
            if ($r['is_active']) {
                $out[$k] = $r['label'];
            }
        }
        return $out;
    }

    /** The label of a role key (also of a deactivated role, as payouts keep their key). */
    public static function roleLabel(string $key): string
    {
        return self::allRoles()[$key]['label'] ?? ($key === 'associate' ? 'Associate (agreement)' : $key);
    }

    public static function forgetRoles(): void
    {
        self::$roleCache = null;
    }

    /** Whether a role key is used anywhere (people, payouts, an order's ticks) — then it can only be deactivated. */
    public static function roleInUse(string $key): bool
    {
        foreach (['user_incentive_roles' => 'role', 'order_incentive_people' => 'role', 'incentive_payouts' => 'role', 'order_incentive_roles' => 'role_key'] as $table => $col) {
            $stmt = Database::connection()->prepare("SELECT 1 FROM {$table} WHERE {$col} = :k LIMIT 1");
            $stmt->execute(['k' => $key]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{role_key:string,label:string,share_pct:float,is_active:bool,sort_order:int}> $rows */
    public static function saveRoles(array $rows, int $userId): void
    {
        $pdo = Database::connection();
        $upd = $pdo->prepare('UPDATE incentive_roles SET label = :l, share_pct = :p, is_active = :a, sort_order = :o, updated_by = :u WHERE role_key = :k');
        $ins = $pdo->prepare('INSERT INTO incentive_roles (role_key, label, share_pct, is_active, sort_order, updated_by) VALUES (:k, :l, :p, :a, :o, :u)');
        $existing = self::allRoles();
        foreach ($rows as $r) {
            $params = ['k' => $r['role_key'], 'l' => $r['label'], 'p' => $r['share_pct'], 'a' => $r['is_active'] ? 1 : 0, 'o' => $r['sort_order'], 'u' => $userId];
            isset($existing[$r['role_key']]) ? $upd->execute($params) : $ins->execute($params);
        }
        self::forgetRoles();
    }

    /**
     * D-259: the caps per role. @param array<string, array{order:?float, month:?float}> $caps role key => caps (null = no cap)
     */
    public static function saveCaps(array $caps, int $userId): void
    {
        $stmt = Database::connection()->prepare('UPDATE incentive_roles SET cap_per_order_inr = :o, cap_per_month_inr = :m, updated_by = :u WHERE role_key = :k');
        foreach ($caps as $key => $c) {
            $stmt->execute(['o' => $c['order'], 'm' => $c['month'], 'u' => $userId, 'k' => $key]);
        }
        self::forgetRoles();
    }

    /**
     * D-259: what a person has already been given in a role in one incentive month (locked
     * payouts of other orders, clawbacks included) — the monthly cap counts this.
     */
    public static function lockedInMonth(int $userId, string $role, string $month, int $exceptOrderId): float
    {
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(amount_inr), 0) FROM incentive_payouts
             WHERE payee_kind = 'staff' AND user_id = :u AND role = :r AND incentive_month = :m AND order_id <> :o"
        );
        $stmt->execute(['u' => $userId, 'r' => $role, 'm' => $month, 'o' => $exceptOrderId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** D-259: one kept line (not eligible / above a cap) written when the order is locked. @param array<string,mixed> $row */
    public static function addKept(array $row): void
    {
        Database::connection()->prepare(
            'INSERT INTO incentive_kept (order_id, user_id, role, by_share_inr, paid_inr, kept_inr, kept_reason, kept_note, incentive_month, locked_on)
             VALUES (:order_id, :user_id, :role, :by_share_inr, :paid_inr, :kept_inr, :kept_reason, :kept_note, :incentive_month, :locked_on)'
        )->execute([
            'order_id' => $row['order_id'], 'user_id' => $row['user_id'], 'role' => $row['role'], 'by_share_inr' => $row['by_share_inr'],
            'paid_inr' => $row['paid_inr'], 'kept_inr' => $row['kept_inr'], 'kept_reason' => $row['kept_reason'], 'kept_note' => $row['kept_note'] ?? null,
            'incentive_month' => $row['incentive_month'], 'locked_on' => $row['locked_on'],
        ]);
    }

    /** @return list<array<string,mixed>> the order's kept lines, with the person's name */
    public static function keptForOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT k.*, u.name AS user_name FROM incentive_kept k JOIN users u ON u.id = k.user_id WHERE k.order_id = :id ORDER BY k.id'
        );
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetchAll();
    }

    /** D-259: the kept lines go with the payouts when the MD unlocks the order. */
    public static function deleteOrderKept(int $orderId): void
    {
        Database::connection()->prepare('DELETE FROM incentive_kept WHERE order_id = :id')->execute(['id' => $orderId]);
    }

    public static function deleteRole(string $key): void
    {
        Database::connection()->prepare('DELETE FROM incentive_roles WHERE role_key = :k')->execute(['k' => $key]);
        self::forgetRoles();
    }

    /** @return array<string, array{gets_it:bool,share_pct:?float}> the order's ticks and shares by role key */
    public static function orderRoles(int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT role_key, gets_it, share_pct FROM order_incentive_roles WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['role_key']] = ['gets_it' => (int) $r['gets_it'] === 1, 'share_pct' => $r['share_pct'] !== null ? (float) $r['share_pct'] : null];
        }
        return $out;
    }

    /** @param array<string, array{gets_it:bool,share_pct:?float}> $roles */
    public static function replaceOrderRoles(int $orderId, array $roles): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM order_incentive_roles WHERE order_id = :id')->execute(['id' => $orderId]);
        $stmt = $pdo->prepare('INSERT INTO order_incentive_roles (order_id, role_key, gets_it, share_pct) VALUES (:o, :k, :g, :s)');
        foreach ($roles as $key => $r) {
            $stmt->execute(['o' => $orderId, 'k' => $key, 'g' => $r['gets_it'] ? 1 : 0, 's' => $r['share_pct']]);
        }
    }

    /** Owner decision D-245 P4: every change to an order's incentive is kept. */
    public static function addHistory(int $orderId, string $action, ?string $before, ?string $after, ?string $reason, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_incentive_history (order_id, action, before_value, after_value, reason, changed_by) VALUES (:o, :a, :b, :v, :r, :u)'
        )->execute(['o' => $orderId, 'a' => $action, 'b' => $before, 'v' => $after, 'r' => $reason, 'u' => $userId]);
    }

    /** @return list<array<string,mixed>> newest first, with the name of who changed it */
    public static function history(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT h.*, u.name AS changed_by_name FROM order_incentive_history h LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.order_id = :id ORDER BY h.changed_at DESC, h.id DESC'
        );
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, string> setting_key => setting_value */
    public static function settings(): array
    {
        // D-245 P5: read once per report run (BatchCache scope only).
        // D-257: a division's own values over the company ones (outside the memo, which is not per division).
        return \App\Services\DivisionService::overlay('incentive', \App\Helpers\BatchCache::memo('incentive_settings', static fn () => self::settingsUncached()));
    }

        private static function settingsUncached(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT setting_key, setting_value FROM incentive_settings')->fetchAll() as $row) {
            $out[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
        return $out;
    }

    public static function setSetting(string $key, string $value, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO incentive_settings (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        )->execute(['k' => $key, 'v' => $value, 'u' => $userId]);
    }

    /** @return array<int, list<string>> user_id => roles */
    public static function userRoles(): array
    {
        // D-245 P5: read once per report run (BatchCache scope only).
        return \App\Helpers\BatchCache::memo('user_incentive_roles', static fn () => self::userRolesUncached());
    }

        private static function userRolesUncached(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT user_id, role FROM user_incentive_roles')->fetchAll() as $row) {
            $out[(int) $row['user_id']][] = (string) $row['role'];
        }
        return $out;
    }

    /** @param array<int, list<string>> $rolesByUser */
    public static function replaceUserRoles(array $rolesByUser): void
    {
        $pdo = Database::connection();
        $pdo->exec('DELETE FROM user_incentive_roles');
        $stmt = $pdo->prepare('INSERT INTO user_incentive_roles (user_id, role) VALUES (:u, :r)');
        foreach ($rolesByUser as $userId => $roles) {
            foreach (array_unique($roles) as $role) {
                if (isset(self::roles()[$role])) {
                    $stmt->execute(['u' => $userId, 'r' => $role]);
                }
            }
        }
    }

    public static function forOrder(int $orderId): ?array
    {
        // D-245 P5: read once per report run (BatchCache scope only).
        return \App\Helpers\BatchCache::memo('oi:' . $orderId, static fn () => self::forOrderUncached($orderId));
    }

        private static function forOrderUncached(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_incentives WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    /** Creates the order's incentive row when missing, then updates the given columns. */
    public static function saveOrder(int $orderId, array $fields, int $userId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT IGNORE INTO order_incentives (order_id, updated_by) VALUES (:id, :u)')->execute(['id' => $orderId, 'u' => $userId]);
        if ($fields === []) {
            return;
        }
        $sets = [];
        $params = ['id' => $orderId, 'u' => $userId];
        foreach ($fields as $column => $value) {
            $sets[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        $pdo->prepare('UPDATE order_incentives SET ' . implode(', ', $sets) . ', updated_by = :u WHERE order_id = :id')->execute($params);
    }

    /** @return array<string, list<int>> role => user ids set on the order */
    public static function orderPeople(int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT role, user_id FROM order_incentive_people WHERE order_id = :id ORDER BY user_id');
        $stmt->execute(['id' => $orderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['role']][] = (int) $row['user_id'];
        }
        return $out;
    }

    /** @param array<string, list<int>> $people */
    public static function replaceOrderPeople(int $orderId, array $people): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM order_incentive_people WHERE order_id = :id')->execute(['id' => $orderId]);
        $stmt = $pdo->prepare('INSERT INTO order_incentive_people (order_id, role, user_id) VALUES (:o, :r, :u)');
        foreach ($people as $role => $userIds) {
            foreach (array_unique($userIds) as $userId) {
                $stmt->execute(['o' => $orderId, 'r' => $role, 'u' => $userId]);
            }
        }
    }

    public static function addPayout(array $row): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO incentive_payouts (order_id, payee_kind, user_id, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, payout_run_on, locked_on)
             VALUES (:order_id, :payee_kind, :user_id, :associate_id, :role, :amount_inr, :amount_fc, :currency_code, :incentive_month, :payout_run_on, :locked_on)'
        )->execute([
            'payout_run_on' => $row['payout_run_on'] ?? null, 'locked_on' => $row['locked_on'] ?? null,
            'order_id' => $row['order_id'], 'payee_kind' => $row['payee_kind'], 'user_id' => $row['user_id'] ?? null,
            'associate_id' => $row['associate_id'] ?? null, 'role' => $row['role'], 'amount_inr' => $row['amount_inr'],
            'amount_fc' => $row['amount_fc'] ?? null, 'currency_code' => $row['currency_code'] ?? null, 'incentive_month' => $row['incentive_month'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> with the person's or associate's name */
    public static function payoutsForOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, u.name AS user_name, a.name AS associate_name
             FROM incentive_payouts p
             LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN associates a ON a.id = p.associate_id
             WHERE p.order_id = :id ORDER BY p.payee_kind DESC, p.id'
        );
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> one incentive month's payouts, for Accounts */
    public static function payoutsForMonth(string $month): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT p.*, u.name AS user_name, a.name AS associate_name, a.based_in, a.country, a.currency_code AS associate_currency,
                    o.buyer_inquiry_ref, o.order_reference
             FROM incentive_payouts p
             JOIN orders o ON o.id = p.order_id
             LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN associates a ON a.id = p.associate_id
             WHERE p.incentive_month = :m
             ORDER BY p.payee_kind DESC, COALESCE(u.name, a.name), p.id"
        );
        $stmt->execute(['m' => $month]);
        return $stmt->fetchAll();
    }

    /** @return list<string> incentive months that have payouts, newest first */
    public static function payoutMonths(): array
    {
        return array_map('strval', Database::connection()->query('SELECT DISTINCT incentive_month FROM incentive_payouts ORDER BY incentive_month DESC')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> one person's payouts, newest first */
    public static function payoutsForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, o.buyer_inquiry_ref, o.order_reference, o.company_legal_name
             FROM incentive_payouts p JOIN orders o ON o.id = p.order_id
             WHERE p.user_id = :u AND p.payee_kind = :k
             ORDER BY p.incentive_month DESC, p.id DESC'
        );
        $stmt->execute(['u' => $userId, 'k' => 'staff']);
        return $stmt->fetchAll();
    }

    public static function findPayout(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM incentive_payouts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return bool whether this call marked it paid (false: it already was — QA Round 6, R6-N17) */
    public static function markPayoutPaid(int $id, string $paidOn, float $paidInr, ?float $tdsInr, int $userId, ?string $paidVia = null, ?string $paidReference = null): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE incentive_payouts SET status = 'paid', paid_on = :d, paid_inr = :p, tds_inr = :t, paid_by = :u, paid_via = :via, paid_reference = :ref WHERE id = :id AND status = 'due'"
        );
        $stmt->execute(['d' => $paidOn, 'p' => $paidInr, 't' => $tdsInr, 'u' => $userId, 'via' => $paidVia, 'ref' => $paidReference !== null ? trim($paidReference) : null, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** Owner decision 2 Nov 2026 (D-235): the TDS rate, section and reason used on an associate payout. */
    public static function setPayoutTds(int $id, ?float $rate, ?string $section, ?string $reason, ?string $overrideReason): void
    {
        Database::connection()->prepare('UPDATE incentive_payouts SET tds_rate = :r, tds_section = :s, tds_reason = :why, tds_override_reason = :o WHERE id = :id')
            ->execute(['r' => $rate, 's' => $section, 'why' => $reason !== null ? mb_substr($reason, 0, 255) : null, 'o' => $overrideReason !== null ? mb_substr($overrideReason, 0, 255) : null, 'id' => $id]);
    }

    /** QA batch 4, MON-1: TDS still owed after this payout (capped at the payout), carried to the next one. */
    public static function setPayoutTdsShortfall(int $id, float $shortfall): void
    {
        Database::connection()->prepare('UPDATE incentive_payouts SET tds_shortfall_inr = :s WHERE id = :id')
            ->execute(['s' => $shortfall > 0 ? round($shortfall, 2) : null, 'id' => $id]);
    }

    /** Owner decision 2 Nov 2026 (D-235): the challan Accounts deposited the TDS with. */
    public static function setPayoutChallan(int $id, ?string $challanNo, ?string $challanDate): void
    {
        Database::connection()->prepare("UPDATE incentive_payouts SET tds_challan_no = :n, tds_challan_date = :d WHERE id = :id AND payee_kind = 'associate' AND status = 'paid' AND tds_inr > 0")
            ->execute(['n' => $challanNo, 'd' => $challanDate, 'id' => $id]);
    }

    /** Owner decision 1 Nov 2026 (AS-3): a due payout put on hold, with the reason. */
    public static function holdPayout(int $id, string $reason): bool
    {
        $stmt = Database::connection()->prepare("UPDATE incentive_payouts SET status = 'held', hold_reason = :r WHERE id = :id AND status = 'due'");
        $stmt->execute(['r' => $reason, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public static function releasePayout(int $id): bool
    {
        $stmt = Database::connection()->prepare("UPDATE incentive_payouts SET status = 'due', hold_reason = NULL WHERE id = :id AND status = 'held'");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * AS-9: a claw-back after a refund — a negative line in the associate's
     * next payout month, tied to the paid payout it reverses.
     */
    public static function addClawback(array $paid, float $amountInr, ?float $amountFc, string $month, string $note): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            "INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, clawback_of_payout_id, note)
             VALUES (:o, 'associate', :a, 'associate', :inr, :fc, :cur, :m, :of, :n)"
        )->execute(['o' => $paid['order_id'], 'a' => $paid['associate_id'], 'inr' => -abs($amountInr), 'fc' => $amountFc !== null ? -abs($amountFc) : null,
            'cur' => $paid['currency_code'], 'm' => $month, 'of' => $paid['id'], 'n' => $note]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * QA Round 8, R8-N12: a claw-back is saved only while it fits what is left
     * of the paid commission — checked with the paid payout row locked, so
     * simultaneous claw-backs (a double click, two people) cannot take back
     * more than was paid.
     *
     * @return array{id: ?int, left: float} id null when it no longer fits (left = what was still left)
     */
    public static function addClawbackWithinPaid(int $payoutId, float $amountInr, string $month, string $noteSuffix): array
    {
        return Database::transaction(static function () use ($payoutId, $amountInr, $month, $noteSuffix): array {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT * FROM incentive_payouts WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $payoutId]);
            $paid = $stmt->fetch();
            if (!$paid || $paid['status'] !== 'paid' || (float) $paid['amount_inr'] <= 0) {
                return ['id' => null, 'left' => 0.0];
            }
            $sum = $pdo->prepare('SELECT COALESCE(-SUM(amount_inr), 0) FROM incentive_payouts WHERE clawback_of_payout_id = :p');
            $sum->execute(['p' => $payoutId]);
            $left = round((float) $paid['paid_inr'] - (float) $sum->fetchColumn(), 2);
            if ($amountInr <= 0 || $amountInr > $left + 0.005) {
                return ['id' => null, 'left' => $left];
            }
            $fc = $paid['amount_fc'] !== null ? round((float) $paid['amount_fc'] * $amountInr / (float) $paid['amount_inr'], 2) : null;
            $id = self::addClawback($paid, $amountInr, $fc, $month, $noteSuffix);
            return ['id' => $id, 'left' => $left];
        });
    }

    /** The order's unpaid payouts (unlock); a paid payout is never deleted (fresh-eyes review M1). */
    public static function deleteOrderPayouts(int $orderId): void
    {
        Database::connection()->prepare("DELETE FROM incentive_payouts WHERE order_id = :id AND status <> 'paid'")->execute(['id' => $orderId]);
    }

    /** Incentive cost entries of the order (team / associate), keyed by kind. */
    public static function costEntries(int $orderId): array
    {
        // D-245 P5: read once per report run (BatchCache scope only).
        return \App\Helpers\BatchCache::memo('oce:' . $orderId, static fn () => self::costEntriesUncached($orderId));
    }

        private static function costEntriesUncached(int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_cost_entries WHERE order_id = :id AND incentive_kind IS NOT NULL');
        $stmt->execute(['id' => $orderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['incentive_kind']] = $row;
        }
        return $out;
    }

    public static function addCostEntry(int $orderId, string $kind, string $description, float $amountInr, string $incurredAt, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            "INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by, incentive_kind)
             VALUES (:o, 'commission', :d, :a, :i, :u, :k)"
        )->execute(['o' => $orderId, 'd' => $description, 'a' => $amountInr, 'i' => $incurredAt, 'u' => $userId, 'k' => $kind]);
        return (int) $pdo->lastInsertId();
    }

    /** All of the kind paid: the cost entry takes the amounts actually paid and goes to Zoho Books (paid from the company account). */
    public static function settleCostEntry(int $entryId, float $amountInr, string $paidOn, ?string $paidNote = null): void
    {
        // Owner rule D-212: the cost line (and so the Zoho expense) says how and with what reference it was paid.
        Database::connection()->prepare(
            "UPDATE order_cost_entries SET amount_inr = :a, incurred_at = :d, paid_from = 'company_account',
                    description = CASE WHEN :note IS NULL OR LOCATE(' · paid ', COALESCE(description, '')) > 0 THEN description ELSE CONCAT(COALESCE(description, ''), ' · paid ', :note2) END
              WHERE id = :id"
        )->execute(['a' => $amountInr, 'd' => $paidOn, 'note' => $paidNote, 'note2' => $paidNote, 'id' => $entryId]);
    }

    /** Owner rule D-212: how a payout was paid. */
    public const PAID_VIA = ['salary' => 'with the salary (payroll)', 'bank_transfer' => 'by bank transfer', 'cheque' => 'by cheque'];

    /** Order ids whose buyer may have fully paid and whose incentive is not locked yet (candidates for "ready to lock"). */
    public static function unlockedOrderIdsWithPayments(): array
    {
        return array_map('intval', Database::connection()->query(
            "SELECT o.id FROM orders o
             JOIN order_payment_status ps ON ps.order_id = o.id
             LEFT JOIN order_incentives oi ON oi.order_id = o.id
             WHERE (oi.locked_at IS NULL) AND o.is_test_data = 0 AND o.is_sample_data = 0
               AND o.status NOT IN ('cancelled','lost')
               AND (ps.balance_cleared_at IS NOT NULL OR ps.advance_cleared_at IS NOT NULL OR ps.freight_cleared_at IS NOT NULL)"
            // D-257 (fresh-eyes review L1): "Ready to lock" / "Lock all ready" stay within the request's division(s).
            . \App\Services\DivisionService::sql('o.division_id')
        )->fetchAll(\PDO::FETCH_COLUMN));
    }
}
