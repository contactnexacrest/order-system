<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\MoneyFormat;

/**
 * Owner decision D-339 (12 Nov 2026, owner confirmed twice — "confirm 1" and
 * "confirm 2 with option a"; mockups approved): the detailed product table of
 * the Quotation, Proforma Invoice, Order Confirmation, Commercial Invoice, Buyer
 * PO and Packing List, and the "No. of pieces" of an order line.
 *
 *   # | Material / Colour | Surface / Finish | Dimensions (cm) L W H | <Unit> per piece
 *     | Qty No. of pcs | <Unit> all pcs | Price (<CCY>) per <Unit> | Price per piece | Total
 *
 * Measures of one piece (sizes in cm):
 *   CBM   L × W × H / 1,000,000, 4 decimals
 *   SQM   L × W / 10,000, 4 decimals kept, 2 shown
 *   SQFT  SQM × 10.7639, 2 decimals
 *   PCS   one piece (no measure)
 * Measure of all pieces = measure per piece × pieces (CBM 3 decimals, SQM / SQFT 2).
 * Price per piece = unit price × measure per piece (2 decimals). The line total is
 * always the line's own amount (quantity × unit price, as today) — never worked out
 * again from the rounded figures shown.
 *
 * An order keeps the layout it had: orders.line_table_layout 'classic' (every order
 * that existed before migration 2026-11-12g — its documents print exactly as before)
 * or 'detailed' (new orders). Pure functions only (no database).
 *
 * Node twin: src/services/lineTable.js (same wording, same rounding).
 */
final class LineTable
{
    public const LAYOUT_CLASSIC = 'classic';
    public const LAYOUT_DETAILED = 'detailed';

    /** The documents that print the detailed table on a 'detailed' order. */
    public const DOCUMENTS = ['QT', 'PI', 'OC', 'CI', 'BUYERPO', 'PL'];

    /** LineUnits kind => the unit code the documents print. */
    private const DOC_UNITS = ['CBM' => 'CBM', 'M2' => 'SQM', 'SQFT' => 'SQFT', 'PCS' => 'PCS'];

    /** Decimals of the measure of all pieces (the line quantity) per document unit. */
    private const ALL_DECIMALS = ['CBM' => 3, 'SQM' => 2, 'SQFT' => 2, 'PCS' => 0];

    /** Decimals shown for the measure of one piece. */
    private const PIECE_DECIMALS = ['CBM' => 4, 'SQM' => 2, 'SQFT' => 2];

    public const DASH = '—';

    /** "CBM", "SQM", "SQFT", "PCS", or null for any other unit (Ton, RMT, Set, Lot …). */
    public static function docUnit(?string $unit): ?string
    {
        $c = LineUnits::canonical($unit);
        return $c !== null && isset(self::DOC_UNITS[$c]) ? self::DOC_UNITS[$c] : null;
    }

    /** A unit worked out from the piece size (CBM, SQM, SQFT). */
    public static function isMeasureUnit(?string $unit): bool
    {
        $u = self::docUnit($unit);
        return $u !== null && $u !== 'PCS';
    }

    /** The measure of one piece in the line's unit (rounded as it is used), or null when it cannot be worked out. */
    public static function perPiece(?string $unit, ?float $l, ?float $w, ?float $h): ?float
    {
        $u = self::docUnit($unit);
        $ok = static fn (?float $v): bool => $v !== null && $v > 0.0;
        if ($u === null || $u === 'PCS' || !$ok($l) || !$ok($w)) {
            return null;
        }
        if ($u === 'CBM') {
            return $ok($h) ? round($l * $w * $h / 1000000, 4) : null;
        }
        $sqm = round($l * $w / 10000, 4);
        return $u === 'SQM' ? $sqm : round($sqm * LineUnits::SQFT_PER_M2, 2);
    }

