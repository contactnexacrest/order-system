<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DisputeDocumentRepository;
use App\Repositories\DisputeRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\FileUploadService;

/** Spec Section 16 — Dispute Management. */
final class DisputeController
{
    /** Global dispute log, filterable by status. */
    public function index(array $params): void
    {
        $status = trim((string) ($_GET['status'] ?? '')) ?: null;
        View::render('disputes/index', [
            'disputes'      => DisputeRepository::all($status),
            'statusFilter'  => $status,
            'statusOptions' => LookupRepository::dropdownOptions('dispute_status'),
        ], 'layout/base');
    }

    public function forOrder(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        View::render('disputes/order', [
            'order'         => $order,
            'disputes'      => DisputeRepository::forOrder($orderId),
            'users'         => UserRepository::listActive(),
            'statusOptions' => LookupRepository::dropdownOptions('dispute_status'),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $orderId = (int) $params['id'];
        $noticeDate = trim((string) ($_POST['notice_date'] ?? '')) ?: date('Y-m-d');
        $fromParty = trim((string) ($_POST['from_party'] ?? '')) ?: null;
        $description = trim((string) ($_POST['description'] ?? ''));
        $assignedTo = ($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : null;

        if ($description === '') {
            Flash::set('error', 'A description is mandatory to raise a dispute.');
            header("Location: /orders/{$orderId}/disputes");
            return;
        }

        $responseDays = (int) (CompanySettingsRepository::get('dispute_response_days_n') ?? '7');
        $responseDueDate = (new \DateTimeImmutable($noticeDate))->modify("+{$responseDays} days")->format('Y-m-d');

        $disputeId = DisputeRepository::create($orderId, $noticeDate, $fromParty, $description, $assignedTo, $responseDueDate);
        AuditLogRepository::log((int) AuthService::currentUser()['id'], 'DISPUTE_RAISED', 'disputes', $disputeId, null, null, $description);

        Flash::set('success', "Dispute logged — response due by {$responseDueDate}.");
        header("Location: /orders/{$orderId}/disputes");
    }

    public function updateStatus(array $params): void
    {
        $disputeId = (int) $params['disputeId'];
        $dispute = DisputeRepository::find($disputeId);
        if (!$dispute) {
            http_response_code(404);
            echo 'Dispute not found.';
            return;
        }

        $status = (string) ($_POST['status'] ?? '');
        $resolutionNotes = trim((string) ($_POST['resolution_notes'] ?? ''));

        if ($status === 'Resolved') {
            DisputeRepository::resolve($disputeId, $resolutionNotes ?: 'Resolved.');
        } else {
            DisputeRepository::updateStatus($disputeId, $status);
        }
        AuditLogRepository::log((int) AuthService::currentUser()['id'], 'DISPUTE_STATUS_CHANGED', 'disputes', $disputeId, 'status', $dispute['status'], $status, $resolutionNotes ?: null);

        Flash::set('success', 'Dispute status updated.');
        header("Location: /orders/{$dispute['order_id']}/disputes");
    }

    public function uploadDocument(array $params): void
    {
        $disputeId = (int) $params['disputeId'];
        $dispute = DisputeRepository::find($disputeId);
        if (!$dispute) {
            http_response_code(404);
            echo 'Dispute not found.';
            return;
        }
        $order = OrderRepository::find((int) $dispute['order_id']);

        try {
            $fileId = FileUploadService::handleUpload(
                'document',
                'dispute_document',
                'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/disputes/' . $disputeId,
                null,
                (int) $dispute['order_id'],
                (int) AuthService::currentUser()['id'],
                null,
                trim((string) ($_POST['received_from'] ?? '')) ?: null,
                'Dispute-related document'
            );
            DisputeDocumentRepository::attach($disputeId, $fileId);
            Flash::set('success', 'Document attached to dispute.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header("Location: /orders/{$dispute['order_id']}/disputes");
    }
}
