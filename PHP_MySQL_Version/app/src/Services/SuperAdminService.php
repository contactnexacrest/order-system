<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;

/**
 * The Super Admin tier: unrestricted everywhere, including self-approval on
 * every gate that otherwise requires a different privileged user. Checked
 * by name at those explicit gates (FieldProtectionRepository::approve()
 * being the first) and centrally in PermissionService::can(), so a Super
 * Admin never needs a specific permission granted — every permission check
 * short-circuits true for them.
 *
 * Minimum reason length (10 chars) is enforced here, not just on this
 * feature's own forms — this is the first "reason" field built after the
 * user's explicit request that every reason field have a minimum length,
 * so it starts here rather than being retrofitted later.
 */
final class SuperAdminService
{
    public const MIN_REASON_LENGTH = 10;

    public static function isEffective(int $userId): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT is_super_admin FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if ($row && (int) $row['is_super_admin'] === 1) {
            return true;
        }

        $stmt = $pdo->prepare(
            "SELECT id FROM super_admin_delegations
             WHERE delegate_user_id = :id AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        return (bool) $stmt->fetch();
    }

    /** @return array<int, array<string,mixed>> every user flagged is_super_admin = 1 */
    public static function permanentSuperAdmins(): array
    {
        return Database::connection()
            ->query('SELECT id, name, email, is_protected_account FROM users WHERE is_super_admin = 1 AND is_active = 1 ORDER BY name')
            ->fetchAll();
    }

    /** @return array<int, array<string,mixed>> active (not revoked, not expired) delegations */
    public static function activeDelegations(): array
    {
        return Database::connection()->query(
            "SELECT sad.*, u.name AS delegate_name, u.email AS delegate_email, g.name AS granted_by_name
             FROM super_admin_delegations sad
             JOIN users u ON u.id = sad.delegate_user_id
             JOIN users g ON g.id = sad.granted_by
             WHERE sad.revoked_at IS NULL AND (sad.expires_at IS NULL OR sad.expires_at > NOW())
             ORDER BY sad.granted_at DESC"
        )->fetchAll();
    }

    /** @return array<int, array<string,mixed>> past (revoked or expired) delegations, most recent first */
    public static function delegationHistory(int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT sad.*, u.name AS delegate_name, g.name AS granted_by_name, r.name AS revoked_by_name
             FROM super_admin_delegations sad
             JOIN users u ON u.id = sad.delegate_user_id
             JOIN users g ON g.id = sad.granted_by
             LEFT JOIN users r ON r.id = sad.revoked_by
             WHERE sad.revoked_at IS NOT NULL OR (sad.expires_at IS NOT NULL AND sad.expires_at <= NOW())
             ORDER BY sad.granted_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @throws \RuntimeException if the reason is too short, the target is
     *         already a permanent Super Admin, or already has an active
     *         delegation.
     */
    public static function grantDelegation(int $delegateUserId, int $grantedBy, string $reason, ?string $expiresAt): int
    {
        if (mb_strlen(trim($reason)) < self::MIN_REASON_LENGTH) {
            throw new \RuntimeException('Reason must be at least ' . self::MIN_REASON_LENGTH . ' characters — describe why this delegation is needed.');
        }
        if (self::isEffective($delegateUserId)) {
            throw new \RuntimeException('This user is already a Super Admin or already has an active delegation.');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO super_admin_delegations (delegate_user_id, granted_by, reason, expires_at)
             VALUES (:delegate, :granted_by, :reason, :expires_at)'
        );
        $stmt->execute([
            'delegate' => $delegateUserId,
            'granted_by' => $grantedBy,
            'reason' => trim($reason),
            'expires_at' => $expiresAt ?: null,
        ]);
        $id = (int) $pdo->lastInsertId();

        AuditLogRepository::log($grantedBy, 'SUPER_ADMIN_DELEGATION_GRANTED', 'users', $delegateUserId, 'is_super_admin', '0', '1 (delegated)', trim($reason));
        return $id;
    }

    /** @throws \RuntimeException if the reason is too short or the delegation isn't active */
    public static function revokeDelegation(int $delegationId, int $revokedBy, string $reason): void
    {
        if (mb_strlen(trim($reason)) < self::MIN_REASON_LENGTH) {
            throw new \RuntimeException('Reason must be at least ' . self::MIN_REASON_LENGTH . ' characters.');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "UPDATE super_admin_delegations
             SET revoked_at = NOW(), revoked_by = :revoked_by, revoked_reason = :reason
             WHERE id = :id AND revoked_at IS NULL"
        );
        $stmt->execute(['revoked_by' => $revokedBy, 'reason' => trim($reason), 'id' => $delegationId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Delegation not found, or already revoked/expired.');
        }

        $delegateStmt = $pdo->prepare('SELECT delegate_user_id FROM super_admin_delegations WHERE id = :id');
        $delegateStmt->execute(['id' => $delegationId]);
        $delegateUserId = (int) $delegateStmt->fetchColumn();

        AuditLogRepository::log($revokedBy, 'SUPER_ADMIN_DELEGATION_REVOKED', 'users', $delegateUserId, 'is_super_admin', '1 (delegated)', '0', trim($reason));
    }

    /**
     * Promotes/demotes the PERMANENT is_super_admin flag — a distinct,
     * audited action from delegation, and distinct from delete/deactivate
     * (which the DB trigger blocks unconditionally while the flag is 1).
     * Only an existing effective Super Admin may call this (enforced by
     * the controller, not here) — this method just performs the change.
     */
    public static function setPermanentFlag(int $userId, bool $isSuperAdmin, int $changedBy, string $reason): void
    {
        if (mb_strlen(trim($reason)) < self::MIN_REASON_LENGTH) {
            throw new \RuntimeException('Reason must be at least ' . self::MIN_REASON_LENGTH . ' characters.');
        }
        if (!$isSuperAdmin && count(self::permanentSuperAdmins()) <= 1) {
            throw new \RuntimeException('Cannot remove the last remaining Super Admin — promote someone else first.');
        }
        if (!$isSuperAdmin) {
            $target = UserRepository::findById($userId);
            if ($target && (int) $target['is_protected_account'] === 1) {
                throw new \RuntimeException('This is a protected founder account — Super Admin status can never be removed from it.');
            }
        }

        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET is_super_admin = :flag WHERE id = :id')
            ->execute(['flag' => $isSuperAdmin ? 1 : 0, 'id' => $userId]);

        AuditLogRepository::log(
            $changedBy,
            $isSuperAdmin ? 'SUPER_ADMIN_PROMOTED' : 'SUPER_ADMIN_DEMOTED',
            'users',
            $userId,
            'is_super_admin',
            $isSuperAdmin ? '0' : '1',
            $isSuperAdmin ? '1' : '0',
            trim($reason)
        );
    }
}
