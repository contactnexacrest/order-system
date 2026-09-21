<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class SupplierRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_legal_name')
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM suppliers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (supplier_legal_name, address, gstin, pan, contact_person, phone, supplier_type)
             VALUES (:name, :address, :gstin, :pan, :contact_person, :phone, :supplier_type)'
        );
        $stmt->execute([
            'name'           => $data['supplier_legal_name'],
            'address'        => $data['address'] ?? null,
            'gstin'          => $data['gstin'] ?? null,
            'pan'            => $data['pan'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'phone'          => $data['phone'] ?? null,
            'supplier_type'  => $data['supplier_type'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Phase E follow-up — flags a supplier as Sample Data Playground content (see SampleDataService). */
    public static function markSample(int $id): void
    {
        Database::connection()->prepare('UPDATE suppliers SET is_sample_data = 1 WHERE id = :id')->execute(['id' => $id]);
    }
}
