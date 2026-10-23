<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * docs/schema.sql Section BQ — the small fixed catalog of UI templates
 * (today: 'standard', 'kanban') a user or the company default can point
 * at. 'standard' can never be deactivated (see UiTemplateService) — that
 * is enforced by the controller/service layer, not here.
 */
final class UiTemplateRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()->query('SELECT * FROM ui_templates ORDER BY id')->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function active(): array
    {
        return Database::connection()->query('SELECT * FROM ui_templates WHERE is_active = 1 ORDER BY id')->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ui_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByKey(string $templateKey): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ui_templates WHERE template_key = :key');
        $stmt->execute(['key' => $templateKey]);
        return $stmt->fetch() ?: null;
    }

    public static function setActive(int $id, bool $isActive): void
    {
        Database::connection()->prepare(
            'UPDATE ui_templates SET is_active = :is_active WHERE id = :id'
        )->execute(['is_active' => $isActive ? 1 : 0, 'id' => $id]);
    }
}
