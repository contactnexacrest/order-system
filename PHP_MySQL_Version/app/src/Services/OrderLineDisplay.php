<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner-approved layout of the order page's Products table (mockup 2, 18 Nov 2026):
 * how a line's figures are SHOWN in that table. Display only — nothing stored or
 * calculated changes, and no document uses these.
 *
 *   quantityText   "25" for PCS / SET / LOT … (piece-type units), CBM 3 decimals, SQM / SQFT 2,
 *                  any other unit as stored
 *   cbmEachText    "0.1274 CBM each" for a line with length × width × height
 *   pricedAtShort  D-374's record line, shortened: "₹91.61 / USD · 18 Nov 2026"
 *   chipText       an option chip; a bare "None" gets its specification's name ("Edge work: None")
 *
 * Node twin: src/services/orderLineDisplay.js.
 */
final class OrderLineDisplay
{
    /** Units counted in whole pieces besides the PCS kind (PCS, PC, NOS …). */
    public const PIECE_UNITS = ['SET', 'SETS', 'LOT', 'LOTS', 'PAIR', 'PAIRS', 'BOX', 'BOXES', 'UNIT', 'UNITS', 'PKG', 'PKGS', 'PACK', 'PACKS', 'BUNDLE', 'BUNDLES', 'CRATE', 'CRATES'];

    public static function isPieceUnit(?string $unit): bool
    {
        return LineUnits::canonical($unit) === 'PCS' || in_array(strtoupper(trim((string) $unit)), self::PIECE_UNITS, true);
    }

    public static function quantityText(mixed $quantity, ?string $unit): string
    {
        if ($quantity === null || $quantity === '' || !is_numeric($quantity)) {
            return (string) ($quantity ?? '');
        }
        $q = (float) $quantity;
        if (self::isPieceUnit($unit)) {
            // A part piece (rare) keeps its decimals, trimmed: 2.5 SET stays "2.5".
            return abs($q - round($q)) < 1e-9 ? number_format($q, 0) : rtrim(rtrim(number_format($q, 3), '0'), '.');
        }
        return match (LineUnits::canonical($unit)) {
            'CBM' => number_format($q, 3),
            'M2', 'SQFT' => number_format($q, 2),
            default => (string) $quantity,
        };
    }

    /** "0.1274 CBM each" (L × W × H / 1,000,000, 4 decimals), or '' without all three sizes. */
    public static function cbmEachText(array $line): string
    {
        $cbm = LineTable::perPiece('CBM', LineUnits::size($line['piece_length_cm'] ?? null), LineUnits::size($line['piece_width_cm'] ?? null), LineUnits::size($line['piece_thickness_cm'] ?? null));
        return $cbm !== null ? number_format($cbm, 4) . ' CBM each' : '';
    }

    /** D-374 record line, short: "₹91.61 / USD · 18 Nov 2026", "Rate not recorded", or '' for a line without a price. */
    public static function pricedAtShort(array $line): string
    {
        if (($line['unit_price'] ?? null) === null) {
            return '';
        }
        if (($line['priced_fx_rate'] ?? null) === null || empty($line['priced_at'])) {
            return 'Rate not recorded';
        }
        return '₹' . number_format((float) $line['priced_fx_rate'], 2) . ' / USD · ' . date('j M Y', (int) strtotime((string) $line['priced_at']));
    }

    /** @param array{name: string, gone: bool, spec?: string} $chip */
    public static function chipText(array $chip): string
    {
        $spec = trim((string) ($chip['spec'] ?? ''));
        return !$chip['gone'] && $spec !== '' && strcasecmp(trim($chip['name']), 'None') === 0 ? $spec . ': ' . $chip['name'] : $chip['name'];
    }
}
