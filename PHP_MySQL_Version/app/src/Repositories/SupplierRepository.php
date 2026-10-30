<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Suppliers directory (docs/schema.sql Section BZ). A supplier can hold
 * several types (supplier_type_links), each the text of a
 * dropdown_options('supplier_type') option. Orders never read this table
 * for their own content — order_supplier_po snapshots the supplier's
 * details when the Supplier PO terms are saved.
 */
final class SupplierRepository
{
    /** QA Round 6, R6-N27: the columns the supplier edit form saves (its edit fingerprint). */
    public const EDIT_FIELDS = [
        'supplier_legal_name', 'address', 'city', 'state', 'gstin', 'pan', 'contact_person', 'contact_person_phone',
        'contact_person_whatsapp', 'phone', 'email', 'whatsapp_number', 'notes', 'bank_account_name', 'bank_name',
        'bank_branch', 'bank_account_number', 'bank_ifsc', 'bank_account_type', 'bank_upi_id',
    ];

    /** Directory columns written by create()/update(), besides the name. */
    private const FIELDS = [
        'address', 'city', 'state', 'gstin', 'pan', 'contact_person', 'contact_person_phone',
        'contact_person_whatsapp', 'phone', 'email', 'whatsapp_number', 'notes',
    ];

    /**
     * @return array<int, array<string,mixed>> ordered by name, each row with a `types` list.
     *   $type filters to suppliers holding that type; $search matches the name.
     */
    public static function all(bool $includeInactive = false, ?string $type = null, ?string $search = null): array
    {
        $where = [];
        $params = [];
        if (!$includeInactive) {
            $where[] = 's.is_active = 1';
        }
        if ($type !== null && $type !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM supplier_type_links f WHERE f.supplier_id = s.id AND f.supplier_type = :type)';
            $params['type'] = $type;
        }
        if ($search !== null && $search !== '') {
            $where[] = 's.supplier_legal_name LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        $sql = 'SELECT s.* FROM suppliers s';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.supplier_legal_name';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return self::withTypes($stmt->fetchAll());
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM suppliers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? self::withTypes([$row])[0] : null;
    }

    /** @param array<string,mixed> $data includes `types` (list of type texts) */
    public static function create(array $data, ?int $createdBy = null): int
    {
        $pdo = Database::connection();
        $columns = array_merge(['supplier_legal_name'], self::FIELDS, ['created_by']);
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (' . implode(', ', $columns) . ')
             VALUES (:' . implode(', :', $columns) . ')'
        );
        $stmt->execute(self::bindData($data) + ['created_by' => $createdBy]);
        $id = (int) $pdo->lastInsertId();
        self::setTypes($id, $data['types'] ?? []);
        return $id;
    }

    /** @param array<string,mixed> $data includes `types` (list of type texts) */
    public static function update(int $id, array $data): void
    {
        $sets = array_map(static fn(string $c): string => "{$c} = :{$c}", array_merge(['supplier_legal_name'], self::FIELDS));
        Database::connection()->prepare('UPDATE suppliers SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute(self::bindData($data) + ['id' => $id]);
        self::setTypes($id, $data['types'] ?? []);
    }

    /** @param array<int,string> $types */
    public static function setTypes(int $id, array $types): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM supplier_type_links WHERE supplier_id = :id')->execute(['id' => $id]);
        $insert = $pdo->prepare('INSERT IGNORE INTO supplier_type_links (supplier_id, supplier_type) VALUES (:id, :type)');
        foreach ($types as $type) {
            $type = trim((string) $type);
            if ($type !== '') {
                $insert->execute(['id' => $id, 'type' => $type]);
            }
        }
    }

    public static function toggleActive(int $id): void
    {
        Database::connection()->prepare('UPDATE suppliers SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $id]);
    }

    /** True once any order has referenced this supplier — such a supplier can only be deactivated, never deleted. */
    public static function isUsedOnAnOrder(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT (SELECT COUNT(*) FROM order_supplier_po WHERE supplier_id = :a)
                  + (SELECT COUNT(*) FROM order_production WHERE supplier_id = :b) AS c'
        );
        $stmt->execute(['a' => $id, 'b' => $id]);
        return (int) $stmt->fetch()['c'] > 0;
    }

    /** @return array<int,true> ids of every supplier any order has referenced, as a lookup set */
    public static function idsUsedOnOrders(): array
    {
        $rows = Database::connection()->query(
            'SELECT supplier_id FROM order_supplier_po
             UNION SELECT supplier_id FROM order_production WHERE supplier_id IS NOT NULL'
        )->fetchAll();
        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['supplier_id']] = true;
        }
        return $ids;
    }

    /** Deletes a never-used supplier along with its factory addresses (its types go by ON DELETE CASCADE). */
    public static function delete(int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM supplier_factory_addresses WHERE supplier_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $id]);
    }

    /** Phase E follow-up — flags a supplier as Sample Data Playground content (see SampleDataService). */
    public static function markSample(int $id): void
    {
        Database::connection()->prepare('UPDATE suppliers SET is_sample_data = 1 WHERE id = :id')->execute(['id' => $id]);
    }

    /** Test Mode (docs/schema.sql Section V) — mirrors markSample()'s pattern. */
    public static function markTest(int $id): void
    {
        Database::connection()->prepare('UPDATE suppliers SET is_test_data = 1 WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array<string,mixed> */
    private static function bindData(array $data): array
    {
        $bound = ['supplier_legal_name' => $data['supplier_legal_name']];
        foreach (self::FIELDS as $field) {
            $bound[$field] = $data[$field] ?? null;
        }
        return $bound;
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array<int, array<string,mixed>> the same rows, each with `types` (sorted list of type texts)
     */
    private static function withTypes(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT supplier_id, supplier_type FROM supplier_type_links WHERE supplier_id IN ({$placeholders}) ORDER BY supplier_type"
        );
        $stmt->execute($ids);
        $typesById = [];
        foreach ($stmt->fetchAll() as $link) {
            $typesById[(int) $link['supplier_id']][] = $link['supplier_type'];
        }
        foreach ($rows as &$row) {
            $row['types'] = $typesById[(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }
}
