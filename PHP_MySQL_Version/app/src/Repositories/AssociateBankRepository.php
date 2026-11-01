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
                    b.account_currency, b.proof_file_id, b.requested_by_associate, b.requested_at, b.check_method, b.first_checked_by, b.first_checked_at,
                    b.verified_by, b.verified_at, b.usable_from, b.rejected_reason, u1.name AS first_checked_by_name, u2.name AS verified_by_name
               FROM associate_bank_accounts b LEFT JOIN users u1 ON u1.id = b.first_checked_by LEFT JOIN users u2 ON u2.id = b.verified_by
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
