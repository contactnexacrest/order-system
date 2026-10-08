<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\ClientRepository;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * Section BB — the client's own self-service Consignee (QT stage) and
 * Consignee+Notify Party (PI stage) "Same as X?" split on the public
 * intake forms, mirroring the structured fields staff already had on the
 * admin Clients form (Section BA). These tests exercise the repository
 * layer directly (the controllers' collectPartyFields()/
 * collectConsigneeFields() helpers just build this same array from
 * $_POST, which PHPUnit has no superglobal context to simulate against).
 */
final class IntakeConsigneeNotifyPartySplitTest extends DbTestCase
{
    public function testClientIntakeSubmissionStoresIndependentConsignee(): void
    {
        $id = ClientIntakeRepository::create([
            'company_legal_name'     => 'QT Test Buyer Ltd',
            'billing_address'        => '1 Buyer Street',
            'vat_eori_tax_no'        => 'GB123456789',
            'contact_person'         => 'John Buyer',
            'email'                  => 'john@buyer.example',
            'phone'                  => '+44 20 1234 5678',
            'country_of_destination' => 'United Kingdom',
            'port_of_discharge_text' => 'Felixstowe',
            'coo_type'               => 'Non-preferential',
            'incoterm_preference'    => 'FOB',
            'container_type_text'    => null,
            'buyer_own_reference'    => null,
            'notes'                  => null,
            'consignee_same_as_buyer'    => 0,
            'consignee_name'              => 'ABC Memorial Stones Ltd',
            'consignee_address_line1'     => '99 Consignee Road',
            'consignee_address_line2'     => null,
            'consignee_city'               => 'Leeds',
            'consignee_postcode'           => 'LS1 1AA',
            'consignee_country'            => 'United Kingdom',
            'consignee_vat_eori_tax_no'    => 'GB987654321',
            'consignee_contact_person'     => 'Alice Consignee',
            'consignee_phone'              => '+44 20 9999 0000',
            'consignee_email'              => 'alice@consignee.example',
        ], '127.0.0.1');

        $row = ClientIntakeRepository::find($id);
        self::assertSame(0, (int) $row['consignee_same_as_buyer']);
        self::assertSame('ABC Memorial Stones Ltd', $row['consignee_name']);
        self::assertSame('Leeds', $row['consignee_city']);
        self::assertSame('alice@consignee.example', $row['consignee_email']);
    }

    public function testClientIntakeSubmissionDefaultsConsigneeSameAsBuyer(): void
    {
        $id = ClientIntakeRepository::create([
            'company_legal_name'     => 'QT Test Buyer 2 Ltd',
            'billing_address'        => '2 Buyer Street',
            'vat_eori_tax_no'        => 'GB111111111',
            'contact_person'         => 'Jo Buyer',
            'email'                  => 'jo@buyer2.example',
            'phone'                  => null,
            'country_of_destination' => 'United Kingdom',
            'port_of_discharge_text' => null,
            'coo_type'               => null,
            'incoterm_preference'    => 'FOB',
            'container_type_text'    => null,
            'buyer_own_reference'    => null,
            'notes'                  => null,
            'consignee_same_as_buyer' => 1,
            'consignee_name' => null, 'consignee_address_line1' => null, 'consignee_address_line2' => null,
            'consignee_city' => null, 'consignee_postcode' => null, 'consignee_country' => null,
            'consignee_vat_eori_tax_no' => null, 'consignee_contact_person' => null,
            'consignee_phone' => null, 'consignee_email' => null,
        ], null);

        $row = ClientIntakeRepository::find($id);
        self::assertSame(1, (int) $row['consignee_same_as_buyer']);
        self::assertNull($row['consignee_name']);
    }

