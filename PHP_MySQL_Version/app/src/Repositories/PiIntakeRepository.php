<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * PI-stage intake — see schema.sql Section AA. A separate, second public
 * form from client_intake_submissions (the Quotation stage): staff
 * generate a per-order link once the Quotation is out, the client
 * confirms/fills their PI-stage details, and it lands in its own staff
 * review queue. Never auto-applied to the client/order — see
 * PiIntakeReviewController::accept().
 */
final class PiIntakeRepository
{
    private const TOKEN_TTL_DAYS = 30;

    /** Generates a fresh link for this order and returns the RAW token (only its hash is stored). */
    public static function createLink(int $orderId, ?int $createdBy): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO pi_intake_submissions (order_id, access_token_hash, access_token_expires_at, created_by)
             VALUES (:order_id, :hash, :expires, :created_by)'
        );
        $stmt->execute([
            'order_id'    => $orderId,
            'hash'        => hash('sha256', $rawToken),
            'expires'     => date('Y-m-d H:i:s', time() + (self::TOKEN_TTL_DAYS * 86400)),
            'created_by'  => $createdBy,
        ]);
        return $rawToken;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM pi_intake_submissions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Only while awaiting the client's (first or corrected) submission — not once it's pending staff review or already applied. */
    public static function findValidByToken(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);
        $stmt = Database::connection()->prepare(
            "SELECT pis.*, o.order_reference, c.company_legal_name AS client_company_legal_name
             FROM pi_intake_submissions pis
             JOIN orders o ON o.id = pis.order_id
             JOIN clients c ON c.id = o.client_id
             WHERE pis.access_token_hash = :hash AND pis.access_token_expires_at > NOW()
               AND pis.status IN ('awaiting_client', 'rejected')"
        );
        $stmt->execute(['hash' => $hash]);
        return $stmt->fetch() ?: null;
    }

    public static function latestForOrder(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT pis.*, u.name AS reviewed_by_name
             FROM pi_intake_submissions pis
             LEFT JOIN users u ON u.id = pis.reviewed_by
             WHERE pis.order_id = :order_id ORDER BY pis.created_at DESC LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function submit(int $id, array $data, ?string $ip): void
    {
        Database::connection()->prepare(
            'UPDATE pi_intake_submissions SET
                company_legal_name = :company_legal_name, billing_address = :billing_address,
                consignee_name = :consignee_name, consignee_address = :consignee_address,
                vat_eori_tax_no = :vat_eori_tax_no, contact_person = :contact_person, email = :email,
                phone = :phone, notify_party = :notify_party,
                port_of_discharge_text = :port_of_discharge_text, country_of_destination = :country_of_destination,
                incoterm_confirmed = :incoterm_confirmed, container_type_text = :container_type_text,
                payment_terms_confirmation = :payment_terms_confirmation,
                quotation_acceptance_reference = :quotation_acceptance_reference,
                coo_type = :coo_type, buyer_po_ref = :buyer_po_ref,
                changes_from_quotation = :changes_from_quotation,
                special_document_requirements = :special_document_requirements,
                status = \'pending_review\', submitted_at = NOW(), submitted_ip = :ip,
                rejection_reason = NULL
             WHERE id = :id'
        )->execute([
            'company_legal_name'             => $data['company_legal_name'],
            'billing_address'                => $data['billing_address'],
            'consignee_name'                 => $data['consignee_name'],
            'consignee_address'               => $data['consignee_address'],
            'vat_eori_tax_no'                => $data['vat_eori_tax_no'],
            'contact_person'                 => $data['contact_person'],
            'email'                          => $data['email'],
            'phone'                          => $data['phone'],
            'notify_party'                   => $data['notify_party'] ?: null,
            'port_of_discharge_text'          => $data['port_of_discharge_text'],
            'country_of_destination'          => $data['country_of_destination'],
            'incoterm_confirmed'             => $data['incoterm_confirmed'],
            'container_type_text'            => $data['container_type_text'] ?: null,
            'payment_terms_confirmation'      => $data['payment_terms_confirmation'],
            'quotation_acceptance_reference'  => $data['quotation_acceptance_reference'],
            'coo_type'                        => $data['coo_type'],
            'buyer_po_ref'                    => $data['buyer_po_ref'] ?: null,
            'changes_from_quotation'          => $data['changes_from_quotation'] ?: null,
            'special_document_requirements'   => $data['special_document_requirements'] ?: null,
            'ip'                              => $ip,
            'id'                              => $id,
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    public static function pendingReview(): array
    {
        return Database::connection()->query(
            "SELECT pis.*, o.order_reference, c.company_legal_name AS client_company_legal_name
             FROM pi_intake_submissions pis
             JOIN orders o ON o.id = pis.order_id
             JOIN clients c ON c.id = o.client_id
             WHERE pis.status = 'pending_review'
             ORDER BY pis.submitted_at ASC"
        )->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function recentResolved(int $limit = 30): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT pis.*, o.order_reference, c.company_legal_name AS client_company_legal_name, u.name AS reviewed_by_name
             FROM pi_intake_submissions pis
             JOIN orders o ON o.id = pis.order_id
             JOIN clients c ON c.id = o.client_id
             LEFT JOIN users u ON u.id = pis.reviewed_by
             WHERE pis.status IN ('applied', 'rejected')
             ORDER BY pis.reviewed_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function markApplied(int $id, int $reviewedBy): void
    {
        Database::connection()->prepare(
            "UPDATE pi_intake_submissions SET status = 'applied', reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id"
        )->execute(['reviewed_by' => $reviewedBy, 'id' => $id]);
    }

    public static function markRejected(int $id, int $reviewedBy, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE pi_intake_submissions SET status = 'rejected', rejection_reason = :reason, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id"
        )->execute(['reason' => $reason, 'reviewed_by' => $reviewedBy, 'id' => $id]);
    }
}
