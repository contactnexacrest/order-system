<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Spec Section 16 — "Export to CSV/Excel." CSV only (see README's Phase E
 * judgment-call note): it opens natively in Excel with correct commas/
 * quoting/UTF-8, so a second heavy dependency (PhpSpreadsheet, plus the
 * same sandbox git-clone dance every other vendor package needed) wasn't
 * worth it for a format Excel already reads perfectly.
 */
final class Csv
{
    /**
     * Streams a CSV directly to the response and exits — call this last,
     * after all validation, since it sends headers and terminates output.
     *
     * @param string[] $headers
     * @param array<int, array<int|string, mixed>> $rows each row either a
     *   plain indexed array (already in header order) or an associative
     *   array keyed the same as $headers
     */
    public static function stream(string $filename, array $headers, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens non-ASCII text correctly
        fputcsv($out, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            if (array_is_list($row)) {
                fputcsv($out, $row, ',', '"', '\\');
                continue;
            }
            $ordered = [];
            foreach ($headers as $h) {
                $ordered[] = $row[$h] ?? '';
            }
            fputcsv($out, $ordered, ',', '"', '\\');
        }
        fclose($out);
        exit;
    }
}
