<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/** Mirrors PasswordResetTokenRepository exactly, scoped to clients instead of staff users. */
final class ClientPasswordResetTokenRepository
{
    public static function create(int $clientId, string $tokenHash, string $expiresAt, ?string $requestedIp): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO client_password_reset_tokens (client_id, token_hash, requested_ip, expires_at)
             VALUES (:client_id, :token_hash, :ip, :expires_at)'
        );
        $stmt->execute(['client_id' => $clientId, 'token_hash' => $tokenHash, 'ip' => $requestedIp, 'expires_at' => $expiresAt]);
        return (int) $pdo->lastInsertId();
    }

    public static function findValidByHash(string $tokenHash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT cprt.*, c.email AS client_email, c.company_legal_name, c.is_active AS client_is_active
             FROM client_password_reset_tokens cprt
             JOIN clients c ON c.id = cprt.client_id
             WHERE cprt.token_hash = :hash AND cprt.used_at IS NULL AND cprt.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        if (!$row || !$row['client_is_active']) {
            return null;
        }
        return $row;
    }

    public static function countRecentForClient(int $clientId, int $withinMinutes): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM client_password_reset_tokens
             WHERE client_id = :client_id AND created_at > (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->execute(['client_id' => $clientId, 'minutes' => $withinMinutes]);
        return (int) $stmt->fetchColumn();
    }

    public static function markUsed(int $id): void
    {
        Database::connection()->prepare('UPDATE client_password_reset_tokens SET used_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    public static function invalidateAllForClient(int $clientId): void
    {
        Database::connection()
            ->prepare('UPDATE client_password_reset_tokens SET used_at = NOW() WHERE client_id = :client_id AND used_at IS NULL')
            ->execute(['client_id' => $clientId]);
    }
}
