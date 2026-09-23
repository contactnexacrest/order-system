<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\ClientIntakeRepository;

/**
 * Public, unauthenticated quotation-stage intake form — the actual entry
 * point into this system, per the confirmed scope: a Zoho-qualified
 * prospect is sent this form's link by staff; filling it out is their
 * onboarding. Submitting never creates a client or an order — it only
 * ever lands in the staff review queue (ClientIntakeReviewController).
 */
final class ClientIntakeController
{
    public function show(array $params): void
    {
        View::render('client_intake/form', [], 'layout/bare');
    }

    public function submit(array $params): void
    {
        $companyLegalName = trim((string) ($_POST['company_legal_name'] ?? ''));
        $billingAddress = trim((string) ($_POST['billing_address'] ?? ''));
        $contactPerson = trim((string) ($_POST['contact_person'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $countryOfDestination = trim((string) ($_POST['country_of_destination'] ?? ''));

        if ($companyLegalName === '' || $billingAddress === '' || $contactPerson === '' || $email === '' || $countryOfDestination === '') {
            Flash::set('error', 'Please fill in all required fields (marked *).');
            header('Location: /quotation-details');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', "\"{$email}\" doesn't look like a valid email address.");
            header('Location: /quotation-details');
            return;
        }

        ClientIntakeRepository::create([
            'company_legal_name'     => $companyLegalName,
            'billing_address'        => $billingAddress,
            'vat_eori_tax_no'        => trim((string) ($_POST['vat_eori_tax_no'] ?? '')),
            'contact_person'         => $contactPerson,
            'email'                  => $email,
            'phone'                  => trim((string) ($_POST['phone'] ?? '')),
            'country_of_destination' => $countryOfDestination,
            'port_of_discharge_text' => trim((string) ($_POST['port_of_discharge_text'] ?? '')),
            'coo_type'               => trim((string) ($_POST['coo_type'] ?? '')),
            'incoterm_preference'    => trim((string) ($_POST['incoterm_preference'] ?? '')),
            'container_type_text'    => trim((string) ($_POST['container_type_text'] ?? '')),
            'buyer_own_reference'    => trim((string) ($_POST['buyer_own_reference'] ?? '')),
            'notes'                  => trim((string) ($_POST['notes'] ?? '')),
        ], $_SERVER['REMOTE_ADDR'] ?? null);

        View::render('client_intake/thank_you', [], 'layout/bare');
    }
}
