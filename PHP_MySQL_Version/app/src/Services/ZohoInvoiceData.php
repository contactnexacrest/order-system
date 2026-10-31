<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DocumentRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;

/**
 * Zoho integration Stage 2 (2026-10-29) — what an order's Commercial
 * Invoice or freight Debit Note says, as the export invoice that goes to
 * Zoho Books. Reads the same data the documents are built from; it never
 * changes a document.
 *
 * - CI: one line per active order product, at its unit price, so the
 *   total is the FOB value. Freight and insurance are NOT on it — on
 *   CFR/CIF orders they are billed on the Debit Note, its own invoice, so
 *   Books does not count them twice.
 * - Debit Note: ocean freight, plus insurance on CIF. Its lines carry the
 *   goods' HS code (the first product's) — owner decision 2026-10-29 (CA
 *   advice): freight billed with export goods is part of the zero-rated
 *   export, so it goes under the goods' code.
 * - Number and date: the document's own reference and printed date.
 * - Exchange rate (INR per unit of the order currency): the customs
 *   exchange rate on the shipping bill (owner decision 2026-10-29, CA
 *   advice), so the GST return matches the shipping bill. Until it is
 *   recorded on the order the invoice is not sent.
 *
 * Every "not ready" case throws a RuntimeException with a message staff
 * can act on; CaSyncService logs it and retries on the next run.
 */
final class ZohoInvoiceData
{
    public const KIND_LABELS = ['ci' => 'Commercial Invoice', 'dn' => 'Debit Note'];

    /**
     * @return array{order_id:int, kind:string, document_id:int, revision:int, client_id:int, company_legal_name:string, currency_code:string,
     *   invoice_number:string, date:string, due_date:?string, reference_number:string, exchange_rate:float, total:float,
     *   lines: list<array{name:string, description:string, hs_code:?string, quantity:float, unit:?string, rate:float}>}
     */
    public static function build(int $orderId, string $kind, int $documentId): array
    {
        $order = OrderRepository::find($orderId);
        $document = DocumentRepository::find($documentId);
        if ($order === null || $document === null) {
            throw new \RuntimeException("Order #{$orderId} or its document #{$documentId} no longer exists.");
        }
        $label = self::KIND_LABELS[$kind];
        if (!empty($document['drift_flagged_at'])) {
            throw new \RuntimeException("{$label} not sent: {$document['document_reference']} is flagged on the order page as possibly out of date (an earlier document was regenerated after it). Generate and approve a revised {$label} first.");
        }
        $currency = (string) ($order['currency_code'] ?? 'INR');
        $payment = OrderPaymentStatusRepository::find($orderId) ?? [];

        $lines = $kind === 'ci' ? self::ciLines($orderId, $label) : self::debitNoteLines($orderId, $order, $label);
        $total = 0.0;
        foreach ($lines as $line) {
            $total += round($line['quantity'] * $line['rate'], 2);
        }

        $rate = 1.0;
        if ($currency !== 'INR') {
            $shipping = OrderShippingRepository::find($orderId) ?? [];
            if (empty($shipping['customs_exchange_rate']) || (float) $shipping['customs_exchange_rate'] <= 0) {
                throw new \RuntimeException("{$label} not sent: record the shipping bill's customs exchange rate on the order (Shipping Bill, on the order page) first.");
            }
            $rate = (float) $shipping['customs_exchange_rate'];
        }

        $date = substr(DocumentRepository::printedDate($document), 0, 10);
        $dueDate = null;
        if ($kind === 'ci' && !empty($payment['balance_due_date']) && (string) $payment['balance_due_date'] >= $date) {
            $dueDate = (string) $payment['balance_due_date'];
        }

        return [
            'order_id' => $orderId,
            'kind' => $kind,
            'document_id' => $documentId,
            'revision' => (int) $document['revision_number'],
            'client_id' => (int) $order['client_id'],
            'company_legal_name' => (string) $order['company_legal_name'],
            'currency_code' => $currency,
            'invoice_number' => (string) $document['document_reference'],
            'date' => $date,
            'due_date' => $dueDate,
            'reference_number' => (string) $order['buyer_inquiry_ref'],
            'summary' => self::summary($kind, $order),
            'exchange_rate' => $rate,
            'total' => round($total, 2),
            'lines' => $lines,
        ];
    }

