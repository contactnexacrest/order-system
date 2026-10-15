<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\ClientRepository;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #45 (schema.sql Section BI): the PI-details public
 * form's buyer billing_address was the one remaining flat free-text box
 * on either public intake form — its own Consignee and Notify Party
 * sections (Section BB) were already fully structured, and the
 * Quotation-stage form got the same billing-address treatment in Section
 * BF, but pi_intake_submissions' own billing_address was left behind.
 * Exercises the repository layer directly (the controller's
 * extractAndValidate()-equivalent in PiIntakeController::submit() just
 * builds this same array from $_POST, which PHPUnit has no superglobal
 * context to simulate against — same convention as
 * IntakeConsigneeNotifyPartySplitTest).
 */
final class PiIntakeStructuredBillingAddressTest extends DbTestCase
{
    private function submitData(array $overrides = []): array
    {
        return array_merge([
            'company_legal_name'             => 'PI Billing Test Ltd',
            'billing_address'                => '10 Billing Street, Suite 2, Rotterdam, 3011 AD, Netherlands',
            'billing_address_line1'          => '10 Billing Street',
            'billing_address_line2'          => 'Suite 2',
            'billing_city'                   => 'Rotterdam',
            'billing_postcode'               => '3011 AD',
            'billing_country'                => 'Netherlands',
            'vat_eori_tax_no'                => 'NL123456789B01',
            'contact_person'                 => 'Jamie Buyer',
            'email'                          => 'jamie@buyer.example',
            'phone'                          => '+31 10 123 4567',
            'port_of_discharge_text'         => 'Rotterdam',
            'country_of_destination'         => 'Netherlands',
            'incoterm_confirmed'             => 'FOB',
            'container_type_text'            => null,
            'payment_terms_confirmation'      => 'CONFIRMED',
            'quotation_acceptance_reference'  => 'We accept Quotation Z',
            'coo_type'                        => 'Non-preferential',
            'buyer_po_ref'                    => null,
            'changes_from_quotation'          => null,
            'special_document_requirements'   => null,
            'consignee_same_as_buyer'        => 1,
            'consignee_name' => null, 'consignee_address_line1' => null, 'consignee_address_line2' => null,
            'consignee_city' => null, 'consignee_postcode' => null, 'consignee_country' => null,
            'consignee_vat_eori_tax_no' => null, 'consignee_contact_person' => null,
            'consignee_phone' => null, 'consignee_email' => null,
            'notify_party_same_as_consignee' => 1,
            'notify_party' => null, 'notify_party_address_line1' => null, 'notify_party_address_line2' => null,
            'notify_party_city' => null, 'notify_party_postcode' => null, 'notify_party_country' => null,
            'notify_party_contact_person' => null, 'notify_party_phone' => null, 'notify_party_email' => null,
        ], $overrides);
    }

    public function testSubmitPersistsStructuredBillingAddressColumns(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        PiIntakeRepository::submit((int) $submission['id'], $this->submitData(), '127.0.0.1');

        $reloaded = PiIntakeRepository::find((int) $submission['id']);
        self::assertSame('10 Billing Street', $reloaded['billing_address_line1']);
        self::assertSame('Suite 2', $reloaded['billing_address_line2']);
        self::assertSame('Rotterdam', $reloaded['billing_city']);
        self::assertSame('3011 AD', $reloaded['billing_postcode']);
        self::assertSame('Netherlands', $reloaded['billing_country']);
        self::assertSame('10 Billing Street, Suite 2, Rotterdam, 3011 AD, Netherlands', $reloaded['billing_address'], 'flat billing_address must stay populated for older consumers');
    }

    public function testSubmitComposesFlatBillingAddressFromStructuredPartsOmittingBlankLine2(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        PiIntakeRepository::submit((int) $submission['id'], $this->submitData([
            'billing_address'       => '10 Billing Street, Rotterdam, 3011 AD, Netherlands',
            'billing_address_line2' => null,
        ]), '127.0.0.1');

        $reloaded = PiIntakeRepository::find((int) $submission['id']);
        self::assertNull($reloaded['billing_address_line2']);
        self::assertStringNotContainsString(', , ', $reloaded['billing_address'], 'an empty Line 2 must never leave a double comma in the composed address');
    }

    public function testAcceptCarriesStructuredBillingAddressThroughToClientRow(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        PiIntakeRepository::submit((int) $submission['id'], $this->submitData(), '127.0.0.1');
        $submission = PiIntakeRepository::find((int) $submission['id']);

        // Mirrors PiIntakeReviewController::accept() exactly (that
        // controller needs a real HTTP/session context PHPUnit can't
        // simulate, so the ClientRepository::update() call it makes is
        // exercised directly here, same convention as
        // IntakeConsigneeNotifyPartySplitTest::testPiIntakeAcceptAppliesFullStructuredSplitToClientRow).
        ClientRepository::update($clientId, [
            'company_legal_name'     => $submission['company_legal_name'],
            'billing_address'        => $submission['billing_address'],
            'billing_address_line1'  => $submission['billing_address_line1'],
            'billing_address_line2'  => $submission['billing_address_line2'],
            'billing_city'           => $submission['billing_city'],
            'billing_postcode'       => $submission['billing_postcode'],
            'billing_country'        => $submission['billing_country'],
            'vat_eori_tax_no'        => $submission['vat_eori_tax_no'],
            'contact_person'         => $submission['contact_person'],
            'email'                  => $submission['email'],
            'phone'                  => $submission['phone'],
            'country_of_destination' => $submission['country_of_destination'],
            'coo_type'               => $submission['coo_type'],
        ]);

        $client = ClientRepository::find($clientId);
        self::assertSame('10 Billing Street', $client['billing_address_line1']);
        self::assertSame('Rotterdam', $client['billing_city']);
        self::assertSame('Netherlands', $client['billing_country']);
    }
}
