<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Repositories\OrderComplaintReplyRepository;
use App\Repositories\OrderComplaintRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\OrderComplaintService;
use App\Services\PermissionService;
use App\Services\UiTemplateService;

/** docs/schema.sql Section BX — Complaint / Process Issue management (distinct from Disputes). */
final class ComplaintController
{
    private const STATUSES = ['open', 'acknowledged', 'resolved'];

    /** Global complaint log, filterable by status. */
    public function index(array $params): void
    {
        $actor = AuthService::currentUser();
        if (UiTemplateService::showsBoard($actor['ui_template_preference_id'] ?? null)) {
            $this->indexKanban();
            return;
        }

        $status = trim((string) ($_GET['status'] ?? '')) ?: null;
        \App\Helpers\View::render('complaints/index', [
            'complaints'   => \App\Services\OrderAccessService::visibleRows(OrderComplaintRepository::all($status)),
            'statusFilter' => $status,
            // Owner hard rule 2026-11-01: a Kanban user who switched to the list can switch back.
            'kanbanUser'   => UiTemplateService::resolve($actor['ui_template_preference_id'] ?? null) === 'kanban',
        ], 'layout/base');
    }

    /** The Kanban UI template's Complaints board: Open / Acknowledged / Resolved columns. */
    private function indexKanban(): void
    {
        // Owner hard rule 2026-11-01: the same status filter as the list — a chosen status shows its column only.
        $filter = trim((string) ($_GET['status'] ?? '')) ?: null;
        $columns = [];
        foreach (self::STATUSES as $status) {
            if ($filter === null || $filter === $status) {
                $columns[$status] = \App\Services\OrderAccessService::visibleRows(OrderComplaintRepository::all($status));
            }
        }

        \App\Helpers\View::render('complaints/index_kanban', [
            'columns' => $columns,
            'statusFilter' => $filter,
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

        $complaints = OrderComplaintRepository::forOrder($orderId);
        $replies = [];
        foreach ($complaints as $c) {
            $replies[(int) $c['id']] = OrderComplaintReplyRepository::forComplaint((int) $c['id']);
        }

        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $canManage = PermissionService::can((int) $user['id'], $roleId, 'manage_complaints');
        $canRespond = PermissionService::can((int) $user['id'], $roleId, 'respond_to_complaints');

        \App\Helpers\View::render('complaints/order', [
            'order'      => $order,
            'complaints' => $complaints,
            'replies'    => $replies,
            'canManage'  => $canManage,
            'canRespond' => $canRespond,
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $description = trim((string) ($_POST['description'] ?? ''));
        if ($description === '') {
            Flash::set('error', 'A description is mandatory to log a complaint.');
            header("Location: /orders/{$orderId}/complaints");
            return;
        }

        $userId = (int) AuthService::currentUser()['id'];
        OrderComplaintService::raiseAsStaff($orderId, (int) $order['client_id'], $userId, $description);

        Flash::set('success', 'Complaint logged.');
        header("Location: /orders/{$orderId}/complaints");
    }

    public function postReply(array $params): void
    {
        $complaintId = (int) $params['complaintId'];
        $complaint = OrderComplaintRepository::find($complaintId);
        if (!$complaint) {
            http_response_code(404);
            echo 'Complaint not found.';
            return;
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            Flash::set('error', 'Write a reply before posting.');
            header("Location: /orders/{$complaint['order_id']}/complaints");
            return;
        }

        $userId = (int) AuthService::currentUser()['id'];
        OrderComplaintService::replyAsStaff($complaintId, $userId, $body);

        // QA Round 5, R5-16: with email sending off, nothing was emailed — say so.
        Flash::set('success', \App\Services\EmailDispatchService::mailSendingEnabled()
            ? 'Reply posted — the client has been emailed.'
            : 'Reply posted to the client portal. Email sending is turned off, so the client was NOT emailed — tell them yourself if it is urgent.');
        header("Location: /orders/{$complaint['order_id']}/complaints");
    }

    public function updateStatus(array $params): void
    {
        $complaintId = (int) $params['complaintId'];
        $complaint = OrderComplaintRepository::find($complaintId);
        if (!$complaint) {
            http_response_code(404);
            echo 'Complaint not found.';
            return;
        }

        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            Flash::set('error', 'Invalid complaint status.');
            header("Location: /orders/{$complaint['order_id']}/complaints");
            return;
        }

        $userId = (int) AuthService::currentUser()['id'];
        OrderComplaintService::updateStatus($complaintId, $status, $userId);

        Flash::set('success', 'Complaint status updated.');
        header("Location: /orders/{$complaint['order_id']}/complaints");
    }
}
