<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\PiIntakeRepository;

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
        View::render('pi_intake/form', ['submission' => $submission, 'token' => $params['token']], 'layout/bare');
    }

    public function submit(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $submission = PiIntakeRepository::findValidByToken($token);
        if (!$submission) {
            View::render('pi_intake/link_expired', [], 'layout/bare');
            return;
        }

        $data = [
            'company_legal_name'             => trim((string) ($_POST['company_legal_name'] ?? '')),
            'billing_address'                => trim((string) ($_POST['billing_address'] ?? '')),
            'consignee_name'                 => trim((string) ($_POST['consignee_name'] ?? '')),
            'consignee_address'               => trim((string) ($_POST['consignee_address'] ?? '')),
            'vat_eori_tax_no'                => trim((string) ($_POST['vat_eori_tax_no'] ?? '')),
            'contact_person'                 => trim((string) ($_POST['contact_person'] ?? '')),
            'email'                          => trim((string) ($_POST['email'] ?? '')),
            'phone'                          => trim((string) ($_POST['phone'] ?? '')),
            'notify_party'                   => trim((string) ($_POST['notify_party'] ?? '')),
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
        ];

        // Required set per the business's own PI Form spec (Client_Forms.xlsx).
        $required = [
            'company_legal_name', 'billing_address', 'consignee_name', 'consignee_address',
            'vat_eori_tax_no', 'contact_person', 'email', 'phone',
            'port_of_discharge_text', 'country_of_destination', 'incoterm_confirmed',
            'payment_terms_confirmation', 'quotation_acceptance_reference', 'coo_type',
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
}
