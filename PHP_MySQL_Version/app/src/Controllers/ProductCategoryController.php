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
        View::render('products/categories', [
            'categories' => ProductCategoryRepository::all(),
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
        [$labels, $error] = ProductCategoryRepository::parseLabels((array) ($_POST['attributes'] ?? []));
        $error = self::nameError($name, null) ?? $error;
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: /products/categories');
            return;
        }
        $userId = (int) AuthService::currentUserId();
        $id = ProductCategoryRepository::create($name, $labels, $userId);
        AuditLogRepository::log($userId, 'PRODUCT_CATEGORY_CREATED', 'product_categories', $id, 'attributes', null, $name . ': ' . implode(', ', $labels));
        Flash::set('success', "Category \"{$name}\" added with " . implode(', ', $labels) . '.');
        header('Location: /products/categories');
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
        if ((int) $category['is_system'] === 1) {
            Flash::set('error', 'Natural Stone is today\'s products — its Dimensions and Finish stay as they are.');
            header('Location: ' . self::backTo('/products/categories'));
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        [$labels, $error] = ProductCategoryRepository::parseLabels((array) ($_POST['attributes'] ?? []));
        $error = self::nameError($name, $id) ?? $error;
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: ' . self::backTo('/products/categories'));
            return;
        }
        $active = !empty($_POST['is_active']);
        ProductCategoryRepository::update($id, $name, $labels, $active);
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
        $category = ProductCategoryRepository::find($id);
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
        $data = [
            'category' => $category, 'tab' => $tab, 'seesPricing' => $seesPricing,
            'canManageCatalog' => $can('manage_product_catalog'), 'canManagePricing' => $can('manage_pricing'),
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
            Flash::set('success', $changes === 0 ? 'Nothing changed.' : 'Price rules saved (' . $changes . ' change' . ($changes === 1 ? '' : 's') . '). Prices worked out from now on use them; quotes already sent keep their prices.');
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
