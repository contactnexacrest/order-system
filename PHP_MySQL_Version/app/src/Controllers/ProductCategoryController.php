<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProductCategoryRepository;
use App\Services\AuthService;

/**
 * Owner decision D-215 (5a): Products → Categories — each category names
 * the attributes its order lines carry (e.g. Size, Grade, Colour). The
 * system category "Natural Stone" is today's products and keeps its
 * Dimensions and Finish unchanged. Port: productCategoryController.js.
 */
final class ProductCategoryController
{
    public function index(array $params): void
    {
        $mine = \App\Services\AssociateBrands::userBrandId();
        View::render('products/categories', [
            // D-435: the tree — main categories with their sub-categories and brand; a brand manager sees their brand + company-wide.
            'categories' => ProductCategoryRepository::tree(false, $mine),
            'mainCategories' => array_values(array_filter(ProductCategoryRepository::tree(true, $mine), static fn (array $c): bool => (int) $c['level'] === 1
                && ($mine === null || (int) ($c['brand_id'] ?? 0) === $mine))),
            'brands' => \App\Repositories\AssociateBrandRepository::usable(),
            'brandScope' => $mine,
            'brandFilter' => (string) ($_GET['brand'] ?? ''),
            'maxAttributes' => ProductCategoryRepository::MAX_ATTRIBUTES,
            // D-257 0B: each category's units, HS code, packing, quality documents and T&C clause set.
            'units' => array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('unit')),
            'packageTypes' => array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('package_type')),
            'hsCodes' => \App\Repositories\HsCodeRepository::active(),
            'tcClauseSets' => ProductCategoryRepository::TC_CLAUSE_SETS,
        ], 'layout/base');
    }

    public function store(array $params): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        // D-435: a sub-category names its main category; its attributes are optional (empty = the main category's).
        $mine = \App\Services\AssociateBrands::userBrandId();
        $parentRaw = trim((string) ($_POST['parent_id'] ?? ''));
        $parent = $parentRaw !== '' ? ProductCategoryRepository::findOwn((int) $parentRaw) : null;
        $rawLabels = (array) ($_POST['attributes'] ?? []);
        $hasLabels = array_filter(array_map(static fn ($v): string => trim((string) $v), $rawLabels)) !== [];
        [$labels, $error] = ($parent !== null && !$hasLabels) ? [[], null] : ProductCategoryRepository::parseLabels($rawLabels);
        if ($parentRaw !== '' && ($parent === null || !empty($parent['parent_id']))) {
            $error = 'Choose the main category for the sub-category.';
        } elseif ($parent !== null && $mine !== null && (int) ($parent['brand_id'] ?? 0) !== $mine) {
            $error = 'You can add sub-categories only to your own brand\'s categories.';
        }
        $brandId = null;
        if ($parent === null) {
            $brandRaw = trim((string) ($_POST['brand_id'] ?? ''));
            $brandId = $mine ?? ($brandRaw !== '' ? (int) $brandRaw : null); // a brand manager's categories are always their brand's
            if ($brandId !== null && \App\Repositories\AssociateBrandRepository::find($brandId) === null) {
                $error = 'Choose a brand from the list.';
            }
        }
        $error = self::nameError($name, null) ?? $error;
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: /products/categories');
            return;
        }
        $userId = (int) AuthService::currentUserId();
        $id = ProductCategoryRepository::create($name, $labels, $userId, $parent !== null ? (int) $parent['id'] : null, $brandId);
        AuditLogRepository::log($userId, 'PRODUCT_CATEGORY_CREATED', 'product_categories', $id, 'attributes', null,
            ($parent !== null ? $parent['name'] . ' › ' : '') . $name . ': ' . ($labels === [] ? '(attributes of ' . ($parent['name'] ?? '') . ')' : implode(', ', $labels)));
        Flash::set('success', $parent !== null
            ? "Sub-category \"{$name}\" added under {$parent['name']}. It follows {$parent['name']}'s settings until you change them."
            : "Category \"{$name}\" added with " . implode(', ', $labels) . '.');
        header('Location: /products/categories');
    }

    /** D-435: a main category's brand (empty = company-wide); only staff who look after all brands change it. Sub-categories follow. */
    private static function saveBrand(int $id, array $own): void
    {
        if (!empty($own['parent_id']) || !array_key_exists('brand_id', $_POST) || \App\Services\AssociateBrands::userBrandId() !== null) {
            return;
        }
        $brandRaw = trim((string) $_POST['brand_id']);
        $newBrand = $brandRaw !== '' && \App\Repositories\AssociateBrandRepository::find((int) $brandRaw) !== null ? (int) $brandRaw : null;
        $oldBrand = empty($own['brand_id']) ? null : (int) $own['brand_id'];
        if ($newBrand !== $oldBrand) {
            ProductCategoryRepository::setBrand($id, $newBrand);
            AuditLogRepository::log((int) AuthService::currentUserId(), 'PRODUCT_CATEGORY_BRAND_SET', 'product_categories', $id, 'brand_id',
                (string) ($oldBrand ?? 'company-wide'), (string) ($newBrand ?? 'company-wide'));
        }
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $category = ProductCategoryRepository::find($id);
        if ($category === null) {
            http_response_code(404);
            echo 'Category not found.';
            return;
        }
        $own = ProductCategoryRepository::findOwn($id) ?? $category;
        if (!empty($_POST['brand_only'])) { // D-435: the brand alone (Natural Stone keeps its name and attributes)
            self::saveBrand($id, $own);
            Flash::set('success', 'Brand saved for ' . $own['name'] . '.');
            header('Location: ' . self::backTo('/products/categories'));
            return;
        }
        if ((int) $category['is_system'] === 1) {
            Flash::set('error', 'Natural Stone is today\'s products — its Dimensions and Finish stay as they are.');
            header('Location: ' . self::backTo('/products/categories'));
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $rawLabels = (array) ($_POST['attributes'] ?? []);
        $hasLabels = array_filter(array_map(static fn ($v): string => trim((string) $v), $rawLabels)) !== [];
        [$labels, $error] = (!empty($own['parent_id']) && !$hasLabels) ? [[], null] : ProductCategoryRepository::parseLabels($rawLabels);
        $error = self::nameError($name, $id) ?? $error;
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: ' . self::backTo('/products/categories'));
            return;
        }
        $active = !empty($_POST['is_active']);
        ProductCategoryRepository::update($id, $name, $labels, $active);
        self::saveBrand($id, $own);
        AuditLogRepository::log((int) AuthService::currentUserId(), 'PRODUCT_CATEGORY_UPDATED', 'product_categories', $id, 'attributes',
            $category['name'] . ': ' . implode(', ', $category['attributes']), $name . ': ' . implode(', ', $labels) . ($active ? '' : ' (inactive)'));
        Flash::set('success', "Category \"{$name}\" saved.");
        header('Location: ' . self::backTo('/products/categories'));
    }

    /**
     * Owner decision D-257 Phase 0B (5 Nov 2026): a category's settings —
     * default and allowed units, default HS code, packing type, the quality
     * documents its orders need, and its T&C clause set. Natural Stone's
     * settings can be edited too (its attributes stay locked).
     */
    public function settings(array $params): void
    {
        $id = (int) $params['id'];
        $category = ProductCategoryRepository::findOwn($id); // D-435: a sub-category saves only its own values (empty = its main category's)
        if ($category === null) {
            http_response_code(404);
            echo 'Category not found.';
            return;
        }
        [$settings, $error] = ProductCategoryRepository::parseSettings(
            $_POST,
            array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('unit')),
            array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('package_type'))
        );
        if ($error !== null) {
            Flash::set('error', $category['name'] . ': ' . $error);
            header('Location: ' . self::backTo('/products/categories'));
            return;
        }
        ProductCategoryRepository::updateSettings($id, $settings);
        AuditLogRepository::log((int) AuthService::currentUserId(), 'PRODUCT_CATEGORY_SETTINGS_UPDATED', 'product_categories', $id, 'settings',
            ProductCategoryRepository::settingsSummary($category), ProductCategoryRepository::settingsSummary($settings));
        Flash::set('success', "Settings for \"{$category['name']}\" saved.");
        header('Location: ' . self::backTo('/products/categories'));
    }

    /**
     * Owner decision D-306 (point 20, 11 Nov 2026): Products › Categories › <category> is one
     * page with tabs — General (name, units, HS code, packing, documents) | Options and their
     * price effect (was Admin › Pricing › Specifications) | Price rules (D-300) | Products in
     * this category.
     */
    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $category = ProductCategoryRepository::find($id);
        if ($category === null) {
            http_response_code(404);
            echo 'Category not found.';
            return;
        }
        $user = AuthService::currentUser() ?? [];
        $can = static fn (string $k): bool => $user !== [] && \App\Services\PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, $k);
        $seesPricing = $can('view_price_buildup') || $can('manage_pricing');
        $tab = (string) ($_GET['tab'] ?? 'general');
        if (!in_array($tab, ['general', 'options', 'rules', 'products'], true) || (!$seesPricing && in_array($tab, ['options', 'rules'], true))) {
            $tab = 'general';
        }
        $products = array_values(array_filter(\App\Repositories\ProductRepository::all(false),
            static fn (array $p): bool => \App\Services\ProductSpecs::categoryIdFor($p) === $id));
        $own = ProductCategoryRepository::findOwn($id);
        $main = !empty($own['parent_id']) ? ProductCategoryRepository::find((int) $own['parent_id']) : null;
        $data = [
            // D-435: the form shows a sub-category's own values; 'main' is what it follows when a value is empty.
            // own values only — a brand's or main category's value shows as a hint, never saved as this category's
            'category' => $own ?? $category, 'tab' => $tab, 'seesPricing' => $seesPricing,
            'main' => $main, 'effective' => $category, 'subCategories' => ProductCategoryRepository::children($id),
            'brands' => \App\Repositories\AssociateBrandRepository::usable(),
            'canSetBrand' => $can('manage_product_catalog') && \App\Services\AssociateBrands::userBrandId() === null && $main === null,
            // D-435: a brand manager only views a company-wide category (or another brand's — the router refuses those).
            'canManageCatalog' => $can('manage_product_catalog') && \App\Services\AssociateBrands::refusalForPath("/products/categories/{$id}", 'POST') === null,
            'canManagePricing' => $can('manage_pricing') && \App\Services\AssociateBrands::refusalForPath("/products/categories/{$id}", 'POST') === null,
            'products' => $products,
            'maxAttributes' => ProductCategoryRepository::MAX_ATTRIBUTES,
            'units' => array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('unit')),
            'packageTypes' => array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('package_type')),
            'hsCodes' => \App\Repositories\HsCodeRepository::active(),
            'tcClauseSets' => ProductCategoryRepository::TC_CLAUSE_SETS,
            'categories' => ProductCategoryRepository::all(),
        ];
        if ($tab === 'options') {
            $data += \App\Controllers\SpecController::categoryData($id) + ['edit' => (int) ($_GET['edit'] ?? 0), 'canManage' => $can('manage_pricing')];
        }
        if ($tab === 'rules') {
            $data['rules'] = \App\Services\PriceRules::forCategory($id);
        }
        View::render('products/category', $data, 'layout/base');
    }

    /** D-300: the category's price rules — MD only (manage_pricing), with a reason. */
    public function saveRules(array $params): void
    {
        $id = (int) $params['id'];
        [$changes, $error] = \App\Services\PriceRules::save($id, $_POST, (int) AuthService::currentUserId());
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
        } else {
            Flash::set('success', $changes === 0 ? 'Nothing changed.' : 'Category price rules saved (' . $changes . ' change' . ($changes === 1 ? '' : 's') . '). Prices worked out from now on use them; quotes already sent keep their prices.');
        }
        header("Location: /products/categories/{$id}?tab=rules");
    }

    /** D-300: Products menu › Price rules — the first (system) category's rules page. */
    public function priceRules(array $params): void
    {
        $id = (int) ($_GET['category'] ?? 0) ?: (\App\Repositories\SpecRepository::systemCategoryId() ?? (int) (ProductCategoryRepository::all()[0]['id'] ?? 0));
        header("Location: /products/categories/{$id}?tab=rules");
    }

    /** D-306: where to go after saving — back to the category page when the form came from it. */
    private static function backTo(string $default): string
    {
        $to = (string) ($_POST['return_to'] ?? '');
        return preg_match('#^/products/categories/\d+(\?tab=[a-z]+)?$#', $to) === 1 ? $to : $default;
    }

    private static function nameError(string $name, ?int $id): ?string
    {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return 'Name the category in 2 to 80 characters.';
        }
        if (ProductCategoryRepository::nameTaken($name, $id)) {
            return "There is already a category called \"{$name}\".";
        }
        return null;
    }
}
