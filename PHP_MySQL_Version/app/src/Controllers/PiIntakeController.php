<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\PiIntakeRepository;
use App\Services\DocumentDataAssembler;

/**
 * Public, unauthenticated PI-stage intake form — a SEPARATE second stage
 * from ClientIntakeController's Quotation-stage form, per the business's
 * own Client_Forms.xlsx spec. Staff generate the link (one per order,
 * from the order screen) once the Quotation is out; the client confirms
 * their details here "exactly as they appear on official documents" and
 * adds fields the Quotation stage never asked for. Submitting never
 * writes to the client/order directly — it only ever lands in the staff
 * review queue (PiIntakeReviewController).
 */
final class PiIntakeController
{
    public function show(array $params): void
    {
        $submission = PiIntakeRepository::findValidByToken((string) ($params['token'] ?? ''));
        if (!$submission) {
            View::render('pi_intake/link_expired', [], 'layout/bare');
            return;
        }
        View::render('pi_intake/form', [
            'submission' => $submission,
            'token' => $params['token'],
            'wrapClass' => 'intake-wrap',
            'paymentTermsPlaceholder' => self::buildPaymentTermsPlaceholder($submission),
        ], 'layout/bare');
    }

    /**
     * Point 6 — the Payment Terms Confirmation field's placeholder used to
     * be a generic made-up example; it now mirrors this specific order's
     * own payment preset wording (the same sentence DocumentDataAssembler
     * prints on the PI itself), so the client sees exactly what they're
     * expected to confirm rather than guessing at the format.
     */
    private static function buildPaymentTermsPlaceholder(array $submission): string
    {
        $advancePct = rtrim(rtrim(number_format((float) $submission['order_advance_pct'], 2), '0'), '.');
        $balancePct = rtrim(rtrim(number_format((float) $submission['order_balance_pct'], 2), '0'), '.');
        $balanceTerms = DocumentDataAssembler::balanceTriggerSentence(
            $submission['order_balance_trigger_option'] ?? null,
            isset($submission['order_balance_days']) ? (int) $submission['order_balance_days'] : null,
            $submission['order_balance_trigger_wording'] ?? null
        );
        return "CONFIRMED — {$advancePct}% advance T/T on FOB Value {$submission['order_advance_trigger_text']} + {$balancePct}% balance {$balanceTerms}";
    }

    public function submit(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $submission = PiIntakeRepository::findValidByToken($token);
        if (!$submission) {
            View::render('pi_intake/link_expired', [], 'layout/bare');
            return;
        }

        $data = array_merge([
            'company_legal_name'             => trim((string) ($_POST['company_legal_name'] ?? '')),
            'billing_address'                => trim((string) ($_POST['billing_address'] ?? '')),
            'vat_eori_tax_no'                => trim((string) ($_POST['vat_eori_tax_no'] ?? '')),
            'contact_person'                 => trim((string) ($_POST['contact_person'] ?? '')),
            'email'                          => trim((string) ($_POST['email'] ?? '')),
            'phone'                          => trim((string) ($_POST['phone'] ?? '')),
            'port_of_discharge_text'          => trim((string) ($_POST['port_of_discharge_text'] ?? '')),
            'country_of_destination'          => trim((string) ($_POST['country_of_destination'] ?? '')),
            'incoterm_confirmed'             => trim((string) ($_POST['incoterm_confirmed'] ?? '')),
            'container_type_text'            => trim((string) ($_POST['container_type_text'] ?? '')),
            'payment_terms_confirmation'      => trim((string) ($_POST['payment_terms_confirmation'] ?? '')),
            'quotation_acceptance_reference'  => trim((string) ($_POST['quotation_acceptance_reference'] ?? '')),
            'coo_type'                        => trim((string) ($_POST['coo_type'] ?? '')),
            'buyer_po_ref'                    => trim((string) ($_POST['buyer_po_ref'] ?? '')),
            'changes_from_quotation'          => trim((string) ($_POST['changes_from_quotation'] ?? '')),
            'special_document_requirements'   => trim((string) ($_POST['special_document_requirements'] ?? '')),
        ], self::collectPartyFields());

        // Required set per the business's own PI Form spec (Client_Forms.xlsx).
        // Consignee/Notify Party are never in this list — they're
        // self-service structured fields gated by their own "Same as X?"
        // checkbox (see collectPartyFields()), not free-text requireds.
        $required = [
            'company_legal_name', 'billing_address',
            'vat_eori_tax_no', 'contact_person', 'email', 'phone',
            'port_of_discharge_text', 'country_of_destination', 'incoterm_confirmed',
            'payment_terms_confirmation', 'quotation_acceptance_reference',
        ];
        foreach ($required as $field) {
            if ($data[$field] === '') {
                Flash::set('error', 'Please fill in all required fields (marked *).');
                header("Location: /pi-details/{$token}");
                return;
            }
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', "\"{$data['email']}\" doesn't look like a valid email address.");
            header("Location: /pi-details/{$token}");
            return;
        }
        if (empty($_POST['confirm_lock'])) {
            Flash::set('error', 'Please check the confirmation box — the details above must be confirmed as correct before this form can be submitted.');
            header("Location: /pi-details/{$token}");
            return;
        }

        PiIntakeRepository::submit((int) $submission['id'], $data, $_SERVER['REMOTE_ADDR'] ?? null);
        View::render('pi_intake/thank_you', [], 'layout/bare');
    }

