<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Helpers\AmountInWords;
use App\Helpers\OrderStorage;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DomesticOrderRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderRepository;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): the documents of a Domestic
 * Trade (DOM) order — new documents, for DOM orders only; the export
 * documents are not touched:
 *
 *   DQT quotation · DOC order confirmation · GTI GST tax invoice · DCH delivery challan
 *
 * One template (templates/DOM/domestic_document.html.twig) prints all four.
 * The tax invoice shows CGST + SGST (same state) or IGST (another state), an
 * HSN summary and the total in words (lakh / crore); the invoice and the
 * challan carry the transport and e-way bill details. Every figure is worked
 * out here (GstCalculator) so the template only prints. Port: domesticDocumentService.js.
 */
final class DomesticDocumentService
{
    /** @return array<string,mixed> everything the template prints */
    public static function context(int $orderId, string $code, string $reference): array
    {
        $order = OrderRepository::find($orderId);
        $dom = DomesticOrderRepository::find($orderId);
        if ($order === null || $dom === null || !isset(DomesticOrderService::DOCUMENTS[$code])) {
            throw new \RuntimeException('Not a domestic order or document.');
        }
        $t = DomesticOrderService::totals($orderId);
        $lines = [];
        foreach ($t['lines'] as $i => $l) {
            $lines[] = [
                'no' => $i + 1,
                'description' => (string) $l['description'],
                'hsn_code' => (string) $l['hsn_code'],
                'quantity' => self::trimDecimals((string) $l['quantity']),
                'unit' => (string) $l['unit'],
                'rate' => GstCalculator::inr((string) $l['rate_inr']),
                'gst_rate' => self::trimDecimals((string) $l['gst_rate_pct']) . '%',
                'taxable' => GstCalculator::inr($l['taxable']),
                'cgst' => GstCalculator::inr($l['cgst']),
                'sgst' => GstCalculator::inr($l['sgst']),
                'igst' => GstCalculator::inr($l['igst']),
                'line_total' => GstCalculator::inr($l['line_total']),
            ];
        }
        $hsn = [];
        foreach ($t['hsn_summary'] as $h) {
            $hsn[] = [
                'hsn_code' => $h['hsn_code'],
                'gst_rate' => self::trimDecimals((string) $h['gst_rate_pct']) . '%',
                'taxable' => GstCalculator::inr($h['taxable']),
                'cgst' => GstCalculator::inr($h['cgst']),
                'sgst' => GstCalculator::inr($h['sgst']),
                'igst' => GstCalculator::inr($h['igst']),
                'tax_total' => GstCalculator::inr($h['tax_total']),
            ];
        }
        $company = OrderSnapshotService::companyReader($dom['company_snapshot_json'] ?? null); // D-262: frozen at dispatch
        $state = static fn (?string $c): string => $c ? DomesticOrderRepository::stateName($c) . ' (' . $c . ')' : '';
        $date = static fn (?string $d): string => $d ? (new \DateTimeImmutable($d))->format('d M Y') : '';
        $isInvoice = $code === 'GTI';
        return [
            'code' => $code,
            'title' => ['DQT' => 'QUOTATION', 'DOC' => 'ORDER CONFIRMATION', 'GTI' => 'TAX INVOICE', 'DCH' => 'DELIVERY CHALLAN'][$code],
            'reference' => $reference,
            'document_date' => $date($isInvoice ? (string) $dom['tax_invoice_date'] : date('Y-m-d')),
            'order_reference' => (string) $order['order_reference'],
            'buyer_po_ref' => (string) ($dom['buyer_po_ref'] ?? ''),
            'company' => [
                'name' => $company('legal_name'),
                'address_lines' => self::lines($company('registered_office')),
                'gstin' => $company('gstin'),
                'pan' => $company('iec_pan'),
                'phone' => $company('phone'),
                'email' => $company('email'),
                'state' => $state((string) $dom['seller_state_code']),
                'bank_name' => $company('bank_name'),
                'bank_branch' => $company('bank_branch'),
                'bank_account_no' => $company('bank_account_no'),
                'ifsc' => $company('ifsc'),
            ],
            'buyer' => [
                'name' => (string) $order['company_legal_name'],
                'address_lines' => self::lines((string) ($order['billing_address'] ?? '')),
                'gstin' => (string) ($dom['buyer_gstin'] ?? ''),
                'state' => $state($dom['buyer_state_code']),
            ],
            'delivery_address_lines' => self::lines((string) ($dom['delivery_address'] ?: ($order['billing_address'] ?? ''))),
            'place_of_supply' => $state((string) $dom['place_of_supply']),
            'intra_state' => $t['intra_state'],
            'lines' => $lines,
            'hsn_summary' => $hsn,
            'taxable_total' => GstCalculator::inr($t['taxable_total']),
            'cgst_total' => GstCalculator::inr($t['cgst_total']),
            'sgst_total' => GstCalculator::inr($t['sgst_total']),
            'igst_total' => GstCalculator::inr($t['igst_total']),
            'tax_total' => GstCalculator::inr($t['tax_total']),
            'round_off' => GstCalculator::inr($t['round_off']),
            'invoice_total' => GstCalculator::inr($t['invoice_total']),
            'amount_in_words' => AmountInWords::formatIndian($t['invoice_total']),
            'payment_terms_days' => (int) $dom['payment_terms_days'],
            'validity_days' => 15,
            'show_transport' => in_array($code, ['GTI', 'DCH'], true),
            'tax_invoice_no' => (string) ($dom['tax_invoice_no'] ?? ''),
            'tax_invoice_date' => $date($dom['tax_invoice_date'] ?? null),
            'dispatch_date' => $date($dom['dispatch_date'] ?? null),
            'transporter_name' => (string) ($dom['transporter_name'] ?? ''),
            'transporter_id' => (string) ($dom['transporter_id'] ?? ''),
            'vehicle_no' => (string) ($dom['vehicle_no'] ?? ''),
            'lr_no' => (string) ($dom['lr_no'] ?? ''),
            'eway_bill_no' => (string) ($dom['eway_bill_no'] ?? ''),
            'eway_bill_date' => $date($dom['eway_bill_date'] ?? null),
            'e_invoice_note' => $isInvoice && DomesticOrderService::setting('dom_e_invoice_applicable') === '1',
        ];
    }

