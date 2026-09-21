<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AdminOverrideRepository;
use App\Repositories\AmendmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderRepository;
use App\Services\AmendmentService;
use App\Services\AuthService;
use App\Services\FileUploadService;

/** Spec Section 8 — Payment Terms Amendment System (SC/AMD). */
final class AmendmentController
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

        View::render('amendments/index', [
            'order'      => $order,
            'amendments' => AmendmentRepository::forOrder($orderId),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $orderId = (int) $params['id'];
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $requestedBy = (string) ($_POST['requested_by'] ?? 'importer');
        $amendedAdvancePct = ($_POST['amended_advance_pct'] ?? '') !== '' ? (float) $_POST['amended_advance_pct'] : null;
        $amendedAdvanceAmount = ($_POST['amended_advance_amount'] ?? '') !== '' ? (float) $_POST['amended_advance_amount'] : null;
        $amendedBalanceTerms = trim((string) ($_POST['amended_balance_terms'] ?? '')) ?: null;
        $rawTriggerOption = (string) ($_POST['amended_balance_trigger_option'] ?? '');
        $amendedBalanceTriggerOption = in_array($rawTriggerOption, ['A_BEFORE_SHIPMENT', 'B_AGAINST_BL'], true) ? $rawTriggerOption : null;
        $amendedBalanceDays = ($_POST['amended_balance_days'] ?? '') !== '' ? (int) $_POST['amended_balance_days'] : null;
        $amendedBalanceAmount = ($_POST['amended_balance_amount'] ?? '') !== '' ? (float) $_POST['amended_balance_amount'] : null;
        $effectiveFrom = trim((string) ($_POST['effective_from'] ?? '')) ?: null;

        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}/amendments");
            return;
        }

        try {
            AmendmentService::createRequest(
                $orderId,
                $reason,
                $requestedBy === 'exporter' ? 'exporter' : 'importer',
                $amendedAdvancePct,
                $amendedAdvanceAmount,
                $amendedBalanceTerms,
                $amendedBalanceTriggerOption,
                $amendedBalanceDays,
                $amendedBalanceAmount,
                $effectiveFrom,
                (int) AuthService::currentUser()['id']
            );
            Flash::set('success', 'Amendment request created — awaiting MD approval.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header("Location: /orders/{$orderId}/amendments");
    }

    public function mdApprove(array $params): void
    {
        $amendmentId = (int) $params['amendmentId'];
        $amendment = AmendmentRepository::find($amendmentId);

        try {
            AmendmentService::approveByMd($amendmentId, (int) AuthService::currentUser()['id']);
            Flash::set('success', 'Amendment MD-approved. Generate the agreement document next.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header('Location: /orders/' . ($amendment['order_id'] ?? '') . '/amendments');
    }

    public function reject(array $params): void
    {
        $amendmentId = (int) $params['amendmentId'];
        $amendment = AmendmentRepository::find($amendmentId);

        \App\Services\AmendmentService::rejectAmendment($amendmentId, (int) AuthService::currentUser()['id']);
        Flash::set('success', 'Amendment request rejected.');

        header('Location: /orders/' . ($amendment['order_id'] ?? '') . '/amendments');
    }

    public function generateDocument(array $params): void
    {
        $amendmentId = (int) $params['amendmentId'];
        $amendment = AmendmentRepository::find($amendmentId);

        try {
            AmendmentService::generateDocument($amendmentId, (int) AuthService::currentUser()['id']);
            Flash::set('success', 'Payment Terms Amendment Agreement generated. Print, obtain wet signature + company stamp from the Importer, then upload the signed copy.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header('Location: /orders/' . ($amendment['order_id'] ?? '') . '/amendments');
    }

    public function uploadSignedCopy(array $params): void
    {
        $amendmentId = (int) $params['amendmentId'];
        $amendment = AmendmentRepository::find($amendmentId);
        if (!$amendment) {
            http_response_code(404);
            echo 'Amendment not found.';
            return;
        }
        $order = OrderRepository::find((int) $amendment['order_id']);

        try {
            $fileId = FileUploadService::handleUpload(
                'signed_copy',
                'amendment_signed_copy',
                'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/amendments/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $amendment['amendment_reference']) . '/signed',
                null,
                (int) $amendment['order_id'],
                (int) AuthService::currentUser()['id'],
                null,
                'Buyer',
                'Countersigned Payment Terms Amendment Agreement'
            );
            AmendmentService::attachSignedCopyAndActivate($amendmentId, $fileId, (int) AuthService::currentUser()['id']);
            Flash::set('success', 'Signed copy uploaded — amendment is now active and payment terms are updated.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header('Location: /orders/' . ($amendment['order_id'] ?? '') . '/amendments');
    }

    /**
     * Spec Section 13 — "Amendment reference numbers" is explicitly named
     * as an Admin-editable field. Business Rule #20 (never printed on any
     * buyer-facing document) is what makes this safe to allow at all —
     * unlike a QT/PI/OC/CI reference, no external party has ever seen
     * this string. Reason mandatory, logged with old/new value.
     */
    public function overrideReference(array $params): void
    {
        $amendmentId = (int) $params['amendmentId'];
        $amendment = AmendmentRepository::find($amendmentId);
        if (!$amendment) {
            http_response_code(404);
            echo 'Amendment not found.';
            return;
        }

        $user = AuthService::currentUser();
        $newReference = trim((string) ($_POST['amendment_reference'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($newReference === '' || $newReference === $amendment['amendment_reference']) {
            Flash::set('success', 'No change was made.');
            header('Location: /orders/' . $amendment['order_id'] . '/amendments');
            return;
        }
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: /orders/' . $amendment['order_id'] . '/amendments');
            return;
        }

        AdminOverrideRepository::updateAmendmentReference($amendmentId, $newReference);
        AuditLogRepository::log((int) $user['id'], 'FIELD_EDIT', 'amendments', $amendmentId, 'amendment_reference', $amendment['amendment_reference'], $newReference, $reason);
        Flash::set('success', "Amendment reference overridden to {$newReference}.");
        header('Location: /orders/' . $amendment['order_id'] . '/amendments');
    }
}
