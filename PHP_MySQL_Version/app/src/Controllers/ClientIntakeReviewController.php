<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\ClientRepository;
use App\Services\AuthService;
use App\Services\ReferenceNumberService;

/**
 * Staff review queue for public quotation-details submissions
 * (client_intake_submissions). Accepting a submission creates the real
 * `clients` row through the exact same ClientRepository::create() path a
 * manually-entered walk-in client goes through — never a separate,
 * parallel creation path — then hands off to the normal /clients/{id}
 * screen so staff proceeds through order creation and Quotation
 * generation exactly as they always have. Per the confirmed flow, this
 * never auto-generates a Quotation.
 */
final class ClientIntakeReviewController
{
    public function index(array $params): void
    {
        View::render('client_intake_review/index', [
            'pending' => ClientIntakeRepository::pending(),
            'resolved' => ClientIntakeRepository::recentResolved(),
        ], 'layout/base');
    }

    public function accept(array $params): void
    {
        $user = AuthService::currentUser();
        $id = (int) ($params['id'] ?? 0);
        $submission = ClientIntakeRepository::find($id);

        if (!$submission || $submission['status'] !== 'pending') {
            Flash::set('error', 'Submission not found, or already resolved.');
            header('Location: /client-intake');
            return;
        }

        $clientUniqueNumber = ReferenceNumberService::generateClientUniqueNumber();
        $consigneeSameAsBuyer = (int) ($submission['consignee_same_as_buyer'] ?? 1) === 1;
        $clientId = ClientRepository::create([
            'company_legal_name'     => $submission['company_legal_name'],
            'billing_address'        => $submission['billing_address'],
            'billing_address_line1'  => $submission['billing_address_line1'] ?? null,
            'billing_address_line2'  => $submission['billing_address_line2'] ?? null,
            'billing_city'           => $submission['billing_city'] ?? null,
            'billing_postcode'       => $submission['billing_postcode'] ?? null,
            'billing_country'        => $submission['billing_country'] ?? null,
            'vat_eori_tax_no'        => $submission['vat_eori_tax_no'],
            'contact_person'         => $submission['contact_person'],
            'email'                  => $submission['email'],
            'phone'                  => $submission['phone'],
            'country_of_destination' => $submission['country_of_destination'],
            'coo_type'               => $submission['coo_type'] ?: 'To Be Confirmed',
            // Section BB — the client's own self-service Consignee split
            // from the quotation-details form; Notify Party is never
            // collected at this stage (see ClientIntakeController), so it
            // stays at its default (same-as-consignee) here.
            'consignee_same_as_buyer' => $consigneeSameAsBuyer ? 1 : 0,
            'consignee_name'              => $consigneeSameAsBuyer ? null : $submission['consignee_name'],
            'consignee_address_line1'     => $consigneeSameAsBuyer ? null : $submission['consignee_address_line1'],
            'consignee_address_line2'     => $consigneeSameAsBuyer ? null : $submission['consignee_address_line2'],
            'consignee_city'              => $consigneeSameAsBuyer ? null : $submission['consignee_city'],
            'consignee_postcode'          => $consigneeSameAsBuyer ? null : $submission['consignee_postcode'],
            'consignee_country'           => $consigneeSameAsBuyer ? null : $submission['consignee_country'],
            'consignee_vat_eori_tax_no'   => $consigneeSameAsBuyer ? null : $submission['consignee_vat_eori_tax_no'],
            'consignee_contact_person'    => $consigneeSameAsBuyer ? null : $submission['consignee_contact_person'],
            'consignee_phone'             => $consigneeSameAsBuyer ? null : $submission['consignee_phone'],
            'consignee_email'             => $consigneeSameAsBuyer ? null : $submission['consignee_email'],
        ], (int) $user['id'], $clientUniqueNumber);

        ClientIntakeRepository::markConverted($id, $clientId, (int) $user['id']);

        Flash::set('success', "Client created — Buyer Inquiry Ref {$clientUniqueNumber}. Proceed to create the order and generate the Quotation.");
        header("Location: /clients/{$clientId}");
    }

    public function reject(array $params): void
    {
        $user = AuthService::currentUser();
        $id = (int) ($params['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: /client-intake');
            return;
        }

        ClientIntakeRepository::markRejected($id, (int) $user['id'], $reason);
        Flash::set('success', 'Request rejected.');
        header('Location: /client-intake');
    }
}
