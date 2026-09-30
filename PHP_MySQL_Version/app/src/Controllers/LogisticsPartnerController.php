<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\LogisticsPartnerRepository;
use App\Services\AuthService;

/**
 * CHA / Transportation partner directory (docs/schema.sql Section AQ).
 * Gated on manage_logistics_partners, same tier as manage_hs_codes —
 * Admin/MD/ED and Super Admin only by default.
 */
final class LogisticsPartnerController
{
    public function index(array $params): void
    {
        $serviceType = trim((string) ($_GET['service_type'] ?? ''));
        View::render('logistics_partners/index', [
            'partners' => LogisticsPartnerRepository::all(true, $serviceType !== '' ? $serviceType : null),
            'serviceTypes' => LogisticsPartnerRepository::SERVICE_TYPES,
            'filterServiceType' => $serviceType,
        ], 'layout/base');
    }

    public function createForm(array $params): void
    {
        View::render('logistics_partners/create', [
            'serviceTypes' => LogisticsPartnerRepository::SERVICE_TYPES,
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $data = $this->collectFormData();
        if ($data === null) {
            header('Location: /logistics-partners/create');
            return;
        }

        $user = AuthService::currentUser();
        $id = LogisticsPartnerRepository::create($data, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'LOGISTICS_PARTNER_ADDED', 'logistics_partners', $id, null, null, $data['partner_name']);
        Flash::set('success', "\"{$data['partner_name']}\" added to the logistics partners directory.");
        header('Location: /logistics-partners');
    }

    public function editForm(array $params): void
    {
        $id = (int) $params['id'];
        $partner = LogisticsPartnerRepository::find($id);
        if (!$partner) {
            http_response_code(404);
            echo 'Logistics partner not found.';
            return;
        }
        View::render('logistics_partners/edit', [
            'partner' => $partner,
            'serviceTypes' => LogisticsPartnerRepository::SERVICE_TYPES,
        ], 'layout/base');
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $partner = LogisticsPartnerRepository::find($id);
        if (!$partner) {
            http_response_code(404);
            echo 'Logistics partner not found.';
            return;
        }

        $data = $this->collectFormData();
        if ($data === null) {
            header("Location: /logistics-partners/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        LogisticsPartnerRepository::update($id, $data);
        AuditLogRepository::log((int) $user['id'], 'LOGISTICS_PARTNER_UPDATED', 'logistics_partners', $id, 'partner_name', $partner['partner_name'], $data['partner_name']);
        Flash::set('success', "\"{$data['partner_name']}\" updated.");
        header('Location: /logistics-partners');
    }

    public function toggleActive(array $params): void
    {
        $id = (int) $params['id'];
        $partner = LogisticsPartnerRepository::find($id);
        if (!$partner) {
            Flash::set('error', 'Logistics partner not found.');
            header('Location: /logistics-partners');
            return;
        }

        $user = AuthService::currentUser();
        LogisticsPartnerRepository::toggleActive($id);
        $nowActive = !((bool) $partner['is_active']);
        AuditLogRepository::log((int) $user['id'], $nowActive ? 'LOGISTICS_PARTNER_REACTIVATED' : 'LOGISTICS_PARTNER_DEACTIVATED', 'logistics_partners', $id, 'is_active', (string) (int) $partner['is_active'], (string) (int) $nowActive);
        Flash::set('success', "\"{$partner['partner_name']}\" " . ($nowActive ? 'reactivated' : 'deactivated') . '.');
        header('Location: /logistics-partners');
    }

    public function delete(array $params): void
    {
        $id = (int) $params['id'];
        $partner = LogisticsPartnerRepository::find($id);
        if (!$partner) {
            Flash::set('error', 'Logistics partner not found.');
            header('Location: /logistics-partners');
            return;
        }

        $user = AuthService::currentUser();
        LogisticsPartnerRepository::delete($id);
        AuditLogRepository::log((int) $user['id'], 'LOGISTICS_PARTNER_DELETED', 'logistics_partners', $id, 'partner_name', $partner['partner_name'], null);
        Flash::set('success', "\"{$partner['partner_name']}\" removed from the directory.");
        header('Location: /logistics-partners');
    }

    /** @return array<string,mixed>|null null if validation failed (flash already set) */
    private function collectFormData(): ?array
    {
        $partnerName = trim((string) ($_POST['partner_name'] ?? ''));
        $serviceType = trim((string) ($_POST['service_type'] ?? ''));

        if ($partnerName === '') {
            Flash::set('error', 'Partner name is required.');
            return null;
        }
        if (!array_key_exists($serviceType, LogisticsPartnerRepository::SERVICE_TYPES)) {
            Flash::set('error', 'Select a valid service type.');
            return null;
        }

        return [
            'partner_name'            => $partnerName,
            'service_type'            => $serviceType,
            'address'                 => trim((string) ($_POST['address'] ?? '')) ?: null,
            'city'                    => trim((string) ($_POST['city'] ?? '')) ?: null,
            'state'                   => trim((string) ($_POST['state'] ?? '')) ?: null,
            'phone'                   => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'whatsapp_number'         => trim((string) ($_POST['whatsapp_number'] ?? '')) ?: null,
            'email'                   => trim((string) ($_POST['email'] ?? '')) ?: null,
            'contact_person_name'     => trim((string) ($_POST['contact_person_name'] ?? '')) ?: null,
            'contact_person_phone'    => trim((string) ($_POST['contact_person_phone'] ?? '')) ?: null,
            'contact_person_whatsapp' => trim((string) ($_POST['contact_person_whatsapp'] ?? '')) ?: null,
            'gstin'                   => trim((string) ($_POST['gstin'] ?? '')) ?: null,
            'pan'                     => trim((string) ($_POST['pan'] ?? '')) ?: null,
            'notes'                   => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
    }
}
