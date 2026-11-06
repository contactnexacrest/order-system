<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\DepartmentRepository;
use App\Repositories\PermissionRepository;
use App\Services\SuperAdminService;
use App\Tests\Support\DbTestCase;

/**
 * CI 6 Nov 2026: usersWithPermission() checked every active user one by one, so with thousands
 * of users the daily alert job was slow. It now checks only the people who could hold the
 * permission. This proves the answer is exactly the same as checking everyone.
 */
final class UsersWithPermissionCandidatesTest extends DbTestCase
{
    /** @return list<int> */
    private function everyoneChecked(string $key): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT id, role_id FROM users WHERE is_active = 1 ORDER BY id')->fetchAll() as $u) {
            $id = (int) $u['id'];
            if (SuperAdminService::isEffective($id)) {
                $out[] = $id;
                continue;
            }
            $roleIds = DepartmentRepository::activeRoleIdsForUser($id);
            if ($roleIds === []) {
                $roleIds = [$u['role_id'] !== null ? (int) $u['role_id'] : null];
            }
            foreach ($roleIds as $r) {
                if (!empty(PermissionRepository::effectivePermissions($id, $r)[$key])) {
                    $out[] = $id;
                    break;
                }
            }
        }
        return $out;
    }

    /** @return array{0:int,1:int,2:int,3:int} granted, revoked, expired grant, plain */
    private function makeUsers(): array
    {
        $pdo = Database::connection();
        $keyId = (int) $pdo->query("SELECT id FROM permissions WHERE permission_key = 'manage_company_settings'")->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled, reason, expires_at) VALUES (?, ?, ?, ?, ?)');
        $granted = $this->createTestUser('Export Executive');
        $ins->execute([$granted, $keyId, 1, 'test grant', null]);
        $revoked = $this->createTestUser('Admin');
        $ins->execute([$revoked, $keyId, 0, 'test revoke', null]);
        $expired = $this->createTestUser('Export Executive');
        $ins->execute([$expired, $keyId, 1, 'test expired', '2020-01-01']);
        $plain = $this->createTestUser('Export Executive');
        return [$granted, $revoked, $expired, $plain];
    }

    public function testSameAnswerAsCheckingEveryone(): void
    {
        $this->makeUsers();
        foreach (['manage_company_settings', 'manage_orders', 'pay_incentives', 'view_all_orders'] as $key) {
            self::assertSame($this->everyoneChecked($key), array_map('intval', PermissionRepository::usersWithPermission($key)), $key);
        }
    }

    public function testAPersonalGrantCountsARevokeOrExpiredGrantDoesNot(): void
    {
        [$granted, $revoked, $expired, $plain] = $this->makeUsers();
        $ids = array_map('intval', PermissionRepository::usersWithPermission('manage_company_settings'));
        self::assertContains($granted, $ids);
        self::assertNotContains($revoked, $ids);
        self::assertNotContains($expired, $ids);
        self::assertNotContains($plain, $ids);
    }
}
