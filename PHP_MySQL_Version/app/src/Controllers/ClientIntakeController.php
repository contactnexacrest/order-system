<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\ClientIntakeRepository;
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
        View::render('client_intake/form', [], 'layout/bare');
    }

    public function submit(array $params): void
    {
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
        View::render('client_intake/edit_form', ['submission' => $submission, 'token' => $params['token']], 'layout/bare');
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
        $data = [
            'company_legal_name'     => trim((string) ($_POST['company_legal_name'] ?? '')),
            'billing_address'        => trim((string) ($_POST['billing_address'] ?? '')),
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
        // Company Legal Name, Billing Address, VAT/EORI/Tax Reg. No., Contact Person,
        // Email, Country of Destination, Incoterm.
        $required = ['company_legal_name', 'billing_address', 'vat_eori_tax_no', 'contact_person', 'email', 'country_of_destination', 'incoterm_preference'];
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
        return $data;
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