    public function testAcceptingQuotationIntakeWithIndependentConsigneeCreatesStructuredClientRow(): void
    {
        $id = ClientIntakeRepository::create([
            'company_legal_name'     => 'QT Accept Test Ltd',
            'billing_address'        => '3 Buyer Street',
            'vat_eori_tax_no'        => 'GB222222222',
            'contact_person'         => 'Sam Buyer',
            'email'                  => 'sam@buyer3.example',
            'phone'                  => null,
            'country_of_destination' => 'United Kingdom',
            'port_of_discharge_text' => null,
            'coo_type'               => 'Non-preferential',
            'incoterm_preference'    => 'FOB',
            'container_type_text'    => null,
            'buyer_own_reference'    => null,
            'notes'                  => null,
            'consignee_same_as_buyer'    => 0,
            'consignee_name'              => 'Independent Consignee Co',
            'consignee_address_line1'     => '5 Consignee Ave',
            'consignee_address_line2'     => null,
            'consignee_city'               => 'Manchester',
            'consignee_postcode'           => 'M1 1AA',
            'consignee_country'            => 'United Kingdom',
            'consignee_vat_eori_tax_no'    => 'GB333333333',
            'consignee_contact_person'     => 'Pat Consignee',
            'consignee_phone'              => '+44 161 000 0000',
            'consignee_email'              => 'pat@consignee3.example',
        ], null);

        $submission = ClientIntakeRepository::find($id);
        $user = $this->createTestUser('Admin');

        $consigneeSameAsBuyer = (int) ($submission['consignee_same_as_buyer'] ?? 1) === 1;
        $clientId = ClientRepository::create([
            'company_legal_name'     => $submission['company_legal_name'],
            'billing_address'        => $submission['billing_address'],
            'vat_eori_tax_no'        => $submission['vat_eori_tax_no'],
            'contact_person'         => $submission['contact_person'],
            'email'                  => $submission['email'],
            'phone'                  => $submission['phone'],
            'country_of_destination' => $submission['country_of_destination'],
            'coo_type'               => $submission['coo_type'] ?: 'To Be Confirmed',
            'consignee_same_as_buyer' => $consigneeSameAsBuyer ? 1 : 0,
            'consignee_name'              => $consigneeSameAsBuyer ? null : $submission['consignee_name'],
            'consignee_address_line1'     => $consigneeSameAsBuyer ? null : $submission['consignee_address_line1'],
            'consignee_address_line2'     => $consigneeSameAsBuyer ? null : $submission['consignee_address_line2'],
            'consignee_city'              => $consigneeSameAsBuyer ? null : $submission['consignee_city'],
            'consignee_postcode'          => $consigneeSameAsBuyer ? null : $submission['consignee_postcode'],
            'consignee_country'           => $consigneeSameAsBuyer ? null : $submission['consignee_country'],
            'consignee_vat_eori_tax_no'   => $consigneeSameAsBuyer ? null : $submission['consignee_vat_eori_tax_no'],
            'consignee_contact_person'    => $consigneeSameAsBuyer ? null : $submission['consignee_contact_person'],
            'consignee_phone'             => $consigneeSameAsBuyer ? null : $submission['consignee_phone'],
            'consignee_email'             => $consigneeSameAsBuyer ? null : $submission['consignee_email'],
        ], $user, 'NC/SC/TEST/' . bin2hex(random_bytes(3)));

        $client = ClientRepository::find($clientId);
        self::assertSame(0, (int) $client['consignee_same_as_buyer']);
        self::assertSame('Independent Consignee Co', $client['consignee_name']);
        self::assertSame('Manchester', $client['consignee_city']);
        self::assertSame(1, (int) $client['notify_party_same_as_consignee'], 'QT stage never collects Notify Party — must stay at its default');
    }

