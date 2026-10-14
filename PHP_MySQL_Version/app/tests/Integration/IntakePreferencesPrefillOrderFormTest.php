<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientIntakeReviewController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\ClientIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog, P1 (#41/#46): the buyer's commercial preferences given
 * at Quotation-intake (Incoterm, container type, discharge port) were
 * never carried into the New Order form — staff had to remember to
 * re-enter them from scratch. Confirmed via screenshots: a buyer's public
 * QT intake genuinely submitted Incoterm=CIF, but the New Order form
 * silently defaulted to FOB. This covers OrderController::create() now
 * prefilling those fields from the client's originating intake submission.
 */
final class IntakePreferencesPrefillOrderFormTest extends DbTestCase
{
    private function createAndConvertIntake(string $incotermPreference, string $containerType, string $portText): int
    {
        $id = ClientIntakeRepository::create([
            'company_legal_name'     => 'Intake Prefill Buyer Ltd',
            'billing_address'        => '1 Intake Street',
            'billing_address_line1'  => null,
            'billing_address_line2'  => null,
            'billing_city'           => null,
            'billing_postcode'       => null,
            'billing_country'        => null,
            'vat_eori_tax_no'        => null,
            'contact_person'         => 'Jane Buyer',
            'email'                  => 'jane@intake-prefill.example',
            'phone'                  => null,
            'country_of_destination' => 'Netherlands',
            'port_of_discharge_text' => $portText,
            'coo_type'               => null,
            'incoterm_preference'    => $incotermPreference,
            'container_type_text'    => $containerType,
            'buyer_own_reference'    => null,
            'notes'                  => null,
        ], '127.0.0.1');

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new ClientIntakeReviewController())->accept(['id' => (string) $id]);
        ob_end_clean();
        Flash::pull();

        $submission = ClientIntakeRepository::find($id);
        self::assertSame('converted', $submission['status']);
        return (int) $submission['converted_client_id'];
    }

    public function testNewOrderFormPrefillsIncotermContainerTypeAndPortFromTheIntake(): void
    {
        $clientId = $this->createAndConvertIntake('CIF', '40ft High Cube', 'Rotterdam');

        $_GET['client_id'] = (string) $clientId;
        ob_start();
        (new OrderController())->create([]);
        $output = ob_get_clean();
        unset($_GET['client_id']);

        $cifIncotermId = (int) \App\Config\Database::connection()
            ->query("SELECT id FROM incoterms WHERE code = 'CIF'")->fetchColumn();

        self::assertStringContainsString("value=\"{$cifIncotermId}\" selected", $output, 'CIF must be pre-selected, not left to default to FOB');
        self::assertStringContainsString('value="40ft High Cube" selected', $output);
        self::assertStringContainsString('value="Rotterdam"', $output);
    }

    public function testDoesNotPrefillWhenTheIntakeLeftFieldsBlank(): void
    {
        $clientId = $this->createAndConvertIntake('', '', '');

        $_GET['client_id'] = (string) $clientId;
        ob_start();
        (new OrderController())->create([]);
        $output = ob_get_clean();
        unset($_GET['client_id']);

        // Nothing pre-selected — the default "— To Be Confirmed —" / no-selection state holds.
        self::assertStringNotContainsString('40ft High Cube" selected', $output);
    }
}
