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

    /**
     * External QA report R-13: a dropdown_options-backed field like
     * coo_type was never validated server-side — any string the client
     * sent (e.g. "TBC", or stale text from before an admin removed an
     * option) was stored as-is, even though the form itself only ever
     * offers the active options plus the "nothing chosen yet" sentinel.
     * Returns $value unchanged when it matches an active option for
     * $listKey or is the literal "To Be Confirmed" sentinel every such
     * field already falls back to when blank; otherwise returns '' so
     * the caller's own existing `?: <fallback>` handles an invalid value
     * exactly like a blank submission.
     */
    public static function sanitizeOption(string $listKey, string $value): string
    {
        if ($value === '' || $value === 'To Be Confirmed') {
            return $value;
        }
        return self::matchOption(array_column(self::dropdownOptions($listKey), 'option_value'), $value);
    }

    /**
     * Owner report (2026-10-26): a client typing "GSP Form A" or
     * "non-preferential" on the Quotation/PI details forms lost their
     * answer entirely, because only an exact match to "GSP Form A
     * (Preferential)" / "Non-Preferential" was kept. Matches ignoring
     * case and spacing, and accepts the start of an option's name;
     * returns the option's own spelling, or '' when nothing (or more than
     * one option) fits.
     *
     * @param string[] $options
     */
    public static function matchOption(array $options, string $value): string
    {
        $norm = static fn(string $v): string => strtolower((string) preg_replace('/\s+/', ' ', trim($v)));
        $wanted = $norm($value);
        if ($wanted === '') {
            return '';
        }
        foreach ($options as $option) {
            if ($norm((string) $option) === $wanted) {
                return (string) $option;
            }
        }
        $starts = array_values(array_filter($options, static fn($o): bool => str_starts_with($norm((string) $o), $wanted)));
        return count($starts) === 1 ? (string) $starts[0] : '';
    }
}
