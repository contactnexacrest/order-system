<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * company_settings is a key-value table (see ARCHITECTURE.md section 4,
 * point 2) — adding a new configurable value later is a DB row, not a
 * migration + code deploy. This repository is deliberately generic:
 * it has no idea what "non_usd_buffer_percent" or "company_name" mean,
 * it just reads/writes whatever rows exist.
 */
final class CompanySettingsRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        $stmt = Database::connection()->query(
            'SELECT * FROM company_settings ORDER BY category, setting_key'
        );
        return $stmt->fetchAll();
    }

    public static function get(string $key): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT setting_value FROM company_settings WHERE setting_key = :key LIMIT 1'
        );
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : null;
    }

    public static function set(string $key, ?string $value, ?int $updatedByUserId = null): void
    {
        Database::connection()->prepare(
            'UPDATE company_settings SET setting_value = :value, updated_by = :updated_by WHERE setting_key = :key'
        )->execute(['value' => $value, 'updated_by' => $updatedByUserId, 'key' => $key]);
    }
}
