<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Self-service "forgot password" (Phase E follow-up, Section 14). Only the
 * SHA-256 hash of the raw token is ever stored — the raw token exists only
 * in the emailed link and this request's memory, mirroring how
 * password_hash never stores a real password. A single-use, short-lived
 * row per request rather than a reusable secret on the user row.
 */
final class PasswordResetTokenRepository
{
    public static function create(int $userId, string $tokenHash, string $expiresAt, ?string $requestedIp): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, requested_ip, expires_at)
             VALUES (:user_id, :token_hash, :ip, :expires_at)'
        );
        $stmt->execute([
            'user_id'     => $userId,
            'token_hash'  => $tokenHash,
            'ip'          => $requestedIp,
            'expires_at'  => $expiresAt,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Returns the token row joined with the user, only if it's unexpired,
     * unused, and the user is still active — a deactivated account's
     * outstanding reset links stop working the moment they're deactivated,
     * with no separate cleanup step needed.
     */
    public static function findValidByHash(string $tokenHash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT prt.*, u.email AS user_email, u.name AS user_name, u.is_active AS user_is_active
             FROM password_reset_tokens prt
             JOIN users u ON u.id = prt.user_id
             WHERE prt.token_hash = :hash
               AND prt.used_at IS NULL
               AND prt.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        if (!$row || !$row['user_is_active']) {
            return null;
        }
        return $row;
    }

    /**
     * A crude but effective abuse guard: this is a public, unauthenticated
     * endpoint that triggers an outbound email, so it's a mail-bombing
     * vector against anyone whose email you know, not just an
     * inconvenience. Caps requests per user rather than per IP, since a
     * shared office/VPN IP shouldn't lock out a legitimate user.
     */
    public static function countRecentForUser(int $userId, int $withinMinutes): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM password_reset_tokens
             WHERE user_id = :user_id AND created_at > (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->execute(['user_id' => $userId, 'minutes' => $withinMinutes]);
        return (int) $stmt->fetchColumn();
    }

    public static function markUsed(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id'
        )->execute(['id' => $id]);
    }

    /**
     * Invalidates every other outstanding, unused token for this user —
     * called once a reset actually completes, so an old emailed link a
     * user forgot about can't still be sitting there valid afterward.
     */
    public static function invalidateAllForUser(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL'
        )->execute(['user_id' => $userId]);
    }
}
