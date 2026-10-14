<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Services\BuyerActionService;

/**
 * 90-item backlog #75: public, no-login counterpart to the two
 * client-portal actions buyers most often need right after receiving
 * their Order Confirmation — acknowledging it, and uploading their signed
 * Buyer PO copy. Authorization is a per-order token
 * (OrderRepository::generateBuyerActionToken()/findByValidBuyerActionToken(),
 * docs/schema.sql Section BG) rather than a client-portal session,
 * modeled on the existing public /quotation-details and /pi-details
 * links. The actual write logic lives in BuyerActionService, shared with
 * ClientPortalController so the session-gated and token-gated routes
 * never drift apart.
 *
 * Deliberately narrower than the full client-portal order page: no
 * document downloads, payment reports, or chat — those stay
 * portal-login-only. This page exists to unblock exactly the two actions
 * the backlog item named.
 */
final class BuyerActionController
{
    /**
     * Validates the token, then re-loads the full, client-joined order row
     * via find() — findByValidBuyerActionToken() stays a minimal
     * authorization check, not a second copy of find()'s whole JOIN.
     */
    private function orderOrExpired(string $token): ?array
    {
        $tokenRow = OrderRepository::findByValidBuyerActionToken($token);
        if (!$tokenRow) {
            View::render('buyer_action/link_expired', [], 'layout/bare');
            return null;
        }
        return OrderRepository::find((int) $tokenRow['id']);
    }

    public function show(array $params): void
    {
        $order = $this->orderOrExpired((string) ($params['token'] ?? ''));
        if (!$order) {
            return;
        }
        $orderId = (int) $order['id'];

        View::render('buyer_action/show', [
            'token' => $params['token'],
            'order' => $order,
            'products' => OrderProductRepository::forOrder($orderId),
            'totalFobValue' => OrderProductRepository::totalFobValue($orderId),
            'ocAcknowledgment' => OrderOcAcknowledgmentRepository::find($orderId),
            'buyerPoDocuments' => OrderBuyerPoDocumentRepository::forOrder($orderId),
        ], 'layout/bare');
    }

    public function acknowledgeOc(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $order = $this->orderOrExpired($token);
        if (!$order) {
            return;
        }

        $result = BuyerActionService::acknowledgeOc((int) $order['id']);
        Flash::set($result['ok'] ? 'success' : 'error', $result['message']);
        header("Location: /buyer-action/{$token}");
    }

    public function uploadBuyerPo(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $order = $this->orderOrExpired($token);
        if (!$order) {
            return;
        }

        $result = BuyerActionService::uploadBuyerPo($order);
        Flash::set($result['ok'] ? 'success' : 'error', $result['message']);
        header("Location: /buyer-action/{$token}");
    }
}
