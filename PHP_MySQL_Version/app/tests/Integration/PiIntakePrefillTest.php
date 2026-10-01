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
}
