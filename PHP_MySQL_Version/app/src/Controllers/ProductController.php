<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProductImageRepository;
use App\Repositories\ProductMiscChargeRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\ProductService;

/**
 * Product Interface — internal product catalog (docs/schema.sql Section
 * U). Completely independent of the order pipeline: nothing here is ever
 * selected into an order, and this catalog is never shown to clients.
 *
 * Two visibility boundaries are enforced SERVER-SIDE (not just hidden in
 * the view), because both are real information-disclosure boundaries:
 *  - browse_product_catalog: without it, a user with only
 *    view_product_catalog gets a search box and no full-list query ever
 *    runs for them (see index()).
 *  - view_product_pricing: without it, pricing/supplier/misc-charge data
 *    is stripped out of the arrays passed to the view, not merely hidden
 *    with CSS (see index() and show()).
 */
final class ProductController
{
    private const ALLOWED_MIME = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5MB

    private ProductService $service;

    public function __construct()
    {
        $this->service = new ProductService();
    }

    public function index(array $params): void
    {
        [$canBrowse, $canViewPricing, $canManage] = $this->perms();

        $query = trim((string) ($_GET['q'] ?? ''));
        $searched = $query !== '';

        if ($searched) {
            $products = ProductRepository::search($query);
        } elseif ($canBrowse) {
            $products = ProductRepository::all();
        } else {
            // Search-only user with no query submitted yet — no full-list
            // query executes at all. This is the actual enforcement point;
            // everything else here is just what gets rendered.
            $products = [];
        }

        if ($canViewPricing) {
            foreach ($products as &$product) {
                $suppliers = ProductSupplierRepository::forProduct((int) $product['id']);
                $primary = null;
                foreach ($suppliers as $supplier) {
                    if (!empty($supplier['is_primary'])) {
                        $primary = $supplier;
                        break;
                    }
                }
                $primary = $primary ?? ($suppliers[0] ?? null);
                $product['headline_fob'] = $primary ? $this->service->effectiveFobForSupplier($primary, $product) : null;
            }
            unset($product);
        } else {
            // Strip pricing data out of the response entirely — not just
            // hidden in the view — for roles without view_product_pricing.
            foreach ($products as &$product) {
                unset(
                    $product['default_factory_cost'],
                    $product['default_transportation_cost'],
                    $product['default_packing_cost'],
                    $product['default_loading_cost'],
                    $product['default_cha_cost']
                );
            }
            unset($product);
        }

        View::render('products/index', [
            'products'       => $products,
            'query'          => $query,
            'searched'       => $searched,
            'canBrowse'      => $canBrowse,
            'canViewPricing' => $canViewPricing,
            'canManage'      => $canManage,
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        View::render('products/create', [
            'product' => null,
        ], 'layout/base');
    }

    public function store(array $params): void
    {
        $data = $this->readProductForm();
        $error = $this->validateProduct($data);
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: /products/create');
            return;
        }

        $user = AuthService::currentUser();
        $id = ProductRepository::create($data, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_CREATED', 'products', $id, null, null, $data['name']);
        Flash::set('success', 'Product added to the catalog.');
        header("Location: /products/{$id}");
    }

    public function show(array $params): void
    {
        [, $canViewPricing, $canManage] = $this->perms();

        $id = (int) ($params['id'] ?? 0);
        $details = $this->service->getProductWithDetails($id);
        if (!$details['product']) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }

        if (!$canViewPricing) {
            unset(
                $details['product']['default_factory_cost'],
                $details['product']['default_transportation_cost'],
                $details['product']['default_packing_cost'],
                $details['product']['default_loading_cost'],
                $details['product']['default_cha_cost']
            );
            $details['suppliers'] = [];
            $details['misc_charges'] = [];
        }

        View::render('products/show', [
            'product'        => $details['product'],
            'images'         => $details['images'],
            'suppliers'      => $details['suppliers'],
            'miscCharges'    => $details['misc_charges'],
            'canViewPricing' => $canViewPricing,
            'canManage'      => $canManage,
        ], 'layout/base');
    }

    public function edit(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($id);
        if (!$product) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }
        View::render('products/edit', ['product' => $product], 'layout/base');
    }

    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($id);
        if (!$product) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }

        $data = $this->readProductForm();
        $error = $this->validateProduct($data);
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /products/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        ProductRepository::update($id, $data, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_UPDATED', 'products', $id, null, null, $data['name']);
        Flash::set('success', 'Product updated.');
        header("Location: /products/{$id}");
    }

    public function delete(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($id);
        if (!$product) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }

        $user = AuthService::currentUser();
        ProductRepository::delete($id);
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_DELETED', 'products', $id, null, $product['name'], null);
        Flash::set('success', 'Product removed from the catalog.');
        header('Location: /products');
    }

    public function uploadImage(array $params): void
    {
        $productId = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($productId);
        if (!$product) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            Flash::set('error', 'No file was uploaded, or the upload failed.');
            header("Location: /products/{$productId}");
            return;
        }

        $file = $_FILES['image'];
        if ($file['size'] > self::MAX_BYTES) {
            Flash::set('error', 'File too large (max 5MB).');
            header("Location: /products/{$productId}");
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            Flash::set('error', 'Unsupported file type. Use PNG or JPG.');
            header("Location: /products/{$productId}");
            return;
        }

        $ext = self::ALLOWED_MIME[$mime];
        $storageBase = Env::get('STORAGE_BASE_PATH', dirname(__DIR__, 3) . '/storage');
        $targetDir = $storageBase . '/products/' . $productId . '/images';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            Flash::set('error', 'Could not save the uploaded file.');
            header("Location: /products/{$productId}");
            return;
        }

        $user = AuthService::currentUser();
        $imageId = ProductImageRepository::create($productId, $targetPath, $mime, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_IMAGE_UPLOADED', 'product_images', $imageId, null, null, $targetPath);
        Flash::set('success', 'Image uploaded.');
        header("Location: /products/{$productId}");
    }

    public function deleteImage(array $params): void
    {
        $imageId = (int) ($params['imageId'] ?? 0);
        $image = ProductImageRepository::find($imageId);
        if (!$image) {
            Flash::set('error', 'Image not found.');
            header('Location: /products');
            return;
        }

        $productId = (int) $image['product_id'];
        $user = AuthService::currentUser();
        ProductImageRepository::delete($imageId);
        if (is_file($image['server_path'])) {
            @unlink($image['server_path']);
        }
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_IMAGE_DELETED', 'product_images', $imageId, null, $image['server_path'], null);
        Flash::set('success', 'Image removed.');
        header("Location: /products/{$productId}");
    }

    /** Streams a product image file — storage lives outside the web root, exactly like AssetController::preview(). */
    public function viewImage(array $params): void
    {
        $imageId = (int) ($params['imageId'] ?? 0);
        $image = ProductImageRepository::find($imageId);
        if (!$image || !is_file($image['server_path'])) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . ($image['mime_type'] ?: 'application/octet-stream'));
        header('Cache-Control: private, max-age=60');
        readfile($image['server_path']);
    }

    public function addSupplier(array $params): void
    {
        $productId = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($productId);
        if (!$product) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }

        $data = $this->readSupplierForm();
        $data['product_id'] = $productId;
        $error = $this->validateSupplier($data);
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /products/{$productId}");
            return;
        }

        $user = AuthService::currentUser();
        $supplierId = ProductSupplierRepository::create($data, (int) $user['id']);
        if (!empty($data['is_primary'])) {
            $this->service->setPrimarySupplier($productId, $supplierId, (int) $user['id']);
        }
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_SUPPLIER_ADDED', 'product_suppliers', $supplierId, null, null, $data['supplier_name']);
        Flash::set('success', 'Supplier added.');
        header("Location: /products/{$productId}");
    }

    public function updateSupplier(array $params): void
    {
        $supplierId = (int) ($params['supplierId'] ?? 0);
        $existing = ProductSupplierRepository::find($supplierId);
        if (!$existing) {
            Flash::set('error', 'Supplier not found.');
            header('Location: /products');
            return;
        }
        $productId = (int) $existing['product_id'];

        $data = $this->readSupplierForm();
        $data['product_id'] = $productId;
        $error = $this->validateSupplier($data);
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /products/{$productId}");
            return;
        }

        $user = AuthService::currentUser();
        ProductSupplierRepository::update($supplierId, $data, (int) $user['id']);
        if (!empty($data['is_primary'])) {
            $this->service->setPrimarySupplier($productId, $supplierId, (int) $user['id']);
        }
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_SUPPLIER_UPDATED', 'product_suppliers', $supplierId, null, null, $data['supplier_name']);
        Flash::set('success', 'Supplier updated.');
        header("Location: /products/{$productId}");
    }

    public function deleteSupplier(array $params): void
    {
        $supplierId = (int) ($params['supplierId'] ?? 0);
        $existing = ProductSupplierRepository::find($supplierId);
        if (!$existing) {
            Flash::set('error', 'Supplier not found.');
            header('Location: /products');
            return;
        }
        $productId = (int) $existing['product_id'];

        $user = AuthService::currentUser();
        ProductSupplierRepository::delete($supplierId);
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_SUPPLIER_DELETED', 'product_suppliers', $supplierId, null, $existing['supplier_name'], null);
        Flash::set('success', 'Supplier removed.');
        header("Location: /products/{$productId}");
    }

    public function setPrimarySupplier(array $params): void
    {
        $supplierId = (int) ($params['supplierId'] ?? 0);
        $existing = ProductSupplierRepository::find($supplierId);
        if (!$existing) {
            Flash::set('error', 'Supplier not found.');
            header('Location: /products');
            return;
        }
        $productId = (int) $existing['product_id'];

        $user = AuthService::currentUser();
        $this->service->setPrimarySupplier($productId, $supplierId, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_SUPPLIER_SET_PRIMARY', 'product_suppliers', $supplierId);
        Flash::set('success', 'Primary supplier updated.');
        header("Location: /products/{$productId}");
    }

    public function addMiscCharge(array $params): void
    {
        $productId = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($productId);
        if (!$product) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $amountRaw = trim((string) ($_POST['amount'] ?? ''));
        if ($label === '' || $amountRaw === '' || !is_numeric($amountRaw)) {
            Flash::set('error', 'A label and a numeric amount are required for a misc charge.');
            header("Location: /products/{$productId}");
            return;
        }

        $user = AuthService::currentUser();
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $chargeId = ProductMiscChargeRepository::create($productId, $label, (float) $amountRaw, $notes !== '' ? $notes : null, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_MISC_CHARGE_ADDED', 'product_misc_charges', $chargeId, null, null, "{$label}: {$amountRaw}");
        Flash::set('success', 'Misc charge added.');
        header("Location: /products/{$productId}");
    }

    public function deleteMiscCharge(array $params): void
    {
        $chargeId = (int) ($params['chargeId'] ?? 0);
        $charge = ProductMiscChargeRepository::find($chargeId);
        if (!$charge) {
            Flash::set('error', 'Misc charge not found.');
            header('Location: /products');
            return;
        }
        $productId = (int) $charge['product_id'];

        $user = AuthService::currentUser();
        ProductMiscChargeRepository::delete($chargeId);
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_MISC_CHARGE_DELETED', 'product_misc_charges', $chargeId, null, $charge['label'] . ': ' . $charge['amount'], null);
        Flash::set('success', 'Misc charge removed.');
        header("Location: /products/{$productId}");
    }

    /** @return array{0: bool, 1: bool, 2: bool} [canBrowse, canViewPricing, canManage] */
    private function perms(): array
    {
        $user = AuthService::currentUser();
        $roleId = $user && $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $userId = (int) $user['id'];
        return [
            PermissionService::can($userId, $roleId, 'browse_product_catalog'),
            PermissionService::can($userId, $roleId, 'view_product_pricing'),
            PermissionService::can($userId, $roleId, 'manage_product_catalog'),
        ];
    }

    /** @return array<string,mixed> */
    private function readProductForm(): array
    {
        return [
            'name'                        => trim((string) ($_POST['name'] ?? '')),
            'specifications'              => trim((string) ($_POST['specifications'] ?? '')),
            'hs_code'                     => trim((string) ($_POST['hs_code'] ?? '')),
            'origin'                      => $this->nullableString($_POST['origin'] ?? ''),
            'default_factory_cost'        => $this->nullableDecimal($_POST['default_factory_cost'] ?? ''),
            'default_transportation_cost' => $this->nullableDecimal($_POST['default_transportation_cost'] ?? ''),
            'default_packing_cost'        => $this->nullableDecimal($_POST['default_packing_cost'] ?? ''),
            'default_loading_cost'        => $this->nullableDecimal($_POST['default_loading_cost'] ?? ''),
            'default_cha_cost'            => $this->nullableDecimal($_POST['default_cha_cost'] ?? ''),
            'is_active'                   => isset($_POST['is_active']) ? 1 : 0,
        ];
    }

    /** hs_code (and name/specifications, both NOT NULL columns) validated server-side — never trusted from client-side only. */
    private function validateProduct(array $data): ?string
    {
        if ($data['name'] === '') {
            return 'Product name is required.';
        }
        if ($data['specifications'] === '') {
            return 'Specifications are required.';
        }
        if ($data['hs_code'] === '') {
            return 'HS Code is required.';
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function readSupplierForm(): array
    {
        $fobSource = (string) ($_POST['fob_source'] ?? 'direct');
        if (!in_array($fobSource, ['direct', 'computed'], true)) {
            $fobSource = 'direct';
        }
        return [
            'supplier_name'       => trim((string) ($_POST['supplier_name'] ?? '')),
            'location'            => $this->nullableString($_POST['location'] ?? ''),
            'contact_person'      => $this->nullableString($_POST['contact_person'] ?? ''),
            'contact_phone'       => $this->nullableString($_POST['contact_phone'] ?? ''),
            'contact_email'       => $this->nullableString($_POST['contact_email'] ?? ''),
            'fob_source'          => $fobSource,
            'fob_value'           => $this->nullableDecimal($_POST['fob_value'] ?? ''),
            'factory_cost'        => $this->nullableDecimal($_POST['factory_cost'] ?? ''),
            'transportation_cost' => $this->nullableDecimal($_POST['transportation_cost'] ?? ''),
            'packing_cost'        => $this->nullableDecimal($_POST['packing_cost'] ?? ''),
            'loading_cost'        => $this->nullableDecimal($_POST['loading_cost'] ?? ''),
            'cha_cost'            => $this->nullableDecimal($_POST['cha_cost'] ?? ''),
            'is_primary'          => isset($_POST['is_primary']) ? 1 : 0,
            'notes'               => $this->nullableString($_POST['notes'] ?? ''),
        ];
    }

    private function validateSupplier(array $data): ?string
    {
        if ($data['supplier_name'] === '') {
            return 'Supplier name is required.';
        }
        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        $value = trim((string) $value);
        return ($value === '' || !is_numeric($value)) ? null : (float) $value;
    }
}
