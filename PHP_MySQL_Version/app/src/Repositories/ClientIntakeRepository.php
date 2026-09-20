<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Public quotation-stage intake submissions — see schema.sql Section O.
 * A submission is never auto-converted into a client; every row is
 * reviewed by staff first (ClientIntakeReviewController).
 */
final class ClientIntakeRepository
{
    public static function create(array $data, ?string $ip): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO client_intake_submissions
                (company_legal_name, billing_address, vat_eori_tax_no, contact_person, email, phone,
                 country_of_destination, port_of_discharge_text, coo_type, incoterm_preference,
                 container_type_text, buyer_own_reference, notes, submitted_ip)
             VALUES
                (:company_legal_name, :billing_address, :vat_eori_tax_no, :contact_person, :email, :phone,
                 :country_of_destination, :port_of_discharge_text, :coo_type, :incoterm_preference,
                 :container_type_text, :buyer_own_reference, :notes, :ip)'
        );
        $stmt->execute([
            'company_legal_name'     => $data['company_legal_name'],
            'billing_address'        => $data['billing_address'],
            'vat_eori_tax_no'        => $data['vat_eori_tax_no'] ?: null,
            'contact_person'         => $data['contact_person'],
            'email'                  => $data['email'],
            'phone'                  => $data['phone'] ?: null,
            'country_of_destination' => $data['country_of_destination'],
            'port_of_discharge_text' => $data['port_of_discharge_text'] ?: null,
            'coo_type'               => $data['coo_type'] ?: null,
            'incoterm_preference'    => $data['incoterm_preference'] ?: null,
            'container_type_text'    => $data['container_type_text'] ?: null,
            'buyer_own_reference'    => $data['buyer_own_reference'] ?: null,
            'notes'                  => $data['notes'] ?: null,
            'ip'                     => $ip,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM client_intake_submissions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function pending(): array
    {
        return Database::connection()
            ->query("SELECT * FROM client_intake_submissions WHERE status = 'pending' ORDER BY submitted_at ASC")
            ->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function recentResolved(int $limit = 30): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT cis.*, u.name AS reviewed_by_name, c.client_unique_number
             FROM client_intake_submissions cis
             LEFT JOIN users u ON u.id = cis.reviewed_by
             LEFT JOIN clients c ON c.id = cis.converted_client_id
             WHERE cis.status != 'pending'
             ORDER BY cis.reviewed_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function markConverted(int $id, int $clientId, int $reviewedBy): void
    {
        Database::connection()->prepare(
            "UPDATE client_intake_submissions
             SET status = 'converted', converted_client_id = :client_id, reviewed_by = :reviewed_by, reviewed_at = NOW()
             WHERE id = :id"
        )->execute(['client_id' => $clientId, 'reviewed_by' => $reviewedBy, 'id' => $id]);
    }

    public static function markRejected(int $id, int $reviewedBy, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE client_intake_submissions
             SET status = 'rejected', rejection_reason = :reason, reviewed_by = :reviewed_by, reviewed_at = NOW()
             WHERE id = :id"
        )->execute(['reason' => $reason, 'reviewed_by' => $reviewedBy, 'id' => $id]);
    }
}
