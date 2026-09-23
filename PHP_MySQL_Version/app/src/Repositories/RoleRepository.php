<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * CRUD for role definitions themselves (name/description) — distinct from
 * PermissionRepository, which owns what a role can DO (role_permissions).
 * See schema.sql Section X for why is_system_role blocks deletion but not
 * rename: nothing in code depends on a role's display name any more (the
 * three notification paths that used to were fixed to check the actual
 * permission instead — see PermissionRepository::usersWithPermission()).
 */
final class RoleRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM roles WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByName(string $name): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM roles WHERE name = :name');
        $stmt->execute(['name' => $name]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $name, ?string $description): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO roles (name, description, is_system_role) VALUES (:name, :description, 0)');
        $stmt->execute(['name' => $name, 'description' => $description]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $name, ?string $description): void
    {
        Database::connection()
            ->prepare('UPDATE roles SET name = :name, description = :description WHERE id = :id')
            ->execute(['name' => $name, 'description' => $description, 'id' => $id]);
    }

    /** How many users currently hold this role — deletion is blocked while this is nonzero. */
    public static function userCount(int $roleId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) AS c FROM users WHERE role_id = :role_id');
        $stmt->execute(['role_id' => $roleId]);
        return (int) $stmt->fetchColumn();
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM roles WHERE id = :id')->execute(['id' => $id]);
    }
}