    public function testPiIntakeSubmitPersistsConsigneeAndNotifyPartyStructuredFields(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name'             => 'PI Test Buyer Ltd',
            'billing_address'                => '1 Buyer Street',
            'vat_eori_tax_no'                => 'GB444444444',
            'contact_person'                 => 'Kim Buyer',
            'email'                          => 'kim@buyer.example',
            'phone'                          => '+44 20 1111 2222',
            'port_of_discharge_text'         => 'Felixstowe',
            'country_of_destination'         => 'United Kingdom',
            'incoterm_confirmed'             => 'FOB',
            'container_type_text'            => null,
            'payment_terms_confirmation'      => 'CONFIRMED',
            'quotation_acceptance_reference'  => 'We accept Quotation X',
            'coo_type'                        => 'Non-preferential',
            'buyer_po_ref'                    => null,
            'changes_from_quotation'          => null,
            'special_document_requirements'   => null,
            'consignee_same_as_buyer'        => 0,
            'consignee_name'                  => 'PI Consignee Co',
            'consignee_address_line1'         => '7 Consignee Lane',
            'consignee_address_line2'         => null,
            'consignee_city'                   => 'Bristol',
            'consignee_postcode'               => 'BS1 1AA',
            'consignee_country'                => 'United Kingdom',
            'consignee_vat_eori_tax_no'        => 'GB555555555',
            'consignee_contact_person'         => 'Robin Consignee',
            'consignee_phone'                  => '+44 117 000 0000',
            'consignee_email'                  => 'robin@consignee.example',
            'notify_party_same_as_consignee'  => 0,
            'notify_party'                     => 'Acme Freight Forwarders',
            'notify_party_address_line1'      => '9 Freight Way',
            'notify_party_address_line2'      => null,
            'notify_party_city'                => 'Southampton',
            'notify_party_postcode'            => 'SO1 1AA',
            'notify_party_country'             => 'United Kingdom',
            'notify_party_contact_person'     => 'Taylor Notify',
            'notify_party_phone'               => '+44 23 000 0000',
            'notify_party_email'               => 'taylor@notify.example',
        ], '127.0.0.1');

        $reloaded = PiIntakeRepository::find((int) $submission['id']);
        self::assertSame(0, (int) $reloaded['consignee_same_as_buyer']);
        self::assertSame('PI Consignee Co', $reloaded['consignee_name']);
        self::assertSame('Bristol', $reloaded['consignee_city']);
        self::assertSame(0, (int) $reloaded['notify_party_same_as_consignee']);
        self::assertSame('Acme Freight Forwarders', $reloaded['notify_party']);
        self::assertSame('Southampton', $reloaded['notify_party_city']);
        self::assertSame('taylor@notify.example', $reloaded['notify_party_email']);
    }

    public function testPiIntakeAcceptAppliesFullStructuredSplitToClientRow(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name'             => 'PI Accept Buyer Ltd',
            'billing_address'                => '1 Buyer Street',
            'vat_eori_tax_no'                => 'GB666666666',
            'contact_person'                 => 'Lee Buyer',
            'email'                          => 'lee@buyer.example',
            'phone'                          => '+44 20 3333 4444',
            'port_of_discharge_text'         => 'Felixstowe',
            'country_of_destination'         => 'United Kingdom',
            'incoterm_confirmed'             => 'FOB',
            'container_type_text'            => null,
            'payment_terms_confirmation'      => 'CONFIRMED',
            'quotation_acceptance_reference'  => 'We accept Quotation Y',
            'coo_type'                        => 'Non-preferential',
            'buyer_po_ref'                    => null,
            'changes_from_quotation'          => null,
            'special_document_requirements'   => null,
            'consignee_same_as_buyer'        => 0,
            'consignee_name'                  => 'Accept Consignee Co',
            'consignee_address_line1'         => '11 Consignee Close',
            'consignee_address_line2'         => null,
            'consignee_city'                   => 'Cardiff',
            'consignee_postcode'               => 'CF1 1AA',
            'consignee_country'                => 'United Kingdom',
            'consignee_vat_eori_tax_no'        => 'GB777777777',
            'consignee_contact_person'         => 'Morgan Consignee',
            'consignee_phone'                  => '+44 29 000 0000',
            'consignee_email'                  => 'morgan@consignee.example',
            'notify_party_same_as_consignee'  => 1,
            'notify_party' => null, 'notify_party_address_line1' => null, 'notify_party_address_line2' => null,
            'notify_party_city' => null, 'notify_party_postcode' => null, 'notify_party_country' => null,
            'notify_party_contact_person' => null, 'notify_party_phone' => null, 'notify_party_email' => null,
        ], '127.0.0.1');

        $submission = PiIntakeRepository::find((int) $submission['id']);
        Database::connection()->prepare("UPDATE pi_intake_submissions SET status = 'pending_review' WHERE id = :id")
            ->execute(['id' => (int) $submission['id']]);
        $submission = PiIntakeRepository::find((int) $submission['id']);

        ClientRepository::update($clientId, [
            'company_legal_name'     => $submission['company_legal_name'],
            'billing_address'        => $submission['billing_address'],
            'vat_eori_tax_no'        => $submission['vat_eori_tax_no'],
            'contact_person'         => $submission['contact_person'],
            'email'                  => $submission['email'],
            'phone'                  => $submission['phone'],
            'country_of_destination' => $submission['country_of_destination'],
            'coo_type'               => $submission['coo_type'],
            'consignee_same_as_buyer'        => $submission['consignee_same_as_buyer'],
            'consignee_name'                  => $submission['consignee_name'],
            'consignee_address_line1'         => $submission['consignee_address_line1'],
            'consignee_address_line2'         => $submission['consignee_address_line2'],
            'consignee_city'                   => $submission['consignee_city'],
            'consignee_postcode'               => $submission['consignee_postcode'],
            'consignee_country'                => $submission['consignee_country'],
            'consignee_vat_eori_tax_no'        => $submission['consignee_vat_eori_tax_no'],
            'consignee_contact_person'         => $submission['consignee_contact_person'],
            'consignee_phone'                  => $submission['consignee_phone'],
            'consignee_email'                  => $submission['consignee_email'],
            'notify_party_same_as_consignee'  => $submission['notify_party_same_as_consignee'],
            'notify_party'                     => $submission['notify_party'],
            'notify_party_address_line1'      => $submission['notify_party_address_line1'],
            'notify_party_address_line2'      => $submission['notify_party_address_line2'],
            'notify_party_city'                => $submission['notify_party_city'],
            'notify_party_postcode'            => $submission['notify_party_postcode'],
            'notify_party_country'             => $submission['notify_party_country'],
            'notify_party_contact_person'     => $submission['notify_party_contact_person'],
            'notify_party_phone'               => $submission['notify_party_phone'],
            'notify_party_email'               => $submission['notify_party_email'],
        ]);

        $client = ClientRepository::find($clientId);
        self::assertSame(0, (int) $client['consignee_same_as_buyer']);
        self::assertSame('Accept Consignee Co', $client['consignee_name']);
        self::assertSame('Cardiff', $client['consignee_city']);
        self::assertSame(1, (int) $client['notify_party_same_as_consignee'], 'notify same-as-consignee was checked, must stay 1');
        self::assertNull($client['notify_party']);
    }
}
