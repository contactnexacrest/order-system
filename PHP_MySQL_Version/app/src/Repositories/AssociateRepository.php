<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Associates (owner decision 2026-10-30): outside people, in India or
 * abroad, who introduce new clients under a signed agreement and earn its
 * rate ladder on those clients' first orders. A client is linked to the
 * associate who introduced it (clients.associate_id), with the date the
 * lead was registered.
 */
final class AssociateRepository
{
    /** The agreement's ladder: [upper bound of the associate's order count => rate column]. */
    public const TIERS = [5 => 'rate_1_5', 10 => 'rate_6_10', 20 => 'rate_11_20', 40 => 'rate_21_40', PHP_INT_MAX => 'rate_41_plus'];

    public const FIELDS = ['name', 'based_in', 'country', 'currency_code', 'email', 'phone', 'tax_id', 'agreement_signed_on',
        'rate_1_5', 'rate_6_10', 'rate_11_20', 'rate_21_40', 'rate_41_plus', 'status', 'notes'];

    /** @return list<array<string,mixed>> with the number of clients each introduced */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT a.*, (SELECT COUNT(*) FROM clients c WHERE c.associate_id = a.id) AS client_count, f.original_filename AS agreement_file_name
             FROM associates a LEFT JOIN file_store f ON f.id = a.agreement_file_id ORDER BY a.status, a.name'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM associates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data, int $userId): int
    {
        $pdo = Database::connection();
        $cols = implode(', ', self::FIELDS);
        $marks = implode(', ', array_map(static fn (string $f): string => ':' . $f, self::FIELDS));
        $pdo->prepare("INSERT INTO associates ({$cols}, created_by) VALUES ({$marks}, :created_by)")
            ->execute(array_intersect_key($data, array_flip(self::FIELDS)) + ['created_by' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $sets = implode(', ', array_map(static fn (string $f): string => "{$f} = :{$f}", self::FIELDS));
        Database::connection()->prepare("UPDATE associates SET {$sets} WHERE id = :id")
            ->execute(array_intersect_key($data, array_flip(self::FIELDS)) + ['id' => $id]);
    }

    /** The signed agreement (owner decision 2026-10-30): a file_store row, replaced by a newer upload. */
    public static function setAgreementFile(int $id, int $fileId): void
    {
        Database::connection()->prepare('UPDATE associates SET agreement_file_id = :f WHERE id = :id')->execute(['f' => $fileId, 'id' => $id]);
    }

    /** Whether the file is an associate's signed agreement (manage_incentives only, wherever it is served). */
    public static function isAgreementFile(int $fileId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM associates WHERE agreement_file_id = :f LIMIT 1');
        $stmt->execute(['f' => $fileId]);
        return $stmt->fetchColumn() !== false;
    }

    /** The rate (%) for the associate's Nth commission order. */
    public static function rateFor(array $associate, int $orderNumber): float
    {
        foreach (self::TIERS as $upTo => $column) {
            if ($orderNumber <= $upTo) {
                return (float) $associate[$column];
            }
        }
        return (float) $associate['rate_41_plus'];
    }

    /** @return list<array<string,mixed>> the clients the associate introduced */
    public static function clients(int $associateId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, company_legal_name, client_unique_number, associate_registered_on FROM clients WHERE associate_id = :id ORDER BY associate_registered_on, company_legal_name'
        );
        $stmt->execute(['id' => $associateId]);
        return $stmt->fetchAll();
    }

    public static function linkClient(int $clientId, ?int $associateId, ?string $registeredOn): void
    {
        Database::connection()->prepare('UPDATE clients SET associate_id = :a, associate_registered_on = :d WHERE id = :id')
            ->execute(['a' => $associateId, 'd' => $associateId === null ? null : $registeredOn, 'id' => $clientId]);
    }

    /** @return list<array<string,mixed>> clients not yet linked to any associate, for the link picker */
    public static function unlinkedClients(): array
    {
        return Database::connection()->query(
            'SELECT id, company_legal_name, client_unique_number FROM clients WHERE associate_id IS NULL AND is_test_data = 0 ORDER BY company_legal_name'
        )->fetchAll();
    }

    /**
     * The associate's commission order number for this client: the same
     * number for every order of the client's first 10 days (one "order"
     * under the agreement), otherwise one more than the clients already
     * counted for this associate.
     */
    public static function orderNumberForClient(int $associateId, int $clientId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT oi.associate_order_number FROM order_incentives oi JOIN orders o ON o.id = oi.order_id
             WHERE oi.associate_id = :a AND o.client_id = :c AND oi.associate_order_number IS NOT NULL LIMIT 1'
        );
        $stmt->execute(['a' => $associateId, 'c' => $clientId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT o.client_id) FROM order_incentives oi JOIN orders o ON o.id = oi.order_id
             WHERE oi.associate_id = :a AND oi.associate_order_number IS NOT NULL'
        );
        $stmt->execute(['a' => $associateId]);
        return (int) $stmt->fetchColumn() + 1;
    }
}
