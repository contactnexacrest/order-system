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
    // D-245 P3 (4 Nov 2026): the per-rung rates are gone — commission levels and the ladder (Admin → Pricing) replace them.
    public const FIELDS = ['name', 'based_in', 'country', 'currency_code', 'email', 'phone', 'tax_id', 'agreement_signed_on',
        'status', 'notes',
        'no_commission', 'agreement_ends_on', 'relationship_manager_id', 'pan', 'gstin', 'address'];

    /** Owner decision 1 Nov 2026 (AS-1 … AS-17): the associate's life cycle. 'inactive' is kept for older rows. */
    public const STATUSES = ['invited' => 'Invited', 'active' => 'Active', 'suspended' => 'Suspended', 'ended' => 'Agreement ended', 'inactive' => 'Inactive'];

    /** @return list<array<string,mixed>> with the number of clients each introduced */
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT a.*, (SELECT COUNT(*) FROM clients c WHERE c.associate_id = a.id) AS client_count, f.original_filename AS agreement_file_name,
                    cl.name AS commission_level_name
             FROM associates a LEFT JOIN file_store f ON f.id = a.agreement_file_id LEFT JOIN commission_levels cl ON cl.id = a.commission_level_id ORDER BY a.status, a.name'
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
            ->execute(self::params($data) + ['created_by' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $sets = implode(', ', array_map(static fn (string $f): string => "{$f} = :{$f}", self::FIELDS));
        Database::connection()->prepare("UPDATE associates SET {$sets} WHERE id = :id")
            ->execute(self::params($data) + ['id' => $id]);
    }

    /** Every field, missing ones empty (no_commission off, status invited). */
    private static function params(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = $data[$f] ?? match ($f) { 'no_commission' => 0, 'status' => 'invited', default => null };
        }
        return $out;
    }

    /** The signed agreement (owner decision 2026-10-30): a file_store row, replaced by a newer upload. */
    public static function setAgreementFile(int $id, int $fileId): void
    {
        Database::connection()->prepare('UPDATE associates SET agreement_file_id = :f WHERE id = :id')->execute(['f' => $fileId, 'id' => $id]);
    }

    /**
     * Whether the file belongs to an associate — their signed agreement, a
     * KYC / tax / invoice document, or a bank proof (owner decision 1 Nov
     * 2026). The general file link refuses it; it is served only on the
     * associate's own pages.
     */
    public static function isAgreementFile(int $fileId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM associates WHERE agreement_file_id = :f
             UNION SELECT 1 FROM associate_documents WHERE file_id = :f2
             UNION SELECT 1 FROM associate_bank_accounts WHERE proof_file_id = :f3 LIMIT 1'
        );
        $stmt->execute(['f' => $fileId, 'f2' => $fileId, 'f3' => $fileId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM associates WHERE LOWER(email) = LOWER(:e)');
        $stmt->execute(['e' => trim($email)]);
        return $stmt->fetch() ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM associates WHERE personal_code = :c');
        $stmt->execute(['c' => strtoupper(trim($code))]);
        return $stmt->fetch() ?: null;
    }

    public static function setFields(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $f): string => "{$f} = :{$f}", array_keys($fields)));
        Database::connection()->prepare("UPDATE associates SET {$sets} WHERE id = :id")->execute($fields + ['id' => $id]);
    }

    /** @return array<string,int> associates per status, for the board and the filter row */
    public static function statusCounts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (Database::connection()->query('SELECT status, COUNT(*) AS n FROM associates GROUP BY status')->fetchAll() as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> the clients the associate introduced */
    public static function clients(int $associateId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, company_legal_name, client_unique_number, country_of_destination, associate_registered_on, associate_locked_until, associate_source FROM clients WHERE associate_id = :id ORDER BY associate_registered_on, company_legal_name'
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
}
