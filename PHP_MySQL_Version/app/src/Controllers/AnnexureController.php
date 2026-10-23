<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderAnnexureTermsRepository;
use App\Repositories\OrderRepository;
use App\Services\AnnexureTermsSanitizer;
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
            'terms' => OrderAnnexureTermsRepository::find($orderId),
            // 90-item backlog #73/#74: staff editing this screen have no
            // indication the Annexure was already approved/sent to the
            // buyer — surfaced as a warning banner + edit confirmation in
            // the view, never a hard block (consistent with every other
            // staff-trust gate already documented in this codebase).
            'annexaDocument' => DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'ANNEXA'),
        ], 'layout/base');
    }

    public function toggleInclude(array $params): void
    {
        $orderId = (int) $params['id'];
        $included = !empty($_POST['include_annexure_a']);
        OrderRepository::setIncludeAnnexureA($orderId, $included);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_INCLUDE_TOGGLED', 'orders', $orderId, 'include_annexure_a', null, (string) (int) $included);
        Flash::set('success', 'Annexure A ' . ($included ? 'enabled' : 'disabled') . ' for this order.');
        header("Location: /orders/{$orderId}/annexure");
    }

    private const VALID_MODES = ['SPEC', 'TERMS', 'BOTH'];

    public function updateMode(array $params): void
    {
        $orderId = (int) $params['id'];
        $mode = (string) ($_POST['mode'] ?? '');
        if (!in_array($mode, self::VALID_MODES, true)) {
            Flash::set('error', 'Invalid Annexure A content mode.');
            header("Location: /orders/{$orderId}/annexure");
            return;
        }

        OrderRepository::setAnnexureMode($orderId, $mode);
        $user = AuthService::currentUser();
        AuditLogRepository::log((int) $user['id'], 'ANNEXURE_MODE_UPDATED', 'order', $orderId, 'annexure_mode', null, $mode);
        Flash::set('success', 'Annexure A content mode updated.');
        header("Location: /orders/{$orderId}/annexure");
    }

    public function updateTerms(array $params): void
    {
        $orderId = (int) $params['id'];
        $sanitized = AnnexureTermsSanitizer::sanitize((string) ($_POST['content_html'] ?? ''));

        $user = AuthService::currentUser();
        OrderAnnexureTermsRepository::upsert($orderId, $sanitized, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ANNEXURE_TERMS_UPDATED', 'order', $orderId, 'content_html', null, null);
        Flash::set('success', 'Additional Terms saved.');
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

        $productId = OrderAnnexureRepository::createProduct($orderId, [
            'name' => $name,
            'description' => trim((string) ($_POST['description'] ?? '')),
            'dimensions' => trim((string) ($_POST['dimensions'] ?? '')),
            'finish' => trim((string) ($_POST['finish'] ?? '')),
            'components' => trim((string) ($_POST['components'] ?? '')),
            'technical_notes' => trim((string) ($_POST['technical_notes'] ?? '')),
        ]);

        if (!empty($_FILES['image']['name'])) {
            $order = OrderRepository::find($orderId);
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
            } catch (\Throwable $e) {
                Flash::set('error', 'Product entry added, but the image failed to upload: ' . Flash::describeException($e, 'upload failed — check the server error log.'));
                header("Location: /orders/{$orderId}/annexure");
                return;
            }
        }

        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_PRODUCT_CREATED', 'order_annexure_products', $productId, null, null, $name);
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
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_PRODUCT_UPDATED', 'order_annexure_products', $productId, 'name', null, $name);
        Flash::set('success', 'Product entry updated.');
        header("Location: /orders/{$orderId}/annexure");
    }

    public function deleteProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $productId = (int) $params['productId'];
        OrderAnnexureRepository::deleteProduct($productId);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_PRODUCT_DELETED', 'order_annexure_products', $productId, null, null, null);
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
            $imageId = OrderAnnexureRepository::addImage($productId, $fileId);
            // External QA report N-08: this action never reached the
            // audit log, so it never notified anyone either.
            AuditLogRepository::log((int) AuthService::currentUser()['id'], 'ANNEXURE_IMAGE_UPLOADED', 'order_annexure_images', $imageId, null, null, null);
            Flash::set('success', 'Image uploaded.');
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
        }
        header("Location: /orders/{$orderId}/annexure");
    }

    public function removeImage(array $params): void
    {
        $orderId = (int) $params['id'];
        $imageId = (int) $params['imageId'];
        OrderAnnexureRepository::removeImage($imageId);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_IMAGE_REMOVED', 'order_annexure_images', $imageId, null, null, null);
        Flash::set('success', 'Image removed.');
        header("Location: /orders/{$orderId}/annexure");
    }
}
