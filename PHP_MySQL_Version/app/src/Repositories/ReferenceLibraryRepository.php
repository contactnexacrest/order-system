<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * CRUD for custom Reference Library entries (schema.sql Section Y) —
 * distinct from InternalReferenceDocRepository, which owns the 8 fixed,
 * document_types-linked entries. These are freely add/delete-able,
 * optionally carrying an uploaded source file alongside or instead of
 * typed content.
 */
final class ReferenceLibraryRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT rld.*, cu.name AS created_by_name, uu.name AS updated_by_name
             FROM reference_library_documents rld
             LEFT JOIN users cu ON cu.id = rld.created_by
             LEFT JOIN users uu ON uu.id = rld.updated_by
             ORDER BY rld.title'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM reference_library_documents WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $title, ?string $content, int $userId): int
    {
        // Two distinct placeholders, not :user_id reused twice — PDO::
        // ATTR_EMULATE_PREPARES is off (native prepares), which doesn't
        // allow binding one named parameter to two positions.
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO reference_library_documents (title, content, created_by, updated_by)
             VALUES (:title, :content, :created_by, :updated_by)'
        );
        $stmt->execute(['title' => $title, 'content' => $content, 'created_by' => $userId, 'updated_by' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateText(int $id, string $title, ?string $content, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE reference_library_documents SET title = :title, content = :content, updated_by = :user_id WHERE id = :id'
        )->execute(['title' => $title, 'content' => $content, 'user_id' => $userId, 'id' => $id]);
    }

    /** Only ever points file_path/name/mime at the new file — never touches or deletes the previous one on disk. */
    public static function updateFile(int $id, string $filePath, string $originalName, string $mimeType, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE reference_library_documents
             SET file_path = :file_path, file_original_name = :original_name, file_mime_type = :mime_type, updated_by = :user_id
             WHERE id = :id'
        )->execute([
            'file_path' => $filePath,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'user_id' => $userId,
            'id' => $id,
        ]);
    }

    /** Deletes only the DB row — the file on disk (if any) is never touched, matching the app's never-delete-files rule. */
    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM reference_library_documents WHERE id = :id')->execute(['id' => $id]);
    }
}
