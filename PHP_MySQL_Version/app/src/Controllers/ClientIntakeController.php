<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\Flash;
use App\Helpers\RateLimiter;
use App\Helpers\View;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\LookupRepository;
use App\Services\EmailService;

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
        View::render('client_intake/form', [
            'wrapClass' => 'intake-wrap',
            'containerTypes' => LookupRepository::dropdownOptions('container_type'),
        ], 'layout/bare');
    }

    // QA-5 INT-05: this form has no auth, no CAPTCHA, and no per-order
    // token the way PI-intake does — a scripted flood from a single IP is
    // otherwise unlimited. 5 submissions per 15 minutes is generous for a
    // genuine prospect (who submits once) while making a burst-flood of
    // the review queue impractical.
    private const RATE_LIMIT_BUCKET = 'quotation_intake_submit';
    private const RATE_LIMIT_MAX_REQUESTS = 5;
    private const RATE_LIMIT_WINDOW_MINUTES = 15;

    public function submit(array $params): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (RateLimiter::tooManyRequests(self::RATE_LIMIT_BUCKET, $ip, self::RATE_LIMIT_MAX_REQUESTS, self::RATE_LIMIT_WINDOW_MINUTES)) {
            Flash::set('error', 'Too many submissions from this connection. Please wait a few minutes and try again.');
            header('Location: /quotation-details');
            return;
        }

        $data = self::extractAndValidate();
        if ($data === null) {
            header('Location: /quotation-details');
            return;
        }

        $id = ClientIntakeRepository::create($data, $_SERVER['REMOTE_ADDR'] ?? null);
        $link = self::issueCorrectionLink($id, $data['email']);

        View::render('client_intake/thank_you', ['correctionLink' => $link], 'layout/bare');
    }

    public function showEdit(array $params): void
    {
        $submission = ClientIntakeRepository::findValidByToken((string) ($params['token'] ?? ''));
        if (!$submission) {
            View::render('client_intake/link_expired', [], 'layout/bare');
            return;
        }
        View::render('client_intake/edit_form', [
            'submission' => $submission,
            'token' => $params['token'],
            'wrapClass' => 'intake-wrap',
            'containerTypes' => LookupRepository::dropdownOptions('container_type'),
        ], 'layout/bare');
    }

    public function updateSubmission(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $submission = ClientIntakeRepository::findValidByToken($token);
        if (!$submission) {
            View::render('client_intake/link_expired', [], 'layout/bare');
            return;
        }

        $data = self::extractAndValidate();
        if ($data === null) {
            header("Location: /quotation-details/edit/{$token}");
            return;
        }

        ClientIntakeRepository::updateFromClient((int) $submission['id'], $data);
        View::render('client_intake/thank_you', ['correctionLink' => null, 'updated' => true], 'layout/bare');
    }

    /** @return array<string,string>|null null means validation failed and a flash error was already set */
    private static function extractAndValidate(): ?array
    {
        // Point 8 — the Billing Address field used to be one free-text box;
        // it's now structured (Line 1/Line 2/City/Postcode/Country), same
        // pattern as the Consignee section just below it on this same
        // form, since documents need these as discrete fields. The flat
        // billing_address column stays NOT NULL (every older consumer —
        // reports, the review screen fallback — still reads it), so it's
        // composed here from the structured parts rather than collected
        // directly from the client.
        $billingAddressLine1 = trim((string) ($_POST['billing_address_line1'] ?? ''));
        $billingAddressLine2 = trim((string) ($_POST['billing_address_line2'] ?? ''));
        $billingCity = trim((string) ($_POST['billing_city'] ?? ''));
        $billingPostcode = trim((string) ($_POST['billing_postcode'] ?? ''));
        $billingCountry = trim((string) ($_POST['billing_country'] ?? ''));
        $composedBillingAddress = implode(', ', array_filter([
            $billingAddressLine1, $billingAddressLine2, $billingCity, $billingPostcode, $billingCountry,
        ], static fn ($part) => $part !== ''));

        $data = [
            'company_legal_name'     => trim((string) ($_POST['company_legal_name'] ?? '')),
            'billing_address'        => $composedBillingAddress,
            'billing_address_line1'  => $billingAddressLine1,
            'billing_address_line2'  => $billingAddressLine2,
            'billing_city'           => $billingCity,
            'billing_postcode'       => $billingPostcode,
            'billing_country'        => $billingCountry,
            'vat_eori_tax_no'        => trim((string) ($_POST['vat_eori_tax_no'] ?? '')),
            'contact_person'         => trim((string) ($_POST['contact_person'] ?? '')),
            'email'                  => trim((string) ($_POST['email'] ?? '')),
            'phone'                  => trim((string) ($_POST['phone'] ?? '')),
            'country_of_destination' => trim((string) ($_POST['country_of_destination'] ?? '')),
            'port_of_discharge_text' => trim((string) ($_POST['port_of_discharge_text'] ?? '')),
            'coo_type'               => trim((string) ($_POST['coo_type'] ?? '')),
            'incoterm_preference'    => trim((string) ($_POST['incoterm_preference'] ?? '')),
            'container_type_text'    => trim((string) ($_POST['container_type_text'] ?? '')),
            'buyer_own_reference'    => trim((string) ($_POST['buyer_own_reference'] ?? '')),
            'notes'                  => trim((string) ($_POST['notes'] ?? '')),
        ];

        // Required set per the business's own Quotation Form spec (Client_Forms.xlsx):
        // Company Legal Name, Billing Address (now Line 1/City/Country),
        // VAT/EORI/Tax Reg. No., Contact Person, Email, Country of
        // Destination, Incoterm. Address Line 2/Postcode stay optional,
        // same as the Consignee section's own optionality on this form.
        $required = ['company_legal_name', 'billing_address_line1', 'billing_city', 'billing_country', 'vat_eori_tax_no', 'contact_person', 'email', 'country_of_destination', 'incoterm_preference'];
        foreach ($required as $field) {
            if ($data[$field] === '') {
                Flash::set('error', 'Please fill in all required fields (marked *).');
                return null;
            }
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', "\"{$data['email']}\" doesn't look like a valid email address.");
            return null;
        }
        return array_merge($data, self::collectConsigneeFields());
    }

    /**
     * Section BB — lets the client self-serve the same Consignee "Same as
     * Buyer?" split staff already had on the admin Clients form (see
     * ClientController::collectPartyFields()). Never Notify Party here —
     * the Quotation stage doesn't show a Notify Party section on any
     * document (docs/SOP/01-stage1-enquiry-quotation.md), so there's
     * nothing for this form to collect.
     */
    private static function collectConsigneeFields(): array
    {
        $consigneeSameAsBuyer = !empty($_POST['consignee_same_as_buyer']);
        $fields = ['consignee_same_as_buyer' => $consigneeSameAsBuyer ? 1 : 0];

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

        return $fields;
    }

    /** Emails the client a one-time correction link and returns it, so the thank-you page can also show it directly. */
    private static function issueCorrectionLink(int $submissionId, string $email): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (7 * 86400));
        ClientIntakeRepository::setAccessToken($submissionId, hash('sha256', $rawToken), $expiresAt);

        $link = rtrim(Env::get('APP_URL', ''), '/') . '/quotation-details/edit/' . $rawToken;
        $body = "Hello,\n\n"
            . "Thank you for your quotation request. We'll review it and get back to you within 24 hours.\n\n"
            . "Spotted a mistake in what you submitted? You can correct it yourself, as long as we haven't already processed it, at:\n{$link}\n\n"
            . "This link works for 7 days.\n\n"
            . "NexaCrest International Private Limited";
        EmailService::sendPlainText($email, 'Your NexaCrest quotation request — correction link', $body);

        return $link;
    }
}
