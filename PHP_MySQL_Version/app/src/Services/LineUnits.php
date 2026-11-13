<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner decision D-270 (point 25, 11 Nov 2026): an order line may be priced in
 * any unit of its category (stone: CBM, m², sq ft, PCS, Ton, RMT). To check the
 * line against the product's prices — which are per the product's reference
 * unit (per CBM for 50 mm and above) — the line price is converted with the
 * piece size Length × Width × Thickness (cm):
 *
 *   CBM           nothing extra             same unit
 *   m² / sq ft    Thickness                 1 m² × thickness (1 m² = 10.7639 sq ft)
 *   PCS           Length × Width × Thickness one piece = L × W × T
 *   RMT           Width × Thickness         1 m × W × T
 *   Ton (and kg)  nothing extra             weight per CBM (category, product override)
 *   Set / Lot …   —                         cannot be converted → MD approval
 *
 * m² and sq ft convert into each other without a thickness. A line in the
 * reference unit itself needs nothing. Pure functions only (no database).
 *
 * Node twin: src/services/lineUnits.js.
 */
final class LineUnits
{
    public const SQFT_PER_M2 = 10.7639;
    /** Point 17: the totals row counts containers of 8 CBM each. */
    public const CONTAINER_CBM = 8.0;
    /** Natural stone's default weight per CBM (tonnes), set on the category. */
    public const STONE_T_PER_CBM = 2.7;

    private const ALIASES = [
        'CBM' => ['CBM', 'M3', 'M³', 'CUM', 'CU M', 'CUBIC METRE', 'CUBIC METER'],
        'M2' => ['M2', 'M²', 'SQM', 'SQ M', 'SQ. M', 'SQMT', 'SQUARE METRE', 'SQUARE METER'],
        'SQFT' => ['SQFT', 'SQ FT', 'SQ. FT', 'SFT', 'FT2', 'FT²', 'SQUARE FEET', 'SQUARE FOOT'],
        'PCS' => ['PCS', 'PC', 'PIECE', 'PIECES', 'NOS', 'NO'],
        'RMT' => ['RMT', 'RM', 'LM', 'RUNNING METRE', 'RUNNING METER'],
        'TON' => ['TON', 'TONS', 'TONNE', 'TONNES', 'MT', 'T'],
        'KG' => ['KG', 'KGS'],
    ];

    private const LABELS = ['CBM' => 'CBM', 'M2' => 'SQM', 'SQFT' => 'SQFT', 'PCS' => 'piece', 'RMT' => 'running metre', 'TON' => 'Ton', 'KG' => 'kg'];

    /** The size fields of a line, in the order they are asked for. */
    public const SIZE_FIELDS = ['piece_length_cm' => 'length', 'piece_width_cm' => 'width', 'piece_thickness_cm' => 'thickness'];

    /** The unit's kind for conversion ("CBM", "M2", "SQFT", "PCS", "RMT", "TON", "KG"), or null when it cannot be converted. */
    public static function canonical(?string $unit): ?string
    {
        $u = strtoupper(trim((string) $unit));
        if ($u === '') {
            return null;
        }
        foreach (self::ALIASES as $canon => $names) {
            if (in_array($u, $names, true)) {
                return $canon;
            }
        }
        return null;
    }

    /** "per piece", "per CBM" … for messages; the unit as written when it has no kind. */
    public static function label(?string $unit): string
    {
        $c = self::canonical($unit);
        return $c !== null ? self::LABELS[$c] : trim((string) $unit);
    }

    /**
     * How many reference units one line unit holds — the line price divided by it
     * is the price per reference unit.
     *
     * @return array{factor: ?float, missing: list<string>, unconvertible: bool}
     *         missing = the size fields (piece_length_cm …) or "weight" still needed
     */
    public static function factor(?string $lineUnit, ?string $refUnit, ?float $lCm, ?float $wCm, ?float $tCm, ?float $tPerCbm): array
    {
        $out = ['factor' => null, 'missing' => [], 'unconvertible' => false];
        $lineRaw = strtoupper(trim((string) $lineUnit));
        $refRaw = strtoupper(trim((string) $refUnit));
        // A product without a reference unit carries the label "unit" (ProductSpecs): no unit, compared as it is.
        if ($refRaw === 'UNIT') {
            $refRaw = '';
        }
        $lc = self::canonical($lineUnit);
        $rc = self::canonical($refUnit);
        // No unit on the line or the product, or the same unit: compared as it is (as before point 25).
        if ($lineRaw === '' || $refRaw === '' || $lineRaw === $refRaw || ($lc !== null && $lc === $rc)) {
            $out['factor'] = 1.0;
            return $out;
        }
        if ($lc === null || $rc === null) {
            $out['unconvertible'] = true;
            return $out;
        }
        $area = ['M2' => 1.0, 'SQFT' => 1 / self::SQFT_PER_M2];
        if (isset($area[$lc], $area[$rc])) {
            $out['factor'] = $area[$lc] / $area[$rc];
            return $out;
        }
        $missing = [];
        $line = self::volume($lc, $lCm, $wCm, $tCm, $tPerCbm, $missing);
        $ref = self::volume($rc, $lCm, $wCm, $tCm, $tPerCbm, $missing);
        $out['missing'] = array_values(array_unique($missing));
        if ($out['missing'] !== [] || $line === null || $ref === null || $ref <= 0.0) {
            return $out;
        }
        $out['factor'] = $line / $ref;
        return $out;
    }

