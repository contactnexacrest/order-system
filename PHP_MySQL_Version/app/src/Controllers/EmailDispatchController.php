<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\DocumentRepository;
use App\Repositories\EmailLogRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\EmailDispatchService;
use App\Services\PermissionService;

/** Spec Section 10 — Email & Deferred Send System, Level 1 + Level 2. */
final class EmailDispatchController
{
    /**
     * Level 1 — compose/preview screen for one document. Loading the page
     * with ?template_key=... also renders the exact merged subject/body
     * that would be queued — the spec's "Preview: exact watermarked PDF +
     * email content + recipient" before submission for Level-2 approval.
     */
    public function compose(array $params): void
    {
        $orderId = (int) $params['id'];
        $documentId = (int) $params['documentId'];
        $templateKey = trim((string) ($_GET['template_key'] ?? '')) ?: null;

        $preview = null;
        $previewError = null;
        if ($templateKey) {
            try {
                $preview = EmailDispatchService::buildPreview($orderId, $documentId, $templateKey, (int) AuthService::currentUser()['id']);
            } catch (\Throwable $e) {
                $previewError = $e->getMessage();
            }
        }

        View::render('email/compose', [
            'order'        => OrderRepository::find($orderId),
            'document'     => DocumentRepository::find($documentId),
            'orderId'      => $orderId,
            'documentId'   => $documentId,
            'templates'    => EmailTemplateRepository::all(),
            'templateKey'  => $templateKey,
            'preview'      => $preview,
            'previewError' => $previewError,
        ], 'layout/base');
    }

    /** Level 1 — submit for Level 2 approval. */
    public function requestSend(array $params): void
    {
        $orderId = (int) $params['id'];
        $documentId = (int) $params['documentId'];
        $templateKey = (string) ($_POST['template_key'] ?? '');
        $scheduledAtRaw = trim((string) ($_POST['scheduled_at'] ?? '')); // empty = immediate
        // <input type="datetime-local"> posts "YYYY-MM-DDTHH:MM" — normalize
        // to a MySQL DATETIME/TIMESTAMP-compatible string.
        $scheduledAt = $scheduledAtRaw !== '' ? str_replace('T', ' ', $scheduledAtRaw) . ':00' : null;

        try {
            EmailDispatchService::requestSend($orderId, $documentId, $templateKey, $scheduledAt, (int) AuthService::currentUser()['id']);
            Flash::set('success', 'Send submitted for approval.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header("Location: /orders/{$orderId}");
    }

    /** Level 2 — approval queue. Shows both awaiting-approval and already-approved-but-not-yet-sent rows, since both are still cancellable. */
    public function approvalQueue(array $params): void
    {
        View::render('email/approvals', [
            'pending' => EmailLogRepository::awaitingDispatch(),
        ], 'layout/base');
    }

    public function approve(array $params): void
    {
        $id = (int) $params['emailLogId'];
        try {
            EmailDispatchService::approveSend($id, (int) AuthService::currentUser()['id']);
            Flash::set('success', 'Send approved — will go out once its scheduled time arrives (dispatched by the cron job).');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /email-approvals');
    }

    public function reject(array $params): void
    {
        $id = (int) $params['emailLogId'];
        try {
            EmailDispatchService::rejectSend($id, (int) AuthService::currentUser()['id'], trim((string) ($_POST['reason'] ?? '')));
            Flash::set('success', 'Send rejected.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /email-approvals');
    }

    /**
     * Cancel a send still in its deferred window (pending_approval or
     * approved, not yet sent). Open to a Level-2 approver for any row, or
     * to anyone else only for a row they themselves requested — enforced
     * in the service, not by route-level permission, since ownership can't
     * be checked until the row is loaded. The redirect target is rebuilt
     * from a validated integer order id rather than trusted from the
     * request body, so this can't be used as an open redirect.
     */
    public function cancel(array $params): void
    {
        $id = (int) $params['emailLogId'];
        $user = AuthService::currentUser();
        $isApprover = PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'approve_email_send');

        $redirectTo = '/email-approvals';
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            $redirectTo = "/orders/{$orderId}";
        }

        try {
            EmailDispatchService::cancelSend($id, (int) $user['id'], trim((string) ($_POST['reason'] ?? '')), $isApprover);
            Flash::set('success', 'Send cancelled before it went out.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header("Location: {$redirectTo}");
    }
}
