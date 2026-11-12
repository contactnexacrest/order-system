<?php

declare(strict_types=1);

namespace App\Services\Docx;

use App\Helpers\MarkdownLite;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/**
 * Shared PHPWord building blocks that translate _layout.html.twig's CSS
 * (colors, section-title bars, kv tables, colored notice boxes, product
 * tables, signature block, header, watermark) into real Word formatting.
 * Every per-document-type render method in DocxDocumentBuilder is built
 * out of these pieces so the 11 document types stay visually consistent
 * with each other and with the PDF, without repeating table-styling code
 * 11 times.
 *
 * Colors are the literal hex values from _layout.html.twig's <style>
 * block (without the leading '#' — PHPWord convention).
 */
final class DocxComponents
{
    public const NAVY = '17233A';
    public const NAVY_MID = '173B73';
    public const GRAY_LIGHT = 'F1F5F9';
    public const BORDER_GRAY = 'D7DEE6';
    public const MUTED = '5B6573';
    public const SPEC_BG = 'EFF3F7';
    public const WHITE = 'FFFFFF';
    public const BLACK = '000000';

    public const AMBER_BG = 'FFF3CD';
    public const AMBER_BG2 = 'FAEEDA';
    public const AMBER_BORDER = '8A6D1D';
    public const AMBER_LABEL = '633806';
    public const AMBER_VALUE = '412402';
    public const AMBER_NOTE = '854F0B';
    public const AMBER_TEXT2 = '5C4813';

    public const GREEN_BG = 'EAF3DE';
    public const GREEN_BORDER = '27500A';
    public const GREEN_VALUE = '3B6D11';

    public const RED_BG = 'FBEAEA';
    public const RED_BORDER = '8A1F1F';
    public const RED_TEXT = '8A1F1F';
    public const RED_SUB = '5A1414';

    public const BLUE_BG = 'E6F1FB';
    public const BLUE_BORDER = '1D6FA8';
    public const BLUE_BG2 = 'EAF1F9';
    public const BLUE_BORDER2 = '17233A';
    public const BLUE_TEXT = '185FA5';

    public const FONT = 'Carlito';

    /**
     * Owner decision D-354 (12 Nov 2026, owner confirm 1 + confirm 2): a section heading, or a
     * table's column-header row, is never left alone at the foot of a page — Word moves it to
     * the next page together with its first rows. KEEP_NEXT ("keep with next") goes on every
     * heading paragraph and on every paragraph of a column-header row (a paragraph in a table
     * row with keep-with-next keeps that row with the next one); HEADER_ROW marks the header
     * row "repeat as header row" and "do not split". Node twin: docxComponents.js.
     */
    public const KEEP_NEXT = ['keepNext' => true];
    public const HEADER_ROW = ['tblHeader' => true, 'cantSplit' => true];

    /** @var string[] temp files created by decodeDataUriToTempFile(), cleaned up after each document render */
    private static array $tempFiles = [];

    // ------------------------------------------------------------------
    // Document-level setup
    // ------------------------------------------------------------------

    public static function applyDefaultStyle(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName(self::FONT);
        $phpWord->setDefaultFontSize(9.5);
        $phpWord->setDefaultParagraphStyle([
            'spaceAfter' => 60,
            'spaceBefore' => 0,
        ]);
    }

    public static function addSection(PhpWord $phpWord): Section
    {
        return $phpWord->addSection([
            'paperSize' => 'A4',
            'orientation' => 'portrait',
            'marginTop' => 720,    // 0.5in
            'marginBottom' => 720, // 0.5in
            'marginLeft' => 850,   // 0.59in
            'marginRight' => 850,  // 0.59in
        ]);
    }

    // ------------------------------------------------------------------
    // Header: logo + company name / doc title / incoterm chip
    // ------------------------------------------------------------------

    public static function addHeader(Section $section, array $context, string $docTitle, ?string $incotermText): void
    {
        $logoDataUri = $context['assets']['logo_data_uri'] ?? null;
        $companyName = strtoupper((string) ($context['company']['legal_name'] ?? ''));

        $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow();
        $logoPath = $logoDataUri ? self::decodeDataUriToTempFile($logoDataUri) : null;
        if ($logoPath) {
            $cell = $table->addCell(750, ['valign' => 'center']);
            $cell->addImage($logoPath, ['width' => 54, 'height' => 54, 'ratio' => true]);
            $titleWidth = 4250;
        } else {
            $titleWidth = 5000;
        }
        $titleCell = $table->addCell($titleWidth, ['valign' => 'center']);
        $titleCell->addText($companyName, ['bold' => true, 'size' => 13, 'color' => self::NAVY], ['alignment' => Jc::END]);
        $titleCell->addText($docTitle, ['bold' => true, 'size' => 18, 'color' => self::NAVY], ['alignment' => Jc::END, 'spaceBefore' => 20]);
        if ($incotermText) {
            $titleCell->addText($incotermText, ['bold' => true, 'size' => 10, 'color' => self::MUTED], ['alignment' => Jc::END, 'spaceBefore' => 20]);
        }
        $section->addTextBreak(1, 6);
    }

