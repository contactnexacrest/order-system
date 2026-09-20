<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class AssetRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        $stmt = Database::connection()->query(
            'SELECT * FROM assets ORDER BY asset_type, name'
        );
        return $stmt->fetchAll();
    }

    public static function findActiveByType(string $assetType): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM assets WHERE asset_type = :type AND is_active = 1 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['type' => $assetType]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function insert(string $assetType, string $name, string $serverPath, ?string $mimeType, ?int $uploadedBy, bool $isActive = true): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO assets (asset_type, name, server_path, mime_type, is_active, uploaded_by)
             VALUES (:type, :name, :path, :mime, :active, :uploaded_by)'
        );
        $stmt->execute([
            'type'        => $assetType,
            'name'        => $name,
            'path'        => $serverPath,
            'mime'        => $mimeType,
            'active'      => $isActive ? 1 : 0,
            'uploaded_by' => $uploadedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Replacing an asset: deactivate the current active row for that type,
     * insert the new one as active. Old rows are kept (not deleted) so
     * there's a history of what was used on documents generated in the past.
     */
    public static function replace(string $assetType, string $name, string $serverPath, ?string $mimeType, ?int $uploadedBy): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE assets SET is_active = 0 WHERE asset_type = :type AND is_active = 1')
                ->execute(['type' => $assetType]);
            $newId = self::insert($assetType, $name, $serverPath, $mimeType, $uploadedBy, true);
            $pdo->commit();
            return $newId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
