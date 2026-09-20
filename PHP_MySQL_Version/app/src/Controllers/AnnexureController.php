<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\FileUploadService;

/**
 * Staff management screen for Annexure A — Product Technical Specifications
 * (order_annexure_products/order_annexure_images, schema Section... these
 * tables shipped in the original delivery with no screen ever built against
 * them). Reached from the order page whenever orders.include_annexure_a is
 * set (or an order created before that flag existed on the New Order form
 * can turn it on here).
 */
final class AnnexureController
{
    public function index(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        View::render('annexure/index', [
            'order' => $order,
            'products' => OrderAnnexureRepository::forOrder($orderId),
        ], 'layout/base');
    }

    public function toggleInclude(array $params): void
    {
        $orderId = (int) $params['id'];
        OrderRepository::setIncludeAnnexureA($orderId, !empty($_POST['include_annexure_a']));
        Flash::set('success', 'Annexure A ' . (!empty($_POST['include_annexure_a']) ? 'enabled' : 'disabled') . ' for this order.');
        header("Location: /orders/{$orderId}/annexure");
    }

    public function createProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Product name is required.');
            header("Location: /orders/{$orderId}/annexure");
            return;
        }

        OrderAnnexureRepository::createProduct($orderId, [
            'name' => $name,
            'description' => trim((string) ($_POST['description'] ?? '')),
            'dimensions' => trim((string) ($_POST['dimensions'] ?? '')),
            'finish' => trim((string) ($_POST['finish'] ?? '')),
            'components' => trim((string) ($_POST['components'] ?? '')),
            'technical_notes' => trim((string) ($_POST['technical_notes'] ?? '')),
        ]);
        Flash::set('success', 'Product entry added.');
        header("Location: /orders/{$orderId}/annexure");
    }

    public function updateProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $productId = (int) $params['productId'];
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Product name is required.');
            header("Location: /orders/{$orderId}/annexure");
            return;
        }

        OrderAnnexureRepository::updateProduct($productId, [
            'name' => $name,
            'description' => trim((string) ($_POST['description'] ?? '')),
            'dimensions' => trim((string) ($_POST['dimensions'] ?? '')),
            'finish' => trim((string) ($_POST['finish'] ?? '')),
            'components' => trim((string) ($_POST['components'] ?? '')),
            'technical_notes' => trim((string) ($_POST['technical_notes'] ?? '')),
        ]);
        Flash::set('success', 'Product entry updated.');
        header("Location: /orders/{$orderId}/annexure");
    }

    public function deleteProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $productId = (int) $params['productId'];
        OrderAnnexureRepository::deleteProduct($productId);
        Flash::set('success', 'Product entry removed.');
        header("Location: /orders/{$orderId}/annexure");
    }

    public function uploadImage(array $params): void
    {
        $orderId = (int) $params['id'];
        $productId = (int) $params['productId'];
        $order = OrderRepository::find($orderId);
        $product = OrderAnnexureRepository::findProduct($productId);
        if (!$order || !$product) {
            http_response_code(404);
            echo 'Not found.';
            return;
        }

        try {
            $fileId = FileUploadService::handleUpload(
                'image',
                'product_image',
                'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/annexure',
                null,
                $orderId,
                (int) AuthService::currentUser()['id']
            );
            OrderAnnexureRepository::addImage($productId, $fileId);
            Flash::set('success', 'Image uploaded.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header("Location: /orders/{$orderId}/annexure");
    }

    public function removeImage(array $params): void
    {
        $orderId = (int) $params['id'];
        $imageId = (int) $params['imageId'];
        OrderAnnexureRepository::removeImage($imageId);
        Flash::set('success', 'Image removed.');
        header("Location: /orders/{$orderId}/annexure");
    }
}