    /** Decimals of the line quantity in this unit (CBM 3, SQM / SQFT 2, PCS 0); null for other units. */
    public static function quantityDecimals(?string $unit): ?int
    {
        $u = self::docUnit($unit);
        return $u !== null ? self::ALL_DECIMALS[$u] : null;
    }

    /** True when the line has the size its unit is worked out from — "No. of pieces" is then required. */
    public static function needsPieces(?string $unit, ?float $l, ?float $w, ?float $h): bool
    {
        return self::perPiece($unit, $l, $w, $h) !== null;
    }

    /**
     * The quantity worked out from the pieces: measure per piece × pieces (CBM 3 decimals,
     * SQM / SQFT 2), or the pieces themselves for PCS. Null when it cannot be worked out.
     */
    public static function quantityFromPieces(?string $unit, ?float $l, ?float $w, ?float $h, ?int $pieces): ?string
    {
        if ($pieces === null || $pieces <= 0) {
            return null;
        }
        $u = self::docUnit($unit);
        if ($u === 'PCS') {
            return number_format($pieces, 3, '.', '');
        }
        $per = self::perPiece($unit, $l, $w, $h);
        if ($per === null) {
            return null;
        }
        $dec = self::ALL_DECIMALS[$u];
        return number_format(round(round($per * $pieces, 6), $dec), 3, '.', '');
    }

    /** Price of one piece: unit price × measure per piece, 2 decimals. */
    public static function pricePerPiece(?float $unitPrice, ?float $perPiece): ?float
    {
        return $unitPrice === null || $perPiece === null ? null : round(round($unitPrice * $perPiece, 6), 2);
    }

    /**
     * "No. of pieces" from a form: empty, or a whole number from 1 to 9,999,999.
     *
     * @return array{0: ?int, 1: ?string} [pieces, error]
     */
    public static function readPieces(mixed $raw): array
    {
        $v = trim((string) $raw);
        if ($v === '') {
            return [null, null];
        }
        if (!preg_match('/^\d{1,7}$/', $v) || (int) $v < 1) {
            return [null, 'No. of pieces must be a whole number from 1 to 9999999 (e.g. 25).'];
        }
        return [(int) $v, null];
    }

    /** The size note from a form (at most 200 characters), or null. @return array{0: ?string, 1: ?string} */
    public static function readSizeNote(mixed $raw): array
    {
        $v = trim((string) $raw);
        if ($v === '') {
            return [null, null];
        }
        if (mb_strlen($v) > 200) {
            return [null, 'Size note: at most 200 characters.'];
        }
        return [$v, null];
    }

    /**
     * The quantity a line is saved with. With the size its unit is worked out from
     * (CBM / SQM / SQFT with Length and Width, and Thickness for CBM), "No. of pieces"
     * is required and the quantity is worked out from it — whatever the browser sent.
     * PCS with pieces: the quantity is the pieces. Otherwise the typed quantity stands.
     *
     * @return array{0: ?string, 1: ?string} [quantity to save (null = keep the typed one), error]
     */
    public static function serverQuantity(?string $unit, ?float $l, ?float $w, ?float $h, ?int $pieces, bool $perAnnexure, bool $quantityIsTbc): array
    {
        if ($perAnnexure) {
            return [null, null];
        }
        if (self::needsPieces($unit, $l, $w, $h)) {
            if ($pieces === null) {
                return [null, 'enter the No. of pieces — with the size given, the quantity is worked out from the pieces.'];
            }
            return [self::quantityFromPieces($unit, $l, $w, $h, $pieces), null];
        }
        if (self::docUnit($unit) === 'PCS' && $pieces !== null && !$quantityIsTbc) {
            return [self::quantityFromPieces($unit, $l, $w, $h, $pieces), null];
        }
        return [null, null];
    }

    /** 0.1872 → "0.1872" (CBM 4 decimals), 0.36 → "0.36" (SQM 2). */
    public static function formatPerPiece(float $v, string $docUnit): string
    {
        return number_format($v, self::PIECE_DECIMALS[$docUnit] ?? 2);
    }

