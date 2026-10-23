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
        // External QA report L-26: session_version is bumped on every
        // password change so SessionAuth can tell an OTHER, still-open
        // session (which stored the old value at its own login time) to
        // log out on its very next request — see SessionAuth::required().
        Database::connection()->prepare(
            'UPDATE users SET password_hash = :hash, force_password_change = :force, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = :id'
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
    /**
     * 90-item backlog item #10: a 3-way status filter (active/inactive/
     * archived), not just the one all-users list this always was.
     * $status defaults to 'active' — archived (and, previously,
     * deactivated) users mixed into the default view is exactly the
     * clutter this item asked to fix.
     */
    public static function listAllForAdmin(string $status = 'active'): array
    {
        $where = match ($status) {
            'inactive' => 'u.is_active = 0 AND u.is_archived = 0',
            'archived' => 'u.is_archived = 1',
            default => 'u.is_active = 1 AND u.is_archived = 0',
        };
        return Database::connection()->query(
            "SELECT u.id, u.name, u.email, u.phone, u.is_active, u.force_password_change,
                    u.two_fa_enabled, u.last_login_at, u.locked_until, u.created_at, u.is_super_admin,
                    u.is_protected_account, u.is_archived, u.archived_at,
                    r.id AS role_id, r.name AS role_name
             FROM users u LEFT JOIN roles r ON r.id = u.role_id
             WHERE {$where}
             ORDER BY u.is_active DESC, u.name"
        )->fetchAll();
    }

    /** @return array<string,int> counts for each of the 3 status tabs, so a tab's own count never requires switching to it first. */
    public static function statusCounts(): array
    {
        $row = Database::connection()->query(
            "SELECT
                SUM(CASE WHEN is_active = 1 AND is_archived = 0 THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN is_active = 0 AND is_archived = 0 THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) AS archived
             FROM users"
        )->fetch();
        return [
            'active' => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'archived' => (int) ($row['archived'] ?? 0),
        ];
    }

    public static function archive(int $userId, int $archivedBy): void
    {
        Database::connection()->prepare(
            'UPDATE users SET is_archived = 1, archived_at = NOW(), archived_by = :archived_by, is_active = 0 WHERE id = :id'
        )->execute(['archived_by' => $archivedBy, 'id' => $userId]);
    }

    public static function unarchive(int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE users SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = :id'
        )->execute(['id' => $userId]);
    }

    /**
     * Real DELETE, deliberately with no manual "is this user referenced
     * anywhere" check first — users.id is a FK target from 57+ tables
     * (audit_log, documents.generated_by, every reviewer/approver
     * column, ...), all left at MySQL's default ON DELETE RESTRICT, so
     * the database itself is already the single, always-up-to-date
     * source of truth for "is this user referenced anywhere." Letting
     * the constraint violation happen and translating it into a clean
     * error (see UserController::delete()) avoids hand-maintaining a
     * table list that would silently go stale the moment a new FK to
     * users is added anywhere else in this schema.
     *
     * External QA report L-33: password_reset_tokens and notifications
     * rows are the one exception — they carry zero history value once
     * the user is gone (an expired/used reset token, a read-or-not
     * notification), yet their FK was enough to block the delete and
     * show "has order, document, or audit history" for a user with
     * neither. Cleared here first so only a REAL blocker (an order,
     * document, audit_log entry, ...) ever reaches that message.
     */
    public static function delete(int $userId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM notifications WHERE user_id = :id')->execute(['id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
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
        // External QA report L-26: see updatePassword()'s own comment —
        // this is the one password-change path most likely to be used
        // specifically BECAUSE a session may already be compromised, so
        // invalidating it is the whole point.
        Database::connection()->prepare(
            'UPDATE users SET password_hash = :hash, force_password_change = 1,
                    password_changed_at = NULL, failed_login_count = 0, locked_until = NULL,
                    session_version = session_version + 1
             WHERE id = :id'
        )->execute(['hash' => $tempPasswordHash, 'id' => $userId]);
    }

    /** docs/schema.sql Section AI — self-service email signature, appended to every email that user sends through the system. */
    public static function updateSignature(int $userId, ?string $signature): void
    {
        Database::connection()->prepare(
            'UPDATE users SET email_signature = :signature WHERE id = :id'
        )->execute(['signature' => $signature, 'id' => $userId]);
    }

    /** User-reported batch item 20 — the per-user "configurable option" opt-in/out for action-notification emails. */
    public static function updateNotificationPreference(int $userId, bool $enabled): void
    {
        Database::connection()->prepare(
            'UPDATE users SET email_action_notifications_enabled = :enabled WHERE id = :id'
        )->execute(['enabled' => $enabled ? 1 : 0, 'id' => $userId]);
    }

    /**
     * docs/schema.sql Section BQ — a null preference means "follow the
     * company default, whatever it currently is" rather than freezing in
     * whatever the default was at the time this was set to null.
     */
    public static function updateUiTemplatePreference(int $userId, ?int $templateId): void
    {
        Database::connection()->prepare(
            'UPDATE users SET ui_template_preference_id = :template_id WHERE id = :id'
        )->execute(['template_id' => $templateId, 'id' => $userId]);
    }
}
