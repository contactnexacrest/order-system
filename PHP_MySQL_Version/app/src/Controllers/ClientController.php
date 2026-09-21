<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AdminOverrideRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\ReferenceNumberService;

final class ClientController
{
    public function index(array $params): void
    {
        View::render('clients/index', ['clients' => ClientRepository::all()], 'layout/base');
    }

    public function create(array $params): void
    {
        View::render('clients/create', [], 'layout/base');
    }

    public function store(array $params): void
    {
        $user = AuthService::currentUser();

        $companyLegalName = trim((string) ($_POST['company_legal_name'] ?? ''));
        $billingAddress = trim((string) ($_POST['billing_address'] ?? ''));

        if ($companyLegalName === '' || $billingAddress === '') {
            Flash::set('error', 'Company legal name and billing address are required.');
            header('Location: /clients/create');
            return;
        }

        $clientUniqueNumber = ReferenceNumberService::generateClientUniqueNumber();
        $clientId = ClientRepository::create([
            'company_legal_name'    => $companyLegalName,
            'billing_address'       => $billingAddress,
            'consignee_name'        => trim((string) ($_POST['consignee_name'] ?? '')) ?: null,
            'consignee_address'     => trim((string) ($_POST['consignee_address'] ?? '')) ?: null,
            'vat_eori_tax_no'       => trim((string) ($_POST['vat_eori_tax_no'] ?? '')) ?: null,
            'contact_person'        => trim((string) ($_POST['contact_person'] ?? '')) ?: null,
            'email'                 => trim((string) ($_POST['email'] ?? '')) ?: null,
            'phone'                 => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'country_of_destination' => trim((string) ($_POST['country_of_destination'] ?? '')) ?: null,
            'coo_type'              => trim((string) ($_POST['coo_type'] ?? '')) ?: 'TBC',
            'notify_party'          => trim((string) ($_POST['notify_party'] ?? '')) ?: null,
        ], (int) $user['id'], $clientUniqueNumber);

        Flash::set('success', "Client created — Buyer Inquiry Ref {$clientUniqueNumber}.");
        header("Location: /clients/{$clientId}");
    }

    public function show(array $params): void
    {
        $client = ClientRepository::find((int) $params['id']);
        if (!$client) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }
        $orders = OrderRepository::forClient((int) $client['id']);
        View::render('clients/show', ['client' => $client, 'orders' => $orders], 'layout/base');
    }

    /**
     * Spec Section 13 — "Client unique numbers" is explicitly named as an
     * Admin-editable field. Reason mandatory, logged with old/new value.
     */
    public function overrideUniqueNumber(array $params): void
    {
        $clientId = (int) $params['id'];
        $client = ClientRepository::find($clientId);
        if (!$client) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }

        $user = AuthService::currentUser();
        $newNumber = trim((string) ($_POST['client_unique_number'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($newNumber === '' || $newNumber === $client['client_unique_number']) {
            Flash::set('success', 'No change was made.');
            header("Location: /clients/{$clientId}");
            return;
        }
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header("Location: /clients/{$clientId}");
            return;
        }

        AdminOverrideRepository::updateClientUniqueNumber($clientId, $newNumber);
        AuditLogRepository::log((int) $user['id'], 'FIELD_EDIT', 'clients', $clientId, 'client_unique_number', $client['client_unique_number'], $newNumber, $reason);
        Flash::set('success', "Buyer Inquiry Ref overridden to {$newNumber}.");
        header("Location: /clients/{$clientId}");
    }
}