    /**
     * The N-column shaded meta strip (QUOTATION NO. / DATE / BUYER INQUIRY
     * REF etc.) — one row, one cell per item, each with a small gray label
     * over a bold navy value.
     *
     * @param array<int, array{label:string,value:string}> $items
     */
    public static function addMetaBar(Section $section, array $items): void
    {
        $count = max(1, count($items));
        $width = intdiv(5000, $count);
        $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow();
        foreach ($items as $item) {
            $cell = $table->addCell($width, [
                'bgColor' => self::GRAY_LIGHT,
                'borderSize' => 4,
                'borderColor' => self::BORDER_GRAY,
                'valign' => 'top',
            ]);
            $cell->addText(strtoupper($item['label']), ['size' => 8, 'color' => self::MUTED], ['spaceAfter' => 20]);
            $cell->addText((string) $item['value'], ['bold' => true, 'size' => 10, 'color' => self::NAVY]);
        }
        $section->addTextBreak(1, 6);
    }

    // ------------------------------------------------------------------
    // Section title bar: solid navy, white bold text
    // ------------------------------------------------------------------

    public static function addSectionTitle(Section $section, string $text, string $bg = self::NAVY): void
    {
        $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow(null, ['cantSplit' => true]);
        $cell = $table->addCell(5000, ['bgColor' => $bg, 'valign' => 'center']);
        $cell->addText($text, ['bold' => true, 'size' => 11, 'color' => self::WHITE], self::KEEP_NEXT);
        // D-356: an empty run, not addTextBreak() — PHPWord writes a second, empty <w:pPr> for a
        // text break with a paragraph style, which Word may report as a damaged file.
        $section->addText('', ['size' => 4], self::KEEP_NEXT);
    }

    // ------------------------------------------------------------------
    // Generic bordered/shaded notice box (single-cell "div") — amber /
    // green / red / blue boxes throughout every template.
    //
    // @param callable(AbstractContainer):void $contentFn
    // ------------------------------------------------------------------

    public static function addColorBox(Section $section, string $bg, string $borderColor, callable $contentFn, int $borderSize = 5): void
    {
        $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow();
        $cell = $table->addCell(5000, [
            'bgColor' => $bg,
            'borderSize' => $borderSize,
            'borderColor' => $borderColor,
            'valign' => 'top',
        ]);
        $contentFn($cell);
        $section->addTextBreak(1, 6);
    }

    public const PAYMENT_TERMS_BG = 'EEF2F8';
    public const PAYMENT_TERMS_EDGE = '1F3A68';

    /**
     * Owner decision D-355 (12 Nov 2026, owner confirm 1 + confirm 2): the PAYMENT TERMS lines in
     * one continuous box — a one-cell table with a light grey-blue fill (EEF2F8), a 3pt navy left
     * edge (1F3A68) and no other border, padded about 6px x 12px like the PDF. The row cannot split
     * across a page and every line but the last keeps with the next, so the box stays together and
     * the heading above (D-354 keep-with-next) stays with its first line. A spacer follows so the
     * box never merges with a table after it. Node twin: docxComponents.js paymentTermsBox().
     *
     * @param list<list<array{0:string,1?:array}>> $lines each paragraph's text runs
     */
    public static function addPaymentTermsBox(Section $section, array $lines): void
    {
        $table = $section->addTable([
            'unit' => TblWidth::PERCENT, 'width' => 5000,
            'cellMarginTop' => 90, 'cellMarginBottom' => 90, 'cellMarginLeft' => 180, 'cellMarginRight' => 180,
        ]);
        $table->addRow(null, ['cantSplit' => true]);
        $cell = $table->addCell(5000, [
            'bgColor' => self::PAYMENT_TERMS_BG,
            'borderLeftSize' => 24,
            'borderLeftColor' => self::PAYMENT_TERMS_EDGE,
            'valign' => 'top',
        ]);
        $last = count($lines) - 1;
        foreach (array_values($lines) as $i => $segments) {
            $run = $cell->addTextRun(['spaceAfter' => $i === $last ? 0 : 60] + ($i === $last ? [] : self::KEEP_NEXT));
            foreach ($segments as $seg) {
                $run->addText($seg[0], array_merge(['size' => 9.5], $seg[1] ?? []));
            }
        }
        $section->addTextBreak(1, 6);
    }

    // ------------------------------------------------------------------
    // KV table: two-column, alternating-shade bold navy labels, thin
    // borders. $rows: list of ['label'=>string, 'value'=>string|callable,
    // 'labelBg'=>?string override, 'full'=>bool (single full-width value
    // cell, no label column, e.g. free-text remark rows)]
    // ------------------------------------------------------------------

