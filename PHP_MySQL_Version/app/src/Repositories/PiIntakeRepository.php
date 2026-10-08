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
            'INSERT INTO pi_intake_submissions (order_id, access_token_hash, access_token_plain, access_token_expires_at, created_by)
             VALUES (:order_id, :hash, :plain, :expires, :created_by)'
        );
        $stmt->execute([
            'order_id'    => $orderId,
            'hash'        => hash('sha256', $rawToken),
            'plain'       => $rawToken,
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
            "SELECT pis.*, o.order_reference, c.company_legal_name AS client_company_legal_name,
                    c.billing_address AS client_billing_address,
                    c.consignee_name AS client_consignee_name,
                    c.consignee_address AS client_consignee_address,
                    c.vat_eori_tax_no AS client_vat_eori_tax_no,
                    c.contact_person AS client_contact_person,
                    c.email AS client_email,
                    c.phone AS client_phone,
                    c.notify_party AS client_notify_party,
                    c.country_of_destination AS client_country_of_destination,
                    c.coo_type AS client_coo_type,
                    COALESCE(p.name, o.port_of_discharge_text) AS order_port_of_discharge,
                    i.code AS order_incoterm_code,
                    o.container_type AS order_container_type,
                    pp.advance_trigger_text AS order_advance_trigger_text,
                    pp.balance_trigger_wording AS order_balance_trigger_wording,
                    COALESCE(o.advance_pct_override, pp.advance_pct) AS order_advance_pct,
                    COALESCE(o.balance_pct_override, pp.balance_pct) AS order_balance_pct,
                    COALESCE(o.balance_trigger_option_override, pp.balance_trigger_option) AS order_balance_trigger_option,
                    COALESCE(o.balance_days_override, pp.balance_days) AS order_balance_days
             FROM pi_intake_submissions pis
             JOIN orders o ON o.id = pis.order_id
             JOIN clients c ON c.id = o.client_id
             LEFT JOIN ports p ON p.id = o.port_of_discharge_id
             LEFT JOIN incoterms i ON i.id = o.incoterm_id
             JOIN payment_presets pp ON pp.id = o.payment_preset_id
             WHERE pis.access_token_hash = :hash AND pis.access_token_expires_at > NOW()
               AND pis.status IN ('awaiting_client', 'rejected')"
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch() ?: null;
        if ($row === null) {
            return null;
        }

        // Prefill: a client who already provided these exact contact
        // details at the Quotation-stage form shouldn't have to retype
        // them here. Only fills in fields the client hasn't already
        // answered themselves — on a rejected-and-resubmitted link, the
        // client's own prior answer always wins over the client/order
        // default, since they may have deliberately corrected it.
        $prefillMap = [
            'company_legal_name'     => 'client_company_legal_name',
            'billing_address'        => 'client_billing_address',
            'consignee_name'         => 'client_consignee_name',
            'consignee_address'      => 'client_consignee_address',
            'vat_eori_tax_no'        => 'client_vat_eori_tax_no',
            'contact_person'         => 'client_contact_person',
            'email'                  => 'client_email',
            'phone'                  => 'client_phone',
            'notify_party'           => 'client_notify_party',
            'country_of_destination' => 'client_country_of_destination',
            'coo_type'               => 'client_coo_type',
            'port_of_discharge_text' => 'order_port_of_discharge',
            'incoterm_confirmed'     => 'order_incoterm_code',
            'container_type_text'    => 'order_container_type',
        ];
        foreach ($prefillMap as $field => $defaultKey) {
            if (($row[$field] ?? '') === '' || $row[$field] === null) {
                $row[$field] = $row[$defaultKey] ?? '';
            }
        }

        return $row;
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
                vat_eori_tax_no = :vat_eori_tax_no, contact_person = :contact_person, email = :email,
                phone = :phone,
                port_of_discharge_text = :port_of_discharge_text, country_of_destination = :country_of_destination,
                incoterm_confirmed = :incoterm_confirmed, container_type_text = :container_type_text,
                payment_terms_confirmation = :payment_terms_confirmation,
                quotation_acceptance_reference = :quotation_acceptance_reference,
                coo_type = :coo_type, buyer_po_ref = :buyer_po_ref,
                changes_from_quotation = :changes_from_quotation,
                special_document_requirements = :special_document_requirements,
                consignee_same_as_buyer = :consignee_same_as_buyer, consignee_name = :consignee_name,
                consignee_address_line1 = :consignee_address_line1, consignee_address_line2 = :consignee_address_line2,
                consignee_city = :consignee_city, consignee_postcode = :consignee_postcode,
                consignee_country = :consignee_country, consignee_vat_eori_tax_no = :consignee_vat_eori_tax_no,
                consignee_contact_person = :consignee_contact_person, consignee_phone = :consignee_phone,
                consignee_email = :consignee_email,
                notify_party_same_as_consignee = :notify_party_same_as_consignee, notify_party = :notify_party,
                notify_party_address_line1 = :notify_party_address_line1, notify_party_address_line2 = :notify_party_address_line2,
                notify_party_city = :notify_party_city, notify_party_postcode = :notify_party_postcode,
                notify_party_country = :notify_party_country, notify_party_contact_person = :notify_party_contact_person,
                notify_party_phone = :notify_party_phone, notify_party_email = :notify_party_email,
                status = \'pending_review\', submitted_at = NOW(), submitted_ip = :ip,
                rejection_reason = NULL
             WHERE id = :id'
        )->execute([
            'company_legal_name'             => $data['company_legal_name'],
            'billing_address'                => $data['billing_address'],
            'vat_eori_tax_no'                => $data['vat_eori_tax_no'],
            'contact_person'                 => $data['contact_person'],
            'email'                          => $data['email'],
            'phone'                          => $data['phone'],
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
            'consignee_same_as_buyer'    => (int) ($data['consignee_same_as_buyer'] ?? 1),
            'consignee_name'              => $data['consignee_name'] ?? null,
            'consignee_address_line1'     => $data['consignee_address_line1'] ?? null,
            'consignee_address_line2'     => $data['consignee_address_line2'] ?? null,
            'consignee_city'              => $data['consignee_city'] ?? null,
            'consignee_postcode'          => $data['consignee_postcode'] ?? null,
            'consignee_country'           => $data['consignee_country'] ?? null,
            'consignee_vat_eori_tax_no'   => $data['consignee_vat_eori_tax_no'] ?? null,
            'consignee_contact_person'    => $data['consignee_contact_person'] ?? null,
            'consignee_phone'             => $data['consignee_phone'] ?? null,
            'consignee_email'             => $data['consignee_email'] ?? null,
            'notify_party_same_as_consignee' => (int) ($data['notify_party_same_as_consignee'] ?? 1),
            'notify_party'                 => $data['notify_party'] ?? null,
            'notify_party_address_line1'  => $data['notify_party_address_line1'] ?? null,
            'notify_party_address_line2'  => $data['notify_party_address_line2'] ?? null,
            'notify_party_city'           => $data['notify_party_city'] ?? null,
            'notify_party_postcode'       => $data['notify_party_postcode'] ?? null,
            'notify_party_country'        => $data['notify_party_country'] ?? null,
            'notify_party_contact_person' => $data['notify_party_contact_person'] ?? null,
            'notify_party_phone'          => $data['notify_party_phone'] ?? null,
            'notify_party_email'          => $data['notify_party_email'] ?? null,
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

    /** Nav-badge count — live, not cached, so it self-decrements the moment a submission is accepted/rejected. */
    public static function pendingReviewCount(): int
    {
        return (int) Database::connection()
            ->query("SELECT COUNT(*) AS c FROM pi_intake_submissions WHERE status = 'pending_review'")
            ->fetch()['c'];
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
