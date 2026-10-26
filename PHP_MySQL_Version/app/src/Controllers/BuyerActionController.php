<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\DocumentRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Services\BuyerActionService;
use App\Services\DocumentGenerationService;

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
 * document downloads or chat — those stay portal-login-only. 90-item
 * backlog #48 added one narrow exception to "no payment reports": the
 * advance-payment self-report below, since that's the one payment leg the
 * portal itself can never reach (its login isn't provisioned until the
 * advance clears — see BuyerActionService::reportAdvancePayment()).
 * Freight/balance self-reports stay portal-only, as before.
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

        $paymentStatus = OrderPaymentStatusRepository::find($orderId);

        // User-reported batch item 10: the acknowledgement section below
        // only ever rendered when an order_oc_acknowledgments row already
        // existed — but that row was only ever created by
        // EmailDispatchService::dispatch() when the OC email actually went
        // out. A Buyer Action Link generated before that email was sent
        // (still awaiting Level-2 approval, mail misconfigured, or staff
        // just generated the link to hand the buyer directly) led to a
        // page with nothing to acknowledge at all. Same lazy fallback
        // OrderController::recordOcAcknowledgment() already uses for the
        // staff-side "Record Buyer's Acknowledgement" path.
        $ocAcknowledgment = OrderOcAcknowledgmentRepository::find($orderId);
        if (!$ocAcknowledgment) {
            $ocDoc = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'OC');
            // Only once the OC counts as issued (approved while email is
            // off, sent while it is on) — the same rule the Stage 4 gate applies.
            if ($ocDoc && DocumentGenerationService::stageDocumentBlockedBy($orderId, 4) === []) {
                OrderOcAcknowledgmentRepository::ensureRowExists($orderId, (int) $ocDoc['id']);
                $ocAcknowledgment = OrderOcAcknowledgmentRepository::find($orderId);
            }
        }

        $totalFobValue = OrderProductRepository::totalFobValue($orderId);
        View::render('buyer_action/show', [
            'wrapClass' => 'intake-wrap',
            'task' => BuyerActionService::currentTask($order, $ocAcknowledgment),
            'issuedBuyerPo' => BuyerActionService::issuedBuyerDocument($orderId, 'BUYERPO'),
            'issuedPi' => BuyerActionService::issuedBuyerDocument($orderId, 'PI'),
            'token' => $params['token'],
            'order' => $order,
            'products' => OrderProductRepository::forOrder($orderId),
            'totalFobValue' => $totalFobValue,
            'advanceReceived' => $paymentStatus !== null && $paymentStatus['advance_amount'] !== null ? (float) $paymentStatus['advance_amount'] : null,
            'balanceDue' => OrderPaymentStatusRepository::balanceDue($totalFobValue, (float) ($order['advance_pct'] ?? 0), $paymentStatus),
            'ocAcknowledgment' => $ocAcknowledgment,
            'buyerPoDocuments' => OrderBuyerPoDocumentRepository::forOrder($orderId),
            'advanceCleared' => $paymentStatus !== null && $paymentStatus['advance_cleared_at'] !== null,
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

    public function reportPayment(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $order = $this->orderOrExpired($token);
        if (!$order) {
            return;
        }

        $result = BuyerActionService::reportAdvancePayment($order);
        Flash::set($result['ok'] ? 'success' : 'error', $result['message']);
        header("Location: /buyer-action/{$token}");
    }

    /**
     * Owner request (2026-10-26): from the link the buyer downloads the Buyer
     * PO (to sign) and, later, the Proforma Invoice (to pay against) — each
     * only once issued, and nothing else.
     */
    public function downloadBuyerPo(array $params): void
    {
        $this->downloadIssued($params, 'BUYERPO');
    }

    public function downloadProformaInvoice(array $params): void
    {
        $this->downloadIssued($params, 'PI');
    }

    private function downloadIssued(array $params, string $typeCode): void
    {
        $order = $this->orderOrExpired((string) ($params['token'] ?? ''));
        if (!$order) {
            return;
        }
        $document = BuyerActionService::issuedBuyerDocument((int) $order['id'], $typeCode);
        \App\Helpers\StoredFileDownload::send($document ? (int) $document['pdf_file_id'] : null);
    }
}
