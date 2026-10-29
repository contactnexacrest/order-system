<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\ZohoGuard;

/**
 * Zoho integration Stage 2 (2026-10-29) — which Zoho Books invoice each
 * order's Commercial Invoice ('ci') and freight Debit Note ('dn') became,
 * with a fingerprint of what was sent so a revised document updates the
 * same Books invoice instead of creating a second one. Written only by
 * CaSyncService.
 */
final class OrderZohoInvoiceRepository
{
    /** Our document type code for each kind. */
    public const DOCUMENT_TYPES = ['ci' => 'CI', 'dn' => 'FDN'];

    public static function find(int $orderId, string $kind): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_zoho_invoices WHERE order_id = :order_id AND kind = :kind');
        $stmt->execute(['order_id' => $orderId, 'kind' => $kind]);
        return $stmt->fetch() ?: null;
    }

    /** @param array{zoho_invoice_id:string, zoho_invoice_number:string, currency_code:string, total:float, exchange_rate:float, pushed_hash:string} $pushed */
    public static function save(int $orderId, string $kind, int $documentId, array $pushed): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_zoho_invoices
                (order_id, kind, document_id, zoho_invoice_id, zoho_invoice_number, currency_code, total, exchange_rate, pushed_hash)
             VALUES (:order_id, :kind, :document_id, :zoho_invoice_id, :zoho_invoice_number, :currency_code, :total, :exchange_rate, :pushed_hash)
             ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), zoho_invoice_id = VALUES(zoho_invoice_id),
                zoho_invoice_number = VALUES(zoho_invoice_number), currency_code = VALUES(currency_code), total = VALUES(total),
                exchange_rate = VALUES(exchange_rate), pushed_hash = VALUES(pushed_hash), updated_at = NOW()'
        )->execute([
            'order_id' => $orderId,
            'kind' => $kind,
            'document_id' => $documentId,
            'zoho_invoice_id' => $pushed['zoho_invoice_id'],
            'zoho_invoice_number' => $pushed['zoho_invoice_number'],
            'currency_code' => $pushed['currency_code'],
            'total' => round($pushed['total'], 2),
            'exchange_rate' => round($pushed['exchange_rate'], 6),
            'pushed_hash' => $pushed['pushed_hash'],
        ]);
    }

    /** The clean-up script cleared this invoice in Books: forget it here too. */
    public static function forgetZohoInvoice(string $zohoInvoiceId): void
    {
        Database::connection()->prepare('DELETE FROM order_zoho_invoices WHERE zoho_invoice_id = :id')->execute(['id' => $zohoInvoiceId]);
    }

    /**
     * Every order's latest Commercial Invoice and Debit Note that has been
     * approved or sent — the ones that may go to Zoho Books. While a newer
     * revision is in draft or review the order is left alone (documents
     * read the order's live figures, which may already hold the revision's
     * edits), so Books keeps the last approved version until the revision
     * is approved. Same rule as the payment
     * legs (CaRepository::legsPendingZohoSync): in production, test and
     * sample orders never go; in test, only TEST- customers do.
     *
     * @return list<array{order_id:int, kind:string, document_id:int}>
     */
    public static function documentsToSync(): array
    {
        $rows = Database::connection()->query(
            "SELECT d.id AS document_id, d.order_id, dt.code, o.company_legal_name,
                    (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
             FROM documents d
             JOIN document_types dt ON dt.id = d.document_type_id
             JOIN orders o ON o.id = d.order_id
             JOIN clients c ON c.id = o.client_id
             WHERE dt.code IN ('CI', 'FDN') AND d.status IN ('approved', 'sent')
               AND d.revision_number = (
                   SELECT MAX(d2.revision_number) FROM documents d2
                   WHERE d2.order_id = d.order_id AND d2.document_type_id = d.document_type_id AND d2.status IN ('approved', 'sent'))
               AND NOT EXISTS (
                   SELECT 1 FROM documents d3
                   WHERE d3.order_id = d.order_id AND d3.document_type_id = d.document_type_id
                     AND d3.revision_number > d.revision_number AND d3.status IN ('draft', 'in_review'))
             ORDER BY d.order_id, dt.code"
        )->fetchAll();

        $kinds = array_flip(self::DOCUMENT_TYPES);
        $out = [];
        foreach ($rows as $row) {
            $allowed = ZohoGuard::isProduction()
                ? (int) $row['is_test_or_sample'] !== 1
                : str_starts_with((string) $row['company_legal_name'], ZohoGuard::TEST_PREFIX);
            if ($allowed) {
                $out[] = ['order_id' => (int) $row['order_id'], 'kind' => $kinds[$row['code']], 'document_id' => (int) $row['document_id']];
            }
        }
        return $out;
    }
}
