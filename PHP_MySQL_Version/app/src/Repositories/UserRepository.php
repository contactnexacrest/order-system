<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

final class UserRepository
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function incrementFailedLogins(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE users SET failed_login_count = failed_login_count + 1 WHERE id = :id'
        )->execute(['id' => $userId]);
    }

    public static function resetFailedLogins(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = :id'
        )->execute(['id' => $userId]);
    }

    public static function lockUntil(int $userId, string $untilDateTime): void
    {
        Database::connection()->prepare(
            'UPDATE users SET locked_until = :until WHERE id = :id'
        )->execute(['until' => $untilDateTime, 'id' => $userId]);
    }

    public static function updateLastLogin(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE users SET last_login_at = NOW() WHERE id = :id'
        )->execute(['id' => $userId]);
    }

    public static function updatePassword(int $userId, string $passwordHash, bool $forceChange = false): void
    {
        Database::connection()->prepare(
            'UPDATE users SET password_hash = :hash, force_password_change = :force, password_changed_at = NOW() WHERE id = :id'
        )->execute([
            'hash'  => $passwordHash,
            'force' => $forceChange ? 1 : 0,
            'id'    => $userId,
        ]);
    }

    /**
     * Section 14 — password_expiry_days enforcement (SessionAuth). Flips
     * the same force_password_change flag a fresh/reset account uses, so
     * the existing gate/redirect and the "Force change" status an admin
     * sees elsewhere both pick this up with no separate expiry concept.
     */
    public static function flagPasswordExpired(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE users SET force_password_change = 1 WHERE id = :id'
        )->execute(['id' => $userId]);
    }

    public static function setTwoFactor(int $userId, bool $enabled, ?string $method, ?string $secret): void
    {
        Database::connection()->prepare(
            'UPDATE users SET two_fa_enabled = :enabled, two_fa_method = :method, two_fa_secret = :secret WHERE id = :id'
        )->execute([
            'enabled' => $enabled ? 1 : 0,
            'method'  => $method,
            'secret'  => $secret,
            'id'      => $userId,
        ]);
    }

    /**
     * @return array<int, array<string,mixed>> every active user, for the
     *         reviewer-assignment picker (Phase D). Deliberately doesn't
     *         filter by permission here — a document type's reviewer pool
     *         is "anyone Admin/MD chooses to assign", not restricted to a
     *         specific role; whether that person can actually see/act on
     *         it is enforced at review-action time by SessionAuth alone
     *         (only the assigned reviewer_id may act on their own row).
     */
    public static function listActive(): array
    {
        return Database::connection()->query(
            "SELECT u.id, u.name, u.email, r.name AS role_name
             FROM users u LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1
             ORDER BY u.name"
        )->fetchAll();
    }

    /**
     * Every user, active or not, for the admin user-management screen
     * (`manage_users`) — unlike listActive() above, which deliberately
     * only feeds pickers that should never offer a deactivated user.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function listAllForAdmin(): array
    {
        return Database::connection()->query(
            "SELECT u.id, u.name, u.email, u.phone, u.is_active, u.force_password_change,
                    u.two_fa_enabled, u.last_login_at, u.locked_until, u.created_at, u.is_super_admin,
                    r.id AS role_id, r.name AS role_name
             FROM users u LEFT JOIN roles r ON r.id = u.role_id
             ORDER BY u.is_active DESC, u.name"
        )->fetchAll();
    }

    /**
     * Creates a user with a one-time temporary password — always with
     * force_password_change = 1 and password_changed_at left NULL, exactly
     * the state a seed-file account starts in, so this reuses the same
     * gate rather than inventing a second "new account" concept.
     */
    public static function create(string $name, string $email, ?string $phone, ?int $roleId, string $tempPasswordHash): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change)
             VALUES (:name, :email, :phone, :hash, :role_id, 1, 1)'
        );
        $stmt->execute([
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'hash'    => $tempPasswordHash,
            'role_id' => $roleId,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function setActive(int $userId, bool $active): void
    {
        Database::connection()->prepare(
            'UPDATE users SET is_active = :active WHERE id = :id'
        )->execute(['active' => $active ? 1 : 0, 'id' => $userId]);
    }

    public static function update(int $userId, string $name, string $email, ?string $phone, ?int $roleId): void
    {
        Database::connection()->prepare(
            'UPDATE users SET name = :name, email = :email, phone = :phone, role_id = :role_id WHERE id = :id'
        )->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'role_id' => $roleId, 'id' => $userId]);
    }

    /**
     * Admin-forced reset (as opposed to updatePassword(), which a user
     * runs on themselves via /force-password-change). Also clears any
     * lockout, since handing someone a fresh password while they're still
     * time-locked out would be a confusing dead end.
     */
    public static function adminForceResetPassword(int $userId, string $tempPasswordHash): void
    {
        Database::connection()->prepare(
            'UPDATE users SET password_hash = :hash, force_password_change = 1,
                    password_changed_at = NULL, failed_login_count = 0, locked_until = NULL
             WHERE id = :id'
        )->execute(['hash' => $tempPasswordHash, 'id' => $userId]);
    }
}
