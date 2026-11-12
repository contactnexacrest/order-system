<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Helpers\Flash;
use App\Repositories\ClientRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-346 (12 Nov 2026): the New / Edit / Duplicate Client
 * forms no longer have the single "Billing Address" box. Line 1, City / Town
 * and Billing Country are required; clients.billing_address is composed
 * from the parts ("Line 1, Line 2, City Postcode, Country"). A legacy client
 * with only the old single box shows it under the address fields on Edit.
 * Node twin: tests/integration/clientBillingAddressParts.test.js.
 */
final class ClientBillingAddressPartsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
    }

    private function clientCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    }

    public function testCreateWithoutLine1CityOrCountryIsRefusedWithTheMessage(): void
    {
        foreach (['billing_address_line1', 'billing_city', 'billing_country'] as $missing) {
            $_POST = [
                'company_legal_name'    => 'D-346 Missing Part Ltd',
                'billing_address_line1' => '1 Test Street',
                'billing_city'          => 'Birmingham',
                'billing_country'       => 'United Kingdom',
                'billing_address'       => 'An old single-box address is ignored',
            ];
            $_POST[$missing] = '';
            $before = $this->clientCount();

            ob_start();
            (new ClientController())->store([]);
            ob_end_clean();

            self::assertSame($before, $this->clientCount(), "{$missing} missing must refuse the save");
            self::assertSame(
                'Company legal name, Billing Address Line 1, City / Town and Billing Country are required.',
                Flash::pull()[0]['message']
            );
        }
    }

    public function testCreateWithThePartsStoresTheComposedAddress(): void
    {
        $_POST = [
            'company_legal_name'             => 'D-346 Composed Address Ltd',
            'billing_address_line1'          => '1 Test Street',
            'billing_address_line2'          => 'Unit 4',
            'billing_city'                   => 'Birmingham',
            'billing_postcode'               => 'B1 1AA',
            'billing_country'                => 'United Kingdom',
            'consignee_same_as_buyer'        => '1',
            'notify_party_same_as_consignee' => '1',
        ];

        ob_start();
        (new ClientController())->store([]);
        ob_end_clean();

        $client = ClientRepository::find((int) Database::connection()->query('SELECT MAX(id) FROM clients')->fetchColumn());
        self::assertSame('D-346 Composed Address Ltd', $client['company_legal_name']);
        self::assertSame('1 Test Street, Unit 4, Birmingham B1 1AA, United Kingdom', $client['billing_address']);
        self::assertSame('Birmingham', $client['billing_city']);
    }

    public function testEditRecomposesTheAddressFromTheParts(): void
    {
        $clientId = $this->createTestClient();
        $_POST = [
            'company_legal_name'             => 'PHPUnit Test Buyer Ltd',
            'billing_address_line1'          => '9 New Road',
            'billing_city'                   => 'Leeds',
            'billing_country'                => 'United Kingdom',
            'consignee_same_as_buyer'        => '1',
            'notify_party_same_as_consignee' => '1',
        ];

        ob_start();
        (new ClientController())->update(['id' => (string) $clientId]);
        ob_end_clean();

        self::assertSame('9 New Road, Leeds, United Kingdom', ClientRepository::find($clientId)['billing_address']);
    }

    public function testEditOfALegacyClientShowsTheOldSingleBoxAddress(): void
    {
        $clientId = $this->createTestClient(); // billing_address only, no parts

        ob_start();
        (new ClientController())->editForm(['id' => (string) $clientId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Address on file (old single box): 1 Test Street, Test City — copy it into the boxes above.', $html);
        self::assertStringNotContainsString('name="billing_address"', $html);
        self::assertMatchesRegularExpression('/<input type="text" name="billing_address_line1"[^>]*required>/', $html);
        self::assertMatchesRegularExpression('/<input type="text" name="billing_city"[^>]*required>/', $html);
        self::assertMatchesRegularExpression('/<input type="text" name="billing_country"[^>]*required>/', $html);
    }

    public function testEditOfAClientWithItsPartsHasNoOldAddressNote(): void
    {
        $clientId = $this->createTestClient();
        Database::connection()->prepare(
            "UPDATE clients SET billing_address_line1 = '1 Test Street', billing_city = 'Test City', billing_country = 'United Kingdom' WHERE id = :id"
        )->execute(['id' => $clientId]);

        ob_start();
        (new ClientController())->editForm(['id' => (string) $clientId]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('Address on file (old single box)', $html);
    }

    public function testNewClientFormHasNoSingleBillingAddressBox(): void
    {
        ob_start();
        (new ClientController())->create([]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('name="billing_address"', $html);
        self::assertStringContainsString('Billing Address Line 1 *', $html);
        self::assertStringContainsString('City / Town *', $html);
        self::assertStringContainsString('Billing Country *', $html);
    }
}
