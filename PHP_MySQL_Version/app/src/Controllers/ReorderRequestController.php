<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\HsCodeRepository;
use App\Repositories\OrderReorderRequestRepository;
use App\Services\AuthService;
use App\Services\OrderDuplicationService;

/**
 * Staff review queue for client-initiated reorder requests (schema.sql
 * Section AJ) — a separate queue from ClientIntakeReviewController's
 * (brand-new client) and PiIntakeReviewController's (PI-stage) queues,
 * since this one's client already exists and already has an order on
 * file. Approving is the one place a reorder request actually becomes a
 * live order — via OrderDuplicationService, seeded from this request's
 * (possibly client-edited) product lines rather than the source order's
 * current ones, so a client's requested change survives into the new
 * order.
 */
final class ReorderRequestController
{
    public function index(array $params): void
    {
        View::render('reorder_requests/index', [
            'pending'  => OrderReorderRequestRepository::pendingReview(),
            'resolved' => OrderReorderRequestRepository::recentResolved(),
        ], 'layout/base');
    }

    public function show(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $request = OrderReorderRequestRepository::find($id);
        if (!$request) {
            Flash::set('error', 'Reorder request not found.');
            header('Location: /reorder-requests');
            return;
        }

        View::render('reorder_requests/show', [
            'request'  => $request,
            'lines'    => OrderReorderRequestRepository::productLines($id),
            'hsCodes'  => HsCodeRepository::active(),
        ], 'layout/base');
    }

    /**
     * Staff confirm/adjust each line's HS code, unit price, and any final
     * wording before this becomes a real order — the client's submission
     * is a starting point, not something applied verbatim.
     */
    public function approve(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $request = OrderReorderRequestRepository::find($id);
        if (!$request || $request['status'] !== 'pending') {
            Flash::set('error', 'Reorder request not found, or already resolved.');
            header('Location: /reorder-requests');
            return;
        }

        $descriptions = $_POST['description'] ?? [];
        $lines = [];
        foreach ($descriptions as $i => $description) {
            $description = trim((string) $description);
            if ($description === '') {
                continue;
            }
            $hsCode = trim((string) ($_POST['hs_code'][$i] ?? ''));
            if ($hsCode === '' || !HsCodeRepository::isActiveCode($hsCode)) {
                Flash::set('error', "HS code \"{$hsCode}\" for line \"{$description}\" is not on the HS Code master list — add it there first, or correct it, before approving.");
                header("Location: /reorder-requests/{$id}");
                return;
            }
            $lines[] = [
                'description'     => $description,
                'dimensions'      => trim((string) ($_POST['dimensions'][$i] ?? '')) ?: null,
                'finish'          => trim((string) ($_POST['finish'][$i] ?? '')) ?: null,
                'quantity'        => trim((string) ($_POST['quantity'][$i] ?? '')) ?: null,
                'quantity_is_tbc' => !empty($_POST['quantity_is_tbc'][$i]),
                'unit'            => trim((string) ($_POST['unit'][$i] ?? '')) ?: null,
                'unit_price'      => trim((string) ($_POST['unit_price'][$i] ?? '')) ?: null,
                'hs_code'         => $hsCode,
            ];
        }
        if (empty($lines)) {
            Flash::set('error', 'At least one product line (with a description) is required to approve.');
            header("Location: /reorder-requests/{$id}");
            return;
        }

        $user = AuthService::currentUser();
        $newOrderId = OrderDuplicationService::duplicate((int) $request['source_order_id'], (int) $user['id'], $lines);
        OrderReorderRequestRepository::markApproved($id, (int) $user['id'], $newOrderId);

        Flash::set('success', 'Reorder request approved — new order created. Review and adjust it before proceeding.');
        header("Location: /orders/{$newOrderId}/edit");
    }

    public function reject(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $request = OrderReorderRequestRepository::find($id);
        if (!$request || $request['status'] !== 'pending') {
            Flash::set('error', 'Reorder request not found, or already resolved.');
            header('Location: /reorder-requests');
            return;
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header("Location: /reorder-requests/{$id}");
            return;
        }

        $user = AuthService::currentUser();
        OrderReorderRequestRepository::markRejected($id, (int) $user['id'], $reason);
        Flash::set('success', 'Reorder request rejected.');
        header('Location: /reorder-requests');
    }
}
