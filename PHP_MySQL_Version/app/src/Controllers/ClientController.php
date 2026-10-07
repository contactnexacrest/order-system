<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AdminOverrideRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\ClientPortalService;
use App\Services\ReferenceNumberService;
use App\Services\SuperAdminService;
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
        // Batch 3 #4 — if this page is being shown again after a validation failure on submit,
        // re-populate the form from what was typed rather than making the user retype everything.
        View::render('clients/create', ['old' => Flash::pullOld()], 'layout/base');
    }

    public function store(array $params): void
    {
        $user = AuthService::currentUser();

        $companyLegalName = trim((string) ($_POST['company_legal_name'] ?? ''));
        $billingAddress = trim((string) ($_POST['billing_address'] ?? ''));

        if ($companyLegalName === '' || $billingAddress === '') {
            Flash::set('error', 'Company legal name and billing address are required.');
            Flash::setOld($_POST);
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
            'coo_type'              => trim((string) ($_POST['coo_type'] ?? '')) ?: 'To Be Confirmed',
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
        $user = AuthService::currentUser();
        View::render('clients/edit', [
            'client' => $client,
            'isSuperAdmin' => SuperAdminService::isEffective((int) $user['id']),
        ], 'layout/base');
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

        $user = AuthService::currentUser();
        if ((int) $client['is_data_locked'] === 1) {
            // Locked forever once consented to (PI-details) or advance-in-motion
            // (Record Advance Remittance) — see docs/schema.sql Section AC. The
            // one narrow escape hatch: a Super Admin fixing their own or
            // another staff member's data-entry mistake, never a client-
            // requested change, mandatory reason, fully audit-logged.
            $isOverride = !empty($_POST['override_lock']) && SuperAdminService::isEffective((int) $user['id']);
            if (!$isOverride) {
                Flash::set('error', 'This client\'s details are locked and can never be edited — create a new client record instead. (A Super Admin can override this only to fix a genuine staff data-entry error, never a client-requested change.)');
                header("Location: /clients/{$clientId}/edit");
                return;
            }
            $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));
            $reasonError = ReasonValidator::check($overrideReason);
            if ($reasonError !== null) {
                Flash::set('error', $reasonError);
                header("Location: /clients/{$clientId}/edit");
                return;
            }
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
            'agreement_footer_text' => trim((string) ($_POST['agreement_footer_text'] ?? '')) ?: null,
        ];

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
                $newVal !== null ? (string) $newVal : null,
                (int) $client['is_data_locked'] === 1 ? trim((string) ($_POST['override_reason'] ?? '')) : null
            );
        }
        if ((int) $client['is_data_locked'] === 1) {
            AuditLogRepository::log((int) $user['id'], 'CLIENT_LOCK_OVERRIDDEN', 'clients', $clientId, null, null, null, trim((string) ($_POST['override_reason'] ?? '')));
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

    /**
     * docs/schema.sql Section AV — admin-only toggle deciding whether THIS
     * client can ever be impersonated, independent of the global
     * client_impersonation_enabled switch and of who holds the
     * impersonate_client permission. Deliberately gated behind
     * manage_company_settings (not manage_orders, which every ordinary
     * client-edit action uses) since it's a security-relevant switch, not
     * routine client data.
     */
    public function setImpersonationAllowed(array $params): void
    {
        $clientId = (int) $params['id'];
        $client = ClientRepository::find($clientId);
        if (!$client) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }

        $allow = !empty($_POST['allow_staff_impersonation']);
        ClientRepository::setAllowStaffImpersonation($clientId, $allow);
        $user = AuthService::currentUser();
        AuditLogRepository::log(
            (int) $user['id'],
            'CLIENT_IMPERSONATION_ALLOWED_CHANGED',
            'clients',
            $clientId,
            'allow_staff_impersonation',
            $client['allow_staff_impersonation'] ? '1' : '0',
            $allow ? '1' : '0'
        );
        Flash::set('success', $allow
            ? "Staff can now log in as {$client['company_legal_name']} (if the global switch and the impersonate_client permission also allow it)."
            : "Staff can no longer log in as {$client['company_legal_name']}.");
        header("Location: /clients/{$clientId}");
    }

    /**
     * docs/schema.sql Section AV — staff-initiated client-portal session,
     * for a client who cannot use the portal themselves. Three gates, all
     * re-checked here (defense in depth — the button itself is only shown
     * when all three already hold): the route's own impersonate_client
     * permission, the global client_impersonation_enabled switch, and this
     * specific client's own allow_staff_impersonation flag.
     */
    public function impersonate(array $params): void
    {
        $clientId = (int) $params['id'];
        $client = ClientRepository::find($clientId);
        if (!$client) {
            http_response_code(404);
            echo 'Client not found.';
            return;
        }
        if (CompanySettingsRepository::get('client_impersonation_enabled') !== '1') {
            Flash::set('error', 'Staff client-portal impersonation is switched off (Company Settings).');
            header("Location: /clients/{$clientId}");
            return;
        }
        if (!$client['allow_staff_impersonation']) {
            Flash::set('error', "Impersonation isn't enabled for {$client['company_legal_name']} — turn it on below first.");
            header("Location: /clients/{$clientId}");
            return;
        }
        if (!$client['is_active']) {
            Flash::set('error', 'This client is deactivated and cannot be impersonated.');
            header("Location: /clients/{$clientId}");
            return;
        }

        $user = AuthService::currentUser();
        ClientPortalService::startImpersonation((int) $user['id'], $clientId);
        header('Location: /client');
    }
}
