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
            header('Location: /products/categories');
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        [$labels, $error] = ProductCategoryRepository::parseLabels((array) ($_POST['attributes'] ?? []));
        $error = self::nameError($name, $id) ?? $error;
        if ($error !== null) {
            Flash::set('error', $error);
            header('Location: /products/categories');
            return;
        }
        $active = !empty($_POST['is_active']);
        ProductCategoryRepository::update($id, $name, $labels, $active);
        AuditLogRepository::log((int) AuthService::currentUserId(), 'PRODUCT_CATEGORY_UPDATED', 'product_categories', $id, 'attributes',
            $category['name'] . ': ' . implode(', ', $category['attributes']), $name . ': ' . implode(', ', $labels) . ($active ? '' : ' (inactive)'));
        Flash::set('success', "Category \"{$name}\" saved.");
        header('Location: /products/categories');
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
