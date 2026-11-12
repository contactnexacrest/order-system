<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\BuyerDetailsRefreshService;
use App\Services\OrderAccessService;

/**
 * Owner decision D-337 (12 Nov 2026): the order page's "buyer details" card —
 * update a draft order from its client, ask to update an order with an issued
 * document, and approve / refuse that request. The rules live in
 * BuyerDetailsRefreshService. Node twin: controllers/buyerDetailsController.js.
 */
final class BuyerDetailsController
{
    /** @return array{0:?array,1:?array} [order, actor] or [null, null] after answering */
    private function load(int $orderId): array
    {
        $order = OrderRepository::find($orderId);
        $actor = AuthService::currentUser();
        if ($order === null || $actor === null) {
            http_response_code(404);
            echo 'Order not found.';
            return [null, null];
        }
        $denied = OrderAccessService::denialReason($order, (int) $actor['id'], $actor['role_id'] !== null ? (int) $actor['role_id'] : null);
        if ($denied !== null) {
            http_response_code(403);
            echo $denied;
            return [null, null];
        }
        return [$order, $actor];
    }

    private function back(int $orderId): void
    {
        header("Location: /orders/{$orderId}#buyer-details-change");
    }

    /** POST /orders/{id}/buyer-details/refresh — nothing issued yet: copy the client's details now. */
    public function refresh(array $params): void
    {
        $orderId = (int) $params['id'];
        [$order, $actor] = $this->load($orderId);
        if ($order === null) {
            return;
        }
        $result = BuyerDetailsRefreshService::refreshOrder($orderId, (int) $actor['id']);
        if ($result['error'] !== null) {
            Flash::set('error', $result['error']);
        } else {
            Flash::set('success', 'Buyer details updated from the client: ' . BuyerDetailsRefreshService::changesText($result['changes']) . '.');
        }
        $this->back($orderId);
    }

    /** POST /orders/{id}/buyer-details/request — an issued document exists: ask for approval. */
    public function request(array $params): void
    {
        $orderId = (int) $params['id'];
        [$order, $actor] = $this->load($orderId);
        if ($order === null) {
            return;
        }
        $error = BuyerDetailsRefreshService::request($orderId, (int) $actor['id'], (string) ($_POST['reason'] ?? ''));
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Request sent — the buyer details change once it is approved.');
        $this->back($orderId);
    }

    /** POST /orders/{id}/buyer-details/{requestId}/approve */
    public function approve(array $params): void
    {
        $orderId = (int) $params['id'];
        [$order, $actor] = $this->load($orderId);
        if ($order === null) {
            return;
        }
        $error = BuyerDetailsRefreshService::approve((int) $params['requestId'], $orderId, (int) $actor['id'], (string) ($_POST['decision_note'] ?? ''));
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Approved — the order now shows the client\'s current details. Issued documents keep the details they were issued with.');
        $this->back($orderId);
    }

    /** POST /orders/{id}/buyer-details/{requestId}/refuse */
    public function refuse(array $params): void
    {
        $orderId = (int) $params['id'];
        [$order, $actor] = $this->load($orderId);
        if ($order === null) {
            return;
        }
        $error = BuyerDetailsRefreshService::refuse((int) $params['requestId'], $orderId, (int) $actor['id'], (string) ($_POST['decision_note'] ?? ''));
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Refused — the order keeps its buyer details.');
        $this->back($orderId);
    }
}
