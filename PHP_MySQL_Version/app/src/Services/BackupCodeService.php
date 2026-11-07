<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\UserRepository;

/**
 * Emergency backup codes (owner decision D-265, 7 Nov 2026). If e-mail is
 * down nobody receives a login code — including the people who would fix it.
 * So the accounts that run the company (an effective Super Admin, or anyone
 * holding "Manage company settings" — the MD and Admin roles) may generate
 * 10 one-time backup codes on My Account and use one instead of the e-mailed
 * code. Stored hashed (two_fa_backup_codes); shown once; a new set replaces
 * the old one. Every use is audited and the company-settings holders are told.
 */
final class BackupCodeService
{
    public const COUNT = 10;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I

    public static function eligible(int $userId): bool
    {
        if (SuperAdminService::isEffective($userId)) {
            return true;
        }
        $user = UserRepository::findById($userId);
        if ($user === null) {
            return false;
        }
        return !empty(PermissionRepository::effectivePermissions($userId, $user['role_id'] !== null ? (int) $user['role_id'] : null)['manage_company_settings']);
    }

    public static function remaining(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM two_fa_backup_codes WHERE user_id = :u AND used_at IS NULL');
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** "ABCD-EFGH" form, upper case, no separators or spaces counted. */
    public static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** @return list<string> the new codes, shown once */
    public static function generate(int $userId): array
    {
        $pdo = Database::connection();
        $codes = [];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM two_fa_backup_codes WHERE user_id = :u')->execute(['u' => $userId]);
            $ins = $pdo->prepare('INSERT INTO two_fa_backup_codes (user_id, code_hash) VALUES (:u, :h)');
            for ($i = 0; $i < self::COUNT; $i++) {
                $raw = '';
                for ($j = 0; $j < 8; $j++) {
                    $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
                }
                $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4);
                $ins->execute(['u' => $userId, 'h' => password_hash($raw, PASSWORD_DEFAULT)]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditLogRepository::log($userId, 'BACKUP_CODES_GENERATED', 'users', $userId, null, null, null, self::COUNT . ' new backup codes; the earlier set no longer works');
        return $codes;
    }

    /** Uses one code if it matches an unused one. */
    public static function consume(int $userId, string $code): bool
    {
        $raw = self::normalise($code);
        if (strlen($raw) !== 8) {
            return false;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, code_hash FROM two_fa_backup_codes WHERE user_id = :u AND used_at IS NULL');
        $stmt->execute(['u' => $userId]);
        foreach ($stmt->fetchAll() as $row) {
            if (password_verify($raw, (string) $row['code_hash'])) {
                $upd = $pdo->prepare('UPDATE two_fa_backup_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
                $upd->execute(['id' => $row['id']]);
                return $upd->rowCount() === 1;
            }
        }
        return false;
    }

    /** Audit + an in-app notice and e-mail to everyone holding "Manage company settings". */
    public static function recordUse(int $userId): void
    {
        $user = UserRepository::findById($userId);
        $left = self::remaining($userId);
        $who = (string) ($user['email'] ?? ('user #' . $userId));
        AuditLogRepository::log($userId, 'LOGIN_BACKUP_CODE_USED', 'users', $userId, null, null, null, "Signed in with a backup code; {$left} left");
        $message = "Security: {$who} signed in with an emergency backup code instead of the e-mailed login code ({$left} backup code(s) left). If this was not expected, reset that login at once.";
        foreach (PermissionRepository::usersWithPermission('manage_company_settings') as $holderId) {
            NotificationRepository::create((int) $holderId, null, 'backup_code_used', null, $message);
        }
    }
}
