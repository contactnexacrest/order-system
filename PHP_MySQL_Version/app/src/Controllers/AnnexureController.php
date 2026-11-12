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
use App\Services\AnnexureChoice;
use App\Services\AnnexureTermsSanitizer;
use App\Services\AuthService;
use App\Services\FileUploadService;

/**
 * Staff management screen for Annexure A — Product Technical Specifications
 * (and, owner decision D-336, the same screen for Annexure B "as shipped",
 * ?which=B — printed with the Commercial Invoice and Packing List)
 * (order_annexure_products/order_annexure_images, schema Section... these
 * tables shipped in the original delivery with no screen ever built against
 * them). Reached from the order page whenever orders.include_annexure_a is
 * set (or an order created before that flag existed on the New Order form
 * can turn it on here).
 */
final class AnnexureController
{
    /** D-336: the page of one annexure — /orders/{id}/annexure (A) or ?which=B. */
    private static function url(int $orderId, string $which): string
    {
        return "/orders/{$orderId}/annexure" . ($which === 'B' ? '?which=B' : '');
    }

    private static function back(int $orderId, string $which): void
    {
        header('Location: ' . self::url($orderId, $which));
    }

    /** D-336: the audit "reason" that marks an Annexure B entry (Annexure A entries stay as before). */
    private static function auditNote(string $which): ?string
    {
        return $which === 'B' ? 'Annexure B' : null;
    }

    /** The annexure a product entry belongs to. */
    private static function whichOfProduct(?array $product): string
    {
        return OrderAnnexureRepository::which($product['which'] ?? 'A');
    }

    public function index(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $which = OrderAnnexureRepository::which($_GET['which'] ?? 'A');
        self::renderPage($order, $which, (string) ($_GET['start'] ?? '') === 'empty');
    }

