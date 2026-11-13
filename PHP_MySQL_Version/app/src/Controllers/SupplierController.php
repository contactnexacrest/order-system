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
use App\Services\SupplierOffers;
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
        // D-360: All / Active / Inactive / "No product prices yet" (suppliers with no offer).
        $show = in_array((string) ($_GET['show'] ?? ''), ['active', 'inactive', 'no-prices'], true) ? (string) $_GET['show'] : 'all';
        $suppliers = SupplierRepository::all(true, $type !== '' ? $type : null, $search !== '' ? $search : null);
        $counts = SupplierOffers::countsBySupplier();
        $noPrices = count(array_filter($suppliers, static fn (array $s): bool => ($counts[(int) $s['id']] ?? 0) === 0));
        $suppliers = array_values(array_filter($suppliers, static fn (array $s): bool => match ($show) {
            'active' => (int) $s['is_active'] === 1,
            'inactive' => (int) $s['is_active'] !== 1,
            'no-prices' => ($counts[(int) $s['id']] ?? 0) === 0,
            default => true,
        }));
        $canSeePrices = \App\Services\MoneyVisibility::can(\App\Services\MoneyVisibility::PURCHASE);
        View::render('suppliers/index', [
            'suppliers' => $suppliers,
            'usedIds' => SupplierRepository::idsUsedOnOrders(),
            'supplierTypes' => self::typeOptions(),
            'filterType' => $type,
            'search' => $search,
            'show' => $show,
            'noPricesCount' => $noPrices,
            'offerCounts' => $counts,
            // D-360: each supplier's product offers (purchase-price viewers only).
            'offers' => $canSeePrices ? SupplierOffers::forSuppliers(array_map(static fn (array $s): int => (int) $s['id'], $suppliers)) : null,
            'canEditPrice' => self::canRecordPrices(),
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
            // D-303 (point 15): the prices this supplier has given for products (purchase-price viewers only).
            'productPrices' => \App\Services\MoneyVisibility::can(\App\Services\MoneyVisibility::PURCHASE) ? \App\Repositories\ProductSupplierRepository::forDirectorySupplier($id) : null,
            // D-359 / D-360: "+ Add product", "Edit price" and the offer switch for those who record supplier prices.
            'canEditPrice' => self::canRecordPrices(),
            'offerRows' => \App\Services\MoneyVisibility::can(\App\Services\MoneyVisibility::PURCHASE) ? array_column(SupplierOffers::forSuppliers([$id])[$id] ?? [], null, 'id') : [],
            'addProduct' => self::canRecordPrices() ? self::addProductView($supplier, 'supplier') : null,
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
        // D-303: "Details pending" (a supplier added quickly from a price) is cleared here once completed.
        if (array_key_exists('details_pending', $_POST)) {
            SupplierRepository::setDetailsPending($id, (string) $_POST['details_pending'] === '1');
        }
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
        Flash::set('success', $sent['message'], $sent['link']); // D-280
        header("Location: /suppliers/{$id}/edit#vendor-details-link");
    }

    /**
     * D-303 (point 15): "+ Add a new supplier (quick)" from a product price — name, location,
     * phone and type. The supplier goes into the directory marked "Details pending"; GSTIN,
     * bank and contacts follow later (details link or Procurement). It is never typed twice.
     */
    public function quickAdd(array $params): void
    {
        $name = trim((string) ($_POST['quick_name'] ?? ''));
        $location = trim((string) ($_POST['quick_location'] ?? ''));
        $phone = trim((string) ($_POST['quick_phone'] ?? ''));
        $type = trim((string) ($_POST['quick_type'] ?? ''));
        $productId = (int) ($_POST['product_id'] ?? 0);
        $back = $productId > 0 ? "/products/{$productId}/market-prices" : '/products/link-suppliers';
        $types = array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('supplier_type'));
        $error = null;
        if (mb_strlen($name) < 2 || mb_strlen($name) > 255) {
            $error = 'Supplier name: 2 to 255 characters.';
        } elseif (mb_strlen($location) < 2 || mb_strlen($location) > 120) {
            $error = 'Location: the town or district and state, e.g. "Ongole, Andhra Pradesh".';
        } elseif (!preg_match('/^\+?[0-9][0-9 \-]{6,18}$/', $phone)) {
            $error = 'Phone: digits, spaces or dashes, with the country code, e.g. +91-0000000000.';
        } elseif ($type !== '' && !in_array($type, $types, true)) {
            $error = 'Choose the supplier type from the list.';
        } elseif (SupplierRepository::findByExactName($name) !== null) {
            $error = "\"{$name}\" is already in the Suppliers directory — pick it from the list.";
        }
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: {$back}#add-price");
            return;
        }
        $userId = (int) AuthService::currentUserId();
        $id = SupplierRepository::quickAdd($name, $location, $phone, $type, $userId);
        AuditLogRepository::log($userId, 'SUPPLIER_QUICK_ADDED', 'suppliers', $id, 'supplier_legal_name', null, "{$name} — {$location} (details pending)");
        Flash::set('success', "\"{$name}\" added to the Suppliers directory (details pending) and picked below.");
        header('Location: ' . ($productId > 0 ? "/products/{$productId}/market-prices?supplier_id={$id}#add-price" : '/products/link-suppliers'));
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

    // ------------------------------------------------------------------ D-360: products on the Suppliers pages

    private static function canRecordPrices(): bool
    {
        $u = AuthService::currentUser();
        return $u !== null && \App\Services\PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, 'record_market_prices');
    }

    /** "suppliers" = the Suppliers list; "supplier" = the Edit supplier page. */
    private static function fromPage(): string
    {
        return (string) ($_GET['from'] ?? $_POST['from'] ?? '') === 'supplier' ? 'supplier' : 'suppliers';
    }

    private static function backTo(int $supplierId, string $from): string
    {
        return $from === 'supplier' ? "/suppliers/{$supplierId}/edit#product-prices" : "/suppliers#supplier-{$supplierId}";
    }

    /** What the "+ Add product" form needs (shared by the list's page and the supplier page's panel). @return array<string,mixed> */
    private static function addProductView(array $supplier, string $from): array
    {
        $old = $_SESSION['add_product_old'][(int) $supplier['id']] ?? [];
        unset($_SESSION['add_product_old'][(int) $supplier['id']]);
        return [
            'supplier' => $supplier, 'from' => $from, 'old' => $old, 'today' => date('Y-m-d'),
            'choices' => SupplierOffers::addProductChoices((int) $supplier['id']),
        ];
    }

    /** D-360: "+ Add product" from the Suppliers list (the same form as on the supplier page). */
    public function addProductForm(array $params): void
    {
        $supplier = SupplierRepository::find((int) ($params['id'] ?? 0));
        if (!$supplier) {
            http_response_code(404);
            echo 'Supplier not found.';
            return;
        }
        if (self::fromPage() === 'supplier') {
            header('Location: /suppliers/' . (int) $supplier['id'] . '/edit?add=1#add-product');
            return;
        }
        View::render('suppliers/add_product', ['addProduct' => self::addProductView($supplier, 'suppliers')], 'layout/base');
    }

    /**
     * D-360: saves "+ Add product" — an existing product, or a new one (name unique, category,
     * HS code; Inactive, not configured), with this supplier's price. A second offer of the same
     * product from the same supplier is refused: the user is sent to "Edit price" instead.
     */
    public function addProduct(array $params): void
    {
        $supplier = SupplierRepository::find((int) ($params['id'] ?? 0));
        if (!$supplier) {
            Flash::set('error', 'Supplier not found.');
            header('Location: /suppliers');
            return;
        }
        $sid = (int) $supplier['id'];
        $from = self::fromPage();
        $formUrl = $from === 'supplier' ? "/suppliers/{$sid}/edit?add=1#add-product" : "/suppliers/{$sid}/add-product";
        $fail = static function (string $message) use ($sid, $formUrl): void {
            $_SESSION['add_product_old'][$sid] = $_POST;
            unset($_SESSION['add_product_old'][$sid]['_csrf'], $_SESSION['add_product_old'][$sid]['csrf_token']);
            Flash::set('error', $message);
            header("Location: {$formUrl}");
        };
        if ((int) $supplier['is_active'] !== 1) {
            $fail('"' . $supplier['supplier_legal_name'] . '" is inactive — reactivate the supplier before adding its prices.');
            return;
        }
        $mode = (string) ($_POST['product_mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
        $product = null;
        $newName = '';
        $categoryId = 0;
        $hs = '';
        if ($mode === 'existing') {
            $product = \App\Repositories\ProductRepository::find((int) ($_POST['product_id'] ?? 0));
            if ($product === null) {
                $fail('Product: pick a product from the list, or create a new product.');
                return;
            }
            $dup = SupplierOffers::existingOffer($sid, (int) $product['id']);
            if ($dup !== null) {
                Flash::set('error', '"' . $supplier['supplier_legal_name'] . '" already has a price for "' . $product['name'] . '" — a supplier offers a product once. Change that price here with Edit price.');
                header('Location: /products/suppliers/' . (int) $dup['id'] . '/edit-price?from=' . $from);
                return;
            }
            $shape = SupplierOffers::priceShape($product);
        } else {
            $newName = trim((string) ($_POST['new_name'] ?? ''));
            $nameError = \App\Services\ProductDuplicateService::nameError($newName);
            if ($nameError !== null) {
                $fail('New product name: ' . $nameError);
                return;
            }
            $categoryId = (int) ($_POST['new_category_id'] ?? 0);
            $category = $categoryId > 0 ? \App\Repositories\ProductCategoryRepository::find($categoryId) : null;
            if ($category === null || (int) ($category['is_active'] ?? 1) !== 1) {
                $fail('Category: choose the new product\'s category.');
                return;
            }
            $hs = trim((string) ($_POST['new_hs_code'] ?? ''));
            if ($hs !== '' && !\App\Repositories\HsCodeRepository::isActiveCode($hs)) {
                $fail("HS code \"{$hs}\" is not on the HS Code Master List — pick one from the list, or leave it empty.");
                return;
            }
            $shape = SupplierOffers::priceShape(['id' => 0, 'category_id' => $categoryId], null, true);
        }
        [$in, $error] = self::readOffer($shape);
        if ($error !== null) {
            $fail($error);
            return;
        }
        $userId = (int) AuthService::currentUserId();
        $pdo = \App\Config\Database::connection();
        try {
            if ($mode === 'new') {
                $unit = !$shape['grid'] && strtoupper($in['unit']) !== 'CBM' ? $in['unit'] : ($shape['grid'] ? null : 'CBM');
                $pid = SupplierOffers::createProduct($newName, $categoryId, $hs, $unit, $userId);
                $product = \App\Repositories\ProductRepository::find($pid);
            }
            $offerId = SupplierOffers::addOffer($supplier, $product, $in, $userId, $mode === 'new');
        } catch (\RuntimeException $e) {
            if ($mode === 'new' && isset($pid)) {
                \App\Repositories\ProductRepository::delete($pid);
            }
            $fail($e->getMessage());
            return;
        }
        $row = \App\Repositories\ProductSupplierRepository::find($offerId);
        $listed = $row !== null ? SupplierOffers::listRow($product, $row, \App\Services\ThicknessPrices::rules($product)) : null;
        Flash::set('success', ($mode === 'new' ? 'New product "' . $product['name'] . '" created (Inactive, not configured yet) and added' : '"' . $product['name'] . '" added')
            . ' to ' . $supplier['supplier_legal_name'] . ($listed !== null ? ': ' . $listed['price'] : '') . ', quoted ' . date('j M Y', (int) strtotime($in['quoted_on'])) . '. The offer is active.');
        header('Location: ' . self::backTo($sid, $from));
    }

    /**
     * Reads the price part of "+ Add product" (the same checks the browser does first).
     *
     * @param array{grid: bool, base_mm: ?int, thicknesses: list<array<string,mixed>>, units: list<array<string,string>>} $shape
     * @return array{0: array<string,mixed>, 1: ?string}
     */
    private static function readOffer(array $shape): array
    {
        $mm = null;
        $unit = trim((string) ($_POST['unit'] ?? ''));
        if ($shape['grid']) {
            $raw = trim((string) ($_POST['thickness_mm'] ?? ''));
            $allowed = array_map(static fn (array $t): int => (int) $t['mm'], $shape['thicknesses']);
            $mm = $raw !== '' && ctype_digit($raw) ? (int) $raw : -1;
            if (!in_array($mm, $allowed, true)) {
                return [[], 'Base thickness: choose the thickness the supplier quoted.'];
            }
            $want = $mm === \App\Services\ThicknessPrices::CBM ? 'CBM' : 'm2';
            if ($unit !== $want) {
                return [[], 'Unit: a price for ' . \App\Services\ThicknessPrices::label($mm) . ' is per ' . ($want === 'CBM' ? 'CBM' : 'm²') . '.'];
            }
        } elseif (!in_array($unit, array_map(static fn (array $u): string => (string) $u['value'], $shape['units']), true)) {
            return [[], 'Unit: choose what the price is per.'];
        }
        $p = trim(str_replace(',', '', (string) ($_POST['price'] ?? '')));
        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $p) !== 1 || (float) $p <= 0) {
            return [[], 'Price: enter the supplier\'s price in ₹ (above 0, at most 2 decimals).'];
        }
        $q = trim((string) ($_POST['quoted_on'] ?? ''));
        $d = \DateTime::createFromFormat('!Y-m-d', $q);
        if ($d === false || $d->format('Y-m-d') !== $q) {
            return [[], 'Quoted on: enter the date the supplier quoted this price.'];
        }
        if ($q > date('Y-m-d')) {
            return [[], 'Quoted on: the date cannot be in the future.'];
        }
        $v = trim((string) ($_POST['valid_until'] ?? ''));
        if ($v !== '') {
            $dv = \DateTime::createFromFormat('!Y-m-d', $v);
            if ($dv === false || $dv->format('Y-m-d') !== $v) {
                return [[], 'Valid until: enter a valid date, or leave it empty.'];
            }
            if ($v < $q) {
                return [[], '"Valid until" cannot be before the quoted date.'];
            }
        }
        return [['price' => (float) $p, 'mm' => $mm, 'unit' => $unit, 'quoted_on' => $q, 'valid_until' => $v], null];
    }

    /** D-360: the "Offer active" switch (Suppliers list and supplier page). */
    public function offerActive(array $params): void
    {
        $row = \App\Repositories\ProductSupplierRepository::find((int) ($params['offerId'] ?? 0));
        $from = self::fromPage();
        if ($row === null || empty($row['supplier_id'])) {
            Flash::set('error', 'That supplier price no longer exists.');
            header('Location: /suppliers');
            return;
        }
        $on = (string) ($_POST['active'] ?? '') === '1';
        $product = \App\Repositories\ProductRepository::find((int) $row['product_id']);
        // The engine reads the switch live (SupplierOffers::usable); nothing else to recalculate.
        SupplierOffers::setOfferActive((int) $row['id'], $on, (int) AuthService::currentUserId());
        Flash::set('success', '"' . ($product['name'] ?? 'Product') . '" from ' . $row['supplier_name'] . ': offer ' . ($on ? 'switched on — its price is used again.' : 'switched off — its price is no longer used for pricing, matching or the lowest / highest supplier price.'));
        header('Location: ' . self::backTo((int) $row['supplier_id'], $from));
    }
}