    /** A line quantity in its unit: "4.680" (CBM), "180.00" (SQM), "20" (PCS). */
    public static function formatQuantity(float $v, string $docUnit): string
    {
        return number_format($v, self::ALL_DECIMALS[$docUnit] ?? 3);
    }

    /**
     * The summary under the order line's fields: "This line: 0.1872 CBM per piece · 25 pcs
     * = 4.680 CBM · USD 365.04 per piece · total USD 9,126.00". Null without a worked-out quantity.
     */
    public static function summary(?string $unit, ?float $l, ?float $w, ?float $h, ?int $pieces, ?float $unitPrice, string $currency): ?string
    {
        $u = self::docUnit($unit);
        if ($u === null || $pieces === null) {
            return null;
        }
        if ($u === 'PCS') {
            $text = 'This line: ' . number_format($pieces) . ' pcs';
            if ($unitPrice !== null) {
                $text .= ' · ' . $currency . ' ' . number_format($unitPrice, 2) . ' per piece · total ' . $currency . ' ' . number_format(round($pieces * $unitPrice, 2), 2);
            }
            return $text;
        }
        $per = self::perPiece($unit, $l, $w, $h);
        $qty = self::quantityFromPieces($unit, $l, $w, $h, $pieces);
        if ($per === null || $qty === null) {
            return null;
        }
        $text = 'This line: ' . self::formatPerPiece($per, $u) . ' ' . $u . ' per piece · ' . number_format($pieces) . ' pcs = ' . self::formatQuantity((float) $qty, $u) . ' ' . $u;
        if ($unitPrice !== null) {
            $text .= ' · ' . $currency . ' ' . number_format((float) self::pricePerPiece($unitPrice, $per), 2) . ' per piece · total ' . $currency . ' '
                . number_format(round(((float) $qty) * $unitPrice, 2), 2);
        }
        return $text;
    }

    /**
     * Owner decision D-343: the total CBM of the goods lines, for the "Weight & Volume" box of a detailed order —
     *   a line with a piece size (L, W, H) and pieces: pieces × L × W × H / 1,000,000 (per piece rounded to 4 decimals, as the table);
     *   a CBM line without a size: its quantity;
     *   a SQM / SQFT line with a thickness: its area × the thickness;
     * null when there is no goods line or any goods line cannot be turned into CBM (SQM / SQFT without a thickness,
     * PCS without a size, any other unit, a quantity still to be confirmed). Services and charges are not counted.
     *
     * @param list<array<string,mixed>> $lines order_products rows
     */
    public static function totalCbm(array $lines): ?float
    {
        $total = 0.0;
        $any = false;
        foreach ($lines as $line) {
            if (($line['line_type'] ?? 'goods') !== 'goods') {
                continue;
            }
            $any = true;
            $l = self::size($line['piece_length_cm'] ?? null);
            $w = self::size($line['piece_width_cm'] ?? null);
            $h = self::size($line['piece_thickness_cm'] ?? null);
            $pieces = isset($line['pieces_count']) && $line['pieces_count'] !== null && $line['pieces_count'] !== '' ? (int) $line['pieces_count'] : 0;
            if ($l !== null && $w !== null && $h !== null && $pieces > 0) {
                $total += (float) self::perPiece('CBM', $l, $w, $h) * $pieces;
                continue;
            }
            $tbc = !empty($line['quantity_is_tbc']) || ($line['quantity'] ?? null) === null || $line['quantity'] === '';
            $u = self::docUnit($line['unit'] ?? null);
            if ($tbc || $u === null || $u === 'PCS') {
                return null;
            }
            $qty = (float) $line['quantity'];
            if ($u === 'CBM') {
                $total += $qty;
            } elseif ($h !== null) {
                $sqm = $u === 'SQM' ? $qty : $qty / LineUnits::SQFT_PER_M2;
                $total += $sqm * $h / 100;
            } else {
                return null;
            }
        }
        return $any ? round(round($total, 6), 3) : null;
    }