    /**
     * Owner rule D-212: "Commercial Invoice for order NX-12 · CIF · buyer PO
     * PO-778 · Acme GmbH" — kept as an internal comment on the Books invoice.
     */
    public static function summary(string $kind, array $order): string
    {
        $parts = [($kind === 'dn' ? 'Freight Debit Note' : 'Commercial Invoice') . ' for order ' . ($order['buyer_inquiry_ref'] ?? '')];
        if (!empty($order['incoterm_code'])) {
            $parts[] = (string) $order['incoterm_code'];
        }
        if (!empty($order['buyers_po_ref'])) {
            $parts[] = 'buyer PO ' . $order['buyers_po_ref'];
        }
        $parts[] = (string) ($order['company_legal_name'] ?? '');
        return implode(' · ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /** A fingerprint of everything sent (not which revision it came from): when it changes, the Books invoice is updated. */
    public static function fingerprint(array $invoice): string
    {
        // The summary is a comment added once, not part of the invoice (so
        // invoices already in Books are not re-sent because of it).
        unset($invoice['document_id'], $invoice['revision'], $invoice['summary']);
        return hash('sha256', (string) json_encode($invoice));
    }

    /** @return list<array{name:string, description:string, hs_code:?string, quantity:float, unit:?string, rate:float}> */
    private static function ciLines(int $orderId, string $label): array
    {
        $lines = [];
        foreach (OrderProductRepository::forOrder($orderId) as $product) {
            if ((int) ($product['is_active'] ?? 1) !== 1) {
                continue;
            }
            if ((int) $product['quantity_is_tbc'] === 1 || $product['quantity'] === null || $product['unit_price'] === null) {
                throw new \RuntimeException("{$label} not sent: line {$product['line_no']} has no confirmed quantity or unit price.");
            }
            $quantity = (float) $product['quantity'];
            $rate = (float) $product['unit_price'];
            if (abs(round($quantity * $rate, 2) - (float) $product['fob_value']) > 0.01) {
                throw new \RuntimeException("{$label} not sent: line {$product['line_no']} quantity x unit price (" . number_format($quantity * $rate, 2, '.', '') . ") does not equal its value ({$product['fob_value']}).");
            }
            $description = trim((string) $product['description']);
            $details = array_filter([
                !empty($product['dimensions']) ? 'Size: ' . $product['dimensions'] : null,
                !empty($product['finish']) ? 'Finish: ' . $product['finish'] : null,
            ]);
            $lines[] = [
                'name' => mb_substr($description !== '' ? $description : 'Line ' . $product['line_no'], 0, 100),
                'description' => implode('. ', array_merge([$description], $details)),
                'hs_code' => $product['hs_code'] ?: null,
                'quantity' => $quantity,
                'unit' => $product['unit'] ?: null,
                'rate' => $rate,
            ];
        }
        if ($lines === []) {
            throw new \RuntimeException("{$label} not sent: the order has no products.");
        }
        return $lines;
    }

    /** @return list<array{name:string, description:string, hs_code:?string, quantity:float, unit:?string, rate:float}> */
    private static function debitNoteLines(int $orderId, array $order, string $label): array
    {
        $freight = OrderFreightRepository::find($orderId);
        if ($freight === null || $freight['confirmed_freight_rate'] === null) {
            throw new \RuntimeException("{$label} not sent: no confirmed freight rate on the order.");
        }
        $from = (string) ($order['port_of_loading_name'] ?: $order['port_of_loading_city'] ?: '');
        $to = (string) ($order['port_of_discharge_name'] ?: $order['port_of_discharge_city'] ?: '');
        $route = $from !== '' && $to !== '' ? "{$from} to {$to}" : '';
        $hsCode = null;
        foreach (OrderProductRepository::forOrder($orderId) as $product) {
            if ((int) ($product['is_active'] ?? 1) === 1 && !empty($product['hs_code'])) {
                $hsCode = (string) $product['hs_code'];
                break;
            }
        }
        $lines = [[
            'name' => 'Ocean freight',
            'description' => 'Ocean freight' . ($route !== '' ? ', ' . $route : '') . ' (' . strtoupper((string) $order['incoterm_code']) . ')',
            'hs_code' => $hsCode,
            'quantity' => 1.0,
            'unit' => null,
            'rate' => (float) $freight['confirmed_freight_rate'],
        ]];
        if (strtoupper((string) $order['incoterm_code']) === 'CIF' && (float) ($freight['insurance_amount'] ?? 0) > 0) {
            $lines[] = ['name' => 'Marine insurance', 'description' => 'Marine insurance (CIF)', 'hs_code' => $hsCode, 'quantity' => 1.0, 'unit' => null, 'rate' => (float) $freight['insurance_amount']];
        }
        return $lines;
    }
}
