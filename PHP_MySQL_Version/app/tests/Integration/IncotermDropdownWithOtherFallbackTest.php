<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientIntakeController;
use App\Controllers\PiIntakeController;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * User-reported batch item 2: "On client side also, we should give
 * dropdown for Incoterms + free text if not available there" — the
 * client-facing Quotation-details form (incoterm_preference) and
 * PI-details form (incoterm_confirmed) both used a bare free-text input.
 * Fixed with a <select> of the admin-managed incoterms list plus an
 * "Other (not listed)" option that reveals a free-text fallback input —
 * the submitted field name/column is unchanged on both ends, so a value
 * not in the list still submits and saves exactly as before.
 */
final class IncotermDropdownWithOtherFallbackTest extends DbTestCase
{
    private function renderPiShow(string $token): string
    {
        ob_start();
        (new PiIntakeController())->show(['token' => $token]);
        return ob_get_clean();
    }

    private function renderClientIntakeShow(): string
    {
        ob_start();
        (new ClientIntakeController())->show([]);
        return ob_get_clean();
    }

    private function renderClientIntakeEdit(string $token): string
    {
        ob_start();
        (new ClientIntakeController())->showEdit(['token' => $token]);
        return ob_get_clean();
    }

    public function testQuotationIntakeFormOffersAnIncotermDropdownWithOtherFallback(): void
    {
        $output = $this->renderClientIntakeShow();

        self::assertStringContainsString('id="incoterm_select"', $output);
        self::assertStringContainsString('>FOB<', $output);
        self::assertStringContainsString('Other (not listed)', $output);
        self::assertStringContainsString('id="incoterm_preference_input"', $output);
    }

    public function testPiIntakeFormOffersAnIncotermDropdownPrefilledFromTheOrdersOwnIncoterm(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $token = PiIntakeRepository::createLink($orderId, null);

        $output = $this->renderPiShow($token);

        self::assertStringContainsString('id="incoterm_select"', $output);
        // The order's own incoterm (FOB) must be the pre-selected option, not the free-text fallback.
        $selectStart = strpos($output, 'id="incoterm_select"');
        $selectTag = substr($output, $selectStart, 2000);
        self::assertMatchesRegularExpression('/value="FOB"\s+selected/', $selectTag);
        $otherInputStart = strpos($output, 'id="incoterm_confirmed_input"');
        $otherInputTag = substr($output, $otherInputStart, 300);
        self::assertStringContainsString('display:none', $otherInputTag);
        self::assertStringContainsString('readonly', $otherInputTag);
    }

    public function testPiIntakeFormFallsBackToFreeTextWhenSubmittedValueIsNotAListedIncoterm(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        // Simulate a stored value that doesn't match any listed incoterm
        // code (e.g. the client typed their own wording on a prior visit)
        // without running the full submit()/lock workflow, which would
        // move the row past the 'awaiting_client' status this view needs.
        \App\Config\Database::connection()
            ->prepare('UPDATE pi_intake_submissions SET incoterm_confirmed = :v WHERE id = :id')
            ->execute(['v' => 'DDP Custom Terms Not Listed', 'id' => $submission['id']]);

        $output = $this->renderPiShow($token);

        $selectStart = strpos($output, 'id="incoterm_select"');
        $selectTag = substr($output, $selectStart, 3000);
        self::assertMatchesRegularExpression('/value="__other__"\s+selected/', $selectTag);
        $otherInputStart = strpos($output, 'id="incoterm_confirmed_input"');
        $otherInputTag = substr($output, $otherInputStart, 300);
        self::assertStringContainsString('value="DDP Custom Terms Not Listed"', $otherInputTag);
        self::assertStringNotContainsString('display:none', $otherInputTag);
    }

    public function testClientIntakeEditFormFallsBackToFreeTextWhenStoredValueIsNotAListedIncoterm(): void
    {
        $id = ClientIntakeRepository::create([
            'company_legal_name' => 'Test Buyer', 'billing_address' => '1 Test Street',
            'billing_address_line1' => '1 Test Street', 'billing_address_line2' => null,
            'billing_city' => 'Testville', 'billing_postcode' => null, 'billing_country' => 'Testland',
            'vat_eori_tax_no' => 'VAT123', 'contact_person' => 'Test Contact', 'email' => 'buyer@example.test',
            'phone' => null, 'country_of_destination' => 'Testland', 'port_of_discharge_text' => null,
            'coo_type' => null, 'incoterm_preference' => 'My Own Custom Incoterm Wording',
            'container_type_text' => null, 'buyer_own_reference' => null, 'notes' => null,
            'consignee_same_as_buyer' => 1, 'consignee_name' => null, 'consignee_address_line1' => null,
            'consignee_address_line2' => null, 'consignee_city' => null, 'consignee_postcode' => null,
            'consignee_country' => null, 'consignee_vat_eori_tax_no' => null, 'consignee_contact_person' => null,
            'consignee_phone' => null, 'consignee_email' => null,
        ], '127.0.0.1');
        $rawToken = ClientIntakeController::issueCorrectionLink($id, 'buyer@example.test');
        $rawToken = substr($rawToken, strrpos($rawToken, '/') + 1);

        $output = $this->renderClientIntakeEdit($rawToken);

        $selectStart = strpos($output, 'id="incoterm_select"');
        $selectTag = substr($output, $selectStart, 3000);
        self::assertMatchesRegularExpression('/value="__other__"\s+selected/', $selectTag);
        $otherInputStart = strpos($output, 'id="incoterm_preference_input"');
        $otherInputTag = substr($output, $otherInputStart, 300);
        self::assertStringContainsString('value="My Own Custom Incoterm Wording"', $otherInputTag);
    }
}
