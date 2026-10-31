<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-214 (2026-10-31), phase 1: departments, their designation
 * ladders (each designation tied to its own role, i.e. permission set) and
 * people's memberships. A person can hold several memberships and picks one
 * at "Log in as"; a person with none keeps their own role (users.role_id).
 * Port: departmentRepository.js.
 */
final class DepartmentRepository
{
    public const LEVELS = ['executive' => 'Executive', 'manager' => 'Manager', 'director' => 'Director', 'other' => 'Other'];

    /** @return list<array<string,mixed>> every department, in display order */
    public static function departments(bool $activeOnly = false): array
    {
        return Database::connection()->query(
            'SELECT * FROM departments' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function findDepartment(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM departments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> every designation with its department, role and how many people hold it today */
    public static function designations(): array
    {
        return Database::connection()->query(
            "SELECT dd.*, d.name AS department_name, d.color AS department_color, d.is_active AS department_active, r.name AS role_name,
                    (SELECT COUNT(*) FROM user_department_memberships m JOIN users u ON u.id = m.user_id
                      WHERE m.designation_id = dd.id AND u.is_active = 1 AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())) AS member_count
               FROM department_designations dd
               JOIN departments d ON d.id = dd.department_id
               JOIN roles r ON r.id = dd.role_id
              ORDER BY d.sort_order, d.name, FIELD(dd.level, 'executive', 'manager', 'director', 'other'), dd.sort_order, dd.title"
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function findDesignation(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT dd.*, d.name AS department_name, d.color AS department_color, r.name AS role_name
               FROM department_designations dd JOIN departments d ON d.id = dd.department_id JOIN roles r ON r.id = dd.role_id
              WHERE dd.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function createDepartment(string $code, string $name, string $color): int
    {
        $pdo = Database::connection();
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM departments')->fetchColumn();
        $pdo->prepare('INSERT INTO departments (code, name, color, sort_order) VALUES (:c, :n, :col, :s)')
            ->execute(['c' => $code, 'n' => $name, 'col' => $color, 's' => $next]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateDepartment(int $id, string $name, string $color, bool $active, bool $seesWholeCompany): void
    {
        Database::connection()->prepare('UPDATE departments SET name = :n, color = :col, is_active = :a, sees_whole_company = :w WHERE id = :id')
            ->execute(['n' => $name, 'col' => $color, 'a' => (int) $active, 'w' => (int) $seesWholeCompany, 'id' => $id]);
    }

    public static function departmentNameTaken(string $name, ?int $exceptId = null): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM departments WHERE name = :n AND (:id IS NULL OR id <> :id2)');
        $stmt->execute(['n' => $name, 'id' => $exceptId, 'id2' => $exceptId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function departmentCodeTaken(string $code): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM departments WHERE code = :c');
        $stmt->execute(['c' => $code]);
        return $stmt->fetchColumn() !== false;
    }

    public static function createDesignation(int $departmentId, string $title, string $level, int $roleId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM department_designations WHERE department_id = :d');
        $stmt->execute(['d' => $departmentId]);
        $pdo->prepare('INSERT INTO department_designations (department_id, title, level, role_id, sort_order) VALUES (:d, :t, :l, :r, :s)')
            ->execute(['d' => $departmentId, 't' => $title, 'l' => $level, 'r' => $roleId, 's' => (int) $stmt->fetchColumn()]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateDesignation(int $id, string $title, string $level, bool $active, ?float $approvalLimit): void
    {
        Database::connection()->prepare('UPDATE department_designations SET title = :t, level = :l, is_active = :a, approval_limit_inr = :lim WHERE id = :id')
            ->execute(['t' => $title, 'l' => $level, 'a' => (int) $active, 'lim' => $approvalLimit, 'id' => $id]);
    }

    public static function designationTitleTaken(int $departmentId, string $title, ?int $exceptId = null): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM department_designations WHERE department_id = :d AND title = :t AND (:id IS NULL OR id <> :id2)');
        $stmt->execute(['d' => $departmentId, 't' => $title, 'id' => $exceptId, 'id2' => $exceptId]);
        return $stmt->fetchColumn() !== false;
    }

    private const MEMBERSHIP_SELECT = "SELECT m.*, dd.title AS designation_title, dd.level, dd.role_id, dd.is_active AS designation_active, dd.approval_limit_inr,
                                              d.id AS department_id, d.name AS department_name, d.color AS department_color, d.is_active AS department_active,
                                              d.sees_whole_company, r.name AS role_name
                                         FROM user_department_memberships m
                                         JOIN department_designations dd ON dd.id = m.designation_id
                                         JOIN departments d ON d.id = dd.department_id
                                         JOIN roles r ON r.id = dd.role_id";

    /** @return list<array<string,mixed>> every membership of a person, current and past, newest first */
    public static function membershipsForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(self::MEMBERSHIP_SELECT . ' WHERE m.user_id = :u ORDER BY (m.ends_on IS NULL OR m.ends_on >= CURDATE()) DESC, d.sort_order, m.starts_on DESC');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> the memberships a person can log in as today (started, not ended, department and designation active) */
    public static function activeMembershipsForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(self::MEMBERSHIP_SELECT . '
             WHERE m.user_id = :u AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
               AND dd.is_active = 1 AND d.is_active = 1
             ORDER BY m.is_default DESC, d.sort_order, dd.sort_order');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function findMembership(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::MEMBERSHIP_SELECT . ' WHERE m.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function addMembership(int $userId, int $designationId, string $startsOn, int $createdBy): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO user_department_memberships (user_id, designation_id, starts_on, created_by) VALUES (:u, :d, :s, :c)')
            ->execute(['u' => $userId, 'd' => $designationId, 's' => $startsOn, 'c' => $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public static function hasOpenMembership(int $userId, int $designationId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM user_department_memberships WHERE user_id = :u AND designation_id = :d AND (ends_on IS NULL OR ends_on >= CURDATE())');
        $stmt->execute(['u' => $userId, 'd' => $designationId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Ends a membership today (it stays on record). */
    public static function endMembership(int $id, int $endedBy, string $endsOn): void
    {
        Database::connection()->prepare('UPDATE user_department_memberships SET ends_on = :e, ended_by = :b, is_default = 0 WHERE id = :id')
            ->execute(['e' => $endsOn, 'b' => $endedBy, 'id' => $id]);
    }

    /** The membership "Log in as" starts with (only one per person). */
    public static function setDefault(int $userId, ?int $membershipId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE user_department_memberships SET is_default = 0 WHERE user_id = :u')->execute(['u' => $userId]);
        if ($membershipId !== null) {
            $pdo->prepare('UPDATE user_department_memberships SET is_default = 1 WHERE id = :id AND user_id = :u')->execute(['id' => $membershipId, 'u' => $userId]);
        }
    }

    /** @return list<int> role ids a person holds today through their memberships */
    public static function activeRoleIdsForUser(int $userId): array
    {
        return array_values(array_unique(array_map(static fn (array $m): int => (int) $m['role_id'], self::activeMembershipsForUser($userId))));
    }
}
