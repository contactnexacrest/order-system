<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class ClientRepository
{
    /** @return array<int, array<string,mixed>> */
    /** Also carries order_count — the card-grid list view (clients/index.php) shows it per client. */
    public static function all(): array
    {
        return Database::connection()
            ->query(
                'SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.client_id = c.id AND o.is_archived = 0) AS order_count
                 FROM clients c WHERE c.is_active = 1 ORDER BY c.company_legal_name'
            )
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** CA / Accounting module (Phase 3) — caches the Zoho Books contact_id created for this client on first sync. */
    public static function setZohoContactId(int $id, string $zohoContactId): void
    {
        Database::connection()->prepare(
            'UPDATE clients SET zoho_contact_id = :zoho_contact_id WHERE id = :id'
        )->execute(['zoho_contact_id' => $zohoContactId, 'id' => $id]);
    }

    /** @return array<int, array<string,mixed>> deactivated clients — never shown in all(), still directly viewable */
    public static function allInactive(): array
    {
        return Database::connection()
            ->query('SELECT * FROM clients WHERE is_active = 0 ORDER BY company_legal_name')
            ->fetchAll();
    }

    public static function create(array $data, int $createdBy, string $clientUniqueNumber): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO clients
                (client_unique_number, company_legal_name, billing_address,
                 billing_address_line1, billing_address_line2, billing_city, billing_postcode, billing_country,
                 consignee_name, consignee_address, consignee_same_as_buyer,
                 consignee_address_line1, consignee_address_line2, consignee_city, consignee_postcode,
                 consignee_country, consignee_vat_eori_tax_no, consignee_contact_person, consignee_phone, consignee_email,
                 vat_eori_tax_no, contact_person, email, phone, country_of_destination, coo_type,
                 notify_party, notify_party_same_as_consignee,
                 notify_party_address_line1, notify_party_address_line2, notify_party_city, notify_party_postcode,
                 notify_party_country, notify_party_contact_person, notify_party_phone, notify_party_email,
                 created_by)
             VALUES
                (:client_unique_number, :company_legal_name, :billing_address,
                 :billing_address_line1, :billing_address_line2, :billing_city, :billing_postcode, :billing_country,
                 :consignee_name, :consignee_address, :consignee_same_as_buyer,
                 :consignee_address_line1, :consignee_address_line2, :consignee_city, :consignee_postcode,
                 :consignee_country, :consignee_vat_eori_tax_no, :consignee_contact_person, :consignee_phone, :consignee_email,
                 :vat_eori_tax_no, :contact_person, :email, :phone, :country_of_destination, :coo_type,
                 :notify_party, :notify_party_same_as_consignee,
                 :notify_party_address_line1, :notify_party_address_line2, :notify_party_city, :notify_party_postcode,
                 :notify_party_country, :notify_party_contact_person, :notify_party_phone, :notify_party_email,
                 :created_by)'
        );
        $stmt->execute([
            'client_unique_number'    => $clientUniqueNumber,
            'company_legal_name'      => $data['company_legal_name'],
            'billing_address'         => $data['billing_address'],
            'billing_address_line1'   => $data['billing_address_line1'] ?? null,
            'billing_address_line2'   => $data['billing_address_line2'] ?? null,
            'billing_city'            => $data['billing_city'] ?? null,
            'billing_postcode'        => $data['billing_postcode'] ?? null,
            'billing_country'         => $data['billing_country'] ?? null,
            'consignee_name'          => ($data['consignee_name'] ?? null) ?: ((int) ($data['consignee_same_as_buyer'] ?? 1) === 1 ? null : 'SAME'),
            'consignee_address'       => $data['consignee_address'] ?? null,
            'consignee_same_as_buyer' => (int) ($data['consignee_same_as_buyer'] ?? 1),
            'consignee_address_line1' => $data['consignee_address_line1'] ?? null,
            'consignee_address_line2' => $data['consignee_address_line2'] ?? null,
            'consignee_city'          => $data['consignee_city'] ?? null,
            'consignee_postcode'      => $data['consignee_postcode'] ?? null,
            'consignee_country'       => $data['consignee_country'] ?? null,
            'consignee_vat_eori_tax_no' => $data['consignee_vat_eori_tax_no'] ?? null,
            'consignee_contact_person' => $data['consignee_contact_person'] ?? null,
            'consignee_phone'         => $data['consignee_phone'] ?? null,
            'consignee_email'         => $data['consignee_email'] ?? null,
            'vat_eori_tax_no'         => $data['vat_eori_tax_no'] ?? null,
            'contact_person'          => $data['contact_person'] ?? null,
            'email'                   => $data['email'] ?? null,
            'phone'                   => $data['phone'] ?? null,
            'country_of_destination'  => $data['country_of_destination'] ?? null,
            'coo_type'                => $data['coo_type'] ?? 'To Be Confirmed',
            'notify_party'            => $data['notify_party'] ?? null,
            'notify_party_same_as_consignee' => (int) ($data['notify_party_same_as_consignee'] ?? 1),
            'notify_party_address_line1' => $data['notify_party_address_line1'] ?? null,
            'notify_party_address_line2' => $data['notify_party_address_line2'] ?? null,
            'notify_party_city'       => $data['notify_party_city'] ?? null,
            'notify_party_postcode'   => $data['notify_party_postcode'] ?? null,
            'notify_party_country'    => $data['notify_party_country'] ?? null,
            'notify_party_contact_person' => $data['notify_party_contact_person'] ?? null,
            'notify_party_phone'      => $data['notify_party_phone'] ?? null,
            'notify_party_email'      => $data['notify_party_email'] ?? null,
            'created_by'              => $createdBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        Database::connection()->prepare(
            'UPDATE clients SET
                company_legal_name = :company_legal_name,
                billing_address = :billing_address,
                billing_address_line1 = :billing_address_line1,
                billing_address_line2 = :billing_address_line2,
                billing_city = :billing_city,
                billing_postcode = :billing_postcode,
                billing_country = :billing_country,
                consignee_name = :consignee_name,
                consignee_address = :consignee_address,
                consignee_same_as_buyer = :consignee_same_as_buyer,
                consignee_address_line1 = :consignee_address_line1,
                consignee_address_line2 = :consignee_address_line2,
                consignee_city = :consignee_city,
                consignee_postcode = :consignee_postcode,
                consignee_country = :consignee_country,
                consignee_vat_eori_tax_no = :consignee_vat_eori_tax_no,
                consignee_contact_person = :consignee_contact_person,
                consignee_phone = :consignee_phone,
                consignee_email = :consignee_email,
                vat_eori_tax_no = :vat_eori_tax_no,
                contact_person = :contact_person,
                email = :email,
                phone = :phone,
                country_of_destination = :country_of_destination,
                coo_type = :coo_type,
                notify_party = :notify_party,
                notify_party_same_as_consignee = :notify_party_same_as_consignee,
                notify_party_address_line1 = :notify_party_address_line1,
                notify_party_address_line2 = :notify_party_address_line2,
                notify_party_city = :notify_party_city,
                notify_party_postcode = :notify_party_postcode,
                notify_party_country = :notify_party_country,
                notify_party_contact_person = :notify_party_contact_person,
                notify_party_phone = :notify_party_phone,
                notify_party_email = :notify_party_email
             WHERE id = :id'
        )->execute([
            'company_legal_name'      => $data['company_legal_name'],
            'billing_address'         => $data['billing_address'],
            'billing_address_line1'   => $data['billing_address_line1'] ?? null,
            'billing_address_line2'   => $data['billing_address_line2'] ?? null,
            'billing_city'            => $data['billing_city'] ?? null,
            'billing_postcode'        => $data['billing_postcode'] ?? null,
            'billing_country'         => $data['billing_country'] ?? null,
            'consignee_name'          => $data['consignee_name'] ?? null,
            'consignee_address'       => $data['consignee_address'] ?? null,
            'consignee_same_as_buyer' => (int) ($data['consignee_same_as_buyer'] ?? 1),
            'consignee_address_line1' => $data['consignee_address_line1'] ?? null,
            'consignee_address_line2' => $data['consignee_address_line2'] ?? null,
            'consignee_city'          => $data['consignee_city'] ?? null,
            'consignee_postcode'      => $data['consignee_postcode'] ?? null,
            'consignee_country'       => $data['consignee_country'] ?? null,
            'consignee_vat_eori_tax_no' => $data['consignee_vat_eori_tax_no'] ?? null,
            'consignee_contact_person' => $data['consignee_contact_person'] ?? null,
            'consignee_phone'         => $data['consignee_phone'] ?? null,
            'consignee_email'         => $data['consignee_email'] ?? null,
            'vat_eori_tax_no'         => $data['vat_eori_tax_no'] ?? null,
            'contact_person'          => $data['contact_person'] ?? null,
            'email'                   => $data['email'] ?? null,
            'phone'                   => $data['phone'] ?? null,
            'country_of_destination'  => $data['country_of_destination'] ?? null,
            'coo_type'                => $data['coo_type'] ?? null,
            'notify_party'            => $data['notify_party'] ?? null,
            'notify_party_same_as_consignee' => (int) ($data['notify_party_same_as_consignee'] ?? 1),
            'notify_party_address_line1' => $data['notify_party_address_line1'] ?? null,
            'notify_party_address_line2' => $data['notify_party_address_line2'] ?? null,
            'notify_party_city'       => $data['notify_party_city'] ?? null,
            'notify_party_postcode'   => $data['notify_party_postcode'] ?? null,
            'notify_party_country'    => $data['notify_party_country'] ?? null,
            'notify_party_contact_person' => $data['notify_party_contact_person'] ?? null,
            'notify_party_phone'      => $data['notify_party_phone'] ?? null,
            'notify_party_email'      => $data['notify_party_email'] ?? null,
            'id'                      => $id,
        ]);
    }

    /**
     * Batch 3 #12 — deliberately its own method/endpoint, never routed through
     * update() above: a client-level agreement T&C footer is a staff-authored
     * annotation of an externally-negotiated term, not a client-submitted
     * identity detail, so it must stay editable even after is_data_locked.
     */
    public static function updateAgreementFooterText(int $id, ?string $text): void
    {
        Database::connection()->prepare(
            'UPDATE clients SET agreement_footer_text = :agreement_footer_text WHERE id = :id'
        )->execute(['agreement_footer_text' => $text, 'id' => $id]);
    }

    /**
     * Item 2 — uploading a new agreement file resets the agreement to a
     * fresh "active" state: force_expired is always cleared (a newly
     * uploaded file is never force-expired on arrival), and the expiry
     * date is set from whatever staff entered (NULL means no automatic
     * expiry — only Force Expire can end it then).
     */
    public static function setAgreementFile(int $id, string $filePath, string $originalFilename, ?string $expiryDate): void
    {
        Database::connection()->prepare(
            'UPDATE clients SET
                agreement_file_path = :path, agreement_file_original_name = :original_name,
                agreement_uploaded_at = NOW(), agreement_expiry_date = :expiry_date, agreement_force_expired = 0
             WHERE id = :id'
        )->execute(['path' => $filePath, 'original_name' => $originalFilename, 'expiry_date' => $expiryDate, 'id' => $id]);
    }

    public static function setAgreementForceExpired(int $id, bool $forced): void
    {
        Database::connection()->prepare('UPDATE clients SET agreement_force_expired = :forced WHERE id = :id')
            ->execute(['forced' => $forced ? 1 : 0, 'id' => $id]);
    }

    /**
     * Resets the expiry date and clears force_expired; optionally also
     * replaces the file itself (staff may renew with just a new date, if
     * the underlying signed document hasn't actually changed).
     */
    public static function renewAgreement(int $id, ?string $expiryDate, ?string $filePath = null, ?string $originalFilename = null): void
    {
        if ($filePath !== null) {
            Database::connection()->prepare(
                'UPDATE clients SET
                    agreement_file_path = :path, agreement_file_original_name = :original_name,
                    agreement_uploaded_at = NOW(), agreement_expiry_date = :expiry_date, agreement_force_expired = 0
                 WHERE id = :id'
            )->execute(['path' => $filePath, 'original_name' => $originalFilename, 'expiry_date' => $expiryDate, 'id' => $id]);
            return;
        }
        Database::connection()->prepare(
            'UPDATE clients SET agreement_expiry_date = :expiry_date, agreement_force_expired = 0 WHERE id = :id'
        )->execute(['expiry_date' => $expiryDate, 'id' => $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::connection()->prepare('UPDATE clients SET is_active = :active WHERE id = :id')
            ->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    /** docs/schema.sql Section AV — per-client gate on staff impersonation, independent of the global company_settings switch. */
    public static function setAllowStaffImpersonation(int $id, bool $allow): void
    {
        Database::connection()->prepare('UPDATE clients SET allow_staff_impersonation = :allow WHERE id = :id')
            ->execute(['allow' => $allow ? 1 : 0, 'id' => $id]);
    }

    /**
     * Permanent — nothing in this app ever sets is_data_locked back to 0.
     * Called once, from whichever of the two trigger points happens first:
     * the client's own PI-details consent, or staff recording the advance
     * remittance if the client never gets there first (docs/schema.sql
     * Section AC).
     */
    public static function lockData(int $id, string $reason): void
    {
        Database::connection()->prepare(
            'UPDATE clients SET is_data_locked = 1, data_locked_at = NOW(), data_locked_reason = :reason WHERE id = :id AND is_data_locked = 0'
        )->execute(['reason' => $reason, 'id' => $id]);
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
