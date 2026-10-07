<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * docs/schema.sql Section AY — Reference Library categories. A category
 * is purely a grouping label (name) with an optional required_permission:
 * NULL means every authenticated staff member can see documents filed
 * under it (today's behaviour, unchanged); set, only a user holding that
 * permission key can see them. Enforcement itself lives in
 * ReferenceDocController::index() (it filters ReferenceLibraryRepository::
 * all()'s rows by PermissionService::can()) — this repository is plain
 * CRUD for the category list.
 */
final class ReferenceLibraryCategoryRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT rlc.*, (SELECT COUNT(*) FROM reference_library_documents d WHERE d.category_id = rlc.id) AS document_count
             FROM reference_library_categories rlc ORDER BY rlc.name'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM reference_library_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $name, ?string $requiredPermission): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO reference_library_categories (name, required_permission) VALUES (:name, :required_permission)'
        );
        $stmt->execute(['name' => $name, 'required_permission' => $requiredPermission]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $name, ?string $requiredPermission): void
    {
        Database::connection()->prepare(
            'UPDATE reference_library_categories SET name = :name, required_permission = :required_permission WHERE id = :id'
        )->execute(['name' => $name, 'required_permission' => $requiredPermission, 'id' => $id]);
    }

    /** Documents filed under the deleted category fall back to uncategorized (visible to everyone) — never deleted themselves. */
    public static function delete(int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE reference_library_documents SET category_id = NULL WHERE category_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM reference_library_categories WHERE id = :id')->execute(['id' => $id]);
    }
}
