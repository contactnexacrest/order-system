<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;

/**
 * Owner decision item 10a (13 Nov 2026, "go for the two-level version"): one line's
 * quantity has two limits, the same for an export order line (create order, add line,
 * edit line) and a Domestic Trade line.
 *
 *   1. Hard block above 1,000,000 in any unit — a typing slip, never an order
 *      (OrderLineValidator::MAX_QUANTITY / DomesticOrderService; browser data-max-value).
 *   2. Above a realistic level for the unit (about 20 containers) the line saves only with
 *      "I confirm this quantity" ticked. The six levels are settings (Settings › Orders),
 *      edited by Admin / MD: SQM 30,000 · SQFT 300,000 · CBM 500 · TON 600 · PCS 600,000 ·
 *      RMT 60,000. Other units have only the hard block. The unit's kind comes from
 *      LineUnits::canonical() (SQM = M2, MT = TON, NOS = PCS …).
 *
 * A confirmed large quantity is written to the audit log (LARGE_QUANTITY_CONFIRMED).
 * Node twin: src/services/quantityLimits.js.
 */
final class QuantityLimits
{
    /** The hard block (any unit). */
    public const HARD_MAX = 1000000.0;

    /** unit kind => [setting key, default level] */
    public const LEVELS = [
        'M2' => ['quantity_warning_level_sqm', 30000],
        'SQFT' => ['quantity_warning_level_sqft', 300000],
        'CBM' => ['quantity_warning_level_cbm', 500],
        'TON' => ['quantity_warning_level_ton', 600],
        'PCS' => ['quantity_warning_level_pcs', 600000],
        'RMT' => ['quantity_warning_level_rmt', 60000],
    ];

    /** The checkbox of the line forms (create order: product_confirm_large_quantity[] per row). */
    public const TICK = 'confirm_large_quantity';

    public const MESSAGE = 'This is unusually large for one line (more than about 20 containers of {unit}). Check the quantity, or tick \'I confirm this quantity\' to save it.';

    /** The level for this unit (from Settings, else the default), or null when the unit has only the hard block. */
    public static function levelFor(?string $unit): ?float
    {
        $kind = LineUnits::canonical($unit);
        if ($kind === null || !isset(self::LEVELS[$kind])) {
            return null;
        }
        [$key, $default] = self::LEVELS[$kind];
        $raw = trim((string) (CompanySettingsRepository::get($key) ?? ''));
        return is_numeric($raw) && (float) $raw > 0 ? (float) $raw : (float) $default;
    }

    /** True when this quantity is above its unit's level (a TBC or blank quantity never is). */
    public static function isLarge(?string $rawQuantity, ?string $unit, bool $isTbc = false): bool
    {
        $q = trim((string) $rawQuantity);
        if ($isTbc || $q === '' || !is_numeric($q)) {
            return false;
        }
        $level = self::levelFor($unit);
        return $level !== null && (float) $q > $level;
    }

    /** The refusal when the quantity is above its level and not confirmed, else null. */
    public static function check(?string $rawQuantity, ?string $unit, bool $isTbc, bool $confirmed): ?string
    {
        return self::isLarge($rawQuantity, $unit, $isTbc) && !$confirmed ? self::message($unit) : null;
    }

    public static function message(?string $unit): string
    {
        return str_replace('{unit}', mb_strtoupper(trim((string) $unit)), self::MESSAGE);
    }

    /**
     * The browser's levels: every way of writing a unit with a level => its level, e.g.
     * {"SQM":30000,"M2":30000,…} — app.js looks up the line's unit as typed or chosen.
     *
     * @return array<string, int|float|null>
     */
    public static function browserLevels(): array
    {
        $out = [];
        foreach (array_keys(self::LEVELS) as $kind) {
            $level = self::levelFor(LineUnits::names($kind)[0] ?? $kind);
            foreach (LineUnits::names($kind) as $name) {
                $out[$name] = $level !== null && $level === floor($level) ? (int) $level : $level;
            }
        }
        return $out;
    }

    /** The quantity field's attributes for app.js (data-confirm-above / -unit / -message), HTML-escaped. */
    public static function fieldAttributes(string $unitField, ?string $unlessField = null): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        return ' data-confirm-above="' . $h((string) json_encode(self::browserLevels(), JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-confirm-unit="' . $h($unitField) . '"'
            . ($unlessField !== null ? ' data-confirm-unless="' . $h($unlessField) . '"' : '')
            . ' data-confirm-message="' . $h(self::MESSAGE) . '"';
    }

    /** Audit: who confirmed which line's quantity in which unit (and the level it passed). */
    public static function audit(int $userId, string $entityType, int $entityId, string $line, string $quantity, string $unit): void
    {
        AuditLogRepository::log(
            $userId,
            'LARGE_QUANTITY_CONFIRMED',
            $entityType,
            $entityId,
            'quantity',
            null,
            trim($quantity) . ' ' . trim($unit),
            'Large quantity confirmed ("I confirm this quantity"): ' . trim($quantity) . ' ' . trim($unit) . ' on line "' . $line . '" — above the '
                . rtrim(rtrim(number_format((float) self::levelFor($unit), 3, '.', ','), '0'), '.') . ' ' . trim($unit) . ' level (about 20 containers).'
        );
    }
}