    /** @return list<string> an address as its non-empty lines */
    public static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), static fn (string $l): bool => $l !== ''));
    }

    /** "10.500" => "10.5", "18.00" => "18", "10" => "10". */
    public static function trimDecimals(string $v): string
    {
        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    public static function html(int $orderId, string $code, string $reference): string
    {
        $twig = new TwigEnvironment(new TwigFilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['cache' => false, 'autoescape' => 'html']);
        return $twig->render('DOM/domestic_document.html.twig', self::context($orderId, $code, $reference));
    }

    /**
     * Generates a DOM document as a PDF, stores it with the order's files and
     * lists it on the order. Returns null on success, else why not.
     *
     * @return array{0:?int, 1:?string} [file id, error]
     */
    public static function generate(int $orderId, string $code, int $userId): array
    {
        $order = OrderRepository::find($orderId);
        $dom = DomesticOrderRepository::find($orderId);
        $error = match (true) {
            $order === null || $dom === null => 'Order not found.',
            !isset(DomesticOrderService::DOCUMENTS[$code]) => 'Unknown document.',
            !DomesticOrderService::documentAllowed($dom, $code) => 'The ' . strtolower(DomesticOrderService::DOCUMENTS[$code]) . ' is generated from the ' . DomesticOrderService::STAGES[['DQT' => 'quotation', 'DOC' => 'order_confirmation', 'GTI' => 'dispatch', 'DCH' => 'dispatch'][$code]] . ' stage on.',
            DomesticOrderRepository::lines($orderId) === [] => 'Add at least one line first.',
            default => null,
        };
        if ($error !== null) {
            return [null, $error];
        }
        $reference = DomesticOrderService::documentReference($orderId, $code);
        $html = self::html($orderId, $code, $reference);
        $pdf = DocumentGenerationService::renderStandalonePdf($html, $reference);
        $dir = rtrim(Env::storageBasePath(), '/') . '/' . OrderStorage::forGeneratedDocument($order, $code);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $uuid = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents("{$dir}/{$uuid}", $pdf);
        $fileId = FileStoreRepository::insertGenerated(null, $orderId, null, "{$dir}/{$uuid}", $uuid, $code . ' ' . str_replace('/', '-', $reference) . '.pdf', strlen($pdf), 'application/pdf', $userId, false);
        DomesticOrderRepository::addDocument($orderId, $code, $reference, $fileId, $userId);
        \App\Repositories\AuditLogRepository::log($userId, 'DOMESTIC_DOCUMENT_GENERATED', 'orders', $orderId, $code, null, $reference);
        return [$fileId, null];
    }
}
