<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SpecRepository;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\ProductSpecs;
use App\Services\SurfaceFinishList;

/**
 * Owner decision D-255 (5 Nov 2026): the specifications that change the price.
 *  - Admin → Pricing → Specifications: a category's specifications and options
 *    (add, edit, reorder, deactivate, delete) — manage_pricing.
 *  - A product's specifications: hide a specification or option, change an
 *    option's value, add options or specifications for that product only, reset —
 *    manage_product_specifications (a role permission; Admin and MD to start).
 * An option or specification already used on an order line or price request can
 * only be deactivated. Every change is audit-logged; issued prices do not change.
 * Node twin: src/controllers/specController.js.
 */
final class SpecController
{
    private static function user(): array
    {
        return AuthService::currentUser() ?? [];
    }

    private static function can(string $key): bool
    {
        $u = self::user();
        return $u !== [] && PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, $key);
    }

    private static function back(string $to): void
    {
        // D-306 (point 20): a category's options are a tab of its page now.
        if (preg_match('~^/pricing/specifications(?:\?category=(\d+))?(?:&edit=(\d+))?(#.*)?$~', $to, $m) === 1) {
            $cat = $m[1] ?? '' ?: (string) (SpecRepository::systemCategoryId() ?? 0);
            $to = "/products/categories/{$cat}?tab=options" . (($m[2] ?? '') !== '' ? '&edit=' . $m[2] : '') . ($m[3] ?? '');
        }
        header('Location: ' . $to);
    }
    /**
     * On a product's routes (/products/{pid}/…) the specification or option must be that
     * product's own — never a category one (those need manage_pricing on the category routes).
     */
    private static function belongs(array $params, mixed $ownerProduct): bool
    {
        if (!isset($params['pid'])) {
            return true;
        }
        if ($ownerProduct === null || (int) $ownerProduct !== (int) $params['pid']) {
            http_response_code(403);
            Flash::set('error', 'That is not one of this product\'s own specifications or options.');
            header('Location: /products/' . (int) $params['pid'] . '/specifications');
            return false;
        }
        return true;
    }

    // ------------------------------------------------------------ category level

    /** D-306 (point 20): the old Admin › Pricing › Specifications address opens the category page's Options tab. */
    public function index(array $params): void
    {
        $categoryId = (int) ($_GET['category'] ?? 0) ?: (int) (SpecRepository::systemCategoryId() ?? (ProductCategoryRepository::all(true)[0]['id'] ?? 0));
        $edit = (int) ($_GET['edit'] ?? 0);
        header("Location: /products/categories/{$categoryId}?tab=options" . ($edit > 0 ? "&edit={$edit}#spec-{$edit}" : ''));
    }

    /**
     * A category's specifications and options with whether each is used (for its Options tab).
     *
     * @return array{categoryId: int, specs: list<array<string,mixed>>}
     */
    public static function categoryData(int $categoryId): array
    {
        $specs = SpecRepository::categorySpecs($categoryId);
        $options = SpecRepository::optionsFor(array_map(static fn (array $s): int => (int) $s['id'], $specs));
        foreach ($specs as &$s) {
            $s['options'] = array_values(array_filter($options[(int) $s['id']] ?? [], static fn (array $o): bool => $o['product_id'] === null));
            $s['used'] = SpecRepository::specUsed((int) $s['id']);
            foreach ($s['options'] as &$o) {
                $o['used'] = SpecRepository::optionUsed((int) $o['id']);
            }
            unset($o);
        }
        unset($s);
        return ['categoryId' => $categoryId, 'specs' => $specs];
    }

    public function addSpec(array $params): void
    {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        [$d, $errors] = ProductSpecs::readSpecForm($_POST);
        if ($categoryId <= 0 || ProductCategoryRepository::find($categoryId) === null) {
            $errors[] = 'Choose a category.';
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            self::back("/pricing/specifications?category={$categoryId}");
            return;
        }
        $d['category_id'] = $categoryId;
        $id = SpecRepository::createSpec($d, (int) self::user()['id']);
        AuditLogRepository::log((int) self::user()['id'], 'SPEC_ADDED', 'spec_definitions', $id, null, null, "{$d['name']} (category #{$categoryId})");
        // D-357: a category's "Surface finish" takes its options from Settings › Lists › surface_finish.
        if (mb_strtolower(trim($d['name'])) === 'surface finish') {
            SpecRepository::setListKey($id, SurfaceFinishList::LIST_KEY);
            SurfaceFinishList::sync([], (int) self::user()['id']);
            Flash::set('success', "Specification \"{$d['name']}\" added with the finishes of Settings › Lists › surface_finish, at no price change — set each finish's price below.");
            self::back("/pricing/specifications?category={$categoryId}&edit={$id}#spec-{$id}");
            return;
        }
        Flash::set('success', "Specification \"{$d['name']}\" added — add its options below.");
        self::back("/pricing/specifications?category={$categoryId}&edit={$id}#spec-{$id}");
    }

    public function updateSpec(array $params): void
    {
        $spec = SpecRepository::findSpec((int) ($params['id'] ?? 0));
        if ($spec === null) {
            Flash::set('error', 'That specification no longer exists.');
            self::back('/pricing/specifications');
            return;
        }
        if (!self::belongs($params, $spec['product_id'])) {
            return;
        }
        [$d, $errors] = ProductSpecs::readSpecForm($_POST);
        $to = $spec['product_id'] !== null ? "/products/{$spec['product_id']}/specifications" : "/pricing/specifications?category={$spec['category_id']}&edit={$spec['id']}#spec-{$spec['id']}";
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            self::back($to);
            return;
        }
        if ($spec['product_id'] !== null && !self::can('manage_product_specifications')) {
            http_response_code(403);
            Flash::set('error', 'You may not change this product\'s specifications.');
            self::back($to);
            return;
        }
        SpecRepository::updateSpec((int) $spec['id'], $d, (int) self::user()['id']);
        AuditLogRepository::log((int) self::user()['id'], 'SPEC_CHANGED', 'spec_definitions', (int) $spec['id'], null, (string) $spec['name'], $d['name']);
        Flash::set('success', 'Specification saved.');
        self::back($to);
    }

    public function toggleSpec(array $params): void
    {
        $spec = SpecRepository::findSpec((int) ($params['id'] ?? 0));
        if ($spec === null || $spec['product_id'] !== null) {
            Flash::set('error', 'That specification no longer exists.');
            self::back('/pricing/specifications');
            return;
        }
        $active = (int) $spec['is_active'] !== 1;
        SpecRepository::setSpecActive((int) $spec['id'], $active, (int) self::user()['id']);
        AuditLogRepository::log((int) self::user()['id'], $active ? 'SPEC_ACTIVATED' : 'SPEC_DEACTIVATED', 'spec_definitions', (int) $spec['id'], null, null, (string) $spec['name']);
        Flash::set('success', "\"{$spec['name']}\" " . ($active ? 'is active again.' : 'is deactivated — no longer offered; orders and price requests that used it keep it.'));
        self::back("/pricing/specifications?category={$spec['category_id']}");
    }

    public function deleteSpec(array $params): void
    {
        $spec = SpecRepository::findSpec((int) ($params['id'] ?? 0));
        if ($spec === null) {
            Flash::set('error', 'That specification no longer exists.');
            self::back('/pricing/specifications');
            return;
        }
        if (!self::belongs($params, $spec['product_id'])) {
            return;
        }
        $to = $spec['product_id'] !== null ? "/products/{$spec['product_id']}/specifications" : "/pricing/specifications?category={$spec['category_id']}";
        if ($spec['product_id'] !== null && !self::can('manage_product_specifications')) {
            http_response_code(403);
            Flash::set('error', 'You may not change this product\'s specifications.');
            self::back($to);
            return;
        }
        if (SpecRepository::specUsed((int) $spec['id'])) {
            if ($spec['product_id'] !== null) {
                SpecRepository::setSpecActive((int) $spec['id'], false, (int) self::user()['id']);
                Flash::set('success', "\"{$spec['name']}\" has been used on orders or price requests, so it was deactivated instead of deleted.");
            } else {
                Flash::set('error', "\"{$spec['name']}\" has been used on orders or price requests — deactivate it instead.");
            }
            self::back($to);
            return;
        }
        SpecRepository::deleteSpec((int) $spec['id']);
        AuditLogRepository::log((int) self::user()['id'], 'SPEC_DELETED', 'spec_definitions', (int) $spec['id'], null, (string) $spec['name'], null);
        Flash::set('success', "\"{$spec['name']}\" deleted.");
        self::back($to);
    }

    public function addOption(array $params): void
    {
        $spec = SpecRepository::findSpec((int) ($params['id'] ?? 0));
        if ($spec === null) {
            Flash::set('error', 'That specification no longer exists.');
            self::back('/pricing/specifications');
            return;
        }
        $productOnly = $spec['product_id'] !== null;
        if ($productOnly && !self::can('manage_product_specifications')) {
            http_response_code(403);
            Flash::set('error', 'You may not change this product\'s specifications.');
            self::back("/products/{$spec['product_id']}/specifications");
            return;
        }
        [$d, $errors] = ProductSpecs::readOptionForm($_POST);
        $to = $productOnly ? "/products/{$spec['product_id']}/specifications" : "/pricing/specifications?category={$spec['category_id']}&edit={$spec['id']}#spec-{$spec['id']}";
        if (SurfaceFinishList::isLinkedSpec($spec)) {
            // D-357: finish names are added in Settings › Lists; they appear here at no price change.
            Flash::set('error', 'Add a surface finish in Settings › Lists › surface_finish — it appears here at once with no price change; then set its price here.');
            self::back($to);
            return;
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            self::back($to);
            return;
        }
        $d['spec_id'] = (int) $spec['id'];
        $d['product_id'] = $productOnly ? (int) $spec['product_id'] : null;
        $id = SpecRepository::createOption($d, (int) self::user()['id']);
        if ($d['is_base'] === 1) {
            SpecRepository::clearBase((int) $spec['id'], $d['product_id'], $id);
        }
        AuditLogRepository::log((int) self::user()['id'], 'SPEC_OPTION_ADDED', 'spec_options', $id, null, null,
            "{$spec['name']}: {$d['name']} " . ProductSpecs::changeLabel($d['change_type'], $d['value'] !== null ? (float) $d['value'] : null));
        Flash::set('success', "Option \"{$d['name']}\" added.");
        self::back($to);
    }

    public function updateOption(array $params): void
    {
        $o = SpecRepository::findOption((int) ($params['id'] ?? 0));
        $spec = $o !== null ? SpecRepository::findSpec((int) $o['spec_id']) : null;
        if ($o === null || $spec === null) {
            Flash::set('error', 'That option no longer exists.');
            self::back('/pricing/specifications');
            return;
        }
        $ownerProduct = $o['product_id'] ?? $spec['product_id'];
        if (!self::belongs($params, $ownerProduct)) {
            return;
        }
        if ($ownerProduct !== null && !self::can('manage_product_specifications')) {
            http_response_code(403);
            Flash::set('error', 'You may not change this product\'s specifications.');
            self::back("/products/{$ownerProduct}/specifications");
            return;
        }
        // D-357: a linked option's name comes from Settings › Lists — only its price is saved here.
        $post = $_POST;
        if (!empty($o['dropdown_option_id'])) {
            $post['name'] = (string) $o['name'];
        }
        [$d, $errors] = ProductSpecs::readOptionForm($post);
        $to = $ownerProduct !== null ? "/products/{$ownerProduct}/specifications" : "/pricing/specifications?category={$spec['category_id']}&edit={$spec['id']}#spec-{$spec['id']}";
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            self::back($to);
            return;
        }
        SpecRepository::updateOption((int) $o['id'], $d, (int) self::user()['id']);
        if ($d['is_base'] === 1) {
            SpecRepository::clearBase((int) $spec['id'], $o['product_id'] !== null ? (int) $o['product_id'] : null, (int) $o['id']);
        }
        AuditLogRepository::log((int) self::user()['id'], 'SPEC_OPTION_CHANGED', 'spec_options', (int) $o['id'], null,
            $o['name'] . ' ' . ProductSpecs::changeLabel((string) $o['change_type'], $o['value'] !== null ? (float) $o['value'] : null),
            $d['name'] . ' ' . ProductSpecs::changeLabel($d['change_type'], $d['value'] !== null ? (float) $d['value'] : null));
        Flash::set('success', 'Option saved. New quotes use it; prices already issued do not change.');
        self::back($to);
    }

    public function toggleOption(array $params): void
    {
        $o = SpecRepository::findOption((int) ($params['id'] ?? 0));
        $spec = $o !== null ? SpecRepository::findSpec((int) $o['spec_id']) : null;
        if ($o === null || $spec === null || $o['product_id'] !== null) {
            Flash::set('error', 'That option no longer exists.');
            self::back('/pricing/specifications');
            return;
        }
        if (!empty($o['dropdown_option_id'])) {
            Flash::set('error', "\"{$o['name']}\" is switched on or off in Settings › Lists › surface_finish (D-357) — the one list of finish names.");
            self::back("/pricing/specifications?category={$spec['category_id']}&edit={$spec['id']}#spec-{$spec['id']}");
            return;
        }
        $active = (int) $o['is_active'] !== 1;
        SpecRepository::setOptionActive((int) $o['id'], $active, (int) self::user()['id']);
        AuditLogRepository::log((int) self::user()['id'], $active ? 'SPEC_OPTION_ACTIVATED' : 'SPEC_OPTION_DEACTIVATED', 'spec_options', (int) $o['id'], null, null, "{$spec['name']}: {$o['name']}");
        Flash::set('success', "\"{$o['name']}\" " . ($active ? 'is active again.' : 'is deactivated everywhere — orders and price requests that used it keep it.'));
        self::back("/pricing/specifications?category={$spec['category_id']}&edit={$spec['id']}#spec-{$spec['id']}");
    }

    public function deleteOption(array $params): void
    {
        $o = SpecRepository::findOption((int) ($params['id'] ?? 0));
        $spec = $o !== null ? SpecRepository::findSpec((int) $o['spec_id']) : null;
        if ($o === null || $spec === null) {
            Flash::set('error', 'That option no longer exists.');
            self::back('/pricing/specifications');
            return;
        }
        $ownerProduct = $o['product_id'] ?? $spec['product_id'];
        if (!self::belongs($params, $ownerProduct)) {
            return;
        }
        if ($ownerProduct !== null && !self::can('manage_product_specifications')) {
            http_response_code(403);
            Flash::set('error', 'You may not change this product\'s specifications.');
            self::back("/products/{$ownerProduct}/specifications");
            return;
        }
        $to = $ownerProduct !== null ? "/products/{$ownerProduct}/specifications" : "/pricing/specifications?category={$spec['category_id']}&edit={$spec['id']}#spec-{$spec['id']}";
        if (!empty($o['dropdown_option_id']) && $o['product_id'] === null) {
            Flash::set('error', "\"{$o['name']}\" comes from Settings › Lists › surface_finish (D-357) — switch it off there.");
            self::back($to);
            return;
        }
        if (SpecRepository::optionUsed((int) $o['id'])) {
            SpecRepository::setOptionActive((int) $o['id'], false, (int) self::user()['id']);
            Flash::set('success', "\"{$o['name']}\" has been used on orders or price requests, so it was deactivated instead of deleted.");
            self::back($to);
            return;
        }
        SpecRepository::deleteOption((int) $o['id']);
        AuditLogRepository::log((int) self::user()['id'], 'SPEC_OPTION_DELETED', 'spec_options', (int) $o['id'], null, "{$spec['name']}: {$o['name']}", null);
        Flash::set('success', "\"{$o['name']}\" removed.");
        self::back($to);
    }

    // ------------------------------------------------------------- product level

    public function product(array $params): void
    {
        $product = ProductRepository::find((int) ($params['id'] ?? 0));
        if ($product === null) {
            http_response_code(404);
            echo 'Product not found.';
            return;
        }
        $tree = ProductSpecs::tree($product, true);
        View::render('products/specifications', [
            'product' => $product, 'tree' => $tree,
            'categories' => ProductCategoryRepository::all(true),
            'categoryId' => ProductSpecs::categoryIdFor($product),
            'canEdit' => self::can('manage_product_specifications'),
            'cbmFrom' => \App\Services\PricingService::settings()['cbm_from_mm'],
            'edit' => (int) ($_GET['edit'] ?? 0),
        ], 'layout/base');
    }

    private static function productOr404(array $params): ?array
    {
        $product = ProductRepository::find((int) ($params['id'] ?? 0));
        if ($product === null) {
            Flash::set('error', 'That product no longer exists.');
            self::back('/products');
        }
        return $product;
    }

    public function saveProductFields(array $params): void
    {
        if (($product = self::productOr404($params)) === null) {
            return;
        }
        $id = (int) $product['id'];
        $errors = [];
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        if ($categoryId <= 0 || ProductCategoryRepository::find($categoryId) === null) {
            $errors[] = 'Choose a category.';
        }
        $forms = [];
        foreach ((array) ($_POST['forms'] ?? []) as $f) {
            if (isset(ProductSpecs::FORMS[(string) $f])) {
                $forms[] = (string) $f;
            }
        }
        $cbm = trim((string) ($_POST['cbm_from_mm'] ?? ''));
        $cbmVal = null;
        if ($cbm !== '') {
            $n = filter_var($cbm, FILTER_VALIDATE_INT);
            if ($n === false || $n < 1 || $n > 2000) {
                $errors[] = 'CBM from: whole millimetres from 1 to 2000, or leave it empty for the global value.';
            } else {
                $cbmVal = $n;
            }
        }
        // D-270 (point 25): the product's own weight per CBM (empty = its category's).
        [$weight, $weightError] = ProductCategoryRepository::weightInput($_POST['weight_t_per_cbm'] ?? '');
        if ($weightError !== null) {
            $errors[] = $weightError;
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            self::back("/products/{$id}/specifications");
            return;
        }
        $before = ($product['category_id'] ?? '—') . ' / ' . ($product['forms'] ?? 'all') . ' / ' . ($product['cbm_from_mm'] ?? 'global') . ' / ' . ($product['weight_t_per_cbm'] ?? 'category');
        SpecRepository::setProductFields($id, $categoryId, $forms === [] ? null : implode(',', $forms), $cbmVal, $weight);
        AuditLogRepository::log((int) self::user()['id'], 'PRODUCT_SPEC_FIELDS_CHANGED', 'catalog_products', $id, 'category / forms / cbm_from_mm / weight_t_per_cbm', $before,
            $categoryId . ' / ' . ($forms === [] ? 'all' : implode(',', $forms)) . ' / ' . ($cbmVal ?? 'global') . ' / ' . ($weight ?? 'category'));
        Flash::set('success', 'Saved.');
        self::back("/products/{$id}/specifications");
    }

    /** A category specification's options for this product: offered or not, and its own value. */
    public function saveProductSpec(array $params): void
    {
        if (($product = self::productOr404($params)) === null) {
            return;
        }
        $id = (int) $product['id'];
        $spec = SpecRepository::findSpec((int) ($params['specId'] ?? 0));
        if ($spec === null || $spec['product_id'] !== null) {
            Flash::set('error', 'That specification no longer exists.');
            self::back("/products/{$id}/specifications");
            return;
        }
        $offered = (array) ($_POST['offered'] ?? []);
        $values = (array) ($_POST['value'] ?? []);
        // D-363 (13 Nov 2026): the same save from Configure › Thickness rules. There the boxes show
        // the category's % too, so a value equal to the category's follows the category (no own
        // value), and the page returns to Configure. Only this product's overrides are written —
        // never the category's options or another product.
        $fromConfigure = ($_POST['from'] ?? '') === 'configure';
        $errors = [];
        $changes = [];
        $anyOffered = false;
        foreach (SpecRepository::optionsFor([(int) $spec['id']], $id)[(int) $spec['id']] ?? [] as $o) {
            if ($o['product_id'] !== null || (int) $o['is_active'] !== 1) {
                continue;
            }
            $oid = (int) $o['id'];
            $hidden = empty($offered[$oid]);
            $anyOffered = $anyOffered || !$hidden;
            $v = trim((string) ($values[$oid] ?? ''));
            $value = null;
            if ($v !== '' && !in_array($o['change_type'], ['none', 'cbm'], true)) {
                if (!preg_match('/^-?\d+(\.\d{1,4})?$/', $v)) {
                    $errors[] = "{$o['name']}: enter a number with at most 4 decimals, or leave it empty to follow the category.";
                    continue;
                }
                $value = number_format((float) $v, 4, '.', '');
                if ($fromConfigure && $o['value'] !== null && abs((float) $value - (float) $o['value']) < 1e-9) {
                    $value = null;
                }
            }
            $changes[] = [$oid, $hidden, $value, $o['name']];
        }
        if (!$anyOffered) {
            $errors[] = 'Keep at least one option offered, or hide the whole specification for this product.';
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            self::back($fromConfigure ? "/products/{$id}/configure#thickness-rules" : "/products/{$id}/specifications?edit={$spec['id']}");
            return;
        }
        $summary = [];
        foreach ($changes as [$oid, $hidden, $value, $name]) {
            SpecRepository::setOptionOverride($id, $oid, $hidden, $value);
            if ($hidden || $value !== null) {
                $summary[] = $name . ($hidden ? ' hidden' : ' ' . $value);
            }
        }
        AuditLogRepository::log((int) self::user()['id'], 'PRODUCT_SPEC_CHANGED', 'catalog_products', $id, (string) $spec['name'], null, $summary === [] ? 'follows the category' : implode('; ', $summary));
        if ($fromConfigure) {
            Flash::set('success', "Saved for {$product['name']} only — other products keep the category's values.");
            self::back("/products/{$id}/configure#thickness-rules");
            return;
        }
        Flash::set('success', "\"{$spec['name']}\" saved for {$product['name']}. New quotes use it.");
        self::back("/products/{$id}/specifications");
    }

    public function hideProductSpec(array $params): void
    {
        $this->setHidden($params, true);
    }

    public function showProductSpec(array $params): void
    {
        $this->setHidden($params, false);
    }

    private function setHidden(array $params, bool $hidden): void
    {
        if (($product = self::productOr404($params)) === null) {
            return;
        }
        $spec = SpecRepository::findSpec((int) ($params['specId'] ?? 0));
        if ($spec === null || $spec['product_id'] !== null) {
            Flash::set('error', 'That specification no longer exists.');
            self::back("/products/{$product['id']}/specifications");
            return;
        }
        SpecRepository::setSpecHidden((int) $product['id'], (int) $spec['id'], $hidden);
        AuditLogRepository::log((int) self::user()['id'], $hidden ? 'PRODUCT_SPEC_HIDDEN' : 'PRODUCT_SPEC_SHOWN', 'catalog_products', (int) $product['id'], (string) $spec['name'], null, null);
        Flash::set('success', "\"{$spec['name']}\" is " . ($hidden ? 'not offered' : 'offered again') . " for {$product['name']}.");
        self::back("/products/{$product['id']}/specifications");
    }

    public function resetProductSpec(array $params): void
    {
        if (($product = self::productOr404($params)) === null) {
            return;
        }
        $spec = SpecRepository::findSpec((int) ($params['specId'] ?? 0));
        if ($spec === null || $spec['product_id'] !== null) {
            Flash::set('error', 'That specification no longer exists.');
            self::back("/products/{$product['id']}/specifications");
            return;
        }
        self::resetOne((int) $product['id'], (int) $spec['id']);
        AuditLogRepository::log((int) self::user()['id'], 'PRODUCT_SPEC_RESET', 'catalog_products', (int) $product['id'], (string) $spec['name'], null, 'follows the category');
        Flash::set('success', "\"{$spec['name']}\" follows the category again for {$product['name']}.");
        self::back("/products/{$product['id']}/specifications");
    }

    private static function resetOne(int $productId, int $specId): void
    {
        SpecRepository::resetSpecForProduct($productId, $specId);
        foreach (SpecRepository::optionsFor([$specId], $productId)[$specId] ?? [] as $o) {
            if ((int) ($o['product_id'] ?? 0) === $productId) {
                SpecRepository::optionUsed((int) $o['id']) ? SpecRepository::setOptionActive((int) $o['id'], false, (int) self::user()['id']) : SpecRepository::deleteOption((int) $o['id']);
            }
        }
    }

    public function resetProduct(array $params): void
    {
        if (($product = self::productOr404($params)) === null) {
            return;
        }
        $id = (int) $product['id'];
        foreach (ProductSpecs::tree($product, true) as $s) {
            if ($s['source'] === 'category') {
                self::resetOne($id, $s['id']);
            } else {
                SpecRepository::specUsed($s['id']) ? SpecRepository::setSpecActive($s['id'], false, (int) self::user()['id']) : SpecRepository::deleteSpec($s['id']);
            }
        }
        AuditLogRepository::log((int) self::user()['id'], 'PRODUCT_SPECS_RESET', 'catalog_products', $id, null, null, 'every specification follows the category');
        Flash::set('success', "{$product['name']} follows its category again.");
        self::back("/products/{$id}/specifications");
    }

    public function addProductSpec(array $params): void
    {
        if (($product = self::productOr404($params)) === null) {
            return;
        }
        [$d, $errors] = ProductSpecs::readSpecForm($_POST);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            self::back("/products/{$product['id']}/specifications");
            return;
        }
        $d['product_id'] = (int) $product['id'];
        $d['applies_to'] = null;
        $specId = SpecRepository::createSpec($d, (int) self::user()['id']);
        AuditLogRepository::log((int) self::user()['id'], 'PRODUCT_SPEC_ADDED', 'spec_definitions', $specId, null, null, "{$d['name']} (only {$product['name']})");
        Flash::set('success', "Specification \"{$d['name']}\" added for {$product['name']} only — add its options.");
        self::back("/products/{$product['id']}/specifications?edit={$specId}");
    }

    /** An option for this product only, on a category specification. */
    public function addProductOption(array $params): void
    {
        if (($product = self::productOr404($params)) === null) {
            return;
        }
        $spec = SpecRepository::findSpec((int) ($params['specId'] ?? 0));
        if ($spec === null || ($spec['product_id'] !== null && (int) $spec['product_id'] !== (int) $product['id'])) {
            Flash::set('error', 'That specification no longer exists.');
            self::back("/products/{$product['id']}/specifications");
            return;
        }
        [$d, $errors] = ProductSpecs::readOptionForm($_POST);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            self::back("/products/{$product['id']}/specifications?edit={$spec['id']}");
            return;
        }
        if (SurfaceFinishList::isLinkedSpec($spec)) {
            // D-357: a product's own finish is one of the Settings list's names.
            $row = SurfaceFinishList::settingsRowByName($d['name']);
            if ($row === null) {
                Flash::set('error', "\"{$d['name']}\" is not in Settings › Lists › surface_finish — add it there first (the one list of finish names).");
                self::back("/products/{$product['id']}/specifications?edit={$spec['id']}");
                return;
            }
            $d['name'] = mb_substr((string) $row['option_value'], 0, SurfaceFinishList::MAX_NAME);
            $d['dropdown_option_id'] = (int) $row['id'];
        }
        $d['spec_id'] = (int) $spec['id'];
        $d['product_id'] = (int) $product['id'];
        $oid = SpecRepository::createOption($d, (int) self::user()['id']);
        if ($d['is_base'] === 1) {
            SpecRepository::clearBase((int) $spec['id'], (int) $product['id'], $oid);
        }
        AuditLogRepository::log((int) self::user()['id'], 'PRODUCT_OPTION_ADDED', 'spec_options', $oid, null, null,
            "{$spec['name']}: {$d['name']} " . ProductSpecs::changeLabel($d['change_type'], $d['value'] !== null ? (float) $d['value'] : null) . " (only {$product['name']})");
        Flash::set('success', "Option \"{$d['name']}\" added for {$product['name']} only.");
        self::back("/products/{$product['id']}/specifications");
    }
}