    /**
     * The detailed table of a document.
     *
     * @param list<array<string,mixed>> $lines     the order_products rows on the document (in order)
     * @param list<array<string,mixed>> $formatted the same lines as the documents print them today
     *                                             (DocumentDataAssembler: description, quantity, unit, unit_price, amount …)
     * @param ?string $annexureLetter              D-336: the annexure "As per annexure" lines point to on this document (null: none)
     * @param array<string,int> $crateCounts       Packing List: crates per product description (lower case)
     * @param array<int, array{material?: string, finish?: string}> $names  per line id: Material / Colour and Surface / Finish
     */
    public static function build(array $lines, array $formatted, string $currency, ?string $annexureLetter, bool $withPrices, array $crateCounts = [], array $names = []): array
    {
        $stone = [];
        $simple = [];
        foreach ($lines as $i => $line) {
            $f = $formatted[$i] + ['no' => $i + 1];
            if (($line['line_type'] ?? 'goods') === 'goods' && self::docUnit($line['unit'] ?? null) !== null) {
                $stone[] = [$line, $f];
            } else {
                $simple[] = $f;
            }
        }
        $out = [
            'variant' => 'none', 'mixed' => false, 'unit' => null, 'currency' => $currency, 'with_prices' => $withPrices,
            'heads' => [], 'rows' => [], 'total' => null, 'totals_by_unit' => null, 'simple' => $simple, 'colspan' => 0,
        ];
        if ($stone === []) {
            return $out;
        }
        $units = array_values(array_unique(array_map(static fn (array $s): string => (string) self::docUnit($s[0]['unit'] ?? null), $stone)));
        $mixed = count($units) > 1;
        $full = false;
        foreach ($stone as [$line]) {
            $perAnnexure = $annexureLetter !== null && !empty($line['as_per_annexure']);
            if (!$perAnnexure && self::size($line['piece_length_cm'] ?? null) !== null && self::size($line['piece_width_cm'] ?? null) !== null) {
                $full = true;
            }
        }
        $out['variant'] = $full ? 'full' : 'short';
        $out['mixed'] = $mixed;
        $out['unit'] = $mixed ? null : $units[0];
        $out['heads'] = self::heads($out['variant'], $mixed ? null : $units[0], $currency, $withPrices);
        $out['colspan'] = count($out['heads']['bottom']) + 1;

        $pcsTotal = 0;
        $pcsAny = false;
        $byUnit = [];
        $amount = 0.0;
        foreach ($stone as [$line, $f]) {
            $u = (string) self::docUnit($line['unit'] ?? null);
            $suffix = static fn (string $v): string => $mixed && $v !== self::DASH ? $v . ' ' . $u : $v;
            $perAnnexure = $annexureLetter !== null && !empty($line['as_per_annexure']);
            $l = self::size($line['piece_length_cm'] ?? null);
            $w = self::size($line['piece_width_cm'] ?? null);
            $h = self::size($line['piece_thickness_cm'] ?? null);
            $pieces = isset($line['pieces_count']) && $line['pieces_count'] !== null && $line['pieces_count'] !== '' ? (int) $line['pieces_count'] : null;
            $tbc = !empty($line['quantity_is_tbc']) || $line['quantity'] === null || $line['quantity'] === '';
            $qty = $tbc ? null : (float) $line['quantity'];
            $price = $line['unit_price'] !== null && $line['unit_price'] !== '' ? (float) $line['unit_price'] : null;
            $hasSize = !$perAnnexure && $l !== null && $w !== null;
            $per = $hasSize ? self::perPiece($line['unit'] ?? null, $l, $w, $h) : null;
            if ($u === 'PCS' && $pieces === null && $qty !== null && !$perAnnexure) {
                $pieces = (int) round($qty);
            }
            if ($perAnnexure || !$hasSize && $u !== 'PCS') {
                $pieces = null;
            }
            if ($pieces !== null) {
                $pcsTotal += $pieces;
                $pcsAny = true;
            }
            if ($qty !== null) {
                $byUnit[$u] = ($byUnit[$u] ?? 0.0) + $qty;
            }
            if ($line['fob_value'] !== null && $line['fob_value'] !== '') {
                $amount += (float) $line['fob_value'];
            }
            $pricePiece = $u === 'PCS' ? $price : self::pricePerPiece($price, $per);
            $row = [
                'no' => $f['no'],
                'material' => $names[(int) ($line['id'] ?? 0)]['material'] ?? (string) $line['description'],
                'finish' => ($names[(int) ($line['id'] ?? 0)]['finish'] ?? '') !== '' ? $names[(int) $line['id']]['finish'] : (trim((string) ($line['finish'] ?? '')) !== '' ? (string) $line['finish'] : self::DASH),
                'annexure' => $perAnnexure ? AnnexureChoice::dimensionsText($annexureLetter) : null,
                'l' => $hasSize ? LineUnits::num($l) : self::DASH,
                'w' => $hasSize ? LineUnits::num($w) : self::DASH,
                'h' => $hasSize && $h !== null ? LineUnits::num($h) : self::DASH,
                'thickness' => !$perAnnexure && $h !== null ? LineUnits::num($h) : self::DASH,
                'per_piece' => $per !== null ? $suffix(self::formatPerPiece($per, $u)) : self::DASH,
                'pcs' => $pieces !== null ? number_format($pieces) : self::DASH,
                'all_pcs' => $qty !== null ? $suffix(self::formatQuantity($qty, $u)) : 'To Be Confirmed',
                // The short table's "Qty" (every unit, PCS included).
                'qty' => $qty !== null ? $suffix(self::formatQuantity($qty, $u)) : 'To Be Confirmed',
                // D-343: money in Indian grouping on an INR order (1,75,000.00), as before in every other currency.
                'price_unit' => $price !== null ? MoneyFormat::amount($price, $currency) . ($mixed ? ' /' . $u : '') : 'To Be Confirmed',
                'price_piece' => $pricePiece !== null && !$perAnnexure && ($per !== null || $u === 'PCS') ? MoneyFormat::amount($pricePiece, $currency) : self::DASH,
                'total' => (string) ($f['amount'] ?? ''),
                'crates' => self::crates($crateCounts, (string) $line['description']),
                'detail' => self::detail($line, $f, $perAnnexure ? $annexureLetter : null, $hasSize),
            ];
            if ($u === 'PCS') {
                $row['per_piece'] = self::DASH;
                $row['all_pcs'] = self::DASH;
            }
            $out['rows'][] = $row;
        }
        $order = ['CBM', 'SQM', 'SQFT', 'PCS'];
        $parts = [];
        foreach ($order as $u) {
            if (isset($byUnit[$u])) {
                $parts[] = self::formatQuantity($byUnit[$u], $u) . ' ' . $u;
            }
        }
        $out['total'] = [
            'pcs' => $pcsAny ? number_format($pcsTotal) : '',
            // One unit: its total (a PCS-only full table counts the pieces instead). Mixed units are never added up.
            'measure' => !$mixed && isset($byUnit[$units[0]]) && ($units[0] !== 'PCS' || $out['variant'] === 'short') ? self::formatQuantity($byUnit[$units[0]], $units[0]) : '',
            'amount' => MoneyFormat::amount($amount, $currency),
        ];
        $out['totals_by_unit'] = $mixed && $parts !== [] ? 'Totals by unit: ' . implode(' · ', $parts) : null;
        return $out;
    }