    /** CBM in one unit of this kind; records what is missing. */
    private static function volume(string $canon, ?float $l, ?float $w, ?float $t, ?float $tPerCbm, array &$missing): ?float
    {
        $need = static function (?float $v, string $field) use (&$missing): bool {
            if ($v === null || $v <= 0.0) {
                $missing[] = $field;
                return false;
            }
            return true;
        };
        switch ($canon) {
            case 'CBM':
                return 1.0;
            case 'M2':
                return $need($t, 'piece_thickness_cm') ? $t / 100 : null;
            case 'SQFT':
                return $need($t, 'piece_thickness_cm') ? $t / 100 / self::SQFT_PER_M2 : null;
            case 'PCS':
                $ok = $need($l, 'piece_length_cm');
                $ok = $need($w, 'piece_width_cm') && $ok;
                $ok = $need($t, 'piece_thickness_cm') && $ok;
                return $ok ? ($l / 100) * ($w / 100) * ($t / 100) : null;
            case 'RMT':
                $ok = $need($w, 'piece_width_cm');
                $ok = $need($t, 'piece_thickness_cm') && $ok;
                return $ok ? ($w / 100) * ($t / 100) : null;
            case 'TON':
                return $need($tPerCbm, 'weight') ? 1 / $tPerCbm : null;
            case 'KG':
                return $need($tPerCbm, 'weight') ? 0.001 / $tPerCbm : null;
        }
        return null;
    }

    /** CBM in one unit of a line (for the totals row), or null when its size is not known. */
    public static function cbmPerUnit(?string $unit, ?float $lCm, ?float $wCm, ?float $tCm, ?float $tPerCbm): ?float
    {
        $c = self::canonical($unit);
        if ($c === null) {
            return null;
        }
        $missing = [];
        $v = self::volume($c, $lCm, $wCm, $tCm, $tPerCbm, $missing);
        return $missing === [] ? $v : null;
    }

    /** Point 17: the containers (8 CBM each) for a total CBM. */
    public static function containers(float $cbm): int
    {
        return $cbm > 0.0 ? (int) ceil(round($cbm / self::CONTAINER_CBM, 6)) : 0;
    }

    /** The message beside a missing size field, e.g. "Enter the length — needed to compare a price per piece." */
    public static function missingMessage(string $field, ?string $lineUnit, ?string $refUnit): string
    {
        if ($field === 'weight') {
            return 'Set the weight per CBM on the product or its category (Products) — needed to compare a price per ' . self::label($lineUnit) . '.';
        }
        $what = self::SIZE_FIELDS[$field] ?? $field;
        return 'Enter the ' . $what . ' (cm) — needed to compare a price per ' . self::label($lineUnit) . ' with the product\'s price per ' . self::label($refUnit) . '.';
    }

    /** The Dimensions text the three sizes fill in: "180 × 80 × 13 cm" (only the sizes given). */
    public static function dimensionsText(?float $l, ?float $w, ?float $t): string
    {
        $parts = array_map([self::class, 'num'], array_values(array_filter([$l, $w, $t], static fn ($v) => $v !== null && $v > 0.0)));
        return $parts === [] ? '' : implode(' × ', $parts) . ' cm';
    }

    /** 180 → "180", 2.50 → "2.5". */
    public static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /**
     * Reads the three size fields (names with an optional prefix/suffix, e.g.
     * "product_piece_length_cm" from a row). Up to 2 decimals, more than 0, at most 10000 cm.
     *
     * @return array{0: array{piece_length_cm: ?string, piece_width_cm: ?string, piece_thickness_cm: ?string}, 1: ?string}
     */
    public static function readSize(array $values): array
    {
        $out = [];
        foreach (self::SIZE_FIELDS as $field => $what) {
            $raw = trim((string) ($values[$field] ?? ''));
            if ($raw === '') {
                $out[$field] = null;
                continue;
            }
            if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0.0 || (float) $raw > 10000) {
                return [[], ucfirst($what) . ' (cm) must be a number above 0, up to 10000, with at most 2 decimals (e.g. 180 or 1.5).'];
            }
            $out[$field] = number_format((float) $raw, 2, '.', '');
        }
        return [$out, null];
    }

    /** A size value from a line or form (null when empty). */
    public static function size(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }
}
