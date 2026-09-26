<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\ClientPasswordResetTokenRepository;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderCommentRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Services\ClientPortalService;
use App\Services\FileUploadService;
use App\Services\OrderCommentService;
use App\Services\PasswordPolicyService;

/**
 * The client-facing portal — structurally separate screens from the staff
 * app (own layout, own nav, own session key via ClientPortalService).
 * Read-only for the client's own profile/order data throughout (no
 * editing, no audit visibility) — the exceptions are the client's own
 * password; reportPayment(), which is purely an informational note to
 * staff and never writes to the order/payment records itself; and
 * acknowledgeOc(), the one real state change a client can trigger, gated
 * to only ever move the Stage 4->5 gate forward, never anything else.
 */
final class ClientPortalController
{
    public function showLogin(array $params): void
    {
        if (ClientPortalService::currentClientId()) {
            header('Location: /client');
            return;
        }
        View::render('client_portal/login', [], 'layout/bare');
    }

    public function login(array $params): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        $result = ClientPortalService::attemptLogin($email, $password);

        switch ($result['status']) {
            case 'ok':
                if ($result['force_password_change']) {
                    header('Location: /client/account');
                    return;
                }
                header('Location: /client');
                return;
            case 'locked_out':
                Flash::set('error', 'Too many failed attempts. Try again after ' . htmlspecialchars((string) $result['locked_until']) . '.');
                break;
            case 'account_disabled':
                Flash::set('error', 'This account is disabled. Contact NexaCrest if you believe this is a mistake.');
                break;
            default:
                Flash::set('error', 'Incorrect email or password.');
        }
        header('Location: /client/login');
    }

    public function logout(array $params): void
    {
        ClientPortalService::logout();
        header('Location: /client/login');
    }

    public function showSetPassword(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $row = ClientPasswordResetTokenRepository::findValidByHash(hash('sha256', $token));
        if (!$row) {
            Flash::set('error', 'This link is invalid or has expired. Contact NexaCrest for a new one.');
            header('Location: /client/login');
            return;
        }
        View::render('client_portal/set_password', ['token' => $token, 'email' => $row['client_email']], 'layout/bare');
    }

    public function setPassword(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $row = ClientPasswordResetTokenRepository::findValidByHash(hash('sha256', $token));
        if (!$row) {
            Flash::set('error', 'This link is invalid or has expired. Contact NexaCrest for a new one.');
            header('Location: /client/login');
            return;
        }

        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $policyError = PasswordPolicyService::validate($new);
        if ($policyError !== null) {
            Flash::set('error', $policyError);
            header('Location: /client/set-password/' . $token);
            return;
        }
        if ($new !== $confirm) {
            Flash::set('error', 'Passwords do not match.');
            header('Location: /client/set-password/' . $token);
            return;
        }

        ClientPortalService::changePassword((int) $row['client_id'], $new);
        ClientPasswordResetTokenRepository::markUsed((int) $row['id']);
        ClientPasswordResetTokenRepository::invalidateAllForClient((int) $row['client_id']);

        Flash::set('success', 'Password set. Sign in below.');
        header('Location: /client/login');
    }

    public function dashboard(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        View::render('client_portal/dashboard', [
            'client' => ClientPortalService::currentClient(),
            'orders' => OrderRepository::forClient($clientId),
        ], 'layout/client');
    }

    public function showOrder(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        $orderId = (int) ($params['id'] ?? 0);
        $order = OrderRepository::find($orderId);

        // Ownership check — the one thing this whole controller exists to
        // enforce: a client can never reach another client's order by
        // guessing/changing the id in the URL. Treated identically to "not
        // found" so no information about other clients' orders leaks.
        if (!$order || (int) $order['client_id'] !== $clientId) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        View::render('client_portal/order_show', [
            'client' => ClientPortalService::currentClient(),
            'order' => $order,
            'products' => OrderProductRepository::forOrder($orderId),
            'totalFobValue' => OrderProductRepository::totalFobValue($orderId),
            'documents' => DocumentRepository::customerFacingForOrder($orderId),
            'paymentReports' => ClientPaymentReportRepository::forOrder($orderId),
            'ocAcknowledgment' => OrderOcAcknowledgmentRepository::find($orderId),
            'comments' => OrderCommentRepository::forOrder($orderId),
        ], 'layout/client');
    }

    /** docs/schema.sql Section AI — the client's side of the order progress chat. */
    public function postComment(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        $orderId = (int) ($params['id'] ?? 0);
        $order = OrderRepository::find($orderId);
        if (!$order || (int) $order['client_id'] !== $clientId) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        try {
            $subPath = 'clients/' . FileUploadService::sanitizePathSegment((string) $order['client_unique_number'])
                . '/' . FileUploadService::sanitizePathSegment((string) $order['order_reference'])
                . '/comment_attachments';
            $fileIds = FileUploadService::handleMultipleUploads(
                'attachments',
                'order_comment_media',
                $subPath,
                $clientId,
                $orderId,
                null,
                'Buyer',
                'Order Update Attachment'
            );
            OrderCommentService::postAsClient($orderId, $clientId, $body !== '' ? $body : null, $fileIds);
            Flash::set('success', 'Message sent.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header("Location: /client/orders/{$orderId}#order-updates");
    }

    /** Client download of a comment attachment — same ownership check as every other client-facing file. */
    public function downloadCommentAttachment(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        $orderId = (int) ($params['id'] ?? 0);
        $fileId = (int) ($params['fileId'] ?? 0);
        $order = OrderRepository::find($orderId);
        if (!$order || (int) $order['client_id'] !== $clientId) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $file = OrderCommentRepository::findAttachmentForOrder($fileId, $orderId);
        if (!$file || !is_file($file['server_path'])) {
            http_response_code(404);
            echo 'File not found.';
            return;
        }
        $safeDownloadName = preg_replace('/[\x00-\x1F\x7F"\/\\\\]/', '', $file['original_filename']) ?? $file['original_filename'];
        header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
        header('Content-Length: ' . filesize($file['server_path']));
        readfile($file['server_path']);
    }

    /** A client's own previously-uploaded payment screenshot — same ownership-check pattern as downloadCommentAttachment(). */
    public function downloadPaymentScreenshot(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        $orderId = (int) ($params['id'] ?? 0);
        $reportId = (int) ($params['reportId'] ?? 0);
        $order = OrderRepository::find($orderId);
        if (!$order || (int) $order['client_id'] !== $clientId) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $report = ClientPaymentReportRepository::find($reportId);
        if (!$report || (int) $report['order_id'] !== $orderId || !$report['screenshot_file_id']) {
            http_response_code(404);
            echo 'Attachment not found.';
            return;
        }

        $file = FileStoreRepository::find((int) $report['screenshot_file_id']);
        if (!$file || !is_file($file['server_path'])) {
            http_response_code(404);
            echo 'File is missing from storage.';
            return;
        }
        $safeDownloadName = preg_replace('/[\x00-\x1F\x7F"\/\\\\]/', '', $file['original_filename']) ?? $file['original_filename'];
        header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
        header('Content-Length: ' . filesize($file['server_path']));
        readfile($file['server_path']);
    }

    /**
     * The client's own Order Confirmation acknowledgment — the single
     * button offered (docs/schema.sql Section AE). Deliberately no
     * decline/dispute option here: raising doubt at this exact step isn't
     * the confirmed design, and a genuine dispute has its own channel
     * (the per-order dispute button, where enabled) once the order is
     * further along.
     */
    public function acknowledgeOc(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        $orderId = (int) ($params['id'] ?? 0);
        $order = OrderRepository::find($orderId);
        if (!$order || (int) $order['client_id'] !== $clientId) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        if (!$ack || $ack['acknowledged_at'] !== null) {
            Flash::set('error', 'There is nothing awaiting your acknowledgement on this order.');
            header("Location: /client/orders/{$orderId}");
            return;
        }

        OrderOcAcknowledgmentRepository::markAcknowledged($orderId, 'client_portal', null, null);
        \App\Services\StageGateService::passAndUnlockNext($orderId, 4, null);
        Flash::set('success', 'Thank you — your acknowledgement has been recorded and your order is moving to production.');
        header("Location: /client/orders/{$orderId}");
    }

    /**
     * docs/schema.sql Section AF — only reachable when staff have switched
     * the per-order flag on; re-checked here server-side (not just hidden
     * in the view) so a client can't file one on an order it wasn't
     * enabled for by guessing the URL.
     */
    public function raiseDispute(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        $orderId = (int) ($params['id'] ?? 0);
        $order = OrderRepository::find($orderId);
        if (!$order || (int) $order['client_id'] !== $clientId) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        if ((int) $order['dispute_button_visible_to_client'] !== 1) {
            Flash::set('error', 'Disputes cannot be raised on this order from the portal.');
            header("Location: /client/orders/{$orderId}");
            return;
        }

        $description = trim((string) ($_POST['description'] ?? ''));
        if ($description === '') {
            Flash::set('error', 'Please describe the issue before submitting.');
            header("Location: /client/orders/{$orderId}");
            return;
        }

        $noticeDate = date('Y-m-d');
        $responseDays = (int) (\App\Repositories\CompanySettingsRepository::get('dispute_response_days_n') ?? '10');
        $responseDueDate = \App\Services\WorkingDaysCalculator::addWorkingDays($noticeDate, $responseDays);
        $disputeId = \App\Repositories\DisputeRepository::create($orderId, $noticeDate, 'Buyer (via client portal)', $description, null, $responseDueDate);
        \App\Repositories\AuditLogRepository::log(null, 'DISPUTE_RAISED', 'disputes', $disputeId, null, null, $description, 'Raised by the client via the client portal.');

        Flash::set('success', 'Your dispute has been logged — our team will respond by ' . $responseDueDate . '.');
        header("Location: /client/orders/{$orderId}");
    }

    /**
     * Client's own "I've paid" note — transaction ref + optional screenshot
     * of the remittance advice. Purely informational (docs/schema.sql
     * Section AD): staff still verify the real bank statement by hand
     * before recording the payment the normal way: nothing here ever
     * touches order_payment_status or unlocks a stage gate.
     */
    public function reportPayment(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        $orderId = (int) ($params['id'] ?? 0);
        $order = OrderRepository::find($orderId);
        if (!$order || (int) $order['client_id'] !== $clientId) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $paymentType = (string) ($_POST['payment_type'] ?? '');
        $transactionRef = trim((string) ($_POST['transaction_ref'] ?? ''));
        if (!in_array($paymentType, ['advance', 'balance', 'freight'], true) || $transactionRef === '') {
            Flash::set('error', 'Select which payment this is for and enter the transaction ID/UTR.');
            header("Location: /client/orders/{$orderId}");
            return;
        }

        $screenshotFileId = null;
        if (!empty($_FILES['screenshot']['name'])) {
            try {
                $screenshotFileId = FileUploadService::handleUpload(
                    'screenshot',
                    'received_remittance',
                    'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/client_payment_reports',
                    $clientId,
                    $orderId,
                    null,
                    null,
                    'Client (self-reported)',
                    'Client payment self-report screenshot'
                );
            } catch (\Throwable $e) {
                Flash::set('error', $e->getMessage());
                header("Location: /client/orders/{$orderId}");
                return;
            }
        }

        $amount = trim((string) ($_POST['amount'] ?? ''));
        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        ClientPaymentReportRepository::create(
            $orderId,
            $paymentType,
            $transactionRef,
            trim((string) ($_POST['payer_bank_details'] ?? '')) ?: null,
            $amount !== '' ? (float) $amount : null,
            $paymentDate !== '' ? $paymentDate : null,
            $screenshotFileId
        );

        Flash::set('success', 'Thank you — we have noted your payment details. Our team will verify this against our bank statement and update your order.');
        header("Location: /client/orders/{$orderId}");
    }

    public function downloadDocument(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        $documentId = (int) ($params['id'] ?? 0);
        $document = DocumentRepository::find($documentId);

        if (!$document || $document['order_id'] === null) {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }
        $order = OrderRepository::find((int) $document['order_id']);
        if (!$order || (int) $order['client_id'] !== $clientId) {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }
        // A client only ever sees the FINAL, approved/sent PDF of a
        // customer-facing document type — never a draft, never an
        // internal-only DOCX, regardless of what's asked for in the URL.
        if (!in_array($document['status'], ['approved', 'sent'], true) || $document['document_type_category'] === 'internal') {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }
        if (!$document['pdf_file_id']) {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }

        $file = FileStoreRepository::find((int) $document['pdf_file_id']);
        if (!$file || !is_file($file['server_path'])) {
            http_response_code(404);
            echo 'File is missing from storage.';
            return;
        }

        $safeDownloadName = str_replace(['/', '\\'], '-', $file['original_filename']);
        $safeDownloadName = preg_replace('/[\x00-\x1F\x7F"]/', '', $safeDownloadName) ?? $safeDownloadName;
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
        header('Content-Length: ' . (string) $file['file_size_bytes']);
        readfile($file['server_path']);
    }

    public function showAccount(array $params): void
    {
        View::render('client_portal/account', ['client' => ClientPortalService::currentClient()], 'layout/client');
    }

    public function changePassword(array $params): void
    {
        $clientId = (int) ClientPortalService::currentClientId();
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $login = \App\Repositories\ClientLoginRepository::findByClientId($clientId);
        if (!$login || !password_verify($current, $login['password_hash'])) {
            Flash::set('error', 'Current password is incorrect.');
            header('Location: /client/account');
            return;
        }
        $policyError = PasswordPolicyService::validate($new);
        if ($policyError !== null) {
            Flash::set('error', $policyError);
            header('Location: /client/account');
            return;
        }
        if ($new !== $confirm) {
            Flash::set('error', 'New passwords do not match.');
            header('Location: /client/account');
            return;
        }

        ClientPortalService::changePassword($clientId, $new);
        Flash::set('success', 'Password updated.');
        header('Location: /client/account');
    }
}
