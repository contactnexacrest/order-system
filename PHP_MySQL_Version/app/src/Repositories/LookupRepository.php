<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Small read-only reference tables (incoterms, currencies, ports, payment
 * presets) that only ever populate dropdowns on the order-creation form.
 * Grouped into one class rather than four near-identical one-method
 * repositories — none of these carry business logic of their own.
 */
final class LookupRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function incoterms(): array
    {
        return Database::connection()->query('SELECT * FROM incoterms WHERE is_active = 1 ORDER BY sort_order, code')->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function currencies(): array
    {
        return Database::connection()->query('SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code')->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function ports(string $role = 'both'): array
    {
        // 'both'-role ports are usable as either loading or discharge, so
        // any specific-role query also includes them.
        $stmt = Database::connection()->prepare(
            "SELECT * FROM ports WHERE is_active = 1 AND (port_role = :role OR port_role = 'both') ORDER BY sort_order, name"
        );
        $stmt->execute(['role' => $role]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function paymentPresets(): array
    {
        return Database::connection()->query('SELECT * FROM payment_presets WHERE is_active = 1 ORDER BY is_default DESC, preset_name')->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function stagesMaster(): array
    {
        return Database::connection()->query('SELECT * FROM stages_master WHERE is_active = 1 ORDER BY sequence')->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function roles(): array
    {
        return Database::connection()->query('SELECT * FROM roles ORDER BY id')->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function dropdownOptions(string $listKey): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM dropdown_options WHERE list_key = :list_key AND is_active = 1 ORDER BY sort_order'
        );
        $stmt->execute(['list_key' => $listKey]);
        return $stmt->fetchAll();
    }
}