    public static function addKvTable(Section $section, array $rows, bool $solidLabel = false, int $labelWidthPct = 30): void
    {
        $labelWidth = (int) round($labelWidthPct * 50);
        $valueWidth = 5000 - $labelWidth;
        $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000]);
        $i = 0;
        foreach ($rows as $row) {
            $table->addRow();
            if (!empty($row['full'])) {
                $cell = $table->addCell(5000, [
                    'bgColor' => $row['bg'] ?? null,
                    'borderSize' => 4,
                    'borderColor' => self::BORDER_GRAY,
                    'valign' => 'top',
                ]);
                self::renderValue($cell, $row['value'] ?? '');
                $i++;
                continue;
            }
            $shaded = $solidLabel || ($i % 2 === 0);
            $labelCell = $table->addCell($labelWidth, [
                'bgColor' => $row['labelBg'] ?? ($shaded ? self::GRAY_LIGHT : self::WHITE),
                'borderSize' => 4,
                'borderColor' => self::BORDER_GRAY,
                'valign' => 'top',
            ]);
            $labelCell->addText((string) $row['label'], ['bold' => true, 'size' => 9.5, 'color' => self::NAVY]);

            $valueCell = $table->addCell($valueWidth, [
                'bgColor' => $row['valueBg'] ?? self::WHITE,
                'borderSize' => 4,
                'borderColor' => self::BORDER_GRAY,
                'valign' => 'top',
            ]);
            self::renderValue($valueCell, $row['value'] ?? '');
            $i++;
        }
        $section->addTextBreak(1, 6);
    }

    /** @param string|callable(AbstractContainer):void $value */
    private static function renderValue(AbstractContainer $cell, $value): void
    {
        if (is_callable($value)) {
            $value($cell);
            return;
        }
        $cell->addText((string) $value, ['size' => 9.5]);
    }

    // ------------------------------------------------------------------
    // Product table: navy-mid header, alternating spec sub-rows.
    //
    // @param string[] $headers
    // @param array<int, array{cells: string[], specText?: string, lineColor?: string}> $rows
    // @param int[] $widthsPct percentages summing to 100, one per header
    // ------------------------------------------------------------------

    public static function addProductsTable(Section $section, array $headers, array $widthsPct, array $rows): void
    {
        $widths = array_map(static fn($p) => (int) round($p * 50), $widthsPct);
        $colCount = count($headers);

        $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow(null, self::HEADER_ROW);
        foreach ($headers as $i => $h) {
            $cell = $table->addCell($widths[$i] ?? intdiv(5000, $colCount), [
                'bgColor' => self::NAVY_MID,
                'valign' => 'center',
            ]);
            $cell->addText($h, ['bold' => true, 'size' => 9, 'color' => self::WHITE], self::KEEP_NEXT);
        }

        foreach ($rows as $row) {
            $table->addRow();
            $cells = $row['cells'];
            foreach ($cells as $i => $val) {
                $cell = $table->addCell($widths[$i] ?? intdiv(5000, $colCount), [
                    'borderSize' => 4,
                    'borderColor' => self::BORDER_GRAY,
                    'valign' => 'top',
                ]);
                $color = ($i === 0 && !empty($row['lineColor'])) ? $row['lineColor'] : null;
                $align = in_array($row['align'][$i] ?? null, ['right', 'center'], true) ? $row['align'][$i] : null;
                $pStyle = $align ? ['alignment' => $align === 'right' ? Jc::END : Jc::CENTER] : [];
                $cell->addText((string) $val, array_filter(['size' => 9.5, 'bold' => $i === 0, 'color' => $color]), $pStyle);
            }
            if (!empty($row['specText'])) {
                $table->addRow();
                $specCell = $table->addCell(5000, [
                    'gridSpan' => $colCount,
                    'bgColor' => self::SPEC_BG,
                    'valign' => 'top',
                ]);
                $specRun = $specCell->addTextRun();
                foreach ($row['specText'] as $seg) {
                    $specRun->addText($seg[0], array_merge(['size' => 8.5], $seg[1] ?? []));
                }
            }
        }
        $section->addTextBreak(1, 6);
    }

    /**
     * Owner decision D-339 (12 Nov 2026, owner confirmed twice): the detailed product table
     * (LineTable::build()) — 8 pt, two header rows repeated on every page, a grey detail row
     * under each line (documents with prices), the TOTAL row and, with mixed units, the
     * "Totals by unit" row. The lines not sold by size follow in today's simple table
     * ($simpleHeaders / $simpleRows, built by the caller as today); the Packing List's
     * simple rows carry no prices. Node twin: docxComponents.addLineTable().
     *
     * @param array<string,mixed> $lt
     */
    public static function addLineTable(Section $section, array $lt, array $simpleHeaders = [], array $simpleWidths = [], array $simpleRows = []): void
    {
        if (($lt['variant'] ?? 'none') !== 'none') {
            $widths = array_map(static fn ($p) => (int) round($p * 50), $lt['heads']['widths']);
            $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000, 'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED]);
            $head = static function ($cell, string $text): void {
                $cell->addText($text, ['bold' => true, 'size' => 8, 'color' => self::WHITE], ['alignment' => Jc::CENTER, 'spaceAfter' => 0] + self::KEEP_NEXT);
            };
            $hStyle = ['bgColor' => self::NAVY_MID, 'valign' => 'center', 'borderSize' => 4, 'borderColor' => 'C9D1E3'];
            // Header row 1: "#" spans both rows; "Dimensions (cm)" spans L W H.
            $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
            $head($table->addCell($widths[0], $hStyle + ['vMerge' => 'restart']), '#');
            $wi = 1;
            foreach ($lt['heads']['top'] as $t) {
                if ($t['span'] > 1) {
                    $w = $widths[$wi] + $widths[$wi + 1] + $widths[$wi + 2];
                    $head($table->addCell($w, $hStyle + ['gridSpan' => 3]), $t['text']);
                    $wi += 3;
                } else {
                    $head($table->addCell($widths[$wi], $hStyle), $t['text']);
                    $wi++;
                }
            }
            $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
            $table->addCell($widths[0], $hStyle + ['vMerge' => 'continue']);
            foreach ($lt['heads']['bottom'] as $i => $b) {
                $head($table->addCell($widths[$i + 1], $hStyle), $b);
            }
            $cStyle = ['borderSize' => 4, 'borderColor' => self::BORDER_GRAY, 'valign' => 'top'];
            $cell = static function ($table, int $w, string $text, string $align = 'right', array $font = [], array $extra = []) use ($cStyle): void {
                $c = $table->addCell($w, $cStyle + $extra);
                $dash = $text === '—';
                $c->addText($text, array_merge(['size' => 8], $dash ? ['color' => self::MUTED] : [], $font),
                    ['alignment' => $dash || $align === 'center' ? Jc::CENTER : ($align === 'left' ? Jc::START : Jc::END), 'spaceAfter' => 0]);
            };
            $full = $lt['variant'] === 'full';
            $prices = !empty($lt['with_prices']);
            $cols = count($widths);
            foreach ($lt['rows'] as $r) {
                $table->addRow(null, ['cantSplit' => true]);
                $cell($table, $widths[0], (string) $r['no'], 'center');
                $cell($table, $widths[1], (string) $r['material'], 'left');
                $cell($table, $widths[2], (string) $r['finish'], 'center');
                if ($full) {
                    if ($r['annexure'] !== null) {
                        $cell($table, $widths[3] + $widths[4] + $widths[5], (string) $r['annexure'], 'center', ['italic' => true, 'color' => '8A5A00'], ['gridSpan' => 3]);
                    } else {
                        $cell($table, $widths[3], (string) $r['l']);
                        $cell($table, $widths[4], (string) $r['w']);
                        $cell($table, $widths[5], (string) $r['h']);
                    }
                    $cell($table, $widths[6], (string) $r['per_piece']);
                    $cell($table, $widths[7], (string) $r['pcs']);
                    $cell($table, $widths[8], (string) $r['all_pcs']);
                    if ($prices) {
                        $cell($table, $widths[9], (string) $r['price_unit']);
                        $cell($table, $widths[10], (string) $r['price_piece']);
                        $cell($table, $widths[11], (string) $r['total']);
                    } else {
                        $cell($table, $widths[9], (string) $r['crates']);
                    }
                } else {
                    if ($r['annexure'] !== null) {
                        $cell($table, $widths[3], (string) $r['annexure'], 'center', ['italic' => true, 'color' => '8A5A00']);
                    } else {
                        $cell($table, $widths[3], (string) $r['thickness']);
                    }
                    $cell($table, $widths[4], (string) $r['qty']);
                    if ($prices) {
                        $cell($table, $widths[5], (string) $r['price_unit']);
                        $cell($table, $widths[6], (string) $r['total']);
                    } else {
                        $cell($table, $widths[5], (string) $r['crates']);
                    }
                }
                if ($prices) {
                    $table->addRow(null, ['cantSplit' => true]);
                    $table->addCell($widths[0], ['borderSize' => 4, 'borderColor' => self::BORDER_GRAY, 'bgColor' => self::SPEC_BG]);
                    $det = $table->addCell(5000 - $widths[0], ['gridSpan' => $cols - 1, 'bgColor' => self::SPEC_BG, 'borderSize' => 4, 'borderColor' => self::BORDER_GRAY]);
                    $run = $det->addTextRun(['spaceAfter' => 0]);
                    foreach ($r['detail'] as $k => $d) {
                        if ($k > 0) {
                            $run->addText('   ·   ', ['size' => 7, 'color' => self::MUTED]);
                        }
                        if ($d['label'] !== null) {
                            $run->addText($d['label'] . ' ', ['size' => 7, 'bold' => true, 'color' => self::NAVY]);
                        }
                        $run->addText((string) $d['text'], ['size' => 7, 'color' => '3B4560']);
                    }
                }
            }
            $tot = ['bgColor' => self::NAVY, 'borderSize' => 4, 'borderColor' => self::BORDER_GRAY, 'valign' => 'center'];
            $totText = static function ($table, int $w, string $text, array $extra = []) use ($tot): void {
                $table->addCell($w, $tot + $extra)->addText($text, ['size' => 8, 'bold' => true, 'color' => self::WHITE], ['alignment' => Jc::END, 'spaceAfter' => 0]);
            };
            $table->addRow(null, ['cantSplit' => true]);
            $lead = $full ? 7 : 4;
            $table->addCell(array_sum(array_slice($widths, 0, $lead)), $tot + ['gridSpan' => $lead])
                ->addText('TOTAL', ['size' => 8, 'bold' => true, 'color' => self::WHITE], ['spaceAfter' => 0]);
            if ($full) {
                $totText($table, $widths[7], (string) $lt['total']['pcs']);
                $totText($table, $widths[8], (string) $lt['total']['measure']);
                if ($prices) {
                    $totText($table, $widths[9], '');
                    $totText($table, $widths[10], '');
                    $totText($table, $widths[11], (string) $lt['total']['amount']);
                } else {
                    $totText($table, $widths[9], '');
                }
            } else {
                $totText($table, $widths[4], (string) $lt['total']['measure']);
                if ($prices) {
                    $totText($table, $widths[5], '');
                    $totText($table, $widths[6], (string) $lt['total']['amount']);
                } else {
                    $totText($table, $widths[5], '');
                }
            }
            if (!empty($lt['totals_by_unit'])) {
                $table->addRow(null, ['cantSplit' => true]);
                $table->addCell(5000, ['gridSpan' => $cols, 'bgColor' => self::SPEC_BG, 'borderSize' => 4, 'borderColor' => self::BORDER_GRAY])
                    ->addText((string) $lt['totals_by_unit'], ['size' => 8, 'bold' => true, 'color' => self::NAVY], ['spaceAfter' => 0]);
            }
            $section->addTextBreak(1, 6);
        }
        if ($simpleRows !== []) {
            self::addProductsTable($section, $simpleHeaders, $simpleWidths, $simpleRows);
        }
    }

    // ------------------------------------------------------------------
    // Totals table: last row (highlight) uses navy-mid, white bold italic.
    // @param array<int, array{label:string, value:string, highlight?:bool}> $rows
    // ------------------------------------------------------------------

    public static function addTotalsTable(Section $section, array $rows, int $labelWidthPct = 55): void
    {
        $labelWidth = (int) round($labelWidthPct * 50);
        $valueWidth = 5000 - $labelWidth;
        $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000]);
        foreach ($rows as $row) {
            $table->addRow();
            $highlight = !empty($row['highlight']);
            $labelCell = $table->addCell($labelWidth, [
                'bgColor' => $highlight ? self::NAVY : self::WHITE,
                'borderSize' => 4,
                'borderColor' => self::BORDER_GRAY,
                'valign' => 'top',
            ]);
            $labelCell->addText((string) $row['label'], [
                'bold' => true,
                'size' => $highlight ? 10.5 : 9.5,
                'color' => $highlight ? self::WHITE : self::NAVY,
            ]);
            $valueCell = $table->addCell($valueWidth, [
                'bgColor' => $highlight ? self::NAVY_MID : self::WHITE,
                'borderSize' => 4,
                'borderColor' => self::BORDER_GRAY,
                'valign' => 'top',
            ]);
            self::renderValue($valueCell, $row['value'] ?? '');
        }
        $section->addTextBreak(1, 6);
    }

    // ------------------------------------------------------------------
    // Checklist table (COOPREP): dark-navy header row, optional checkbox col.
    // @param string[] $headers
    // @param array<int, array{cells:(string|callable)[], checkboxCol?:bool}> $rows
    // ------------------------------------------------------------------

    public static function addChecklistTable(Section $section, array $headers, array $widthsPct, array $rows): void
    {
        $widths = array_map(static fn($p) => (int) round($p * 50), $widthsPct);
        $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow(null, self::HEADER_ROW);
        foreach ($headers as $i => $h) {
            $cell = $table->addCell($widths[$i] ?? null, ['bgColor' => self::NAVY, 'valign' => 'center']);
            $cell->addText($h, ['bold' => true, 'size' => 9, 'color' => self::WHITE], self::KEEP_NEXT);
        }
        foreach ($rows as $row) {
            $table->addRow();
            foreach ($row['cells'] as $i => $val) {
                $cell = $table->addCell($widths[$i] ?? null, [
                    'borderSize' => 4,
                    'borderColor' => self::BORDER_GRAY,
                    'valign' => 'top',
                ]);
                self::renderValue($cell, $val);
            }
        }
        $section->addTextBreak(1, 6);
    }

    /** A "Step N" navy badge cell used by COOPREP's step-by-step table. */
    public static function stepBadge(AbstractContainer $cell, string $label): void
    {
        $cell->addText($label, ['bold' => true, 'size' => 9, 'color' => self::WHITE], ['alignment' => Jc::CENTER]);
    }

    // ------------------------------------------------------------------
    // Bullet / numbered plain-text blocks
    // ------------------------------------------------------------------

    /** @param array<int, array{0:string,1?:array}> $segments text runs, each optionally styled */
    public static function addRichParagraph(Section $section, array $segments, array $pStyle = []): void
    {
        $run = $section->addTextRun(array_merge(['spaceAfter' => 60], $pStyle));
        foreach ($segments as $seg) {
            $run->addText($seg[0], array_merge(['size' => 9.5], $seg[1] ?? []));
        }
    }

    public static function addPlainParagraph(Section $section, string $text, array $style = [], array $pStyle = []): void
    {
        $section->addText($text, array_merge(['size' => 9.5], $style), ['spaceAfter' => 60] + $pStyle);
    }

    // ------------------------------------------------------------------
    // Signature block: seal + signature images, name/designation/company,
    // disclaimer text — mirrors _layout.html.twig's default signature_block.
    // ------------------------------------------------------------------

    /**
     * 90-item backlog Phase 6 Item C: a signature block/table must always
     * carry real, document-identifying text — never just a bare
     * seal/signature with no reference to which document it belongs to
     * — so a signature page can never be mistaken for or substituted
     * into a different document, even if it lands alone on its own page.
     * Mirrors _layout.html.twig's .signature-doc-ref line.
     */
    public static function addSignatureDocRefLine(Section $section, array $context): void
    {
        $docTitle = (string) ($context['doc_title'] ?? 'document');
        $meta = $context['meta'] ?? [];
        $order = $context['order'] ?? [];
        $text = "This signature forms part of {$docTitle}";
        if (!empty($meta['document_reference'])) {
            $text .= ' ' . $meta['document_reference'];
        }
        if (!empty($meta['generated_date'])) {
            $text .= ', dated ' . $meta['generated_date'];
        }
        if (!empty($order['buyer_inquiry_ref'])) {
            $text .= ', Buyer Inquiry Ref ' . $order['buyer_inquiry_ref'];
        }
        $text .= '.';
        $section->addText($text, ['bold' => true, 'size' => 8.5, 'color' => self::NAVY], ['spaceAfter' => 80] + self::KEEP_NEXT);
    }

    public static function addSignatureBlock(Section $section, array $context): void
    {
        $company = $context['company'] ?? [];
        $signatory = $context['signatory'] ?? [];

        $section->addTextBreak(1, 12);
        self::addSignatureDocRefLine($section, $context);
        $table = $section->addTable(['unit' => TblWidth::PERCENT, 'width' => 5000]);

        $table->addRow();
        $table->addCell(2500)->addText('For' . "\n" . (string) ($company['legal_name'] ?? ''), ['bold' => true, 'color' => self::NAVY, 'size' => 10]);
        $table->addCell(2500)->addText('Authorised Signatory', ['bold' => true, 'color' => self::MUTED, 'size' => 10]);

        $table->addRow();
        $sealCell = $table->addCell(2500, ['valign' => 'bottom']);
        $sealUri = $signatory['seal_data_uri'] ?? null;
        if ($sealUri) {
            $sealPath = self::decodeDataUriToTempFile($sealUri);
            if ($sealPath) {
                [$w, $h] = self::imagePointSize($sealPath, 85);
                $sealCell->addImage($sealPath, ['width' => $w, 'height' => $h, 'ratio' => true]);
            }
        }

        $sigCell = $table->addCell(2500, ['valign' => 'bottom']);
        $sigUri = $signatory['signature_data_uri'] ?? null;
        if ($sigUri) {
            $sigPath = self::decodeDataUriToTempFile($sigUri);
            if ($sigPath) {
                [$w, $h] = self::imagePointSize($sigPath, 32);
                $sigCell->addImage($sigPath, ['width' => $w, 'height' => $h, 'ratio' => true]);
            }
        }
        $sigCell->addText((string) ($signatory['name'] ?? ''), ['bold' => true, 'size' => 13, 'color' => self::NAVY]);
        $sigCell->addText((string) ($signatory['designation'] ?? ''), ['bold' => true, 'size' => 10, 'color' => self::BLACK]);
        $sigCell->addText((string) ($company['legal_name'] ?? ''), ['size' => 9, 'color' => self::MUTED]);

        $showDisclaimer = ($company['show_generated_document_disclaimer'] ?? true) && ($sigUri || $sealUri);
        if ($showDisclaimer) {
            $text = $company['generated_document_disclaimer_text']
                ?: "This is a system-generated document. The signature and company seal shown are NexaCrest's authorised electronic signature and digital company seal, applied automatically by the order management system under internal document-authorisation controls.";
            $section->addText($text, ['italic' => true, 'size' => 7.5, 'color' => '888888'], ['spaceBefore' => 120]);
        }
    }

    // ------------------------------------------------------------------
    // Terms & conditions section (numbered clauses)
    // ------------------------------------------------------------------

    public static function addTermsSection(Section $section, array $context, int $number, string $title): void
    {
        $terms = $context['terms'] ?? [];
        if (empty($terms)) {
            return;
        }
        self::addSectionTitle($section, "{$number}. {$title}");
        if (!empty($context['order']['include_annexure_a']) && !empty($context['annexure_has_content'])) {
            self::addColorBox($section, self::RED_BG, self::RED_BORDER, function (AbstractContainer $cell) use ($context) {
                $docTitle = strtolower((string) ($context['doc_title'] ?? 'document'));
                $letter = ($context['annexure_letter'] ?? 'A') === 'B' ? 'B' : 'A'; // D-336
                $cell->addText(
                    "\u{26A0} Annexure {$letter} is attached and forms an integral part of this {$docTitle}. Refer Annexure {$letter} for full details.",
                    ['bold' => true, 'size' => 10, 'color' => self::RED_TEXT]
                );
            });
        }
        foreach ($terms as $clause) {
            self::addMarkdownLiteListItem($section, (string) $clause, ['size' => 9.5], ['spaceAfter' => 60]);
        }
        $section->addTextBreak(1, 4);
    }

    /**
     * 90-item backlog Phase 5: a numbered-clause list item whose
     * bold/italic/underline markdown markers (App\Helpers\MarkdownLite)
     * render as real DOCX run formatting instead of literal asterisks —
     * the exact same tokenize() call the markdown_lite Twig filter uses
     * for the PDF side, so the two renderers can never disagree about
     * which part of a clause is bold/italic/underlined. $baseStyle is
     * merged into every run (size/color etc.); bold/italic/underline on
     * it are always overridden per-segment.
     */
    /**
     * Owner decision D-343 (12 Nov 2026): the "LEGAL TERMS & DEFINITIONS" section the PDF prints
     * (templates/_layout.html.twig, block legal_terms_definitions) — same number, heading, boxes and
     * clauses — so the Word copy's sections match the PDF's. Nothing when the PDF has no such section.
     */
    public static function addLegalTermsSection(Section $section, array $context): void
    {
        $number = $context['legal_terms_section_number'] ?? null;
        if ($number === null || $number === '' || $number === 0) {
            return;
        }
        self::addSectionTitle($section, $number . '.  LEGAL TERMS & DEFINITIONS');
        $boxes = [
            [$context['legal_terms_clauses'] ?? [], 'FFF0F0', 'C0392B', 'LEGAL TERMS', 'These terms govern all commercial obligations under this document.'],
            [$context['definition_clauses'] ?? [], 'F0F4FF', '1A5276', 'DEFINITIONS', 'The following terms have the meanings given below wherever they appear in this document.'],
        ];
        foreach ($boxes as [$clauses, $bg, $border, $header, $intro]) {
            if (empty($clauses)) {
                continue;
            }
            self::addColorBox($section, $bg, $border, function (AbstractContainer $cell) use ($clauses, $header, $intro): void {
                $cell->addText($header, ['bold' => true, 'size' => 10, 'color' => self::NAVY], self::KEEP_NEXT);
                $cell->addText($intro, ['italic' => true, 'size' => 8.5, 'color' => self::MUTED], self::KEEP_NEXT);
                foreach ($clauses as $clause) {
                    $run = $cell->addTextRun(['spaceAfter' => 60]);
                    $run->addText("\u{2022} ", ['size' => 9]);
                    $run->addText((string) ($clause['title'] ?? '') . ':', ['bold' => true, 'size' => 9, 'color' => self::NAVY]);
                    $run->addText(' ', ['size' => 9]);
                    foreach (MarkdownLite::tokenize((string) ($clause['text'] ?? '')) as $segment) {
                        $style = ['size' => 9, 'bold' => $segment['bold'], 'italic' => $segment['italic']];
                        if ($segment['underline']) {
                            $style['underline'] = 'single';
                        }
                        $run->addText($segment['text'], $style);
                    }
                }
            }, 4);
        }
    }

    public static function addMarkdownLiteListItem(AbstractContainer $container, string $text, array $baseStyle = [], ?array $paragraphStyle = null): void
    {
        $run = $container->addListItemRun(0, null, $paragraphStyle);
        foreach (MarkdownLite::tokenize($text) as $segment) {
            $style = $baseStyle;
            $style['bold'] = $segment['bold'];
            $style['italic'] = $segment['italic'];
            if ($segment['underline']) {
                $style['underline'] = 'single';
            }
            $run->addText($segment['text'], $style);
        }
    }

    /**
     * Batch 3 #12 — a client-level custom clause (clients.agreement_footer_text),
     * separate from the standard numbered T&C list since it's agreed with this
     * one buyer specifically. Deliberately a standalone call (not folded into
     * addTermsSection above), so it still prints even for a document type with
     * no numbered T&C clauses seeded at all (e.g. CI), which early-returns
     * out of addTermsSection before reaching this point.
     */
    public static function addClientAgreementFooter(Section $section, array $context): void
    {
        $footerText = trim((string) ($context['buyer']['agreement_footer_text'] ?? ''));
        if ($footerText === '') {
            return;
        }
        self::addColorBox($section, self::SPEC_BG, self::BORDER_GRAY, function (AbstractContainer $cell) use ($footerText) {
            $cell->addText('Special Terms (per Client Agreement)', ['bold' => true, 'size' => 9.5, 'color' => self::NAVY]);
            foreach (preg_split('/\r\n|\r|\n/', $footerText) as $line) {
                $cell->addText($line, ['size' => 9.5, 'color' => self::NAVY]);
            }
        }, 4);
    }

    // ------------------------------------------------------------------
    // Watermark: a rotated, semi-transparent diagonal PNG rendered with
    // GD (reliable across LibreOffice/Word, unlike relying on PHPWord's
    // WordprocessingML text-rotation quirks), attached to the section
    // header so it repeats on every page.
    // ------------------------------------------------------------------

    public static function addWatermark(Section $section, array $watermark): void
    {
        if (empty($watermark['enabled']) || empty($watermark['text'])) {
            return;
        }
        $path = self::renderWatermarkPng(
            (string) $watermark['text'],
            (float) ($watermark['font_size'] ?? 60),
            (string) ($watermark['color'] ?? '#C0392B'),
            (float) ($watermark['opacity'] ?? 0.15),
            (float) ($watermark['angle'] ?? 45)
        );
        if (!$path) {
            return;
        }
        $header = $section->addHeader();
        [$w, $h] = self::pixelSizeToPoints($path);
        $header->addWatermark($path, ['width' => $w, 'height' => $h, 'marginTop' => 300, 'marginLeft' => 80]);
    }

    private static function renderWatermarkPng(string $text, float $fontSizePx, string $hexColor, float $opacity, float $angleDeg): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $fontFile = dirname(__DIR__, 3) . '/assets/fonts/Carlito-Bold.ttf';
        if (!is_file($fontFile)) {
            return null;
        }
        // Convert the CSS px font-size to a GD/TTF point size (~0.75 factor)
        // and cap it — a huge watermark canvas produces a huge, slow-to-render
        // image for no visual benefit at document scale.
        $fontSize = max(10, min(54, $fontSizePx * 0.6));
        $angle = $angleDeg;

        $box = imagettfbbox($fontSize, $angle, $fontFile, $text);
        $xs = [$box[0], $box[2], $box[4], $box[6]];
        $ys = [$box[1], $box[3], $box[5], $box[7]];
        $width = (int) (max($xs) - min($xs)) + 20;
        $height = (int) (max($ys) - min($ys)) + 20;
        $originX = -min($xs) + 10;
        $originY = -min($ys) + 10;

        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        [$r, $g, $b] = self::hexToRgb($hexColor);
        $alpha = (int) round((1 - max(0.0, min(1.0, $opacity))) * 127);
        $color = imagecolorallocatealpha($image, $r, $g, $b, $alpha);

        imagettftext($image, $fontSize, $angle, (int) $originX, (int) $originY, $color, $fontFile, $text);

        $tmpPath = tempnam(sys_get_temp_dir(), 'docx_watermark_') . '.png';
        imagepng($image, $tmpPath);
        imagedestroy($image);
        self::$tempFiles[] = $tmpPath;

        return $tmpPath;
    }

    private static function pixelSizeToPoints(string $pngPath): array
    {
        $info = @getimagesize($pngPath);
        if (!$info) {
            return [300, 150];
        }
        // Treat the PNG's pixel dimensions as points 1:1 (72 dpi) — simple
        // and produces a watermark of a sensible, page-appropriate size.
        return [(int) $info[0], (int) $info[1]];
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return [192, 57, 43];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    // ------------------------------------------------------------------
    // Image helpers
    // ------------------------------------------------------------------

    /** Decodes a `data:image/...;base64,...` URI to a temp file PHPWord can addImage() from. */
    public static function decodeDataUriToTempFile(?string $dataUri): ?string
    {
        if (!$dataUri || !str_starts_with($dataUri, 'data:')) {
            return null;
        }
        $comma = strpos($dataUri, ',');
        if ($comma === false) {
            return null;
        }
        $meta = substr($dataUri, 5, $comma - 5); // e.g. "image/png;base64"
        $data = base64_decode(substr($dataUri, $comma + 1), true);
        if ($data === false) {
            return null;
        }
        $ext = 'png';
        if (str_contains($meta, 'jpeg') || str_contains($meta, 'jpg')) {
            $ext = 'jpg';
        } elseif (str_contains($meta, 'gif')) {
            $ext = 'gif';
        }
        $path = tempnam(sys_get_temp_dir(), 'docx_img_') . '.' . $ext;
        file_put_contents($path, $data);
        self::$tempFiles[] = $path;

        return $path;
    }

    /** Returns [widthPt, heightPt] preserving aspect ratio for a target height in points. */
    private static function imagePointSize(string $path, float $targetHeightPt): array
    {
        $info = @getimagesize($path);
        if (!$info || (int) $info[1] === 0) {
            return [$targetHeightPt, $targetHeightPt];
        }
        $ratio = (int) $info[0] / (int) $info[1];

        return [round($targetHeightPt * $ratio, 1), $targetHeightPt];
    }

    /** Call once after a document has been saved, to remove temp image/watermark files created for it. */
    public static function cleanupTempFiles(): void
    {
        foreach (self::$tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        self::$tempFiles = [];
    }
}
