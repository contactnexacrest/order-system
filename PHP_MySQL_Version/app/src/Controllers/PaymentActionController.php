<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\OrderRepository;
use App\Services\PaymentActionService;

/**
 * Owner request (2026-10-27): public, no-login Payment Action Link — the
 * client sends payment details for the advance, freight or balance, one
 * payment at a time. Payment only: no Buyer PO, no Order Confirmation.
 * Authorization is the per-order token (OrderRepository::
 * findByValidPaymentActionToken()), like the Buyer Action Link.
 */
final class PaymentActionController
{
    private function orderOrExpired(string $token): ?array
    {
        $tokenRow = OrderRepository::findByValidPaymentActionToken($token);
        if (!$tokenRow) {
            View::render('payment_action/link_expired', [], 'layout/bare');
            return null;
        }
        return OrderRepository::find((int) $tokenRow['id']);
    }

    public function show(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $order = $this->orderOrExpired($token);
        if (!$order) {
            return;
        }
        $current = PaymentActionService::current($order);
        $leg = $current['leg'];
        View::render('payment_action/show', [
            'wrapClass' => 'intake-wrap',
            'token' => $token,
            'order' => $order,
            'current' => $current,
            'legInfo' => $leg !== null ? PaymentActionService::LEGS[$leg] : null,
            'document' => $leg !== null ? PaymentActionService::issuedDocument((int) $order['id'], $leg) : null,
            'amountDue' => $leg !== null ? PaymentActionService::amountDue($order, $leg) : null,
        ], 'layout/bare');
    }

    public function report(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $order = $this->orderOrExpired($token);
        if (!$order) {
            return;
        }
        $result = PaymentActionService::report($order);
        Flash::set($result['ok'] ? 'success' : 'error', $result['message']);
        header("Location: /payment-action/{$token}");
    }

    /** The document the current payment is made against — the PI, Debit Note or CI — and nothing else. */
    public function downloadDocument(array $params): void
    {
        $order = $this->orderOrExpired((string) ($params['token'] ?? ''));
        if (!$order) {
            return;
        }
        $leg = PaymentActionService::current($order)['leg'];
        $document = $leg !== null ? PaymentActionService::issuedDocument((int) $order['id'], $leg) : null;
        \App\Helpers\StoredFileDownload::send($document ? (int) $document['pdf_file_id'] : null);
    }
}
