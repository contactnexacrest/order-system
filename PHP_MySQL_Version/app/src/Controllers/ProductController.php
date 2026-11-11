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
 * Product Interface — the product catalogue (docs/schema.sql Section U).
 * Products are picked on order lines and used for price checks; prices here
 * are internal and never shown to buyers. A used product is deactivated,
 * never deleted (owner, 12 Nov 2026 — see delete()).
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
        // Owner (12 Nov 2026): "Active (n) · Inactive (n) · All (n)" — on the full list and on a search.
        $status = ProductRepository::statusFilter($_GET['status'] ?? 'active');
        $statusCounts = null;

        if ($searched) {
            $products = ProductRepository::search($query, $status);
            $statusCounts = ProductRepository::statusCounts($query);
        } elseif ($canBrowse) {
            $products = ProductRepository::listing($status);
            $statusCounts = ProductRepository::statusCounts();
        } else {
            // Search-only user with no query submitted yet — no full-list
            // query executes at all. This is the actual enforcement point;
            // everything else here is just what gets rendered.
            $products = [];
        }
        // An inactive product says where it is used, so it is clear whether it may be deleted.
        foreach ($products as &$product) {
            if (empty($product['is_active'])) {
                $product['usage'] = ProductRepository::usage((int) $product['id']);
                $product['usage_text'] = ProductRepository::usageText($product['usage']);
                $product['delete_refusal'] = ProductRepository::deleteRefusal($product, $product['usage']);
            }
        }
        unset($product);

        // Owner decision D-245 (4 Nov 2026): the price build-up for MD/ED; the quote price and
        // lowest price for anyone who may see selling prices. Worked out before anything is stripped.
        [$canBuildup, $canStaffPrice] = $this->pricingPerms();
        if ($canBuildup || $canStaffPrice) {
            $__settings = \App\Services\PricingService::settings();
            $__program = \App\Services\PricingService::programOn();
            $__levels = \App\Repositories\PricingRepository::levels(true);
            foreach ($products as &$product) {
                $__p = \App\Services\PricingService::forProduct($product, $__levels, $__settings, $__program);
                $product['pricing'] = $canBuildup ? $__p : ['build' => $__p['build'] === null ? null : ['staff_quote' => $__p['build']['staff_quote'], 'staff_floor' => $__p['build']['staff_floor']]];
                // D-256: products with something to fix before they can be priced are flagged.
                $product['ready_to_fix'] = $canBuildup ? \App\Services\PricingService::readiness($product, $__settings)['to_fix'] : 0;
            }
            unset($product);
        }

        $canSeeHeadlineFob = $canViewPricing && \App\Services\MoneyVisibility::can('purchase');
        if ($canSeeHeadlineFob) {
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
                // D-304 (point 14): "Supplier price ₹ / unit" — the used-for-pricing price in the product's unit
                // (D-300: a price for every thickness shows its base thickness).
                $parts = $primary ? \App\Services\PricingService::fobParts($product, $primary, ($primary['price_basis'] ?? '') === 'cbm' ? 'cbm' : 'unit', null) : null;
                $product['headline_fob'] = $parts['supplier'] ?? null;
                $product['headline_basis'] = $primary === null ? null : (($primary['price_basis'] ?? '') === 'grid'
                    ? ($parts !== null && $parts['mm'] !== null ? \App\Services\ThicknessPrices::fmt((float) $parts['mm']) . ' mm base' : 'base')
                    : (($primary['price_basis'] ?? '') === 'cbm' ? 'per CBM' : (!empty($primary['is_primary']) ? 'used for pricing' : 'latest price')));
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
            'status'         => $status,
            'statusCounts'   => $statusCounts,
            'canBrowse'      => $canBrowse,
            'canViewPricing' => $canSeeHeadlineFob,
            'canManage'      => $canManage,
            'canBuildup'     => $canBuildup,
            'canStaffPrice'  => $canStaffPrice,
            // D-304 (point 14): the rate line (build-up viewers) and "≈ USD" under the supplier price (product editors).
            'fx'             => $canBuildup || ($canSeeHeadlineFob && $canManage) ? \App\Services\FxRateService::current() : null,
            'canFixHs'       => $canManage,
            'canLinkSuppliers' => $this->can('record_market_prices') || $this->can('manage_suppliers'),
            'canPriceRules'  => $canBuildup || $this->can('manage_pricing'),
            'canPricingSettings' => $canBuildup || $this->can('manage_pricing'),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        View::render('products/create', [
            'product' => null,
            'referenceUnits' => \App\Repositories\LookupRepository::dropdownOptions('unit'),
        ] + $this->formExtras(null), 'layout/base');
    }

    /**
     * D-302 (point 11): the HS code is picked from the HS Code Master List, pre-filled with the
     * category's default; people with manage_hs_codes may add a code there. D-300: the product's
     * CHA + transport default and its own price rules.
     *
     * @return array<string,mixed>
     */
    private function formExtras(?array $product): array
    {
        $categoryId = $product !== null ? \App\Services\ProductSpecs::categoryIdFor($product) : \App\Repositories\SpecRepository::systemCategoryId();
        $category = $categoryId !== null ? \App\Repositories\ProductCategoryRepository::find($categoryId) : null;
        return [
            'hsCodes' => \App\Repositories\HsCodeRepository::active(),
            'categoryDefaultHs' => (string) ($category['default_hs_code'] ?? ''),
            'categoryName' => (string) ($category['name'] ?? ''),
            'categoryId' => $categoryId,
            'canManageHs' => $this->can('manage_hs_codes'),
            'canManagePricing' => $this->can('manage_pricing'),
            'categoryRules' => $category,
            // D-335: the price rules in effect for this product (category's, or its own) and the container.
            'productRules' => \App\Services\ThicknessPrices::rules($product ?? ['id' => 0, 'category_id' => $categoryId]),
        ];
    }

    /**
     * D-300: reads the CHA + transport default (anyone who edits products) and the product's own
     * price rules (MD only — manage_pricing). Returns [fields, error].
     *
     * @return array{0: array<string,?string>, 1: ?string}
     */
    private function readPriceRuleFields(): array
    {
        $out = [];
        $spec = ['cha_transport_per_container' => ['CHA + transport per container (₹)', 0, 9999999999, 2]];
        // D-335: "Use own rules for this product" switched off = the category's rules (own values cleared).
        if ($this->can('manage_pricing') && !empty($_POST['own_rules_shown']) && empty($_POST['own_rules'])) {
            $out = ['thick_k_factor' => null, 'container_cbm' => null, 'container_max_t' => null];
            $spec = ['cha_transport_per_container' => $spec['cha_transport_per_container']];
        } elseif ($this->can('manage_pricing')) {
            $spec += ['thick_k_factor' => ['50 mm and above: 20 mm price per m² ×', 1, 1000, 3], 'container_cbm' => ['Container holds (CBM)', 0.5, 100, 2],
                'container_max_t' => ['Container maximum (tonnes)', 1, 100, 2]];
        }
        $packing = trim((string) ($_POST['packing_loading_cost'] ?? ''));
        if ($packing !== '' && (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $packing) !== 1)) {
            return [[], 'Packing + loading per unit (₹): enter a number with at most 2 decimals, or leave it empty.'];
        }
        foreach ($spec as $key => [$label, $min, $max, $dec]) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $v = trim((string) $_POST[$key]);
            if ($v === '') {
                $out[$key] = null;
                continue;
            }
            if (!preg_match('/^\d{1,10}(\.\d{1,' . $dec . '})?$/', $v) || (float) $v < $min || (float) $v > $max) {
                return [[], "{$label}: enter a number from {$min} to {$max} with at most {$dec} decimals, or leave it empty for the category's value."];
            }
            $out[$key] = number_format((float) $v, $dec, '.', '');
        }
        return [$out, null];
    }

    public function store(array $params): void
    {
        $data = $this->readProductForm();
        [$ruleFields, $ruleError] = $this->readPriceRuleFields();
        $error = $this->validateProduct($data) ?? $this->applySellingUnit($data) ?? $ruleError;
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
            header('Location: /products/create');
            return;
        }

        $user = AuthService::currentUser();
        $id = ProductRepository::create($data, (int) $user['id']);
        ProductRepository::setPriceRuleFields($id, $ruleFields);
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

        // Owner decision D-245 (4 Nov 2026): see index().
        [$canBuildup, $canStaffPrice] = $this->pricingPerms();
        $pricingFull = null;
        $pricingStaff = null;
        if ($canBuildup || $canStaffPrice) {
            $__p = \App\Services\PricingService::forProduct($details['product']);
            if ($canBuildup) {
                $pricingFull = $__p;
            } else {
                $pricingStaff = ['build' => $__p['build'] === null ? null : ['staff_quote' => $__p['build']['staff_quote'], 'staff_floor' => $__p['build']['staff_floor']]];
            }
        }

        // Owner decision D-245: supplier prices are the cost the selling price is built from —
        // only people who may see purchase prices get the supplier list and misc charges.
        $canSeeSuppliers = $canViewPricing && \App\Services\MoneyVisibility::can('purchase');
        if (!$canSeeSuppliers) {
            $details['suppliers'] = [];
            $details['misc_charges'] = [];
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

        // Owner decision D-256 (5 Nov 2026): "Pricing ready?" (Procurement sees only the
        // supplier-price lines) and "Try a price" (people who may see the build-up).
        $readiness = null;
        $canTry = $canBuildup || $this->can('manage_pricing');
        if ($canTry || $this->can('record_market_prices')) {
            $readiness = \App\Services\PricingService::readiness($details['product']);
            if (!$canTry) {
                $readiness['items'] = array_values(array_filter($readiness['items'], static fn (array $i): bool => $i['supplier']));
                $readiness['to_fix'] = count(array_filter($readiness['items'], static fn (array $i): bool => !$i['ok']));
            }
        }
        $tryPrice = null;
        if ($canTry) {
            $__offered = \App\Services\ProductSpecs::offered($details['product']);
            $__sel = is_array($_GET['try'] ?? null) ? array_map('strval', $_GET['try']) : [];
            // The Thickness select starts on "Supplier's base price": no thickness chosen, so the
            // supplier row's own BASE is priced (D-335) — the same figure as the product list.
            $__thick = null;
            foreach ($__offered as $__sp) {
                if ($__sp['kind'] === 'thickness') {
                    $__thick = (int) $__sp['id'];
                    break;
                }
            }
            $__baseLabel = null;
            if ($__thick !== null) {
                if (($__sel[$__thick] ?? $__sel[(string) $__thick] ?? '') === '') {
                    $__sel[$__thick] = \App\Services\ProductSpecs::SUPPLIER_BASE;
                }
                $__ps = \App\Services\PricingService::pricingSupplier((int) $details['product']['id']);
                if ($__ps !== null && ($__ps['price_basis'] ?? '') === 'grid') {
                    $__rules = \App\Services\ThicknessPrices::rules($details['product']);
                    $__baseLabel = \App\Services\ThicknessPrices::label(\App\Services\ThicknessPrices::baseOf($__ps, $__rules), (int) $__rules['cbm_from']);
                }
            }
            $tryPrice = ['offered' => $__offered, 'thickSpec' => $__thick,
                'baseOption' => 'Supplier\'s base price' . ($__baseLabel !== null ? ' (' . $__baseLabel . ')' : ''),
                'p' => $__offered !== [] ? \App\Services\PricingService::forProduct($details['product'], null, null, null, $__sel) : null];
        }

        $usage = ProductRepository::usage($id);
        View::render('products/show', [
            'usage'          => $usage,
            'usageText'      => ProductRepository::usageText($usage),
            'deleteRefusal'  => ProductRepository::deleteRefusal($details['product'], $usage),
            'readiness'      => $readiness,
            'tryPrice'       => $tryPrice,
            'product'        => $details['product'],
            'images'         => $details['images'],
            'suppliers'      => $details['suppliers'],
            'miscCharges'    => $details['misc_charges'],
            'canViewPricing' => $canViewPricing,
            'canManage'      => $canManage,
            'canSeeSuppliers' => $canSeeSuppliers,
            'pricingFull'    => $pricingFull,
            'pricingStaff'   => $pricingStaff,
            'canManagePricing' => $this->can('manage_pricing'),
            'canMarketPrices' => $this->can('record_market_prices') || $canBuildup,
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
        View::render('products/edit', [
            'product' => $product,
            'referenceUnits' => \App\Repositories\LookupRepository::dropdownOptions('unit'),
        ] + $this->formExtras($product), 'layout/base');
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

        $data = $this->readProductForm($product);
        [$ruleFields, $ruleError] = $this->readPriceRuleFields();
        $error = $this->validateProduct($data) ?? $this->applySellingUnit($data) ?? $ruleError;
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /products/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        ProductRepository::update($id, $data, (int) $user['id']);
        foreach ($ruleFields as $k => $v) {
            $old = $product[$k] ?? null;
            if (($old === null) !== ($v === null) || ($old !== null && (float) $old !== (float) $v)) {
                AuditLogRepository::log((int) $user['id'], 'PRODUCT_PRICE_RULE_CHANGED', 'products', $id, $k, $old === null ? 'category' : (string) $old, $v ?? 'category');
            }
        }
        ProductRepository::setPriceRuleFields($id, $ruleFields);
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

        // Owner (12 Nov 2026): only an inactive product that is not used anywhere is deleted —
        // checked here, not only by hiding the button.
        $refusal = ProductRepository::deleteRefusal($product, ProductRepository::usage($id));
        if ($refusal !== null) {
            Flash::set('error', 'Not deleted: ' . $refusal);
            header("Location: /products/{$id}");
            return;
        }

        $user = AuthService::currentUser();
        ProductRepository::delete($id);
        AuditLogRepository::log((int) $user['id'], 'PRODUCT_DELETED', 'products', $id, null, $product['name'], null);
        Flash::set('success', 'Product "' . $product['name'] . '" deleted.');
        header('Location: /products?status=inactive');
    }

    /** Owner (12 Nov 2026): POST /products/{id}/reactivate — back in the active list and the order-line picker. */
    public function reactivate(array $params): void
    {
        $this->setActive($params, true);
    }

    /** Owner (12 Nov 2026): POST /products/{id}/deactivate — hidden from the active list and the order-line picker; nothing is deleted. */
    public function deactivate(array $params): void
    {
        $this->setActive($params, false);
    }

    private function setActive(array $params, bool $active): void
    {
        $id = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($id);
        if (!$product) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }
        $back = ($_POST['return_to'] ?? '') === 'list' ? '/products?status=' . ($active ? 'inactive' : 'active') : "/products/{$id}";
        if ((bool) $product['is_active'] === $active) {
            Flash::set('success', 'Product "' . $product['name'] . '" is already ' . ($active ? 'active' : 'inactive') . '.');
            header("Location: {$back}");
            return;
        }
        $user = AuthService::currentUser();
        ProductRepository::setActive($id, $active, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], $active ? 'PRODUCT_REACTIVATED' : 'PRODUCT_DEACTIVATED', 'products', $id, 'is_active', $active ? '0' : '1', $active ? '1' : '0');
        Flash::set('success', $active
            ? 'Product "' . $product['name'] . '" is active again — it shows in the list and can be picked on order lines.'
            : 'Product "' . $product['name'] . '" is deactivated — it is hidden from the active list and cannot be picked on new order lines.');
        header("Location: {$back}");
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
        $storageBase = Env::storageBasePath();
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

    private function can(string $key): bool
    {
        $user = AuthService::currentUser();
        return $user !== null && PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, $key);
    }

    /**
     * Owner decision D-245: [sees the full build-up, sees only the quote and lowest price].
     * The build-up needs view_price_buildup; the two prices need the selling-price money class.
     *
     * @return array{0: bool, 1: bool}
     */
    private function pricingPerms(): array
    {
        $full = $this->can('view_price_buildup');
        return [$full, !$full && \App\Services\MoneyVisibility::can('selling')];
    }

    /** @return array{0: bool, 1: bool, 2: bool} [canBrowse, canViewPricing, canManage] */
    private function perms(): array
    {
        $user = AuthService::currentUser();
        $roleId = $user && $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $userId = (int) $user['id'];
        return [
            PermissionService::can($userId, $roleId, 'browse_product_catalog'),
            // Owner points 6/15: prices only for people who may also see selling prices (MoneyVisibility).
            PermissionService::can($userId, $roleId, 'view_product_pricing') && \App\Services\MoneyVisibility::can('selling'),
            PermissionService::can($userId, $roleId, 'manage_product_catalog'),
        ];
    }

    /**
     * D-335: the form no longer has the default factory / transportation / CHA cost (the columns
     * stay, kept as they are); "Packing + loading per unit" is stored in default_packing_cost
     * (default_loading_cost then empty — the two are still added up where they are read).
     *
     * @return array<string,mixed>
     */
    private function readProductForm(?array $existing = null): array
    {
        $keep = static fn (string $k): ?float => $existing !== null && ($existing[$k] ?? null) !== null ? (float) $existing[$k] : null;
        $hasPacking = array_key_exists('packing_loading_cost', $_POST);
        return [
            'name'                        => trim((string) ($_POST['name'] ?? '')),
            'specifications'              => trim((string) ($_POST['specifications'] ?? '')),
            'hs_code'                     => trim((string) ($_POST['hs_code'] ?? '')),
            'origin'                      => $this->nullableString($_POST['origin'] ?? ''),
            'default_factory_cost'        => array_key_exists('default_factory_cost', $_POST) ? $this->nullableDecimal($_POST['default_factory_cost']) : $keep('default_factory_cost'),
            'default_transportation_cost' => array_key_exists('default_transportation_cost', $_POST) ? $this->nullableDecimal($_POST['default_transportation_cost']) : $keep('default_transportation_cost'),
            'default_packing_cost'        => $hasPacking ? $this->nullableDecimal($_POST['packing_loading_cost']) : (array_key_exists('default_packing_cost', $_POST) ? $this->nullableDecimal($_POST['default_packing_cost']) : $keep('default_packing_cost')),
            'default_loading_cost'        => $hasPacking ? null : (array_key_exists('default_loading_cost', $_POST) ? $this->nullableDecimal($_POST['default_loading_cost']) : $keep('default_loading_cost')),
            'default_cha_cost'            => array_key_exists('default_cha_cost', $_POST) ? $this->nullableDecimal($_POST['default_cha_cost']) : $keep('default_cha_cost'),
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
        // D-302 (point 11): only a code from the HS Code Master List — so an order line can always use it.
        if (!\App\Repositories\HsCodeRepository::isActiveCode($data['hs_code'])) {
            return "HS code \"{$data['hs_code']}\" is not on the HS Code Master List — pick one from the list" . ($this->can('manage_hs_codes') ? ', or add it with "+ Add a new HS code".' : ' (ask someone who manages HS codes to add it).');
        }
        return null;
    }

    /** Owner decision D-245 P2: the product's selling unit (from the unit list), filled into an order line. */
    private function applySellingUnit(array &$data): ?string
    {
        $unit = trim((string) ($_POST['reference_unit'] ?? ''));
        $units = array_column(\App\Repositories\LookupRepository::dropdownOptions('unit'), 'option_value');
        if ($unit !== '' && !in_array($unit, $units, true)) {
            return 'Choose the selling unit from the list.';
        }
        $data['reference_unit'] = $unit !== '' ? $unit : null;
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