    /**
     * The annexure page. D-341: $errors / $old re-show a "Save all changes" that was
     * refused (messages beside the fields, the typed values kept, nothing saved).
     *
     * @param array<string, mixed> $errors ['mode' => msg, 'terms' => msg, 'entries' => [id => [field => msg]]]
     * @param array<string, mixed>|null $old the posted values
     */
    private static function renderPage(array $order, string $which, bool $startEmpty = false, array $errors = [], ?array $old = null): void
    {
        $orderId = (int) $order['id'];
        $user = AuthService::currentUser();
        $bMade = AnnexureChoice::bMade($orderId);
        View::render('annexure/index', [
            'order' => $order,
            'which' => $which,
            'products' => OrderAnnexureRepository::forOrder($orderId, $which),
            'terms' => OrderAnnexureTermsRepository::find($orderId, $which),
            // D-336: "Copy everything from Annexure A" asks first when B already has content.
            'bHasContent' => $which === 'B' && $bMade,
            // D-341: Annexure B from the Commercial Invoice step on (or once it has content).
            'bAvailable' => $bMade || AnnexureChoice::ciStageReached($orderId),
            // D-341: first visit of an empty Annexure B — "Start from a copy of Annexure A".
            'bStartChoice' => $which === 'B' && !$bMade && !$startEmpty && $old === null,
            // D-341: Annexure A changed after its approved / sent document was generated.
            'staleAnnexa' => $which === 'A' ? AnnexureChoice::staleApprovedAnnexA($orderId) : null,
            'canGenerateDocuments' => $user !== null && \App\Services\PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'generate_documents'),
            'errors' => $errors,
            'old' => $old,
            'imageMaxBytes' => FileUploadService::maxBytes('product_image') ?? 5242880,
            // 90-item backlog #73/#74: staff editing this screen have no
            // indication the Annexure was already approved/sent to the
            // buyer — surfaced as a warning banner + edit confirmation in
            // the view, never a hard block (consistent with every other
            // staff-trust gate already documented in this codebase).
            // D-336: Annexure B has no document of its own (it is printed
            // with the CI and PL), so the warning is Annexure A's only.
            'annexaDocument' => $which === 'A' ? DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'ANNEXA') : null,
            // D-341: the order page's regeneration confirm (QA M-08), for "Generate the new revision".
            'annexaRegenerationRisk' => $which === 'A' ? \App\Services\DocumentGenerationService::downstreamDocumentsAtRisk($orderId, 'ANNEXA') : [],
        ], 'layout/base');
    }

    /**
     * D-341: Annexure B before the Commercial Invoice step (and still empty) — the
     * change is refused and the page goes back to Annexure A. True when refused.
     */
    private static function bRefused(int $orderId, string $which): bool
    {
        if ($which !== 'B' || AnnexureChoice::bAvailable($orderId)) {
            return false;
        }
        Flash::set('error', AnnexureChoice::B_NOT_YET_NOTE . ' Nothing was changed.');
        self::back($orderId, 'A');
        return true;
    }

    /**
     * Owner decision D-341 (12 Nov 2026): one "Save all changes" for the whole
     * annexure page — the content mode, every product entry's fields, the Additional
     * Terms and any newly chosen image per entry (images[<entry id>]), saved together
     * in one transaction. Anything wrong (a missing name, an image of the wrong type
     * or too large) re-shows the page with the message beside the field and nothing
     * saved. One audit row per changed entry, image, the terms and the mode.
     */
    public function saveAll(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $which = OrderAnnexureRepository::which($_POST['which'] ?? 'A');
        if (self::bRefused($orderId, $which)) {
            return;
        }

        $products = [];
        foreach (OrderAnnexureRepository::forOrder($orderId, $which) as $p) {
            $products[(int) $p['id']] = $p;
        }
        $errors = [];

        $currentMode = AnnexureChoice::mode($order, $which);
        $mode = isset($_POST['mode']) ? (string) $_POST['mode'] : $currentMode;
        if (!in_array($mode, self::VALID_MODES, true)) {
            $errors['mode'] = 'Choose what Annexure ' . $which . ' contains.';
        }

        $postedEntries = is_array($_POST['entries'] ?? null) ? $_POST['entries'] : [];
        $entries = [];
        foreach ($postedEntries as $entryId => $fields) {
            $entryId = (int) $entryId;
            if (!isset($products[$entryId]) || !is_array($fields)) {
                continue; // not an entry of this annexure (removed meanwhile) — ignored
            }
            $data = [];
            foreach (self::ENTRY_FIELDS as $field) {
                $data[$field] = trim((string) ($fields[$field] ?? ''));
            }
            if ($data['name'] === '') {
                $errors['entries'][$entryId]['name'] = 'Product name is required.';
            }
            $entries[$entryId] = $data;
        }

        $uploads = self::postedImages();
        foreach ($uploads as $entryId => $upload) {
            if (!isset($products[$entryId])) {
                continue;
            }
            try {
                FileUploadService::checkUpload($upload, 'product_image');
            } catch (\Throwable $e) {
                $errors['entries'][$entryId]['image'] = Flash::describeException($e, 'The image could not be uploaded.');
            }
        }

        $termsPosted = array_key_exists('content_html', $_POST);
        $terms = AnnexureTermsSanitizer::sanitize((string) ($_POST['content_html'] ?? ''));

        if ($errors !== []) {
            http_response_code(422);
            self::renderPage($order, $which, true, $errors, ['mode' => $mode, 'entries' => $entries, 'content_html' => $termsPosted ? $terms : null]);
            return;
        }

        $userId = (int) AuthService::currentUser()['id'];
        $note = self::auditNote($which);
        $pdo = \App\Config\Database::connection();
        $changes = 0;
        $pdo->beginTransaction();
        try {
            if ($mode !== $currentMode) {
                if ($which === 'B') {
                    OrderRepository::setAnnexureBMode($orderId, $mode);
                } else {
                    OrderRepository::setAnnexureMode($orderId, $mode);
                }
                AuditLogRepository::log($userId, 'ANNEXURE_MODE_UPDATED', 'order', $orderId, $which === 'B' ? 'annexure_b_mode' : 'annexure_mode', $currentMode, $mode, $note);
                $changes++;
            }
            foreach ($entries as $entryId => $data) {
                $before = $products[$entryId];
                $changed = false;
                foreach (self::ENTRY_FIELDS as $field) {
                    if ($data[$field] !== trim((string) ($before[$field] ?? ''))) {
                        $changed = true;
                    }
                }
                if ($changed) {
                    OrderAnnexureRepository::updateProduct($entryId, $data);
                    AuditLogRepository::log($userId, 'ANNEXURE_PRODUCT_UPDATED', 'order_annexure_products', $entryId, 'name', (string) $before['name'], $data['name'], $note);
                    $changes++;
                }
            }
            if ($termsPosted && $terms !== AnnexureChoice::termsHtml($orderId, $which)) {
                OrderAnnexureTermsRepository::upsert($orderId, $terms, $userId, $which);
                AuditLogRepository::log($userId, 'ANNEXURE_TERMS_UPDATED', 'order', $orderId, 'content_html', null, null, $note);
                $changes++;
            }
            foreach ($uploads as $entryId => $upload) {
                if (!isset($products[$entryId])) {
                    continue;
                }
                $fileId = FileUploadService::storeUpload($upload, 'product_image', \App\Helpers\OrderStorage::folder($order, 'annexure'), null, $orderId, $userId);
                $imageId = OrderAnnexureRepository::addImage($entryId, $fileId);
                AuditLogRepository::log($userId, 'ANNEXURE_IMAGE_UPLOADED', 'order_annexure_images', $imageId, null, null, null, $note);
                $changes++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Nothing was saved: ' . Flash::describeException($e, 'the changes could not be saved — check the server error log.'));
            self::back($orderId, $which);
            return;
        }
        Flash::set('success', $changes > 0 ? "All changes to Annexure {$which} saved." : 'Nothing had changed — nothing to save.');
        self::back($orderId, $which);
    }

    /** The product entry fields "Save all changes" saves (D-341). */
    private const ENTRY_FIELDS = ['name', 'dimensions', 'finish', 'description', 'components', 'technical_notes'];

    /**
     * D-341: the newly chosen image per entry, from <input type="file" name="images[<entry id>]">,
     * as $_FILES-style entries keyed by entry id (an empty file input is left out).
     *
     * @return array<int, array{name:string, type:string, tmp_name:string, error:int, size:int}>
     */
    private static function postedImages(): array
    {
        $field = $_FILES['images'] ?? null;
        if (!is_array($field) || !is_array($field['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($field['name'] as $entryId => $name) {
            $error = (int) ($field['error'][$entryId] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE || (string) $name === '') {
                continue;
            }
            $out[(int) $entryId] = [
                'name' => (string) $name,
                'type' => (string) ($field['type'][$entryId] ?? 'application/octet-stream'),
                'tmp_name' => (string) ($field['tmp_name'][$entryId] ?? ''),
                'error' => $error,
                'size' => (int) ($field['size'][$entryId] ?? 0),
            ];
        }
        return $out;
    }

    public function toggleInclude(array $params): void
    {
        $orderId = (int) $params['id'];
        $which = OrderAnnexureRepository::which($_POST['which'] ?? 'A');
        if (self::bRefused($orderId, $which)) {
            return;
        }
        if ($which === 'B') {
            $included = !empty($_POST['include_annexure_b']);
            OrderRepository::setIncludeAnnexureB($orderId, $included);
            AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_INCLUDE_TOGGLED', 'orders', $orderId, 'include_annexure_b', null, (string) (int) $included, self::auditNote($which));
        } else {
            $included = !empty($_POST['include_annexure_a']);
            OrderRepository::setIncludeAnnexureA($orderId, $included);
            // External QA report N-08: this action never reached the audit
            // log, so it never notified anyone either.
            AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_INCLUDE_TOGGLED', 'orders', $orderId, 'include_annexure_a', null, (string) (int) $included);
        }
        Flash::set('success', "Annexure {$which} " . ($included ? 'enabled' : 'disabled') . ' for this order.');
        self::back($orderId, $which);
    }

    private const VALID_MODES = ['SPEC', 'TERMS', 'BOTH'];

    public function updateMode(array $params): void
    {
        $orderId = (int) $params['id'];
        $which = OrderAnnexureRepository::which($_POST['which'] ?? 'A');
        if (self::bRefused($orderId, $which)) {
            return;
        }
        $mode = (string) ($_POST['mode'] ?? '');
        if (!in_array($mode, self::VALID_MODES, true)) {
            Flash::set('error', "Invalid Annexure {$which} content mode.");
            self::back($orderId, $which);
            return;
        }

        if ($which === 'B') {
            OrderRepository::setAnnexureBMode($orderId, $mode);
        } else {
            OrderRepository::setAnnexureMode($orderId, $mode);
        }
        $user = AuthService::currentUser();
        AuditLogRepository::log((int) $user['id'], 'ANNEXURE_MODE_UPDATED', 'order', $orderId, $which === 'B' ? 'annexure_b_mode' : 'annexure_mode', null, $mode, self::auditNote($which));
        Flash::set('success', "Annexure {$which} content mode updated.");
        self::back($orderId, $which);
    }

    public function updateTerms(array $params): void
    {
        $orderId = (int) $params['id'];
        $which = OrderAnnexureRepository::which($_POST['which'] ?? 'A');
        if (self::bRefused($orderId, $which)) {
            return;
        }
        $sanitized =AnnexureTermsSanitizer::sanitize((string) ($_POST['content_html'] ?? ''));

        $user = AuthService::currentUser();
        OrderAnnexureTermsRepository::upsert($orderId, $sanitized, (int) $user['id'], $which);
        AuditLogRepository::log((int) $user['id'], 'ANNEXURE_TERMS_UPDATED', 'order', $orderId, 'content_html', null, null, self::auditNote($which));
        Flash::set('success', 'Additional Terms saved.');
        self::back($orderId, $which);
    }

    /**
     * D-336: "Copy everything from Annexure A" — Annexure B becomes a copy of
     * Annexure A: its product entries (with the same images), its Additional
     * Terms and its content mode. Whatever B held is replaced (the page asks
     * first when B already has content).
     */
    public function copyFromA(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        if (self::bRefused($orderId, 'B')) {
            return;
        }
        $user = AuthService::currentUser();
        $count = AnnexureChoice::copyAToB($order, (int) $user['id']);
        $entries = $count . ' product entr' . ($count === 1 ? 'y' : 'ies');
        AuditLogRepository::log((int) $user['id'], 'ANNEXURE_COPIED_FROM_A', 'order', $orderId, null, null, $entries, 'Annexure B');
        Flash::set('success', "Annexure B is now a copy of Annexure A ({$entries}, images, Additional Terms and content). Change what is different.");
        self::back($orderId, 'B');
    }

    public function createProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $which = OrderAnnexureRepository::which($_POST['which'] ?? 'A');
        if (self::bRefused($orderId, $which)) {
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Product name is required.');
            self::back($orderId, $which);
            return;
        }

        $productId = OrderAnnexureRepository::createProduct($orderId, [
            'name' => $name,
            'description' => trim((string) ($_POST['description'] ?? '')),
            'dimensions' => trim((string) ($_POST['dimensions'] ?? '')),
            'finish' => trim((string) ($_POST['finish'] ?? '')),
            'components' => trim((string) ($_POST['components'] ?? '')),
            'technical_notes' => trim((string) ($_POST['technical_notes'] ?? '')),
        ], $which);

        if (!empty($_FILES['image']['name'])) {
            $order = OrderRepository::find($orderId);
            try {
                $fileId = FileUploadService::handleUpload(
                    'image',
                    'product_image',
                    \App\Helpers\OrderStorage::folder($order, 'annexure'),
                    null,
                    $orderId,
                    (int) AuthService::currentUser()['id']
                );
                OrderAnnexureRepository::addImage($productId, $fileId);
            } catch (\Throwable $e) {
                Flash::set('error', 'Product entry added, but the image failed to upload: ' . Flash::describeException($e, 'upload failed — check the server error log.'));
                self::back($orderId, $which);
                return;
            }
        }

        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_PRODUCT_CREATED', 'order_annexure_products', $productId, null, null, $name, self::auditNote($which));
        Flash::set('success', 'Product entry added.');
        self::back($orderId, $which);
    }

    public function updateProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $productId = (int) $params['productId'];
        $which = self::whichOfProduct(OrderAnnexureRepository::findProduct($productId));
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Product name is required.');
            self::back($orderId, $which);
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
        AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_PRODUCT_UPDATED', 'order_annexure_products', $productId, 'name', null, $name, self::auditNote($which));
        Flash::set('success', 'Product entry updated.');
        self::back($orderId, $which);
    }

    public function deleteProduct(array $params): void
    {
        $orderId = (int) $params['id'];
        $productId = (int) $params['productId'];
        $which = self::whichOfProduct(OrderAnnexureRepository::findProduct($productId));
        if (\App\Helpers\RecordCheck::missing('order_annexure_products', $productId, self::url($orderId, $which), 'product entry', ['order_id' => $orderId])) {
            return;
        }
        OrderAnnexureRepository::deleteProduct($productId);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_PRODUCT_DELETED', 'order_annexure_products', $productId, 'order_id', (string) $orderId, null, self::auditNote($which));
        Flash::set('success', 'Product entry removed.');
        self::back($orderId, $which);
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
        $which = self::whichOfProduct($product);

        try {
            $fileId = FileUploadService::handleUpload(
                'image',
                'product_image',
                \App\Helpers\OrderStorage::folder($order, 'annexure'),
                null,
                $orderId,
                (int) AuthService::currentUser()['id']
            );
            $imageId = OrderAnnexureRepository::addImage($productId, $fileId);
            // External QA report N-08: this action never reached the
            // audit log, so it never notified anyone either.
            AuditLogRepository::log((int) AuthService::currentUser()['id'], 'ANNEXURE_IMAGE_UPLOADED', 'order_annexure_images', $imageId, null, null, null, self::auditNote($which));
            Flash::set('success', 'Image uploaded.');
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
        }
        self::back($orderId, $which);
    }

    public function removeImage(array $params): void
    {
        $orderId = (int) $params['id'];
        $imageId = (int) $params['imageId'];
        $image = OrderAnnexureRepository::findImage($imageId);
        $which = self::whichOfProduct($image !== null ? OrderAnnexureRepository::findProduct((int) $image['order_annexure_product_id']) : null);
        // QA Round 6, R6-N04: only an image of this order's annexure.
        if (!\App\Helpers\RecordCheck::found('SELECT 1 FROM order_annexure_images i JOIN order_annexure_products p ON p.id = i.order_annexure_product_id WHERE i.id = :i AND p.order_id = :o', ['i' => $imageId, 'o' => $orderId])) {
            Flash::set('error', 'That image was not found — it may have been removed already. Nothing was changed.');
            self::back($orderId, $which);
            return;
        }
        OrderAnnexureRepository::removeImage($imageId);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log(AuthService::currentUserId(), 'ANNEXURE_IMAGE_REMOVED', 'order_annexure_images', $imageId, 'order_id', (string) $orderId, null, self::auditNote($which));
        Flash::set('success', 'Image removed.');
        self::back($orderId, $which);
    }
}
