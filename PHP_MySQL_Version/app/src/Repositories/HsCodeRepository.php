<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class HsCodeRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()->query('SELECT * FROM hs_codes ORDER BY code')->fetchAll();
    }

    /** @return array<int, array<string,mixed>> active codes only — what the order-creation typeahead offers */
    public static function active(): array
    {
        return Database::connection()->query('SELECT code, description FROM hs_codes WHERE is_active = 1 ORDER BY code')->fetchAll();
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM hs_codes WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isActiveCode(string $code): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM hs_codes WHERE code = :code AND is_active = 1');
        $stmt->execute(['code' => $code]);
        return (bool) $stmt->fetch();
    }

    public static function create(string $code, string $description, int $createdBy): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO hs_codes (code, description, is_active, created_by) VALUES (:code, :description, 1, :created_by)'
        );
        $stmt->execute(['code' => $code, 'description' => $description, 'created_by' => $createdBy]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function updateDescription(int $id, string $description, ?string $usageNote = null): void
    {
        Database::connection()->prepare('UPDATE hs_codes SET description = :description, usage_note = :usage_note WHERE id = :id')
            ->execute(['description' => $description, 'usage_note' => $usageNote, 'id' => $id]);
    }

    /**
     * Point 4 — importing a real customs reference sheet one code at a
     * time was the actual gap; this takes a whole pasted block (Excel's
     * own tab-separated copy/paste format, or comma-separated when typed
     * by hand) and imports every valid line in one pass.
     *
     * @return array{inserted:string[], skipped:string[]}
     */
    public static function bulkImport(string $rawText, int $createdBy): array
    {
        $inserted = [];
        $skipped = [];

        foreach (preg_split('/\r\n|\r|\n/', $rawText) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t/', $line, 2);
            if (count($parts) < 2) {
                $parts = preg_split('/,/', $line, 2);
            }
            if (count($parts) < 2) {
                $skipped[] = "\"{$line}\" — separate the code and description with a Tab or a comma.";
                continue;
            }

            $code = trim($parts[0]);
            $description = trim($parts[1]);

            if (!preg_match('/^\d{6}$|^\d{8}$/', $code)) {
                $skipped[] = "\"{$code}\" — must be exactly 6 or 8 digits, no dots or other characters.";
                continue;
            }
            if ($description === '') {
                $skipped[] = "{$code} — description is blank.";
                continue;
            }
            if (self::findByCode($code)) {
                $skipped[] = "{$code} — already exists.";
                continue;
            }

            self::create($code, $description, $createdBy);
            $inserted[] = $code;
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    public static function toggleActive(int $id): void
    {
        Database::connection()->prepare('UPDATE hs_codes SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public static function usageCount(string $code): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM order_products WHERE hs_code = :code');
        $stmt->execute(['code' => $code]);
        return (int) $stmt->fetchColumn();
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM hs_codes WHERE id = :id')->execute(['id' => $id]);
    }
}
