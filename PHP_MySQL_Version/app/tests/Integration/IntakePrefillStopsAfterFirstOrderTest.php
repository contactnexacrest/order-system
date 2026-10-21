<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientIntakeReviewController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\ClientIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "feedback-loop fix": the intake-preferences prefill
 * (90-item backlog #41/#46, see IntakePreferencesPrefillOrderFormTest)
 * only guarded on "no validation-retry `old` data yet" — so it kept
 * firing on every later New Order form for the same client too, not
 * just the first. A second, third, Nth order for the same client all
 * silently re-prefilled from that client's very first intake, feeding
 * the same aging commercial terms back into the form indefinitely even
 * once the buyer's actual preference had moved on for a later order.
 */
final class IntakePrefillStopsAfterFirstOrderTest extends DbTestCase
{
    private function createAndConvertIntake(string $incotermPreference): int
    {
        $id = ClientIntakeRepository::create([
            'company_legal_name'     => 'Repeat Buyer Ltd',
            'billing_address'        => '1 Repeat Street',
            'billing_address_line1'  => null,
            'billing_address_line2'  => null,
            'billing_city'           => null,
            'billing_postcode'       => null,
            'billing_country'        => null,
            'vat_eori_tax_no'        => null,
            'contact_person'         => 'Jane Buyer',
            'email'                  => 'jane@repeat-buyer.example',
            'phone'                  => null,
            'country_of_destination' => 'Netherlands',
            'port_of_discharge_text' => 'Rotterdam',
            'coo_type'               => null,
            'incoterm_preference'    => $incotermPreference,
            'container_type_text'    => '40ft High Cube',
            'buyer_own_reference'    => null,
            'notes'                  => null,
        ], '127.0.0.1');

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new ClientIntakeReviewController())->accept(['id' => (string) $id]);
        ob_end_clean();
        Flash::pull();

        $submission = ClientIntakeRepository::find($id);
        return (int) $submission['converted_client_id'];
    }

    public function testStillPrefillsOnTheClientsGenuinelyFirstOrder(): void
    {
        $clientId = $this->createAndConvertIntake('CIF');

        $_GET['client_id'] = (string) $clientId;
        ob_start();
        (new OrderController())->create([]);
        $output = ob_get_clean();
        unset($_GET['client_id']);

        $cifIncotermId = (int) \App\Config\Database::connection()
            ->query("SELECT id FROM incoterms WHERE code = 'CIF'")->fetchColumn();
        self::assertStringContainsString("value=\"{$cifIncotermId}\" selected", $output);
    }

    public function testDoesNotKeepRePrefillingOnceTheClientAlreadyHasAnOrder(): void
    {
        $clientId = $this->createAndConvertIntake('CIF');
        $this->createTestOrder($clientId); // the client's first order now exists

        $_GET['client_id'] = (string) $clientId;
        ob_start();
        (new OrderController())->create([]);
        $output = ob_get_clean();
        unset($_GET['client_id']);

        $cifIncotermId = (int) \App\Config\Database::connection()
            ->query("SELECT id FROM incoterms WHERE code = 'CIF'")->fetchColumn();
        self::assertStringNotContainsString(
            "value=\"{$cifIncotermId}\" selected",
            $output,
            'once the client has an order on record, a later New Order form must not keep inheriting the original intake — staff enter this order\'s own terms'
        );
    }
}
