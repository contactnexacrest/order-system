<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class ClientRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT * FROM clients WHERE is_active = 1 ORDER BY company_legal_name')
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data, int $createdBy, string $clientUniqueNumber): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO clients
                (client_unique_number, company_legal_name, billing_address, consignee_name, consignee_address,
                 vat_eori_tax_no, contact_person, email, phone, country_of_destination, coo_type, notify_party,
                 created_by)
             VALUES
                (:client_unique_number, :company_legal_name, :billing_address, :consignee_name, :consignee_address,
                 :vat_eori_tax_no, :contact_person, :email, :phone, :country_of_destination, :coo_type, :notify_party,
                 :created_by)'
        );
        $stmt->execute([
            'client_unique_number'    => $clientUniqueNumber,
            'company_legal_name'      => $data['company_legal_name'],
            'billing_address'         => $data['billing_address'],
            'consignee_name'          => $data['consignee_name'] ?: 'SAME',
            'consignee_address'       => $data['consignee_address'] ?: 'SAME',
            'vat_eori_tax_no'         => $data['vat_eori_tax_no'] ?? null,
            'contact_person'          => $data['contact_person'] ?? null,
            'email'                   => $data['email'] ?? null,
            'phone'                   => $data['phone'] ?? null,
            'country_of_destination'  => $data['country_of_destination'] ?? null,
            'coo_type'                => $data['coo_type'] ?? 'TBC',
            'notify_party'            => $data['notify_party'] ?? null,
            'created_by'              => $createdBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Phase E follow-up — flags a client as Sample Data Playground content (see SampleDataService). */
    public static function markSample(int $id): void
    {
        Database::connection()->prepare('UPDATE clients SET is_sample_data = 1 WHERE id = :id')->execute(['id' => $id]);
    }

    /** Test Mode (docs/schema.sql Section V) — mirrors markSample()'s pattern. */
    public static function markTest(int $id): void
    {
        Database::connection()->prepare('UPDATE clients SET is_test_data = 1 WHERE id = :id')->execute(['id' => $id]);
    }
}
