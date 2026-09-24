<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Designations + per-user signature/designation-seal assets + the
 * three-layer signatory default resolution config (global, per-document-
 * type). The actual resolution used at generation time lives in
 * DocumentDataAssembler::signatoryBlock() — this repository only manages
 * the admin-editable configuration behind it.
 */
final class SignatoryRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function designations(): array
    {
        return Database::connection()
            ->query(
                'SELECT d.*,
                        (SELECT COUNT(*) FROM users u WHERE u.designation_id = d.id) AS usage_count,
                        (SELECT COUNT(*) FROM users u WHERE u.designation_id = d.id AND u.is_protected_account = 1) AS protected_usage_count
                 FROM designations d
                 ORDER BY d.is_active DESC, d.title'
            )
            ->fetchAll();
    }

    public static function createDesignation(string $title, int $createdBy): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO designations (title, created_by) VALUES (:title, :by)');
        $stmt->execute(['title' => $title, 'by' => $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public static function toggleDesignationActive(int $id): void
    {
        Database::connection()
            ->prepare('UPDATE designations SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public static function findDesignation(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM designations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** How many users currently carry this designation — blocks rename-onto-a-protected-user and delete-while-in-use. */
    public static function designationUsageCount(int $id): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE designation_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public static function designationAssignedToProtectedAccount(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users WHERE designation_id = :id AND is_protected_account = 1'
        );
        $stmt->execute(['id' => $id]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    public static function updateDesignationTitle(int $id, string $title): void
    {
        Database::connection()
            ->prepare('UPDATE designations SET title = :title WHERE id = :id')
            ->execute(['title' => $title, 'id' => $id]);
    }

    public static function deleteDesignation(int $id): void
    {
        Database::connection()->prepare('DELETE FROM designations WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array<int, array<string,mixed>> every user, with designation title and eligibility joined in */
    public static function usersWithSignatoryInfo(): array
    {
        return Database::connection()->query(
            'SELECT u.id, u.name, u.email, u.is_signatory_eligible, u.designation_id, u.is_protected_account, d.title AS designation_title
             FROM users u LEFT JOIN designations d ON d.id = u.designation_id
             WHERE u.is_active = 1
             ORDER BY u.name'
        )->fetchAll();
    }

    /** @return array<int, array<string,mixed>> only signatory-eligible users, for dropdowns */
    public static function eligibleSignatories(): array
    {
        return Database::connection()->query(
            "SELECT u.id, u.name, d.title AS designation_title
             FROM users u LEFT JOIN designations d ON d.id = u.designation_id
             WHERE u.is_active = 1 AND u.is_signatory_eligible = 1
             ORDER BY u.name"
        )->fetchAll();
    }

    public static function setEligibility(int $userId, bool $eligible, ?int $designationId, int $updatedBy): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE users SET is_signatory_eligible = :eligible, designation_id = :designation_id WHERE id = :id'
        );
        $stmt->execute([
            'eligible' => $eligible ? 1 : 0,
            'designation_id' => $designationId,
            'id' => $userId,
        ]);
        AuditLogRepository::log($updatedBy, $eligible ? 'SIGNATORY_ELIGIBILITY_GRANTED' : 'SIGNATORY_ELIGIBILITY_REVOKED', 'users', $userId, null, null, null);
    }

    /** @return array<int, array<string,mixed>> */
    public static function assetsForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM user_signature_assets WHERE user_id = :uid ORDER BY asset_kind, is_default_for_kind DESC, id DESC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    public static function addUserAsset(int $userId, string $kind, string $label, string $serverPath, ?string $mime, int $uploadedBy): int
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Only one default per (user, kind) — clear any existing default before inserting the new one as default.
            $pdo->prepare('UPDATE user_signature_assets SET is_default_for_kind = 0 WHERE user_id = :uid AND asset_kind = :kind')
                ->execute(['uid' => $userId, 'kind' => $kind]);
            $stmt = $pdo->prepare(
                'INSERT INTO user_signature_assets (user_id, asset_kind, label, server_path, mime_type, is_default_for_kind, uploaded_by)
                 VALUES (:uid, :kind, :label, :path, :mime, 1, :by)'
            );
            $stmt->execute([
                'uid' => $userId, 'kind' => $kind, 'label' => $label, 'path' => $serverPath, 'mime' => $mime, 'by' => $uploadedBy,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function deactivateUserAsset(int $id): void
    {
        Database::connection()->prepare('UPDATE user_signature_assets SET is_active = 0 WHERE id = :id')->execute(['id' => $id]);
    }

    public static function findUserAsset(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM user_signature_assets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function globalDefaultSignatoryUserId(): ?int
    {
        $row = Database::connection()->query('SELECT user_id FROM company_default_signatory WHERE id = 1')->fetch();
        return $row ? (int) $row['user_id'] : null;
    }

    public static function setGlobalDefaultSignatory(int $userId, int $updatedBy): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO company_default_signatory (id, user_id, updated_by) VALUES (1, :uid, :by)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), updated_by = VALUES(updated_by)'
        );
        $stmt->execute(['uid' => $userId, 'by' => $updatedBy]);
        AuditLogRepository::log($updatedBy, 'GLOBAL_DEFAULT_SIGNATORY_SET', 'company_default_signatory', 1, null, null, (string) $userId);
    }

    /** @return array<int, array<string,mixed>> document types joined with their configured signatory, if any */
    public static function documentTypeSignatories(): array
    {
        return Database::connection()->query(
            "SELECT dt.id AS document_type_id, dt.code, dt.name,
                    dts.user_id, dts.use_designation_seal, u.name AS signatory_name
             FROM document_types dt
             LEFT JOIN document_type_signatories dts ON dts.document_type_id = dt.id
             LEFT JOIN users u ON u.id = dts.user_id
             WHERE dt.is_active = 1
             ORDER BY dt.code"
        )->fetchAll();
    }

    public static function setDocumentTypeSignatory(int $documentTypeId, ?int $userId, bool $useDesignationSeal, int $updatedBy): void
    {
        $pdo = Database::connection();
        if ($userId === null) {
            $pdo->prepare('DELETE FROM document_type_signatories WHERE document_type_id = :dt')->execute(['dt' => $documentTypeId]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO document_type_signatories (document_type_id, user_id, use_designation_seal, updated_by)
                 VALUES (:dt, :uid, :seal, :by)
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), use_designation_seal = VALUES(use_designation_seal), updated_by = VALUES(updated_by)'
            );
            $stmt->execute(['dt' => $documentTypeId, 'uid' => $userId, 'seal' => $useDesignationSeal ? 1 : 0, 'by' => $updatedBy]);
        }
        AuditLogRepository::log($updatedBy, 'DOCUMENT_TYPE_SIGNATORY_SET', 'document_type_signatories', $documentTypeId, null, null, (string) $userId);
    }
}
