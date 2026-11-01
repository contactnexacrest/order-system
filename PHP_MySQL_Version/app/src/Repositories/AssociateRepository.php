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
        'rate_1_5', 'rate_6_10', 'rate_11_20', 'rate_21_40', 'rate_41_plus', 'status', 'notes',
        'no_commission', 'agreement_ends_on', 'relationship_manager_id', 'pan', 'gstin', 'address'];

    /** Owner decision 1 Nov 2026 (AS-1 … AS-17): the associate's life cycle. 'inactive' is kept for older rows. */
    public const STATUSES = ['invited' => 'Invited', 'active' => 'Active', 'suspended' => 'Suspended', 'ended' => 'Agreement ended', 'inactive' => 'Inactive'];

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

    /**
     * The rate (%) for the associate's Nth client — owner decision 1 Nov 2026
     * (AS-17): the associate's personal rate for that rung when it is set
     * (above 0), else the global ladder; none at all when the associate is
     * marked "no commission".
     */
    public static function rateFor(array $associate, int $orderNumber): float
    {
        return self::rateWithSource($associate, $orderNumber)['rate'];
    }

    /** @return array{rate: float, source: string} source 'personal' | 'global' | 'none' */
    public static function rateWithSource(array $associate, int $orderNumber): array
    {
        if ((int) ($associate['no_commission'] ?? 0) === 1) {
            return ['rate' => 0.0, 'source' => 'none'];
        }
        $column = 'rate_41_plus';
        foreach (self::TIERS as $upTo => $col) {
            if ($orderNumber <= $upTo) {
                $column = $col;
                break;
            }
        }
        $personal = $associate[$column] ?? null;
        if ($personal !== null && $personal !== '' && (float) $personal > 0) {
            return ['rate' => (float) $personal, 'source' => 'personal'];
        }
        return ['rate' => (float) (self::globalLadder()[$column] ?? 0), 'source' => 'global'];
    }

    /** @return array<string, float> the global ladder, rung column => rate (owner decision 1 Nov 2026, AS-17) */
    public static function globalLadder(): array
    {
        $settings = IncentiveRepository::settings();
        $defaults = ['rate_1_5' => 0.70, 'rate_6_10' => 1.00, 'rate_11_20' => 1.50, 'rate_21_40' => 1.75, 'rate_41_plus' => 2.00];
        $out = [];
        foreach ($defaults as $column => $default) {
            $v = $settings['associate_' . $column] ?? '';
            $out[$column] = $v !== '' ? (float) $v : $default;
        }
        return $out;
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
