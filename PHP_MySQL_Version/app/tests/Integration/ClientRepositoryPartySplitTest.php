<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\ClientRepository;
use App\Tests\Support\DbTestCase;

/**
 * Buyer/Consignee/Notify Party split on the Client record — covers the
 * structured billing address columns and the two "Same as" flags,
 * including that same_as_buyer/same_as_consignee defaults to 1 when
 * omitted (new client, checkbox checked by default).
 */
final class ClientRepositoryPartySplitTest extends DbTestCase
{
    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'company_legal_name'     => 'PHPUnit Test Buyer Co',
            'billing_address'        => 'Fallback billing address line',
            'billing_address_line1'  => '1 Buyer Street',
            'billing_address_line2'  => 'Suite 2',
            'billing_city'           => 'Buyer City',
            'billing_postcode'       => 'BC123',
            'vat_eori_tax_no'        => 'DE123456789',
            'contact_person'         => 'Buyer Contact',
            'email'                  => 'buyer@phpunit-test.example',
            'phone'                  => '+49 111',
            'country_of_destination' => 'Germany',
            'coo_type'               => 'Non-Preferential',
        ], $overrides);
    }

    public function testCreateDefaultsSameAsFlagsToOneWhenOmitted(): void
    {
        $id = ClientRepository::create($this->baseData(), 1, 'PHPUNIT-' . bin2hex(random_bytes(4)));
        $row = ClientRepository::find($id);

        self::assertSame(1, (int) $row['consignee_same_as_buyer']);
        self::assertSame(1, (int) $row['notify_party_same_as_consignee']);
        self::assertSame('1 Buyer Street', $row['billing_address_line1']);
        self::assertSame('Buyer City', $row['billing_city']);
    }

    public function testCreateStoresIndependentConsigneeColumnsWhenNotSameAsBuyer(): void
    {
        $id = ClientRepository::create($this->baseData([
            'consignee_same_as_buyer' => 0,
            'consignee_name'          => 'Independent Consignee Co',
            'consignee_address_line1' => '9 Consignee Road',
            'consignee_city'          => 'Consignee City',
            'consignee_country'       => 'France',
            'consignee_email'         => 'consignee@phpunit-test.example',
        ]), 1, 'PHPUNIT-' . bin2hex(random_bytes(4)));

        $row = ClientRepository::find($id);
        self::assertSame(0, (int) $row['consignee_same_as_buyer']);
        self::assertSame('Independent Consignee Co', $row['consignee_name']);
        self::assertSame('9 Consignee Road', $row['consignee_address_line1']);
        self::assertSame('France', $row['consignee_country']);
    }

    public function testCreateStoresIndependentNotifyPartyColumnsWhenNotSameAsConsignee(): void
    {
        $id = ClientRepository::create($this->baseData([
            'notify_party_same_as_consignee' => 0,
            'notify_party'                   => 'Freight Forwarder Ltd',
            'notify_party_address_line1'     => '5 Forwarder Lane',
            'notify_party_country'           => 'Netherlands',
            'notify_party_email'             => 'forwarder@phpunit-test.example',
        ]), 1, 'PHPUNIT-' . bin2hex(random_bytes(4)));

        $row = ClientRepository::find($id);
        self::assertSame(0, (int) $row['notify_party_same_as_consignee']);
        self::assertSame('Freight Forwarder Ltd', $row['notify_party']);
        self::assertSame('5 Forwarder Lane', $row['notify_party_address_line1']);
        self::assertSame('Netherlands', $row['notify_party_country']);
    }

    public function testUpdateCanFlipConsigneeSameAsBuyerBackToOneAndClearIndependentFields(): void
    {
        $id = ClientRepository::create($this->baseData([
            'consignee_same_as_buyer' => 0,
            'consignee_name'          => 'Temp Consignee Co',
            'consignee_address_line1' => '9 Consignee Road',
        ]), 1, 'PHPUNIT-' . bin2hex(random_bytes(4)));
        self::assertSame(0, (int) ClientRepository::find($id)['consignee_same_as_buyer']);

        ClientRepository::update($id, $this->baseData([
            'consignee_same_as_buyer' => 1,
            'consignee_name'          => null,
            'consignee_address_line1' => null,
        ]));

        $row = ClientRepository::find($id);
        self::assertSame(1, (int) $row['consignee_same_as_buyer']);
        self::assertNull($row['consignee_name']);
        self::assertNull($row['consignee_address_line1']);
    }

    public function testUpdateOverwritesBillingAddressLineFieldsIndependentlyOfBillingAddress(): void
    {
        $id = ClientRepository::create($this->baseData(), 1, 'PHPUNIT-' . bin2hex(random_bytes(4)));

        ClientRepository::update($id, $this->baseData([
            'billing_address_line1' => '99 New Street',
            'billing_city'          => 'New City',
            'billing_postcode'      => 'NC999',
        ]));

        $row = ClientRepository::find($id);
        self::assertSame('99 New Street', $row['billing_address_line1']);
        self::assertSame('New City', $row['billing_city']);
        self::assertSame('NC999', $row['billing_postcode']);
    }
}
