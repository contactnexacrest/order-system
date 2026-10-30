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
    /** Incentive roles, in the order the screens list them. */
    public const ROLES = [
        'sales'     => 'Sales',
        'export'    => 'Export Executive',
        'marketing' => 'Marketing',
        'logistics' => 'Logistics',
        'accounts'  => 'Accounts',
    ];

    /** @return array<string, string> setting_key => setting_value */
    public static function settings(): array
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
                if (isset(self::ROLES[$role])) {
                    $stmt->execute(['u' => $userId, 'r' => $role]);
                }
            }
        }
    }

    public static function forOrder(int $orderId): ?array
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
            'INSERT INTO incentive_payouts (order_id, payee_kind, user_id, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month)
             VALUES (:order_id, :payee_kind, :user_id, :associate_id, :role, :amount_inr, :amount_fc, :currency_code, :incentive_month)'
        )->execute([
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

    public static function markPayoutPaid(int $id, string $paidOn, float $paidInr, ?float $tdsInr, int $userId): void
    {
        Database::connection()->prepare(
            "UPDATE incentive_payouts SET status = 'paid', paid_on = :d, paid_inr = :p, tds_inr = :t, paid_by = :u WHERE id = :id AND status = 'due'"
        )->execute(['d' => $paidOn, 'p' => $paidInr, 't' => $tdsInr, 'u' => $userId, 'id' => $id]);
    }

    public static function deleteOrderPayouts(int $orderId): void
    {
        Database::connection()->prepare('DELETE FROM incentive_payouts WHERE order_id = :id')->execute(['id' => $orderId]);
    }

    /** Incentive cost entries of the order (team / associate), keyed by kind. */
    public static function costEntries(int $orderId): array
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
    public static function settleCostEntry(int $entryId, float $amountInr, string $paidOn): void
    {
        Database::connection()->prepare(
            "UPDATE order_cost_entries SET amount_inr = :a, incurred_at = :d, paid_from = 'company_account' WHERE id = :id"
        )->execute(['a' => $amountInr, 'd' => $paidOn, 'id' => $entryId]);
    }

    /** Order ids whose buyer may have fully paid and whose incentive is not fixed yet. */
    public static function unlockedOrderIdsWithPayments(): array
    {
        return array_map('intval', Database::connection()->query(
            "SELECT o.id FROM orders o
             JOIN order_payment_status ps ON ps.order_id = o.id
             LEFT JOIN order_incentives oi ON oi.order_id = o.id
             WHERE (oi.locked_at IS NULL) AND o.is_test_data = 0 AND o.is_sample_data = 0
               AND o.status NOT IN ('cancelled','lost')
               AND (ps.balance_cleared_at IS NOT NULL OR ps.advance_cleared_at IS NOT NULL OR ps.freight_cleared_at IS NOT NULL)"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }
}
