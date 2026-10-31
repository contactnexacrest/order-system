<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision 2026-10-31 (option A, confirmed): GST under LUT. NexaCrest
 * exports under LUT and sells nothing in India, so the GST paid on
 * purchases comes back as a refund, claimed for a whole period (RFD-01),
 * not per order. Each claim, the money the bank credited for it (each
 * credit goes to Zoho Books as a journal) and any amount refused. The
 * order's cost and profit keep the GST — it left the bank. Port:
 * gstRefundRepository.js.
 */
final class GstRefundRepository
{
    public const PARTS = ['provisional' => 'provisional', 'final' => 'final / balance'];

    /** @return list<array<string,mixed>> every claim, newest period first, with what was credited */
    public static function claims(): array
    {
        return Database::connection()->query(
            'SELECT c.*, COALESCE(SUM(r.amount), 0) AS amount_credited, COUNT(r.id) AS credits
               FROM gst_refund_claims c
               LEFT JOIN gst_refund_credits r ON r.claim_id = c.id
              GROUP BY c.id, c.period_from, c.period_to, c.claim_arn, c.filed_on, c.amount_claimed, c.amount_refused, c.refused_note, c.recorded_by, c.created_at
              ORDER BY c.period_from DESC, c.id DESC'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function findClaim(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.*, (SELECT COALESCE(SUM(amount), 0) FROM gst_refund_credits WHERE claim_id = c.id) AS amount_credited FROM gst_refund_claims c WHERE c.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function arnTaken(string $arn): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM gst_refund_claims WHERE claim_arn = :arn');
        $stmt->execute(['arn' => $arn]);
        return $stmt->fetchColumn() !== false;
    }

    public static function createClaim(string $periodFrom, string $periodTo, string $arn, string $filedOn, float $amountClaimed, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO gst_refund_claims (period_from, period_to, claim_arn, filed_on, amount_claimed, recorded_by) VALUES (:f, :t, :arn, :filed, :amt, :u)'
        )->execute(['f' => $periodFrom, 't' => $periodTo, 'arn' => $arn, 'filed' => $filedOn, 'amt' => round($amountClaimed, 2), 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** An amount the officer refused: it adds to the claim's refused total (a real cost, booked in Zoho by the CA). */
    public static function addRefused(int $claimId, float $amount, string $note): void
    {
        Database::connection()->prepare(
            "UPDATE gst_refund_claims SET amount_refused = amount_refused + :amt,
                    refused_note = TRIM(BOTH ' · ' FROM CONCAT(COALESCE(refused_note, ''), ' · ', :note)) WHERE id = :id"
        )->execute(['amt' => round($amount, 2), 'note' => $note, 'id' => $claimId]);
    }

    public static function addCredit(int $claimId, float $amount, string $creditedOn, string $bankReference, string $part, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO gst_refund_credits (claim_id, amount, credited_on, bank_reference, part, recorded_by) VALUES (:c, :amt, :d, :ref, :part, :u)'
        )->execute(['c' => $claimId, 'amt' => round($amount, 2), 'd' => $creditedOn, 'ref' => trim($bankReference), 'part' => $part, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public static function creditsFor(int $claimId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM gst_refund_credits WHERE claim_id = :c ORDER BY credited_on, id');
        $stmt->execute(['c' => $claimId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null the credit with its claim's period and claim no. */
    public static function findCredit(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, c.period_from, c.period_to, c.claim_arn FROM gst_refund_credits r JOIN gst_refund_claims c ON c.id = r.claim_id WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** A credit typed wrongly can be removed only while it is not in Zoho Books. @return bool whether it was removed */
    public static function deleteCredit(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM gst_refund_credits WHERE id = :id AND zoho_journal_id IS NULL');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> credits not in Zoho Books yet (from the go-live date on), with their claim */
    public static function creditsForZohoSync(?string $startDate): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, c.period_from, c.period_to, c.claim_arn FROM gst_refund_credits r JOIN gst_refund_claims c ON c.id = r.claim_id
              WHERE r.zoho_journal_id IS NULL AND (:start IS NULL OR r.credited_on >= :start2) ORDER BY r.credited_on, r.id'
        );
        $stmt->execute(['start' => $startDate, 'start2' => $startDate]);
        return $stmt->fetchAll();
    }

    public static function setCreditZohoJournal(int $id, string $journalId): void
    {
        Database::connection()->prepare('UPDATE gst_refund_credits SET zoho_journal_id = :j, zoho_synced_at = NOW() WHERE id = :id')
            ->execute(['j' => $journalId, 'id' => $id]);
    }

    /** GST on the tax invoices (supplier and logistics bills) dated in a period — what the period's claim is built from. */
    public static function gstOnBills(string $from, string $to): float
    {
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(gst_inr), 0) FROM order_vendor_bills WHERE doc_kind = 'invoice' AND bill_date BETWEEN :f AND :t"
        );
        $stmt->execute(['f' => $from, 't' => $to]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** @return array{supplier: float, logistics: float, total: float} GST on one order's tax invoices */
    public static function gstOnOrderBills(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT vendor_kind, COALESCE(SUM(gst_inr), 0) AS gst FROM order_vendor_bills WHERE order_id = :o AND doc_kind = 'invoice' GROUP BY vendor_kind"
        );
        $stmt->execute(['o' => $orderId]);
        $out = ['supplier' => 0.0, 'logistics' => 0.0];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['vendor_kind'] === 'supplier' ? 'supplier' : 'logistics'] += round((float) $row['gst'], 2);
        }
        return $out + ['total' => round($out['supplier'] + $out['logistics'], 2)];
    }

    /** Refunds the bank credited between two dates (company-level income, e.g. the revenue report's period). */
    public static function creditedBetween(string $from, string $to): float
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(SUM(amount), 0) FROM gst_refund_credits WHERE credited_on BETWEEN :f AND :t');
        $stmt->execute(['f' => $from, 't' => $to]);
        return round((float) $stmt->fetchColumn(), 2);
    }
}
