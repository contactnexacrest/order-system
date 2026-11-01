<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * Owner decision 1 Nov 2026 (D-221): who may approve their own work is set
 * in Admin → Self-approval rules (Super Admins only), one list of roles per
 * rule. Defaults: Managing Director and Executive Director on every rule
 * (QA Round 8, R8-N09: seven rules, one choke point — MakerCheckerGuard).
 * A person qualifies when any of their own roles (primary, acting
 * department, department memberships — GrantGuard::ownRoleIds) is ticked.
 */
final class SelfApprovalRules
{
    public const RULES = [
        'payment_terms' => 'Approve the payment terms of an order they created',
        'document_review' => 'Review (approve) a document they generated',
        'email_send' => 'Approve an e-mail send they requested',
        // QA Round 8, R8-N09: every other self-approval the app has, on the same page.
        'cancellation' => 'Approve or reject the cancellation of an order they requested',
        'purchase_award' => 'Approve a purchase award they made',
        'amendment' => 'MD-approve an amendment they requested',
        'short_payment' => 'Clear short a buyer payment whose receipts they recorded',
    ];

    /** @return list<int> */
    public static function roleIds(string $rule): array
    {
        $stmt = Database::connection()->prepare('SELECT role_id FROM self_approval_rules WHERE rule_key = :k ORDER BY role_id');
        $stmt->execute(['k' => $rule]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> role names, for the audit log and the page */
    public static function roleNames(string $rule): array
    {
        return array_values(array_filter(array_map(static fn (int $id): string => (string) (RoleRepository::find($id)['name'] ?? ''), self::roleIds($rule))));
    }

    public static function allowed(string $rule, int $userId): bool
    {
        $user = UserRepository::findById($userId);
        if ($user === null || !isset(self::RULES[$rule])) {
            return false;
        }
        return array_intersect(GrantGuard::ownRoleIds($user), self::roleIds($rule)) !== [];
    }

    /** @param list<int> $roleIds */
    public static function set(string $rule, array $roleIds): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM self_approval_rules WHERE rule_key = :k')->execute(['k' => $rule]);
        $ins = $pdo->prepare('INSERT INTO self_approval_rules (rule_key, role_id) VALUES (:k, :r)');
        foreach (array_values(array_unique(array_map('intval', $roleIds))) as $roleId) {
            $ins->execute(['k' => $rule, 'r' => $roleId]);
        }
    }
}
