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
use App\Services\TestModeService;

final class ClientController
{
    public function index(array $params): void
    {
        View::render('clients/index', ['clients' => ClientRepository::all()], 'layout/base');
    }

    public function inactiveIndex(array $params): void
    {
        View::render('clients/inactive', ['clients' => ClientRepository::allInactive()], 'layout/base');
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
        if (TestModeService::isEnabled()) {
            ClientRepository::markTest($clientId);
        }

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

    public function editForm(array $params): void
    {
        $client = ClientRepository::find((int) $params['id']);
        if (!$client) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }
        View::render('clients/edit', ['client' => $client], 'layout/base');
    }

    /**
     * Every field except client_unique_number, which stays behind the
     * separate reason-required override below (Spec Section 13 names it
     * explicitly as admin-only, logged with old/new value — general edit
     * shouldn't quietly bypass that).
     */
    public function update(array $params): void
    {
        $clientId = (int) $params['id'];
        $client = ClientRepository::find($clientId);
        if (!$client) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }

        $companyLegalName = trim((string) ($_POST['company_legal_name'] ?? ''));
        $billingAddress = trim((string) ($_POST['billing_address'] ?? ''));
        if ($companyLegalName === '' || $billingAddress === '') {
            Flash::set('error', 'Company legal name and billing address are required.');
            header("Location: /clients/{$clientId}/edit");
            return;
        }

        $data = [
            'company_legal_name'    => $companyLegalName,
            'billing_address'       => $billingAddress,
            'consignee_name'        => trim((string) ($_POST['consignee_name'] ?? '')) ?: null,
            'consignee_address'     => trim((string) ($_POST['consignee_address'] ?? '')) ?: null,
            'vat_eori_tax_no'       => trim((string) ($_POST['vat_eori_tax_no'] ?? '')) ?: null,
            'contact_person'        => trim((string) ($_POST['contact_person'] ?? '')) ?: null,
            'email'                 => trim((string) ($_POST['email'] ?? '')) ?: null,
            'phone'                 => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'country_of_destination' => trim((string) ($_POST['country_of_destination'] ?? '')) ?: null,
            'coo_type'              => trim((string) ($_POST['coo_type'] ?? '')) ?: null,
            'notify_party'          => trim((string) ($_POST['notify_party'] ?? '')) ?: null,
        ];

        $user = AuthService::currentUser();
        $changes = array_filter(
            array_map(
                static fn(string $field) => [$field, $client[$field] ?? null, $data[$field] ?? null],
                array_keys($data)
            ),
            static fn(array $c): bool => (string) ($c[1] ?? '') !== (string) ($c[2] ?? '')
        );

        ClientRepository::update($clientId, $data);

        foreach ($changes as [$field, $oldVal, $newVal]) {
            AuditLogRepository::log(
                (int) $user['id'],
                'CLIENT_UPDATED',
                'clients',
                $clientId,
                $field,
                $oldVal !== null ? (string) $oldVal : null,
                $newVal !== null ? (string) $newVal : null
            );
        }

        Flash::set('success', "{$companyLegalName} updated.");
        header("Location: /clients/{$clientId}");
    }

    /**
     * Deactivate/reactivate only — never a deletion path. A client with
     * orders on file must stay in the database indefinitely (same
     * reasoning as order archiving); this only removes them from the
     * default /clients list (ClientRepository::all() filters is_active),
     * never from search-by-direct-URL, and never touches their orders.
     */
    public function toggleActive(array $params): void
    {
        $clientId = (int) $params['id'];
        $client = ClientRepository::find($clientId);
        if (!$client) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }

        $user = AuthService::currentUser();
        $newState = !((bool) $client['is_active']);
        ClientRepository::setActive($clientId, $newState);
        AuditLogRepository::log(
            (int) $user['id'],
            $newState ? 'CLIENT_REACTIVATED' : 'CLIENT_DEACTIVATED',
            'clients',
            $clientId,
            'is_active',
            $client['is_active'] ? '1' : '0',
            $newState ? '1' : '0'
        );

        Flash::set('success', $newState
            ? "{$client['company_legal_name']} reactivated — visible in the main Clients list again."
            : "{$client['company_legal_name']} deactivated — hidden from the main Clients list. Nothing was deleted; their orders are untouched.");
        header('Location: /clients');
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