    /**
     * The two header rows. 'top' and 'bottom' list the cells after "#"; a top cell
     * may span ('span') columns, and then the bottom row holds one cell per column.
     */
    private static function heads(string $variant, ?string $unit, string $currency, bool $withPrices): array
    {
        $measure = $unit ?? 'Measure';
        $per = $unit !== null ? 'per ' . $unit : 'per unit';
        $price = 'Price (' . $currency . ')';
        if ($variant === 'full') {
            $top = [['Material', 1], ['Surface', 1], ['Dimensions (cm)', 3], [$measure, 1], ['Qty', 1], [$measure, 1]];
            $bottom = ['Colour', 'Finish', 'L', 'W', 'H', 'per piece', 'No. of pcs', 'all pcs'];
            // Column widths (%) as the approved mockups; mixed units leave room for "0.1872 CBM".
            $widths = $withPrices
                ? ($unit !== null ? [3, 13, 8, 5, 5, 5, 8, 7, 8, 12, 12, 14] : [3, 12, 8, 5, 5, 5, 10, 6, 10, 12, 10, 14])
                : ($unit !== null ? [4, 18, 10, 7, 7, 7, 11, 10, 12, 14] : [4, 17, 9, 6, 6, 6, 14, 9, 15, 14]);
            if ($withPrices) {
                $top = array_merge($top, [[$price, 1], [$price, 1], ['Total Price', 1]]);
                $bottom = array_merge($bottom, [$per, 'per piece', '(' . $currency . ')']);
            } else {
                $top[] = ['Crates /', 1];
                $bottom[] = 'Packages';
            }
        } else {
            $top = [['Material', 1], ['Surface', 1], ['Thickness', 1], ['Qty', 1]];
            $bottom = ['Colour', 'Finish', '(cm)', $unit ?? 'in its unit'];
            $widths = $withPrices ? [4, 27, 13, 12, 12, 15, 17] : [5, 35, 15, 15, 15, 15];
            if ($withPrices) {
                $top = array_merge($top, [[$price, 1], ['Total Price', 1]]);
                $bottom = array_merge($bottom, [$per, '(' . $currency . ')']);
            } else {
                $top[] = ['Crates /', 1];
                $bottom[] = 'Packages';
            }
        }
        // Each top cell's width (%): the columns it spans.
        $out = [];
        $wi = 1;
        foreach ($top as [$text, $span]) {
            $out[] = ['text' => $text, 'span' => $span, 'width' => array_sum(array_slice($widths, $wi, $span))];
            $wi += $span;
        }
        return ['top' => $out, 'bottom' => $bottom, 'widths' => $widths];
    }

