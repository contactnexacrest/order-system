<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CompanySettingsRepository;
use App\Repositories\ImportRepository;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;

/**
 * Owner decision D-257 Phase 3 (5 Nov 2026): the import purchase order (IPO)
 * to a foreign supplier, in the supplier's currency — a new document for
 * Import & Distribution only; the export documents are not touched. It is
 * downloaded from the import's page (not stored with an order's files: an
 * import is not a sales order). Port: importDocumentService.js.
 */
final class ImportDocumentService
{
    /** @return array<string,mixed> everything the template prints */
    public static function context(int $shipmentId): array
    {
        $s = ImportRepository::find($shipmentId);
        if ($s === null) {
            throw new \RuntimeException('Import not found.');
        }
        $company = static fn (string $k): string => (string) (CompanySettingsRepository::companyValue($k) ?? '');
        $lines = [];
        $total = 0;
        foreach (ImportRepository::lines($shipmentId) as $i => $l) {
            $cents = ImportService::lineValueCents((string) $l['quantity'], (string) $l['unit_price_fc']);
            $total += $cents;
            $lines[] = [
                'no' => $i + 1,
                'description' => (string) $l['description'],
                'hsn_code' => (string) $l['hsn_code'],
                'quantity' => DomesticDocumentService::trimDecimals((string) $l['quantity']),
                'unit' => (string) $l['unit'],
                'unit_price' => ImportService::fc(GstCalculator::scaled((string) $l['unit_price_fc'], 2)),
                'amount' => ImportService::fc($cents),
            ];
        }
        return [
            'reference' => (string) $s['reference'],
            'po_date' => (new \DateTimeImmutable((string) $s['po_date']))->format('d M Y'),
            'currency' => (string) $s['currency_code'],
            'incoterm' => (string) ($s['incoterm'] ?? ''),
            'payment_terms' => (string) ($s['payment_terms'] ?? ''),
            'lc_no' => (string) ($s['lc_no'] ?? ''),
            'lc_bank' => (string) ($s['lc_bank'] ?? ''),
            'supplier' => [
                'name' => (string) $s['supplier_name'],
                'address_lines' => DomesticDocumentService::lines((string) ($s['supplier_address'] ?? '')),
                'country' => (string) $s['supplier_country'],
            ],
            'company' => [
                'name' => $company('legal_name'),
                'address_lines' => DomesticDocumentService::lines($company('registered_office')),
                'gstin' => $company('gstin'),
                'iec' => $company('iec_pan'),
                'phone' => $company('phone'),
                'email' => $company('email'),
            ],
            'lines' => $lines,
            'total' => ImportService::fc($total),
        ];
    }

    public static function html(int $shipmentId): string
    {
        $twig = new TwigEnvironment(new TwigFilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['cache' => false, 'autoescape' => 'html']);
        return $twig->render('IMP/import_po.html.twig', self::context($shipmentId));
    }

    public static function pdf(int $shipmentId): string
    {
        $s = ImportRepository::find($shipmentId);
        return DocumentGenerationService::renderStandalonePdf(self::html($shipmentId), $s !== null ? (string) $s['reference'] : null);
    }
}
