<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Point 4 — the "which code do I actually use for this product" half of
 * the business's HS Code Quick Reference workbook. Deliberately its own
 * table rather than a column on hs_codes: it's keyed by product name, not
 * by code, and a single product row can legitimately point at more than
 * one candidate code (resolving it to one exact code needs a human to
 * look at the real SKU) — read-only reference material, most useful for
 * a fresher who has never had to classify a product before.
 */
final class HsCodeProductGuideRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()->query('SELECT * FROM hs_code_product_examples ORDER BY sort_order, id')->fetchAll();
    }

    /** @return array{inserted:int, skipped:string[]} */
    public static function bulkImport(string $rawText, int $createdBy): array
    {
        $pdo = Database::connection();
        $maxSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM hs_code_product_examples')->fetchColumn();
        $inserted = 0;
        $skipped = [];

        foreach (preg_split('/\r\n|\r|\n/', $rawText) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t/', $line);
            if (count($parts) < 2) {
                $parts = preg_split('/\|/', $line);
            }
            if (count($parts) < 2) {
                $skipped[] = "\"{$line}\" — expected Product, Code, and an optional Note, separated by Tabs or |.";
                continue;
            }

            $product = trim($parts[0]);
            $code = trim($parts[1]);
            $note = isset($parts[2]) ? trim($parts[2]) : '';

            if ($product === '' || $code === '') {
                $skipped[] = "\"{$line}\" — product and code are both required.";
                continue;
            }

            $maxSort++;
            $pdo->prepare(
                'INSERT INTO hs_code_product_examples (product_description, code_reference, note, sort_order, created_by)
                 VALUES (:product, :code, :note, :sort_order, :created_by)'
            )->execute([
                'product'    => $product,
                'code'       => $code,
                'note'       => $note !== '' ? $note : null,
                'sort_order' => $maxSort,
                'created_by' => $createdBy,
            ]);
            $inserted++;
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM hs_code_product_examples WHERE id = :id')->execute(['id' => $id]);
    }
}
