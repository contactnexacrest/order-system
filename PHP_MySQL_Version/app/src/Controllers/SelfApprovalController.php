<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\RoleRepository;
use App\Services\AuthService;
use App\Services\SelfApprovalRules;

/**
 * Owner decision 1 Nov 2026 (D-221): Admin → Self-approval rules. Super
 * Admins choose, per rule, which roles may approve their own work; every
 * change is written to the audit log with the role lists before and after.
 */
final class SelfApprovalController
{
    public function index(array $params): void
    {
        $current = [];
        foreach (array_keys(SelfApprovalRules::RULES) as $rule) {
            $current[$rule] = SelfApprovalRules::roleIds($rule);
        }
        View::render('self_approval/index', ['rules' => SelfApprovalRules::RULES, 'current' => $current, 'roles' => RoleRepository::all()], 'layout/base');
    }

    public function save(array $params): void
    {
        $user = AuthService::currentUser();
        $valid = array_map(static fn (array $r): int => (int) $r['id'], RoleRepository::all());
        $changed = 0;
        foreach (array_keys(SelfApprovalRules::RULES) as $rule) {
            $posted = array_values(array_intersect(array_map('intval', (array) ($_POST[$rule] ?? [])), $valid));
            sort($posted);
            $before = SelfApprovalRules::roleIds($rule);
            if ($posted === $before) {
                continue;
            }
            $oldNames = implode(', ', SelfApprovalRules::roleNames($rule)) ?: 'nobody';
            SelfApprovalRules::set($rule, $posted);
            $newNames = implode(', ', SelfApprovalRules::roleNames($rule)) ?: 'nobody';
            AuditLogRepository::log((int) $user['id'], 'SELF_APPROVAL_RULE_CHANGED', 'self_approval_rules', null, $rule, $oldNames, $newNames);
            $changed++;
        }
        Flash::set('success', $changed === 0 ? 'Nothing changed.' : 'Self-approval rules saved. They apply from the next approval.');
        header('Location: /admin/self-approval');
    }
}
