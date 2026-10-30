<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\SupplierFactoryAddressRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\VendorDetailsRepository;
use App\Services\AuthService;
use App\Services\TestModeService;
use App\Services\VendorDetailsService;

/**
 * Suppliers directory (docs/schema.sql Section BZ) — built the same way as
 * the Logistics Partners directory. Gated on manage_suppliers (Admin/MD/ED
 * and Super Admin by default). Editing or deleting a supplier here never
 * changes an existing order: order_supplier_po keeps its own snapshot of
 * the supplier's details.
 */
final class SupplierController
{
    public function index(array $params): void
    {
        $type = trim((string) ($_GET['type'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));
        View::render('suppliers/index', [
            'suppliers' => SupplierRepository::all(true, $type !== '' ? $type : null, $search !== '' ? $search : null),
            'usedIds' => SupplierRepository::idsUsedOnOrders(),
            'supplierTypes' => self::typeOptions(),
            'filterType' => $type,
            'search' => $search,
        ], 'layout/base');
    }

    public function createForm(array $params): void
    {
        View::render('suppliers/form', [
            'bank' => VendorDetailsRepository::bankDetails(null),
            'supplier' => null,
            'supplierTypes' => self::typeOptions(),
            'factoryAddresses' => [],
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $data = $this->collectFormData();
        if ($data === null || ($bankError = VendorDetailsService::bankInputError('supplier', null)) !== null) {
            if (isset($bankError)) {
                Flash::set('error', $bankError);
            }
            header('Location: /suppliers/create');
            return;
        }

        $userId = (int) AuthService::currentUserId();
        $id = SupplierRepository::create($data, $userId);
        VendorDetailsService::applyStaffBankInput('supplier', $id, $userId);
        if (TestModeService::isEnabled()) {
            SupplierRepository::markTest($id);
        }
        AuditLogRepository::log($userId, 'SUPPLIER_CREATED', 'suppliers', $id, null, null, $data['supplier_legal_name']);
        Flash::set('success', "\"{$data['supplier_legal_name']}\" added to the suppliers directory. Add its factory addresses below if it has any.");
        header("Location: /suppliers/{$id}/edit");
    }

    public function editForm(array $params): void
    {
        $id = (int) $params['id'];
        $supplier = SupplierRepository::find($id);
        if (!$supplier) {
            http_response_code(404);
            echo 'Supplier not found.';
            return;
        }
        View::render('suppliers/form', [
            'supplier' => $supplier,
            'editFingerprint' => \App\Helpers\EditFingerprint::of($supplier, SupplierRepository::EDIT_FIELDS),
            'supplierTypes' => self::typeOptions($supplier['types']),
            'factoryAddresses' => SupplierFactoryAddressRepository::forSupplier($id),
            'usedOnAnOrder' => SupplierRepository::isUsedOnAnOrder($id),
            'bank' => VendorDetailsRepository::bankDetails($supplier),
            'detailsLink' => self::detailsLinkUrl($supplier),
        ], 'layout/base');
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $supplier = SupplierRepository::find($id);
        if (!$supplier) {
            http_response_code(404);
            echo 'Supplier not found.';
            return;
        }

        // QA Round 6, R6-N27: someone else saved this supplier since the form was opened.
        if ($conflict = \App\Helpers\EditFingerprint::conflict($supplier, SupplierRepository::EDIT_FIELDS, 'suppliers', $id, 'supplier')) {
            Flash::set('error', $conflict);
            header("Location: /suppliers/{$id}/edit");
            return;
        }
        $data = $this->collectFormData($supplier['types']);
        if ($data === null) {
            header("Location: /suppliers/{$id}/edit");
            return;
        }

        if (($bankError = VendorDetailsService::bankInputError('supplier', $id)) !== null) {
            Flash::set('error', $bankError);
            header("Location: /suppliers/{$id}/edit#bank-details");
            return;
        }

        SupplierRepository::update($id, $data);
        VendorDetailsService::applyStaffBankInput('supplier', $id, (int) AuthService::currentUserId());
        AuditLogRepository::log((int) AuthService::currentUserId(), 'SUPPLIER_UPDATED', 'suppliers', $id, 'supplier_legal_name', $supplier['supplier_legal_name'], $data['supplier_legal_name']);
        Flash::set('success', "\"{$data['supplier_legal_name']}\" updated. Orders that already saved Supplier PO terms keep the details they recorded.");
        header('Location: /suppliers');
    }

    public function toggleActive(array $params): void
    {
        $id = (int) $params['id'];
        $supplier = SupplierRepository::find($id);
        if (!$supplier) {
            Flash::set('error', 'Supplier not found.');
            header('Location: /suppliers');
            return;
        }

        SupplierRepository::toggleActive($id);
        $nowActive = !((bool) $supplier['is_active']);
        AuditLogRepository::log((int) AuthService::currentUserId(), $nowActive ? 'SUPPLIER_REACTIVATED' : 'SUPPLIER_DEACTIVATED', 'suppliers', $id, 'is_active', (string) (int) $supplier['is_active'], (string) (int) $nowActive);
        Flash::set('success', "\"{$supplier['supplier_legal_name']}\" " . ($nowActive ? 'reactivated.' : 'deactivated — it no longer appears in the Supplier PO supplier list.'));
        header('Location: /suppliers');
    }

    public function delete(array $params): void
    {
        $id = (int) $params['id'];
        $supplier = SupplierRepository::find($id);
        if (!$supplier) {
            Flash::set('error', 'Supplier not found.');
            header('Location: /suppliers');
            return;
        }
        if (SupplierRepository::isUsedOnAnOrder($id)) {
            Flash::set('error', "\"{$supplier['supplier_legal_name']}\" has been used on an order, so it can't be deleted — deactivate it instead to hide it from new Supplier POs.");
            header('Location: /suppliers');
            return;
        }

        SupplierRepository::delete($id);
        AuditLogRepository::log((int) AuthService::currentUserId(), 'SUPPLIER_DELETED', 'suppliers', $id, 'supplier_legal_name', $supplier['supplier_legal_name'], null);
        Flash::set('success', "\"{$supplier['supplier_legal_name']}\" removed from the directory.");
        header('Location: /suppliers');
    }

    public function addFactoryAddress(array $params): void
    {
        $id = (int) $params['id'];
        if (!SupplierRepository::find($id)) {
            Flash::set('error', 'Supplier not found.');
            header('Location: /suppliers');
            return;
        }
        $address = trim((string) ($_POST['address'] ?? ''));
        if ($address === '') {
            Flash::set('error', 'Factory address is required.');
            header("Location: /suppliers/{$id}/edit#factory-addresses");
            return;
        }
        $addressId = SupplierFactoryAddressRepository::create($id, $address, trim((string) ($_POST['label'] ?? '')) ?: null);
        AuditLogRepository::log((int) AuthService::currentUserId(), 'SUPPLIER_FACTORY_ADDRESS_CREATED', 'supplier_factory_addresses', $addressId, null, null, $address);
        Flash::set('success', 'Factory address added.');
        header("Location: /suppliers/{$id}/edit#factory-addresses");
    }

    public function deleteFactoryAddress(array $params): void
    {
        $id = (int) $params['id'];
        $addressId = (int) $params['addressId'];
        $address = SupplierFactoryAddressRepository::find($addressId);
        if (!$address || (int) $address['supplier_id'] !== $id) {
            Flash::set('error', 'Factory address not found.');
            header("Location: /suppliers/{$id}/edit#factory-addresses");
            return;
        }
        SupplierFactoryAddressRepository::delete($addressId);
        AuditLogRepository::log((int) AuthService::currentUserId(), 'SUPPLIER_FACTORY_ADDRESS_DELETED', 'supplier_factory_addresses', $addressId, 'address', $address['address'], null);
        Flash::set('success', 'Factory address removed. Supplier POs already saved keep the delivery location they recorded.');
        header("Location: /suppliers/{$id}/edit#factory-addresses");
    }

    /**
     * Active Supplier Type options, plus any type a supplier already holds
     * whose option has since been deactivated (so editing never silently
     * drops it).
     *
     * @param array<int,string> $alsoInclude
     * @return array<int,string>
     */
    /** Owner request (2026-10-27): send the supplier the Vendor Details Link (company + bank details). */
    public function sendDetailsLink(array $params): void
    {
        $id = (int) $params['id'];
        if (!SupplierRepository::find($id)) {
            http_response_code(404);
            echo 'Supplier not found.';
            return;
        }
        $sent = VendorDetailsService::sendDetailsLink('supplier', $id, (int) AuthService::currentUserId());
        Flash::set('success', 'Vendor Details Link ' . ($sent['emailed_to'] ? "emailed to {$sent['emailed_to']}" : 'generated') . ": {$sent['link']}");
        header("Location: /suppliers/{$id}/edit#vendor-details-link");
    }

    private static function detailsLinkUrl(array $vendor): ?string
    {
        $token = VendorDetailsRepository::liveDetailsToken($vendor);
        return $token ? rtrim(\App\Config\Env::get('APP_URL', ''), '/') . '/vendor-details/' . $token : null;
    }

    private static function typeOptions(array $alsoInclude = []): array
    {
        $options = array_map(static fn(array $o): string => $o['option_value'], LookupRepository::dropdownOptions('supplier_type'));
        foreach ($alsoInclude as $type) {
            if (!in_array($type, $options, true)) {
                $options[] = $type;
            }
        }
        return $options;
    }

    /**
     * @param array<int,string> $existingTypes types the supplier already holds (still accepted even if since deactivated)
     * @return array<string,mixed>|null null if validation failed (flash already set)
     */
    private function collectFormData(array $existingTypes = []): ?array
    {
        $name = trim((string) ($_POST['supplier_legal_name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Supplier legal name is required.');
            return null;
        }

        $allowedTypes = self::typeOptions($existingTypes);
        $types = array_values(array_unique(array_filter(array_map(
            static fn($t): string => trim((string) $t),
            (array) ($_POST['types'] ?? [])
        ), static fn(string $t): bool => $t !== '')));
        if ($types === []) {
            Flash::set('error', 'Select at least one supplier type.');
            return null;
        }
        foreach ($types as $type) {
            if (!in_array($type, $allowedTypes, true)) {
                Flash::set('error', "\"{$type}\" is not a valid supplier type.");
                return null;
            }
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Enter a valid email address, or leave it blank.');
            return null;
        }

        $field = static fn(string $key): ?string => trim((string) ($_POST[$key] ?? '')) ?: null;
        return [
            'supplier_legal_name'     => $name,
            'types'                   => $types,
            'address'                 => $field('address'),
            'city'                    => $field('city'),
            'state'                   => $field('state'),
            'gstin'                   => $field('gstin'),
            'pan'                     => $field('pan'),
            'contact_person'          => $field('contact_person'),
            'contact_person_phone'    => $field('contact_person_phone'),
            'contact_person_whatsapp' => $field('contact_person_whatsapp'),
            'phone'                   => $field('phone'),
            'email'                   => $email !== '' ? $email : null,
            'whatsapp_number'         => $field('whatsapp_number'),
            'notes'                   => $field('notes'),
        ];
    }
}
