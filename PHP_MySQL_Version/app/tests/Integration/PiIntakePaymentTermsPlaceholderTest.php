<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\PiIntakeController;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #16: the "Payment Terms Confirmation" field on the
 * PI-details public form is required, but piIntakeController's
 * buildPaymentTermsPlaceholder() computed sentence was only ever passed
 * into the HTML placeholder attribute (ghost text that disappears on
 * focus and is never submitted) — never into the textarea's actual
 * value/body. Since the field is NULL on a fresh, never-submitted
 * submission, the buyer saw an empty required field with only a
 * vanishing hint, not an actual prefilled value to review and confirm.
 * Fixed by rendering the computed sentence as the real prefilled value
 * whenever the submission has no answer of its own yet.
 */
final class PiIntakePaymentTermsPlaceholderTest extends DbTestCase
{
    private function renderShow(string $token): string
    {
        ob_start();
        (new PiIntakeController())->show(['token' => $token]);
        return ob_get_clean();
    }

    public function testFreshSubmissionPrefillsRealComputedSentenceNotJustPlaceholder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = PiIntakeRepository::createLink($orderId, null);

        $output = $this->renderShow($token);

        $textareaStart = strpos($output, 'name="payment_terms_confirmation"');
        self::assertNotFalse($textareaStart);
        $textareaTag = substr($output, $textareaStart, 600);
        $closeTagPos = strpos($textareaTag, '</textarea>');
        self::assertNotFalse($closeTagPos);
        $innerStart = strpos($textareaTag, '>') + 1;
        $body = substr($textareaTag, $innerStart, $closeTagPos - $innerStart);

        self::assertNotSame('', trim($body), 'the textarea body must not be empty on a fresh submission — it must contain the real computed sentence, not just a vanishing placeholder');
        self::assertStringContainsString('advance T/T on FOB Value', $body);
    }

    public function testClientsOwnPreviousAnswerIsShownVerbatimNotTheComputedDefault(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name'             => $submission['company_legal_name'],
            'billing_address'                => $submission['billing_address'],
            'billing_address_line1'          => $submission['billing_address_line1'],
            'billing_address_line2'          => $submission['billing_address_line2'],
            'billing_city'                   => $submission['billing_city'],
            'billing_postcode'               => $submission['billing_postcode'],
            'billing_country'                => $submission['billing_country'],
            'vat_eori_tax_no'                => $submission['vat_eori_tax_no'],
            'contact_person'                 => $submission['contact_person'],
            'email'                          => $submission['email'],
            'phone'                          => $submission['phone'],
            'port_of_discharge_text'         => $submission['port_of_discharge_text'],
            'country_of_destination'         => $submission['country_of_destination'],
            'incoterm_confirmed'             => $submission['incoterm_confirmed'],
            'container_type_text'            => $submission['container_type_text'],
            'payment_terms_confirmation'      => 'CONFIRMED — My own custom wording',
            'quotation_acceptance_reference'  => 'We accept Quotation X',
            'coo_type'                        => $submission['coo_type'],
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
        ], '127.0.0.1');
        \App\Config\Database::connection()
            ->prepare("UPDATE pi_intake_submissions SET status = 'rejected' WHERE id = :id")
            ->execute(['id' => (int) $submission['id']]);

        $output = $this->renderShow($token);

        self::assertStringContainsString('CONFIRMED — My own custom wording', $output);
    }
}