    /**
     * Section BB — the client's own self-service Consignee/Notify Party
     * "Same as X?" split on the PI-details form, mirroring
     * ClientController::collectPartyFields() field-for-field so the
     * values land in pi_intake_submissions using the exact same column
     * names ClientRepository::update() already expects (see
     * PiIntakeReviewController::accept()).
     */
    private static function collectPartyFields(): array
    {
        $consigneeSameAsBuyer = !empty($_POST['consignee_same_as_buyer']);
        $notifySameAsConsignee = !empty($_POST['notify_party_same_as_consignee']);

        $fields = [
            'consignee_same_as_buyer'        => $consigneeSameAsBuyer ? 1 : 0,
            'notify_party_same_as_consignee' => $notifySameAsConsignee ? 1 : 0,
        ];

        if ($consigneeSameAsBuyer) {
            $fields['consignee_name'] = null;
            $fields['consignee_address_line1'] = null;
            $fields['consignee_address_line2'] = null;
            $fields['consignee_city'] = null;
            $fields['consignee_postcode'] = null;
            $fields['consignee_country'] = null;
            $fields['consignee_vat_eori_tax_no'] = null;
            $fields['consignee_contact_person'] = null;
            $fields['consignee_phone'] = null;
            $fields['consignee_email'] = null;
        } else {
            $fields['consignee_name'] = trim((string) ($_POST['consignee_name'] ?? '')) ?: null;
            $fields['consignee_address_line1'] = trim((string) ($_POST['consignee_address_line1'] ?? '')) ?: null;
            $fields['consignee_address_line2'] = trim((string) ($_POST['consignee_address_line2'] ?? '')) ?: null;
            $fields['consignee_city'] = trim((string) ($_POST['consignee_city'] ?? '')) ?: null;
            $fields['consignee_postcode'] = trim((string) ($_POST['consignee_postcode'] ?? '')) ?: null;
            $fields['consignee_country'] = trim((string) ($_POST['consignee_country'] ?? '')) ?: null;
            $fields['consignee_vat_eori_tax_no'] = trim((string) ($_POST['consignee_vat_eori_tax_no'] ?? '')) ?: null;
            $fields['consignee_contact_person'] = trim((string) ($_POST['consignee_contact_person'] ?? '')) ?: null;
            $fields['consignee_phone'] = trim((string) ($_POST['consignee_phone'] ?? '')) ?: null;
            $fields['consignee_email'] = trim((string) ($_POST['consignee_email'] ?? '')) ?: null;
        }

        if ($notifySameAsConsignee) {
            $fields['notify_party'] = null;
            $fields['notify_party_address_line1'] = null;
            $fields['notify_party_address_line2'] = null;
            $fields['notify_party_city'] = null;
            $fields['notify_party_postcode'] = null;
            $fields['notify_party_country'] = null;
            $fields['notify_party_contact_person'] = null;
            $fields['notify_party_phone'] = null;
            $fields['notify_party_email'] = null;
        } else {
            $fields['notify_party'] = trim((string) ($_POST['notify_party'] ?? '')) ?: null;
            $fields['notify_party_address_line1'] = trim((string) ($_POST['notify_party_address_line1'] ?? '')) ?: null;
            $fields['notify_party_address_line2'] = trim((string) ($_POST['notify_party_address_line2'] ?? '')) ?: null;
            $fields['notify_party_city'] = trim((string) ($_POST['notify_party_city'] ?? '')) ?: null;
            $fields['notify_party_postcode'] = trim((string) ($_POST['notify_party_postcode'] ?? '')) ?: null;
            $fields['notify_party_country'] = trim((string) ($_POST['notify_party_country'] ?? '')) ?: null;
            $fields['notify_party_contact_person'] = trim((string) ($_POST['notify_party_contact_person'] ?? '')) ?: null;
            $fields['notify_party_phone'] = trim((string) ($_POST['notify_party_phone'] ?? '')) ?: null;
            $fields['notify_party_email'] = trim((string) ($_POST['notify_party_email'] ?? '')) ?: null;
        }

        return $fields;
    }
}
