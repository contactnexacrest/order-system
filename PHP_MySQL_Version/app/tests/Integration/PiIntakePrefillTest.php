<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * The PI-details form used to start completely blank even though the
 * client had already given most of this same information at the
 * Quotation-stage intake — company name, address, consignee, VAT/tax no,
 * contact person, email, phone, country, COO type (all on the `clients`
 * row by the time a PI link is generated) and the shipping basics
 * (incoterm, port of discharge, container type) staff already set on the
 * order. findValidByToken() now folds those in as defaults for whichever
 * fields the client hasn't answered themselves yet.
 */
final class PiIntakePrefillTest extends DbTestCase
{
    private function setClientContactFields(int $clientId): void
    {
        Database::connection()->prepare(
            'UPDATE clients SET
                consignee_name = :consignee_name, consignee_address = :consignee_address,
                vat_eori_tax_no = :vat, contact_person = :contact, email = :email, phone = :phone,
                notify_party = :notify, country_of_destination = :country, coo_type = :coo
             WHERE id = :id'
        )->execute([
            'consignee_name'    => 'Same',
            'consignee_address' => 'Same',
            'vat'               => 'NL123456789B01',
            'contact'           => 'Jane Buyer',
            'email'             => 'jane@buyer.example',
            'phone'             => '+31-6-12345678',
            'notify'            => 'Acme Freight Forwarders',
            'country'           => 'Netherlands',
            'coo'               => 'Non-preferential',
            'id'                => $clientId,
        ]);
    }

    private function createLinkAndToken(int $orderId): string
    {
        return PiIntakeRepository::createLink($orderId, null);
    }

    public function testFirstVisitPrefillsFromClientAndOrderData(): void
    {
        $clientId = $this->createTestClient();
        $this->setClientContactFields($clientId);
        $orderId = $this->createTestOrder($clientId, 'FOB');
        Database::connection()->prepare(
            "UPDATE orders SET port_of_discharge_text = 'Rotterdam, Netherlands', container_type = '1x20ft' WHERE id = :id"
        )->execute(['id' => $orderId]);

        $token = $this->createLinkAndToken($orderId);
        $row = PiIntakeRepository::findValidByToken($token);

        self::assertNotNull($row);
        self::assertSame('PHPUnit Test Buyer Ltd', $row['company_legal_name'], 'company name must prefill from the clients row');
        self::assertSame('1 Test Street, Test City', $row['billing_address']);
        self::assertSame('Same', $row['consignee_name']);
        self::assertSame('Same', $row['consignee_address']);
        self::assertSame('NL123456789B01', $row['vat_eori_tax_no']);
        self::assertSame('Jane Buyer', $row['contact_person']);
        self::assertSame('jane@buyer.example', $row['email']);
        self::assertSame('+31-6-12345678', $row['phone']);
        self::assertSame('Acme Freight Forwarders', $row['notify_party']);
        self::assertSame('Netherlands', $row['country_of_destination']);
        self::assertSame('Non-preferential', $row['coo_type']);
        self::assertSame('Rotterdam, Netherlands', $row['port_of_discharge_text'], 'port of discharge must prefill from the order');
        self::assertSame('FOB', $row['incoterm_confirmed'], 'incoterm must prefill from the order');
        self::assertSame('1x20ft', $row['container_type_text']);
    }

