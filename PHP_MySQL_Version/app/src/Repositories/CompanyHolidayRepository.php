<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class CompanyHolidayRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT * FROM company_holidays ORDER BY holiday_date ASC')
            ->fetchAll();
    }

    /**
     * Every holiday_date between $from and $to inclusive, as a flat set of
     * 'Y-m-d' strings — the shape WorkingDaysCalculator needs for a fast
     * in_array() lookup per candidate day, rather than one query per day.
     *
     * @return array<int, string>
     */
    public static function datesBetween(string $from, string $to): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT holiday_date FROM company_holidays WHERE holiday_date BETWEEN :from AND :to'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        return array_map(
            static fn(array $row): string => $row['holiday_date'],
            $stmt->fetchAll()
        );
    }

    public static function create(string $holidayDate, string $description, ?int $createdBy): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO company_holidays (holiday_date, description, created_by) VALUES (:holiday_date, :description, :created_by)'
        );
        $stmt->execute(['holiday_date' => $holidayDate, 'description' => $description, 'created_by' => $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM company_holidays WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function update(int $id, string $holidayDate, string $description): void
    {
        Database::connection()
            ->prepare('UPDATE company_holidays SET holiday_date = :holiday_date, description = :description WHERE id = :id')
            ->execute(['holiday_date' => $holidayDate, 'description' => $description, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM company_holidays WHERE id = :id')->execute(['id' => $id]);
    }
}
