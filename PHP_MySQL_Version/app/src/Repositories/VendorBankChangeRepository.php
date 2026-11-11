<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\EncryptionService;

/**
 * Owner decisions 11 Nov 2026, point 18 (D-294): a change of a supplier's or logistics
 * partner's bank details waits here (vendor_bank_changes) — confirmed by the vendor through
 * a link e-mailed to their registered e-mail, verified by staff, then copied onto the
 * supplier once its waiting days are over. The account in use stays on the supplier row
 * until then. The account number is stored encrypted.
 * Node twin: src/repositories/vendorBankChangeRepository.js.
 */
final class VendorBankChangeRepository
{
    public const OPEN = ['awaiting_confirmation', 'pending', 'verified'];

    /** @param array<string,?string> $bank VendorDetailsRepository::BANK_FIELDS + bank_account_number (null keeps the number on file) */
    public static function create(string $kind, int $vendorId, array $bank, string $source, ?int $userId, ?string $email, string $tokenHash, string $confirmBy, ?string $last4): int
    {
        $pdo = Database::connection();
        // An earlier change still open is replaced by this one.
        $pdo->prepare("UPDATE vendor_bank_changes SET status = 'cancelled', cancelled_at = NOW(), cancelled_how = 'replaced', confirm_token_hash = NULL
                        WHERE vendor_kind = :k AND vendor_id = :v AND status IN ('awaiting_confirmation','pending','verified')")->execute(['k' => $kind, 'v' => $vendorId]);
        $pdo->prepare(
            'INSERT INTO vendor_bank_changes (vendor_kind, vendor_id, bank_account_name, bank_name, bank_branch, bank_account_number, account_last4, bank_ifsc, bank_account_type, bank_upi_id,
                entered_source, entered_by, sent_to_email, confirm_token_hash, confirm_by)
             VALUES (:k, :v, :holder, :bank, :branch, :num, :l4, :ifsc, :type, :upi, :src, :u, :email, :tok, :by)'
        )->execute([
            'k' => $kind, 'v' => $vendorId, 'holder' => $bank['bank_account_name'] ?? null, 'bank' => $bank['bank_name'] ?? null, 'branch' => $bank['bank_branch'] ?? null,
            'num' => !empty($bank['bank_account_number']) ? EncryptionService::encrypt((string) $bank['bank_account_number']) : null, 'l4' => $last4,
            'ifsc' => $bank['bank_ifsc'] ?? null, 'type' => $bank['bank_account_type'] ?? null, 'upi' => $bank['bank_upi_id'] ?? null,
            'src' => $source, 'u' => $userId, 'email' => $email, 'tok' => $tokenHash, 'by' => $confirmBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM vendor_bank_changes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** The change a live confirmation link points to (still waiting, within its 7 days). */
    public static function findByToken(string $raw): ?array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM vendor_bank_changes WHERE confirm_token_hash = :h AND status = 'awaiting_confirmation' AND confirm_by > NOW()");
        $stmt->execute(['h' => hash('sha256', $raw)]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> newest first, with the staff names */
    public static function forVendor(string $kind, int $vendorId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, u1.name AS entered_by_name, u2.name AS first_checked_by_name, u3.name AS verified_by_name
               FROM vendor_bank_changes c LEFT JOIN users u1 ON u1.id = c.entered_by LEFT JOIN users u2 ON u2.id = c.first_checked_by LEFT JOIN users u3 ON u3.id = c.verified_by
              WHERE c.vendor_kind = :k AND c.vendor_id = :v ORDER BY c.id DESC'
        );
        $stmt->execute(['k' => $kind, 'v' => $vendorId]);
        return $stmt->fetchAll();
    }

    /** The new account number (decrypted), or null when the change keeps the number on file. */
    public static function number(array $row): ?string
    {
        return !empty($row['bank_account_number']) ? EncryptionService::decrypt((string) $row['bank_account_number']) : null;
    }

    public static function newToken(int $id, string $hash, string $confirmBy, ?string $email): void
    {
        Database::connection()->prepare('UPDATE vendor_bank_changes SET confirm_token_hash = :h, confirm_by = :b, sent_to_email = :e, reminded_at = NOW() WHERE id = :id')
            ->execute(['h' => $hash, 'b' => $confirmBy, 'e' => $email, 'id' => $id]);
    }

    /** Status change guarded by the expected current status; true when it happened. */
    public static function move(int $id, string $from, string $to, array $set = []): bool
    {
        $cols = ['status = :to'];
        $params = ['to' => $to, 'id' => $id, 'from' => $from];
        foreach ($set as $col => $val) {
            if ($val === 'NOW()') {
                $cols[] = "{$col} = NOW()";
            } else {
                $cols[] = "{$col} = :{$col}";
                $params[$col] = $val;
            }
        }
        $stmt = Database::connection()->prepare('UPDATE vendor_bank_changes SET ' . implode(', ', $cols) . ' WHERE id = :id AND status = :from');
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public static function overdue(string $now): array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM vendor_bank_changes WHERE status = 'awaiting_confirmation' AND confirm_by < :n ORDER BY id");
        $stmt->execute(['n' => $now]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> verified changes whose waiting days are over */
    public static function due(string $today, ?string $kind = null, ?int $vendorId = null): array
    {
        $sql = "SELECT * FROM vendor_bank_changes WHERE status = 'verified' AND usable_from <= :t";
        $params = ['t' => $today];
        if ($kind !== null && $vendorId !== null) {
            $sql .= ' AND vendor_kind = :k AND vendor_id = :v';
            $params += ['k' => $kind, 'v' => $vendorId];
        }
        $stmt = Database::connection()->prepare($sql . ' ORDER BY id');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
