<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * The two global watermark rows (draft / final) DocumentGenerationService
 * reads from — see docs/schema.sql Section 'watermark_settings'. Scoped
 * to 'global' rows only; document-type/document-specific overrides exist
 * in the schema but have never been wired into the generation service, so
 * this CRUD deliberately doesn't expose them either — no UI for a setting
 * nothing reads yet.
 */
final class WatermarkSettingsRepository
{
    public static function findGlobal(bool $isDraftMode): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM watermark_settings WHERE scope = 'global' AND is_draft_mode = :draft LIMIT 1"
        );
        $stmt->execute(['draft' => $isDraftMode ? 1 : 0]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function upsertGlobal(bool $isDraftMode, array $data, int $updatedBy): void
    {
        $existing = self::findGlobal($isDraftMode);
        $pdo = Database::connection();
        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE watermark_settings SET mode = :mode, text_content = :text_content, font = :font,
                    font_size = :font_size, color = :color, opacity = :opacity, angle = :angle,
                    image_asset_id = :image_asset_id, image_opacity = :image_opacity, image_position = :image_position,
                    created_by = :created_by
                 WHERE id = :id'
            );
            $stmt->execute($data + ['id' => $existing['id'], 'created_by' => $updatedBy]);
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO watermark_settings (scope, is_draft_mode, mode, text_content, font, font_size, color, opacity, angle, image_asset_id, image_opacity, image_position, created_by)
             VALUES ('global', :is_draft_mode, :mode, :text_content, :font, :font_size, :color, :opacity, :angle, :image_asset_id, :image_opacity, :image_position, :created_by)"
        );
        $stmt->execute($data + ['is_draft_mode' => $isDraftMode ? 1 : 0, 'created_by' => $updatedBy]);
    }
}