    public function testClientsOwnPriorAnswerIsNeverOverwrittenByTheDefault(): void
    {
        $clientId = $this->createTestClient();
        $this->setClientContactFields($clientId);
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $token = $this->createLinkAndToken($orderId);

        // Simulate: client already submitted once with a deliberately
        // different company name (e.g. a trading-name correction), staff
        // rejected it for an unrelated reason, and the client is now
        // back on the same link to resubmit.
        $submission = PiIntakeRepository::findValidByToken($token);
        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name'            => 'Buyer Trading Co (corrected)',
            'billing_address'               => $submission['billing_address'],
            'consignee_name'                => $submission['consignee_name'],
            'consignee_address'             => $submission['consignee_address'],
            'vat_eori_tax_no'               => $submission['vat_eori_tax_no'],
            'contact_person'                => $submission['contact_person'],
            'email'                         => $submission['email'],
            'phone'                         => $submission['phone'],
            'notify_party'                  => $submission['notify_party'],
            'port_of_discharge_text'        => $submission['port_of_discharge_text'],
            'country_of_destination'        => $submission['country_of_destination'],
            'incoterm_confirmed'            => $submission['incoterm_confirmed'],
            'container_type_text'           => $submission['container_type_text'],
            'payment_terms_confirmation'    => 'CONFIRMED',
            'quotation_acceptance_reference' => 'We accept Quotation X',
            'coo_type'                      => $submission['coo_type'],
            'buyer_po_ref'                  => null,
            'changes_from_quotation'        => null,
            'special_document_requirements' => null,
        ], '127.0.0.1');
        Database::connection()->prepare("UPDATE pi_intake_submissions SET status = 'rejected' WHERE id = :id")
            ->execute(['id' => (int) $submission['id']]);

        $reloaded = PiIntakeRepository::findValidByToken($token);
        self::assertSame('Buyer Trading Co (corrected)', $reloaded['company_legal_name'], 'the client\'s own corrected answer must win over the clients-row default');
    }

    /**
     * Owner report (2026-10-25, a regression of the fix above): the PI
     * form was later split into structured billing / consignee / notify-
     * party fields that this prefill never covered, so they opened empty
     * even though the Quotation-stage intake had already captured them —
     * and the "same as" checkboxes always started ticked, hiding a
     * consignee that differs from the buyer. Checked on the rendered form,
     * not just the repository row.
     */
    public function testRenderedFormPrefillsStructuredAddressesAndSameAsChoices(): void
    {
        $clientId = $this->createTestClient();
        Database::connection()->prepare(
            "UPDATE clients SET
                billing_address_line1 = '12 Harbour Road', billing_address_line2 = 'Unit 4',
                billing_city = 'Rotterdam', billing_postcode = '3011 AA', billing_country = 'Netherlands',
                consignee_same_as_buyer = 0, consignee_name = 'Delta Warehousing BV',
                consignee_address_line1 = '8 Dock Street', consignee_city = 'Antwerp',
                consignee_postcode = '2000', consignee_country = 'Belgium',
                consignee_contact_person = 'Piet Jansen', consignee_phone = '+32-3-1234567',
                consignee_email = 'piet@delta.example', consignee_vat_eori_tax_no = 'BE0123456789',
                notify_party_same_as_consignee = 0, notify_party = 'Acme Freight Forwarders',
                notify_party_address_line1 = '1 Port Lane', notify_party_city = 'Hamburg',
                notify_party_country = 'Germany', notify_party_contact_person = 'Anna Schulz'
             WHERE id = :id"
        )->execute(['id' => $clientId]);
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $token = $this->createLinkAndToken($orderId);

        ob_start();
        (new \App\Controllers\PiIntakeController())->show(['token' => $token]);
        $html = (string) ob_get_clean();

        foreach ([
            'billing_address_line1' => '12 Harbour Road', 'billing_address_line2' => 'Unit 4', 'billing_city' => 'Rotterdam',
            'billing_postcode' => '3011 AA', 'billing_country' => 'Netherlands',
            'consignee_name' => 'Delta Warehousing BV', 'consignee_address_line1' => '8 Dock Street', 'consignee_city' => 'Antwerp',
            'consignee_postcode' => '2000', 'consignee_country' => 'Belgium', 'consignee_contact_person' => 'Piet Jansen',
            'consignee_phone' => '+32-3-1234567', 'consignee_email' => 'piet@delta.example', 'consignee_vat_eori_tax_no' => 'BE0123456789',
            'notify_party_address_line1' => '1 Port Lane', 'notify_party_city' => 'Hamburg', 'notify_party_country' => 'Germany',
            'notify_party_contact_person' => 'Anna Schulz',
        ] as $field => $value) {
            self::assertMatchesRegularExpression('/name="' . $field . '" value="' . preg_quote($value, '/') . '"/', $html, "{$field} must be prefilled");
        }
        self::assertMatchesRegularExpression('/name="consignee_same_as_buyer" value="1"\s*>/', $html, 'a consignee that differs from the buyer must not start as "same as buyer"');
        self::assertMatchesRegularExpression('/name="notify_party_same_as_consignee" value="1"\s*>/', $html);
    }

    public function testAResubmittingClientKeepsTheirOwnSameAsChoice(): void
    {
        $clientId = $this->createTestClient();
        Database::connection()->exec("UPDATE clients SET consignee_same_as_buyer = 0 WHERE id = {$clientId}");
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $token = $this->createLinkAndToken($orderId);
        // Submitted once with "same as buyer" ticked, then rejected by staff.
        Database::connection()->exec(
            "UPDATE pi_intake_submissions SET consignee_same_as_buyer = 1, submitted_at = NOW(), status = 'rejected' WHERE order_id = {$orderId}"
        );

        $row = PiIntakeRepository::findValidByToken($token);

        self::assertSame(1, (int) $row['consignee_same_as_buyer']);
    }

    /**
     * The client's own Quotation-stage port/container answers live on their
     * accepted intake, not the client record — the PI form falls back to
     * them when the order itself has none (e.g. an order created by picking
     * the client from the dropdown rather than from the intake).
     */
    public function testPortFallsBackToTheClientsQuotationStageAnswer(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $pdo = Database::connection();
        $pdo->exec("UPDATE orders SET port_of_discharge_text = NULL, port_of_discharge_name = NULL, container_type = NULL WHERE id = {$orderId}");
        $pdo->prepare(
            "INSERT INTO client_intake_submissions (company_legal_name, billing_address, contact_person, email, country_of_destination, port_of_discharge_text, container_type_text, status, converted_client_id, access_token_hash, access_token_expires_at)
             VALUES ('PHPUnit Test Buyer Ltd', '1 Test Street', 'Test Contact', 'intake@buyer.test', 'Belgium', 'Antwerp, Belgium', '1x40ft HC', 'converted', :client_id, :hash, NOW() + INTERVAL 1 DAY)"
        )->execute(['client_id' => $clientId, 'hash' => bin2hex(random_bytes(32))]);

        $row = PiIntakeRepository::findValidByToken($this->createLinkAndToken($orderId));

        self::assertSame('Antwerp, Belgium', $row['port_of_discharge_text']);
        self::assertSame('1x40ft HC', $row['container_type_text']);
    }
}
