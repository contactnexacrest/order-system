<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AmountInWords;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\MerchantTradeRepository;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;

/**
 * Owner decision D-257 Phase 4 (5 Nov 2026): the two documents of a Merchant
 * Trade deal — the sale invoice to the buyer (in the sale currency) and the
 * purchase order to the supplier (in the purchase currency). New documents
 * for Merchant Trade only, from one template; the export documents are not
 * touched. Downloaded from the deal's page (a deal is not a sales order, so
 * nothing is stored with an order's files). Port: merchantDocumentService.js.
 */
final class MerchantDocumentService
{
    public const KINDS = ['invoice' => 'Sale invoice', 'po' => 'Purchase order'];

    /** @return array<string,mixed> everything the template prints */
    public static function context(int $dealId, string $kind): array
    {
        $d = MerchantTradeRepository::find($dealId);
        if ($d === null || !isset(self::KINDS[$kind])) {
            throw new \RuntimeException('Deal not found.');
        }
        $company = static fn (string $k): string => (string) (CompanySettingsRepository::companyValue($k) ?? '');
        $invoice = $kind === 'invoice';
        $currency = (string) ($invoice ? $d['sale_currency'] : $d['purchase_currency']);
        $lines = [];
        $total = 0;
        foreach (MerchantTradeRepository::lines($dealId) as $i => $l) {
            $price = (string) ($invoice ? $l['sell_price_fc'] : $l['buy_price_fc']);
            $cents = ImportService::lineValueCents((string) $l['quantity'], $price);
            $total += $cents;
            $lines[] = [
                'no' => $i + 1,
                'description' => (string) $l['description'],
                'hsn_code' => (string) $l['hsn_code'],
                'quantity' => DomesticDocumentService::trimDecimals((string) $l['quantity']),
                'unit' => (string) $l['unit'],
                'unit_price' => ImportService::fc(GstCalculator::scaled($price, 2)),
                'amount' => ImportService::fc($cents),
            ];
        }
        $party = $invoice
            ? ['label' => 'BUYER', 'name' => (string) $d['buyer_name'], 'address_lines' => DomesticDocumentService::lines((string) ($d['buyer_address'] ?? '')), 'country' => (string) $d['buyer_country']]
            : ['label' => 'SUPPLIER', 'name' => (string) $d['supplier_name'], 'address_lines' => DomesticDocumentService::lines((string) ($d['supplier_address'] ?? '')), 'country' => (string) $d['supplier_country']];
        $date = $invoice ? (string) ($d['invoice_date'] ?: date('Y-m-d')) : (string) $d['deal_date'];
        return [
            'kind' => $kind,
            'title' => $invoice ? 'INVOICE' : 'PURCHASE ORDER',
            'number' => (string) ($invoice ? $d['sale_invoice_no'] : $d['po_no']),
            'deal_reference' => (string) $d['reference'],
            'date' => (new \DateTimeImmutable($date))->format('d M Y'),
            'currency' => $currency,
            'incoterm' => (string) (($invoice ? $d['sale_incoterm'] : $d['purchase_incoterm']) ?? ''),
            'origin_country' => (string) $d['origin_country'],
            'port_of_loading' => (string) ($d['port_of_loading'] ?? ''),
            'port_of_discharge' => (string) ($d['port_of_discharge'] ?? ''),
            'bl_no' => (string) ($d['switch_bl'] ? $d['switch_bl_no'] : ($d['bl_no'] ?? '')),
            'bl_date' => !empty($d['bl_date']) ? (new \DateTimeImmutable((string) $d['bl_date']))->format('d M Y') : '',
            'vessel' => (string) ($d['vessel'] ?? ''),
            'party' => $party,
            'company' => [
                'name' => $company('legal_name'),
                'address_lines' => DomesticDocumentService::lines($company('registered_office')),
                'iec' => $company('iec_pan'),
                'phone' => $company('phone'),
                'email' => $company('email'),
            ],
            'lines' => $lines,
            'total' => ImportService::fc($total),
            'total_in_words' => AmountInWords::format(GstCalculator::money($total), $currency),
        ];
    }

    public static function html(int $dealId, string $kind): string
    {
        $twig = new TwigEnvironment(new TwigFilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['cache' => false, 'autoescape' => 'html']);
        return $twig->render('MTT/merchant_document.html.twig', self::context($dealId, $kind));
    }

    public static function pdf(int $dealId, string $kind): string
    {
        $c = self::context($dealId, $kind);
        return DocumentGenerationService::renderStandalonePdf(self::html($dealId, $kind), $c['number']);
    }
}
