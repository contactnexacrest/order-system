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

    /** Level 2 — approval queue. */
    public function approvalQueue(array $params): void
    {
        View::render('email/approvals', [
            'pending' => EmailLogRepository::pendingApproval(),
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
}
