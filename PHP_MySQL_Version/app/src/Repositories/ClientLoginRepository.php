<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * client_logins — one row per client, created ONLY by
 * ClientPortalService::provisionIfNeeded() from the Stage 3 advance-cleared
 * gate. No row = that client cannot log in, full stop.
 */
final class ClientLoginRepository
{
    public static function findByClientId(int $clientId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM client_logins WHERE client_id = :cid');
        $stmt->execute(['cid' => $clientId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Joins clients so login can look up by the client's own email in one
     * query — the client portal has no separate login-identifier column.
     */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT cl.*, c.email AS client_email, c.company_legal_name, c.client_unique_number, c.is_active AS client_is_active
             FROM client_logins cl
             JOIN clients c ON c.id = cl.client_id
             WHERE c.email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT cl.*, c.email AS client_email, c.company_legal_name, c.client_unique_number, c.is_active AS client_is_active
             FROM client_logins cl JOIN clients c ON c.id = cl.client_id
             WHERE cl.client_id = :cid'
        );
        $stmt->execute(['cid' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $clientId, string $passwordHash, ?int $createdByOrderId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO client_logins (client_id, password_hash, created_by_order_id) VALUES (:cid, :hash, :order_id)'
        );
        $stmt->execute(['cid' => $clientId, 'hash' => $passwordHash, 'order_id' => $createdByOrderId]);
        return (int) $pdo->lastInsertId();
    }

    public static function incrementFailedLogins(int $clientId): void
    {
        Database::connection()
            ->prepare('UPDATE client_logins SET failed_login_count = failed_login_count + 1 WHERE client_id = :cid')
            ->execute(['cid' => $clientId]);
    }

    public static function resetFailedLogins(int $clientId): void
    {
        Database::connection()
            ->prepare('UPDATE client_logins SET failed_login_count = 0, locked_until = NULL WHERE client_id = :cid')
            ->execute(['cid' => $clientId]);
    }

    public static function lockUntil(int $clientId, string $until): void
    {
        Database::connection()
            ->prepare('UPDATE client_logins SET locked_until = :until WHERE client_id = :cid')
            ->execute(['until' => $until, 'cid' => $clientId]);
    }

    public static function updateLastLogin(int $clientId): void
    {
        Database::connection()
            ->prepare('UPDATE client_logins SET last_login_at = NOW() WHERE client_id = :cid')
            ->execute(['cid' => $clientId]);
    }

    public static function updatePassword(int $clientId, string $passwordHash, bool $forcePasswordChange): void
    {
        Database::connection()->prepare(
            'UPDATE client_logins SET password_hash = :hash, force_password_change = :force, password_changed_at = NOW() WHERE client_id = :cid'
        )->execute(['hash' => $passwordHash, 'force' => $forcePasswordChange ? 1 : 0, 'cid' => $clientId]);
    }
}
