<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\EncryptionService;

/**
 * Owner decision 1 Nov 2026 (AS-7): an associate's bank details. A new or
 * changed account is a request: payments keep going to the verified
 * account until two different Accounts people have checked the new one;
 * it is then usable after a waiting period. The number is stored encrypted
 * and shown masked; every version is kept.
 *
 * Owner decisions 11 Nov 2026 (D-293): a new request starts as
 * 'awaiting_confirmation' (AssociateBankConfirmation::start) — the associate
 * confirms it in the portal ('pending' = confirmed, waiting for staff) or it
 * is cancelled ('This is not me', or 7 days without an answer). Staff then
 * verify it: one person (default) or two (setting).
 */
final class AssociateBankRepository
{
    public static function request(int $associateId, array $d, bool $byAssociate, ?int $userId): int
    {
        $pdo = Database::connection();
        $number = preg_replace('/\s+/', '', (string) $d['account_number']) ?? '';
        $pdo->prepare(
            'INSERT INTO associate_bank_accounts (associate_id, holder_name, account_number_enc, account_last4, ifsc, swift, bank_name, bank_branch_address,
                intermediary_bank, account_currency, proof_file_id, requested_by_associate, requested_by_user)
             VALUES (:a, :h, :n, :l4, :ifsc, :swift, :bank, :addr, :inter, :cur, :proof, :ba, :u)'
        )->execute([
            'a' => $associateId, 'h' => $d['holder_name'], 'n' => EncryptionService::encrypt($number), 'l4' => substr($number, -4),
            'ifsc' => $d['ifsc'] ?: null, 'swift' => $d['swift'] ?: null, 'bank' => $d['bank_name'], 'addr' => $d['bank_branch_address'] ?: null,
            'inter' => $d['intermediary_bank'] ?: null, 'cur' => $d['account_currency'], 'proof' => $d['proof_file_id'] ?? null,
            'ba' => $byAssociate ? 1 : 0, 'u' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> newest first, without the encrypted number */
    public static function forAssociate(int $associateId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT b.id, b.associate_id, b.status, b.holder_name, b.account_last4, b.ifsc, b.swift, b.bank_name, b.bank_branch_address, b.intermediary_bank,
                    b.account_currency, b.proof_file_id, b.requested_by_associate, b.requested_by_user, b.requested_at, b.check_method, b.first_checked_by, b.first_checked_at,
                    b.verified_by, b.verified_at, b.usable_from, b.rejected_reason, b.confirm_by, b.confirmed_at, b.reminded_at, b.cancelled_at, b.cancelled_how,
                    b.check_bank_proof, b.check_phone, b.check_test_payment, b.test_payment_ref, b.verify_note, b.self_verify_reason,
                    u1.name AS first_checked_by_name, u2.name AS verified_by_name, u3.name AS requested_by_name
               FROM associate_bank_accounts b LEFT JOIN users u1 ON u1.id = b.first_checked_by LEFT JOIN users u2 ON u2.id = b.verified_by LEFT JOIN users u3 ON u3.id = b.requested_by_user
              WHERE b.associate_id = :a ORDER BY b.id DESC'
        );
        $stmt->execute(['a' => $associateId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM associate_bank_accounts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** The full account number (Finance / MD only — the caller checks and audit-logs). */
    public static function fullNumber(int $id): ?string
    {
        $row = self::find($id);
        return $row === null ? null : EncryptionService::decrypt((string) $row['account_number_enc']);
    }

    /** The verified account payments go to today, if any. */
    public static function usable(int $associateId, ?string $onDate = null): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM associate_bank_accounts WHERE associate_id = :a AND status = 'verified' AND usable_from <= :d ORDER BY verified_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute(['a' => $associateId, 'd' => $onDate ?? date('Y-m-d')]);
        return $stmt->fetch() ?: null;
    }

    public static function pendingCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM associate_bank_accounts WHERE status = 'pending'")->fetchColumn();
    }

    /** D-293: does the associate already have a verified account (then a new one is a change: the longer wait)? */
    public static function hasVerified(int $associateId, ?int $exceptId = null): bool
    {
        $stmt = Database::connection()->prepare("SELECT 1 FROM associate_bank_accounts WHERE associate_id = :a AND status = 'verified' AND id <> :x LIMIT 1");
        $stmt->execute(['a' => $associateId, 'x' => $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    /** D-293: the request waiting for the associate's confirmation, if any (newest). */
    public static function awaiting(int $associateId): ?array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM associate_bank_accounts WHERE associate_id = :a AND status = 'awaiting_confirmation' ORDER BY id DESC LIMIT 1");
        $stmt->execute(['a' => $associateId]);
        return $stmt->fetch() ?: null;
    }

    /** D-293: the request now waits for the associate's confirmation until $confirmBy; older open requests are replaced. */
    public static function markAwaiting(int $id, int $associateId, string $confirmBy): void
    {
        $pdo = Database::connection();
        $pdo->prepare("UPDATE associate_bank_accounts SET status = 'cancelled', cancelled_at = NOW(), cancelled_how = 'replaced'
                        WHERE associate_id = :a AND id <> :id AND status IN ('awaiting_confirmation','pending')")->execute(['a' => $associateId, 'id' => $id]);
        $pdo->prepare("UPDATE associate_bank_accounts SET status = 'awaiting_confirmation', confirm_by = :c WHERE id = :id")->execute(['c' => $confirmBy, 'id' => $id]);
    }

    public static function setCode(int $id, string $hash, string $expires): void
    {
        Database::connection()->prepare('UPDATE associate_bank_accounts SET confirm_code_hash = :h, confirm_code_expires = :e, confirm_code_attempts = 0 WHERE id = :id')
            ->execute(['h' => $hash, 'e' => $expires, 'id' => $id]);
    }

    public static function addCodeAttempt(int $id): void
    {
        Database::connection()->prepare('UPDATE associate_bank_accounts SET confirm_code_attempts = confirm_code_attempts + 1 WHERE id = :id')->execute(['id' => $id]);
    }

    /** The associate confirmed: now waiting for staff ('pending'). False if no longer waiting. */
    public static function markConfirmed(int $id): bool
    {
        $stmt = Database::connection()->prepare("UPDATE associate_bank_accounts SET status = 'pending', confirmed_at = NOW(), confirm_code_hash = NULL, confirm_code_expires = NULL
                                                  WHERE id = :id AND status = 'awaiting_confirmation'");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** Cancelled: 'not_me' (the associate), 'expired' (7 days) or 'replaced'. False if no longer waiting. */
    public static function cancel(int $id, string $how): bool
    {
        $stmt = Database::connection()->prepare("UPDATE associate_bank_accounts SET status = 'cancelled', cancelled_at = NOW(), cancelled_how = :h, confirm_code_hash = NULL
                                                  WHERE id = :id AND status IN ('awaiting_confirmation','pending')");
        $stmt->execute(['h' => $how, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public static function markReminded(int $id): void
    {
        Database::connection()->prepare('UPDATE associate_bank_accounts SET reminded_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<array<string,mixed>> requests still waiting for the associate after their confirm-by time */
    public static function overdue(string $now): array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM associate_bank_accounts WHERE status = 'awaiting_confirmation' AND confirm_by IS NOT NULL AND confirm_by < :n ORDER BY id");
        $stmt->execute(['n' => $now]);
        return $stmt->fetchAll();
    }

    /** D-293: the "how I checked" ticks of the (first) check. */
    public static function saveChecks(int $id, array $checks, ?string $selfReason): void
    {
        Database::connection()->prepare(
            'UPDATE associate_bank_accounts SET check_bank_proof = :bp, check_phone = :ph, check_test_payment = :tp, test_payment_ref = :ref, verify_note = :note,
                    self_verify_reason = COALESCE(:sr, self_verify_reason) WHERE id = :id'
        )->execute(['bp' => $checks['check_bank_proof'], 'ph' => $checks['check_phone'], 'tp' => $checks['check_test_payment'], 'ref' => $checks['test_payment_ref'],
            'note' => $checks['verify_note'], 'sr' => $selfReason, 'id' => $id]);
    }

    /** D-293, one person: verified straight from 'pending' (confirmed); usable after the waiting days. */
    public static function verifyOne(int $id, int $userId, int $waitDays): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE associate_bank_accounts SET status = 'verified', verified_by = :u, verified_at = NOW(), usable_from = DATE_ADD(CURDATE(), INTERVAL :w DAY)
              WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['u' => $userId, 'w' => $waitDays, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** First of the two checks. Returns false if the request is no longer pending. */
    public static function firstCheck(int $id, int $userId, string $method): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE associate_bank_accounts SET first_checked_by = :u, first_checked_at = NOW(), check_method = :m
              WHERE id = :id AND status = 'pending' AND first_checked_by IS NULL"
        );
        $stmt->execute(['u' => $userId, 'm' => $method, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** Second check by a different person: verified, usable after the waiting days; until then the older verified account keeps being used. */
    public static function verify(int $id, int $userId, int $waitDays): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $row = $pdo->prepare("SELECT * FROM associate_bank_accounts WHERE id = :id AND status = 'pending' FOR UPDATE");
            $row->execute(['id' => $id]);
            $b = $row->fetch();
            if (!$b || $b['first_checked_by'] === null || (int) $b['first_checked_by'] === $userId) {
                $pdo->rollBack();
                return false;
            }
            // The older verified account stays in use until the new one's waiting days are over (usable() picks the newest usable one).
            $pdo->prepare("UPDATE associate_bank_accounts SET status = 'verified', verified_by = :u, verified_at = NOW(), usable_from = DATE_ADD(CURDATE(), INTERVAL :w DAY) WHERE id = :id")
                ->execute(['u' => $userId, 'w' => $waitDays, 'id' => $id]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function reject(int $id, string $reason): bool
    {
        $stmt = Database::connection()->prepare("UPDATE associate_bank_accounts SET status = 'rejected', rejected_reason = :r WHERE id = :id AND status = 'pending'");
        $stmt->execute(['r' => $reason, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** "HDFC Bank ••••4321" */
    public static function masked(array $b): string
    {
        return $b['bank_name'] . ' ••••' . $b['account_last4'];
    }
}
