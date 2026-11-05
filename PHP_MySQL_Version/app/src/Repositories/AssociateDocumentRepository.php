<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/** Owner decision 1 Nov 2026: an associate's KYC, invoices and tax papers (AS-13). */
final class AssociateDocumentRepository
{
    public const KINDS = [
        'pan' => 'PAN card', 'aadhaar' => 'Aadhaar card', 'identity' => 'Passport / ID', 'bank_proof' => 'Bank proof (cancelled cheque / statement)', 'agreement' => 'Signed agreement', 'address' => 'Address proof', 'gst' => 'GST certificate',
        'company' => 'Company registration', 'tax_residency' => 'Tax residency certificate', 'form_10f' => 'Form 10F',
        'no_pe' => 'No-PE declaration', 'invoice' => 'Commission invoice', 'form_16a' => 'Form 16A (TDS certificate)',
        'form_15ca' => 'Form 15CA', 'form_15cb' => 'Form 15CB', 'other' => 'Other',
    ];
    /** What an associate may upload themselves; the rest come from Accounts. */
    public const ASSOCIATE_KINDS = ['pan', 'aadhaar', 'identity', 'bank_proof', 'agreement', 'address', 'gst', 'company', 'tax_residency', 'form_10f', 'no_pe', 'invoice', 'other'];

    public static function add(int $associateId, string $kind, int $fileId, ?int $payoutId, ?string $reference, bool $byAssociate, ?int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO associate_documents (associate_id, kind, payout_id, file_id, reference, uploaded_by_associate, uploaded_by_user)
                       VALUES (:a, :k, :p, :f, :r, :ba, :u)')
            ->execute(['a' => $associateId, 'k' => $kind, 'p' => $payoutId, 'f' => $fileId, 'r' => $reference, 'ba' => $byAssociate ? 1 : 0, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public static function forAssociate(int $associateId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.*, f.original_filename, u.name AS reviewed_by_name FROM associate_documents d JOIN file_store f ON f.id = d.file_id
               LEFT JOIN users u ON u.id = d.reviewed_by WHERE d.associate_id = :a ORDER BY d.id DESC'
        );
        $stmt->execute(['a' => $associateId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT d.*, f.original_filename, f.server_path, f.mime_type FROM associate_documents d JOIN file_store f ON f.id = d.file_id WHERE d.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function invoiceForPayout(int $payoutId): ?array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM associate_documents WHERE payout_id = :p AND kind = 'invoice' ORDER BY id DESC LIMIT 1");
        $stmt->execute(['p' => $payoutId]);
        return $stmt->fetch() ?: null;
    }
}