    /** Packing List: the crates whose product description is this line's, or a dash. */
    private static function crates(array $crateCounts, string $description): string
    {
        $n = $crateCounts[mb_strtolower(trim($description))] ?? 0;
        return $n > 0 ? (string) $n : self::DASH;
    }

    /**
     * The grey row under a line: "Qty as per Annexure A" · the size note · the Dimensions text of a
     * line without a piece size · another category's attributes · HS Code · Country of Origin.
     *
     * @return list<array{label: ?string, text: string}>
     */
    private static function detail(array $line, array $f, ?string $annexureLetter, bool $hasSize): array
    {
        $out = [];
        if ($annexureLetter !== null) {
            $out[] = ['label' => null, 'text' => 'Qty as per Annexure ' . $annexureLetter];
        }
        $note = trim((string) ($line['size_note'] ?? ''));
        if ($note !== '') {
            $out[] = ['label' => 'Sizes:', 'text' => $note];
        }
        $dims = trim((string) ($line['dimensions'] ?? ''));
        if ($annexureLetter === null && !$hasSize && $dims !== '' && $note === '') {
            $out[] = ['label' => 'Dimensions:', 'text' => $dims];
        }
        foreach ($f['attributes'] ?? [] as $a) {
            $out[] = ['label' => $a['label'] . ':', 'text' => (string) $a['value']];
        }
        $out[] = ['label' => 'HS Code:', 'text' => (string) ($line['hs_code'] ?? '')];
        $out[] = ['label' => 'Country of Origin:', 'text' => 'India'];
        return $out;
    }

    private static function size(mixed $v): ?float
    {
        return $v === null || $v === '' || (float) $v <= 0.0 ? null : (float) $v;
    }
}
