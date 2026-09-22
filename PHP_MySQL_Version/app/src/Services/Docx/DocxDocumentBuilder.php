<?php

declare(strict_types=1);

namespace App\Services\Docx;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Per-document-type DOCX composition. Each render*() method mirrors its
 * own Twig template (app/templates/{TYPE}/*.html.twig) section-by-section,
 * built out of DocxComponents' shared toolkit — see that class for the
 * color/style constants translated from _layout.html.twig's CSS.
 *
 * AMD is intentionally not handled here — it has no DOCX generation path
 * (see DocumentGenerationService::generateAmendment(), PDF-only).
 */
final class DocxDocumentBuilder
{
    private const C = DocxComponents::class;

    public static function render(string $path, string $documentTypeCode, array $context): void
    {
        // PHPWord defaults to NOT XML-escaping text passed to addText()/addTextRun()
        // (PhpOffice\PhpWord\Settings::$outputEscapingEnabled = false, for backward
        // compatibility with callers who write raw XML themselves) — every one of
        // these 11 templates has plain business text containing a literal "&"
        // (labels like "TERMS & CONDITIONS", clause text, "Founder & Managing
        // Director", etc.), which produced an unescaped "&" in word/document.xml
        // and a DOCX that LibreOffice/Word refuse to open at all. Must be enabled
        // before any element is written.
        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord();
        DocxComponents::applyDefaultStyle($phpWord);

        match ($documentTypeCode) {
            'QT' => self::renderQt($phpWord, $context),
            'PI' => self::renderPi($phpWord, $context),
            'OC' => self::renderOc($phpWord, $context),
            'ANNEXA' => self::renderAnnexa($phpWord, $context),
            'BUYERPO' => self::renderBuyerPo($phpWord, $context),
            'SUPPO' => self::renderSupPo($phpWord, $context),
            'FDN' => self::renderFdn($phpWord, $context),
            'PL' => self::renderPl($phpWord, $context),
            'BLI' => self::renderBli($phpWord, $context),
            'CI' => self::renderCi($phpWord, $context),
            'COOPREP' => self::renderCooprep($phpWord, $context),
            default => throw new \RuntimeException("No DOCX render method for document type {$documentTypeCode}"),
        };

        $writer = PhpWordIOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($path);
        DocxComponents::cleanupTempFiles();
    }

    // ------------------------------------------------------------------
    // Small local helpers
    // ------------------------------------------------------------------

    private static function g(array $arr, string $path, $default = '—')
    {
        $cur = $arr;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return $default;
            }
            $cur = $cur[$key];
        }
        return $cur === null ? $default : $cur;
    }

    private static function titleFor(string $code): string
    {
        return match ($code) {
            'QT' => 'QUOTATION',
            'ANNEXA' => 'ANNEXURE A — PRODUCT TECHNICAL SPECIFICATIONS',
            'PI' => 'PROFORMA INVOICE',
            'OC' => 'ORDER CONFIRMATION',
            'BUYERPO' => 'PURCHASE ORDER — ORDER ACCEPTANCE',
            'SUPPO' => 'PURCHASE ORDER — MATERIAL PROCUREMENT',
            'FDN' => 'FREIGHT DEBIT NOTE',
            'PL' => 'PACKING LIST',
            'BLI' => 'BILL OF LADING INSTRUCTION SHEET',
            'CI' => 'COMMERCIAL INVOICE',
            'COOPREP' => 'COO PREPARATION SHEET',
            default => $code,
        };
    }

    /**
     * The kv-table checkbox-row look (BLI Sections 5/7). Uses plain ASCII
     * brackets rather than the Unicode ballot-box glyphs (☑/☐) — those
     * glyphs are missing from Carlito and render as tofu boxes in
     * LibreOffice/Word for the (very common) unchecked state, so "[X]" /
     * "[ ]" is the more reliable real-Word-formatting equivalent of the
     * CSS .cbx checkbox square.
     */
    private static function checkbox(bool $checked, string $label): string
    {
        return ($checked ? '[X] ' : '[ ] ') . $label;
    }

    private static function starRun(): array
    {
        return ['*', ['bold' => true, 'color' => 'C0392B']];
    }

    /** Bank Details kv rows — reused verbatim by PI/FDN/CI Section "BANK DETAILS". */
    private static function bankDetailsRows(array $context, string $paymentRefLabel, string $rbiCode): array
    {
        $company = $context['company'] ?? [];
        return [
            ['label' => 'Account Holder', 'value' => strtoupper((string) self::g($company, 'legal_name', ''))],
            ['label' => 'Bank', 'value' => self::g($company, 'bank_name')],
            ['label' => 'Branch', 'value' => self::g($company, 'bank_branch')],
            ['label' => 'Account No.', 'value' => self::g($company, 'bank_account_no')],
            ['label' => 'IFSC', 'value' => self::g($company, 'ifsc')],
            ['label' => 'SWIFT / BIC', 'value' => self::g($company, 'swift_bic')],
            ['label' => 'Bank Address', 'value' => self::g($company, 'bank_address')],
            ['label' => 'Payment Reference', 'value' => $paymentRefLabel, 'valueBg' => null],
            ['label' => 'RBI Purpose Code', 'value' => $rbiCode],
        ];
    }

    private static function documentsProvidedParagraphs(Section $section, string $heading, array $lines): void
    {
        DocxComponents::addRichParagraph($section, [[$heading, ['bold' => true]]]);
        foreach ($lines as $line) {
            DocxComponents::addPlainParagraph($section, $line);
        }
    }

    /** Standard "1. {title}" seller/exporter block shared by QT/PI/OC/SUPPO/FDN/CI/PL. */
    private static function addDefaultSection1(Section $section, array $context, string $title): void
    {
        $company = $context['company'] ?? [];
        DocxComponents::addSectionTitle($section, "1. {$title}");
        DocxComponents::addKvTable($section, [
            ['label' => 'Company / Legal Entity', 'value' => self::g($company, 'legal_name')],
            ['label' => 'Registered Office', 'value' => self::g($company, 'registered_office')],
            ['label' => 'Corporate Office', 'value' => self::g($company, 'corporate_office')],
            ['label' => 'GSTIN', 'value' => self::g($company, 'gstin')],
            ['label' => 'IEC / PAN', 'value' => self::g($company, 'iec_pan')],
            ['label' => 'Contact Person', 'value' => self::g($company, 'md_name') . ' — ' . self::g($company, 'md_title')],
            ['label' => 'Phone', 'value' => self::g($company, 'phone')],
            ['label' => 'Email', 'value' => self::g($company, 'email')],
        ]);
    }

    private static function productTableHeaders(string $currency): array
    {
        return ["#", "Product Description *", "Qty *", "Unit *", "Unit Price ({$currency}) *", "Amount ({$currency}) *"];
    }

    /** Builds the shared 6-col product table + spec sub-row rows (QT/PI/OC/CI all share this exact schema). */
    private static function buildProductRows(array $products): array
    {
        $rows = [];
        foreach ($products as $i => $p) {
            $lineColor = ($i % 2 === 0) ? '1D6FA8' : '2D7D56';
            $n = $i + 1;
            $rows[] = [
                'cells' => [(string) $n, $p['description'] ?? '', $p['quantity'] ?? '', $p['unit'] ?? '', $p['unit_price'] ?? '', $p['amount'] ?? ''],
                'lineColor' => $lineColor,
                'align' => [null, null, 'right', null, 'right', 'right'],
                'specText' => (!empty($p['dimensions']) || !empty($p['finish'])) ? [
                    ["{$n}. ", ['bold' => true, 'color' => $lineColor]],
                    ['Dimensions: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
                    [($p['dimensions'] ?: 'TBC') . '   ·   ', []],
                    ['Finish: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
                    [($p['finish'] ?: 'TBC') . '   ·   ', []],
                    ['HS Code: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
                    [($p['hs_code'] ?? '') . '   ·   ', []],
                    ['Country of Origin: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
                    ['India', []],
                ] : null,
            ];
        }
        return $rows;
    }

    private static function weightTable(Section $section, array $order): void
    {
        DocxComponents::addPlainParagraph($section, 'Weight & Volume (Estimated — actuals confirmed on Packing List after production)', ['bold' => true, 'size' => 10.5, 'color' => DocxComponents::NAVY]);
        $rows = [
            ['Total CBM (m³)', self::g($order, 'estimated_total_cbm', 'TBC'), 'm³'],
            ['Gross Weight (incl. packing)', self::g($order, 'estimated_gross_weight_kg', 'TBC'), 'kg'],
            ['Net Weight (stone only)', self::g($order, 'estimated_net_weight_kg', 'TBC'), 'kg'],
            ['No. of Packages / Crates', self::g($order, 'estimated_package_count', 'TBC'), 'Crates'],
            ['Package Type', self::g($order, 'estimated_package_type', 'TBC'), '—'],
        ];
        DocxComponents::addChecklistTable($section, ['Parameter', 'Estimated Value *', 'Unit'], [50, 35, 15], array_map(
            static fn(array $r) => ['cells' => [(string) $r[0], (string) $r[1], (string) $r[2]]],
            $rows
        ));
    }

    private static function paymentTermsBlock(Section $section, array $context): void
    {
        $financial = $context['financial'] ?? [];
        $order = $context['order'] ?? [];
        $currency = self::g($order, 'currency_code', '');
        DocxComponents::addRichParagraph($section, [
            ['• ', []], [self::g($financial, 'advance_pct', '') . '% advance T/T on FOB Value against Proforma Invoice. ', []], self::starRun(),
        ]);
        DocxComponents::addRichParagraph($section, [
            ['   ' . self::g($financial, 'advance_pct', '') . '% Advance Amount:  ' . $currency . ' ' . self::g($financial, 'advance_amount', ''), []], self::starRun(),
        ]);
        $balanceText = self::g($financial, 'balance_trigger_option', '') === 'A_BEFORE_SHIPMENT'
            ? 'payable before shipment — within ' . self::g($financial, 'balance_days', '') . ' working days of receiving shipment readiness confirmation from NexaCrest.'
            : 'payable against scanned copy of Bill of Lading, within ' . self::g($financial, 'balance_days', '') . ' days of BL date.';
        DocxComponents::addRichParagraph($section, [
            ['• ', []], [self::g($financial, 'balance_pct', '') . '% balance T/T on FOB Value ' . $balanceText . ' ', []], self::starRun(),
        ]);
        DocxComponents::addRichParagraph($section, [
            ['   ' . self::g($financial, 'balance_pct', '') . '% Balance Amount:  ' . $currency . ' ' . self::g($financial, 'balance_amount', ''), []], self::starRun(),
        ]);
        if (empty($order['is_fob'])) {
            DocxComponents::addRichParagraph($section, [
                ['• Freight & Insurance (CFR/CIF orders only): ', []],
                ['Actual confirmed amounts invoiced separately by Freight Debit Note before shipment booking is confirmed. Payment required within 3 working days of Freight Debit Note date.', ['color' => '333333']],
            ]);
        }
        DocxComponents::addPlainParagraph($section, '   Currency: ' . $currency);
    }

    // ==================================================================
    // QT — Quotation
    // ==================================================================
    private static function renderQt(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $buyer = $context['buyer'] ?? [];
        $financial = $context['financial'] ?? [];
        $currency = self::g($order, 'currency_code', '');

        DocxComponents::addHeader($section, $context, self::titleFor('QT'), self::g($order, 'incoterm_code', null) ? (self::g($order, 'incoterm_code') . ' ' . strtoupper((string) self::g($order, 'port_of_loading', ''))) : null);
        DocxComponents::addMandatoryNote($section);
        DocxComponents::addMetaBar($section, [
            ['label' => 'QUOTATION NO. *', 'value' => self::g($meta, 'document_reference', '') . ' ' . self::g($meta, 'revision_label', '')],
            ['label' => 'DATE *', 'value' => self::g($order, 'quotation_date') !== '—' ? self::g($order, 'quotation_date') : self::g($meta, 'generated_date', '')],
            ['label' => 'BUYER INQUIRY REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);
        DocxComponents::addColorBox($section, DocxComponents::AMBER_BG, DocxComponents::AMBER_BORDER, function (AbstractContainer $cell) use ($order) {
            $run = $cell->addTextRun();
            $run->addText("\u{23F1}  ", ['size' => 9.5]);
            $run->addText('VALID UNTIL * ', ['bold' => true, 'color' => DocxComponents::AMBER_LABEL, 'size' => 9.5]);
            $run->addText(' ' . self::g($order, 'quotation_valid_until', 'TBC') . '  ', ['bold' => true, 'size' => 11, 'color' => DocxComponents::AMBER_VALUE]);
            $run->addText('Prices and terms are not valid after this date.', ['italic' => true, 'size' => 8.5, 'color' => DocxComponents::AMBER_NOTE]);
        });

        self::addDefaultSection1($section, $context, 'SELLER / EXPORTER');

        DocxComponents::addSectionTitle($section, '2. BUYER / CONSIGNEE DETAILS');
        DocxComponents::addKvTable($section, [
            ['label' => 'Company Legal Name *', 'value' => self::g($buyer, 'company_legal_name')],
            ['label' => 'Billing Address *', 'value' => self::g($buyer, 'billing_address')],
            ['label' => 'Consignee Name *', 'value' => self::g($buyer, 'consignee_name')],
            ['label' => 'Consignee Address *', 'value' => self::g($buyer, 'consignee_address')],
            ['label' => 'VAT / EORI / Tax Reg. No. *', 'value' => self::g($buyer, 'vat_eori_tax_no', 'TBC')],
            ['label' => 'Country of Destination *', 'value' => self::g($buyer, 'country_of_destination', 'TBC')],
            ['label' => 'Certificate of Origin Type', 'value' => self::g($order, 'coo_type')],
            ['label' => 'Contact Person *', 'value' => self::g($buyer, 'contact_person', 'TBC')],
            ['label' => 'Email *', 'value' => self::g($buyer, 'email', 'TBC')],
            ['label' => 'Phone', 'value' => self::g($buyer, 'phone', '—')],
            ['label' => 'Port of Discharge *', 'value' => self::g($order, 'port_of_discharge')],
        ]);

        DocxComponents::addSectionTitle($section, '3. PRODUCT / ORDER DETAILS');
        DocxComponents::addProductsTable($section, self::productTableHeaders($currency), [6, 34, 10, 10, 20, 20], self::buildProductRows($context['products'] ?? []));

        $freightText = !empty($order['is_fob'])
            ? 'NIL — freight arranged by buyer.'
            : 'Indicative approx. ' . $currency . ' ' . self::g($order, 'indicative_freight_low', 'XXX') . '–' . self::g($order, 'indicative_freight_high', 'XXX') . ' per ' . self::g($order, 'container_type') . ', ' . self::g($order, 'port_of_loading') . ' to ' . self::g($order, 'port_of_discharge') . '. Subject to confirmation at time of booking. Actual freight confirmed and recovered IN ADVANCE by Freight Debit Note when cargo is packed and ready — payment required within 3 working days, and must be received BEFORE shipment booking is confirmed.';
        $insuranceText = !empty($order['is_fob']) ? "NIL — buyer's responsibility." : 'Indicative approx. ' . $currency . ' ' . self::g($order, 'indicative_insurance_amount', 'XX') . '. Confirmed by Freight Debit Note when cargo is ready.';
        DocxComponents::addTotalsTable($section, [
            ['label' => "FOB Value ({$currency}) *", 'value' => self::g($financial, 'fob_value')],
            ['label' => "Freight ({$currency})", 'value' => $freightText],
            ['label' => "Insurance ({$currency})", 'value' => $insuranceText],
            ['label' => "TOTAL QUOTED VALUE ({$currency})", 'value' => self::g($financial, 'total_value'), 'highlight' => true],
        ]);

        self::weightTable($section, $order);

        DocxComponents::addSectionTitle($section, '4. SHIPPING / COMMERCIAL TERMS');
        DocxComponents::addKvTable($section, [
            ['label' => 'Incoterm *', 'value' => self::g($order, 'incoterm_label')],
            ['label' => 'Port of Loading *', 'value' => self::g($order, 'port_of_loading')],
            ['label' => 'Port of Discharge *', 'value' => self::g($order, 'port_of_discharge')],
            ['label' => 'Container Type', 'value' => self::g($order, 'container_type')],
            ['label' => 'Est. Lead Time', 'value' => self::g($order, 'est_lead_time_text')],
            ['label' => 'Insurance', 'value' => !empty($order['is_fob']) ? "Buyer's responsibility." : 'Seller arranges — cost included in invoice.'],
        ]);

        DocxComponents::addSectionTitle($section, '5. PAYMENT TERMS');
        self::paymentTermsBlock($section, $context);

        DocxComponents::addSectionTitle($section, '6. DOCUMENTS TO BE PROVIDED');
        self::documentsProvidedParagraphs($section, 'Upon shipment, the following documents will be provided:', [
            '1.  Commercial Invoice (signed and stamped)',
            '2.  Packing List (signed and stamped)',
            '3.  Certificate of Origin — ' . self::g($order, 'coo_type') . ' — Issued by CAPEXIL',
            '4.  Bill of Lading — Original Negotiable, 3 originals — Released to buyer only after ' . self::g($financial, 'balance_pct') . '% balance T/T is received and cleared',
            '5.  Fumigation Certificate — provided as standard with every shipment',
        ]);

        if (!empty($order['special_requirements'])) {
            DocxComponents::addSectionTitle($section, 'SPECIAL REQUIREMENTS');
            DocxComponents::addKvTable($section, [['full' => true, 'value' => (string) $order['special_requirements']]]);
        }

        DocxComponents::addTermsSection($section, $context, (int) ($context['terms_section_number'] ?? 7), (string) ($context['terms_section_title'] ?? 'TERMS & CONDITIONS'));
        self::addAnnexureAppendixIfAny($section, $context);
        DocxComponents::addSignatureBlock($section, $context);
    }

    // ==================================================================
    // PI — Proforma Invoice
    // ==================================================================
    private static function renderPi(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $buyer = $context['buyer'] ?? [];
        $financial = $context['financial'] ?? [];
        $company = $context['company'] ?? [];
        $currency = self::g($order, 'currency_code', '');

        DocxComponents::addHeader($section, $context, self::titleFor('PI'), self::g($order, 'incoterm_code', null) ? (self::g($order, 'incoterm_code') . ' ' . strtoupper((string) self::g($order, 'port_of_loading', ''))) : null);
        DocxComponents::addMandatoryNote($section);
        DocxComponents::addMetaBar($section, [
            ['label' => 'PI NUMBER *', 'value' => self::g($meta, 'document_reference', '') . ' ' . self::g($meta, 'revision_label', '')],
            ['label' => 'PI DATE *', 'value' => self::g($order, 'pi_date') !== '—' ? self::g($order, 'pi_date') : self::g($meta, 'generated_date', '')],
            ['label' => 'QUOTATION REF *', 'value' => self::g($order, 'quotation_ref', '—')],
            ['label' => 'BUYER INQUIRY REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);
        DocxComponents::addColorBox($section, DocxComponents::AMBER_BG, DocxComponents::AMBER_BORDER, function (AbstractContainer $cell) use ($order) {
            $run = $cell->addTextRun();
            $run->addText("\u{23F1}  ", ['size' => 9.5]);
            $run->addText('VALID UNTIL * ', ['bold' => true, 'color' => DocxComponents::AMBER_LABEL, 'size' => 9.5]);
            $run->addText(' ' . self::g($order, 'pi_valid_until', 'TBC') . '  ', ['bold' => true, 'size' => 11, 'color' => DocxComponents::AMBER_VALUE]);
            $run->addText('Payment must be received before this date for prices and terms to remain valid.', ['italic' => true, 'size' => 8.5, 'color' => DocxComponents::AMBER_NOTE]);
        });
        DocxComponents::addColorBox($section, DocxComponents::GREEN_BG, DocxComponents::GREEN_BORDER, function (AbstractContainer $cell) use ($company) {
            $run = $cell->addTextRun();
            $run->addText('GST DECLARATION: ', ['bold' => true, 'color' => DocxComponents::GREEN_BORDER, 'size' => 9.5]);
            $run->addText('Supply meant for export under Letter of Undertaking (LUT) without payment of Integrated Tax (IGST). ', ['color' => DocxComponents::GREEN_VALUE, 'size' => 9.5]);
            $run->addText('LUT Order No.: ' . self::g($company, 'lut_number'), ['bold' => true, 'color' => DocxComponents::GREEN_BORDER, 'size' => 9.5]);
            $run->addText('   ·   Valid for ' . self::g($company, 'lut_valid_fy') . '   ·   GSTIN: ' . self::g($company, 'gstin'), ['color' => DocxComponents::GREEN_VALUE, 'size' => 9.5]);
        });

        self::addDefaultSection1($section, $context, 'SELLER / EXPORTER');

        DocxComponents::addSectionTitle($section, '2. BUYER / CONSIGNEE DETAILS');
        DocxComponents::addKvTable($section, [
            ['label' => 'Company Legal Name *', 'value' => self::g($buyer, 'company_legal_name')],
            ['label' => 'Billing Address *', 'value' => self::g($buyer, 'billing_address')],
            ['label' => 'Consignee Name *', 'value' => self::g($buyer, 'consignee_name')],
            ['label' => 'Consignee Address *', 'value' => self::g($buyer, 'consignee_address')],
            ['label' => 'VAT / EORI / Tax Reg. No. *', 'value' => self::g($buyer, 'vat_eori_tax_no', 'TBC')],
            ['label' => 'Country of Final Destination *', 'value' => self::g($buyer, 'country_of_destination', 'TBC')],
            ['label' => 'Certificate of Origin Type', 'value' => self::g($order, 'coo_type')],
            ['label' => "Buyer's PO / Ref No.", 'value' => self::g($order, 'buyers_po_ref')],
            ['label' => 'Contact Person *', 'value' => self::g($buyer, 'contact_person', 'TBC')],
            ['label' => 'Email *', 'value' => self::g($buyer, 'email', 'TBC')],
            ['label' => 'Phone', 'value' => self::g($buyer, 'phone', '—')],
            ['label' => 'Notify Party', 'value' => self::g($buyer, 'notify_party', 'SAME as buyer')],
        ]);

        DocxComponents::addSectionTitle($section, '3. PRODUCT / ORDER DETAILS');
        $products = $context['products'] ?? [];
        DocxComponents::addProductsTable($section, self::productTableHeaders($currency), [6, 34, 10, 10, 20, 20], self::buildProductRows($products));

        $piTotalsRows = [];
        foreach ($products as $p) {
            $piTotalsRows[] = ['label' => ($p['description'] ?? '') . ' (' . ($p['quantity'] ?? '') . ' ' . ($p['unit'] ?? '') . ' × ' . ($p['unit_price'] ?? '') . ')', 'value' => $currency . ' ' . ($p['amount'] ?? '')];
        }
        $piTotalsRows[] = ['label' => "TOTAL PI VALUE ({$currency}) *", 'value' => self::g($financial, 'fob_value'), 'highlight' => true];
        DocxComponents::addTotalsTable($section, $piTotalsRows, 65);
        DocxComponents::addRichParagraph($section, [
            ['Remark: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
            ['Loading quantity subject to final packing confirmation.', ['italic' => true, 'color' => DocxComponents::MUTED]],
        ]);

        $freightText = !empty($order['is_fob'])
            ? 'NIL — freight arranged by buyer.'
            : 'Indicative approx. ' . $currency . ' ' . self::g($order, 'indicative_freight_low', 'XXX') . '–' . self::g($order, 'indicative_freight_high', 'XXX') . ' per ' . self::g($order, 'container_type') . ', ' . self::g($order, 'port_of_loading') . ' to ' . self::g($order, 'port_of_discharge') . '. Subject to confirmation at time of booking. Actual freight confirmed and recovered IN ADVANCE by separate Freight Debit Note when cargo is packed and ready — payment required within 3 working days, and must be received BEFORE shipment booking is confirmed.';
        $insuranceText = !empty($order['is_fob']) ? "NIL — buyer's responsibility." : 'Indicative approx. ' . $currency . ' ' . self::g($order, 'indicative_insurance_amount', 'XX') . '. Confirmed by Freight Debit Note when cargo is ready.';
        DocxComponents::addTotalsTable($section, [
            ['label' => "FOB Value ({$currency}) *", 'value' => self::g($financial, 'fob_value')],
            ['label' => "Freight ({$currency})", 'value' => $freightText],
            ['label' => "Insurance ({$currency})", 'value' => $insuranceText],
            ['label' => "TOTAL PI VALUE ({$currency})", 'value' => self::g($financial, 'total_value'), 'highlight' => true],
        ]);

        self::weightTable($section, $order);

        DocxComponents::addSectionTitle($section, '4. SHIPPING / COMMERCIAL TERMS');
        DocxComponents::addKvTable($section, [
            ['label' => 'Incoterm *', 'value' => self::g($order, 'incoterm_label')],
            ['label' => 'Port of Loading *', 'value' => self::g($order, 'port_of_loading')],
            ['label' => 'Port of Discharge *', 'value' => self::g($order, 'port_of_discharge')],
            ['label' => 'Container Type', 'value' => self::g($order, 'container_type')],
            ['label' => 'Est. Shipment Date', 'value' => self::g($order, 'est_shipment_date_text')],
            ['label' => 'Insurance', 'value' => !empty($order['is_fob']) ? "Buyer's responsibility." : 'Seller arranges — cost included in invoice.'],
        ]);

        DocxComponents::addSectionTitle($section, '5. PAYMENT TERMS');
        self::paymentTermsBlock($section, $context);

        DocxComponents::addSectionTitle($section, '6. BANK DETAILS');
        DocxComponents::addKvTable($section, self::bankDetailsRows(
            $context,
            'Please quote PI No. ' . self::g($meta, 'document_reference', '') . ' in your wire transfer remarks.',
            self::g($company, 'rbi_purpose_code_advance') . ' — enter in the "Purpose of Remittance" field of your wire transfer form.'
        ));

        DocxComponents::addSectionTitle($section, '7. EXPORT DOCUMENTATION');
        self::documentsProvidedParagraphs($section, 'Upon shipment, the following documents will be provided:', [
            '1.  Commercial Invoice (signed and stamped)',
            '2.  Packing List (signed and stamped)',
            '3.  Certificate of Origin — ' . self::g($order, 'coo_type') . ' — Issued by CAPEXIL',
            '4.  Bill of Lading — Original Negotiable, 3 originals — Released to buyer only after ' . self::g($financial, 'balance_pct') . '% balance T/T is received and cleared in NexaCrest bank account',
            '5.  Fumigation Certificate — provided as standard with every shipment',
        ]);

        DocxComponents::addTermsSection($section, $context, (int) ($context['terms_section_number'] ?? 8), (string) ($context['terms_section_title'] ?? 'TERMS & CONDITIONS'));
        self::addAnnexureAppendixIfAny($section, $context);
        DocxComponents::addSignatureBlock($section, $context);
    }

    // ==================================================================
    // OC — Order Confirmation
    // ==================================================================
    private static function renderOc(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $buyer = $context['buyer'] ?? [];
        $financial = $context['financial'] ?? [];
        $payment = $context['payment_status'] ?? [];
        $currency = self::g($order, 'currency_code', '');

        DocxComponents::addHeader($section, $context, self::titleFor('OC'), null); // OC's header carries no incoterm chip
        DocxComponents::addMandatoryNote($section);
        DocxComponents::addMetaBar($section, [
            ['label' => 'OC NUMBER *', 'value' => self::g($meta, 'document_reference', '') . ' ' . self::g($meta, 'revision_label', '')],
            ['label' => 'DATE *', 'value' => self::g($meta, 'generated_date', '')],
            ['label' => 'PI REFERENCE *', 'value' => self::g($order, 'pi_ref', '—')],
            ['label' => 'BUYER INQUIRY REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);
        DocxComponents::addColorBox($section, DocxComponents::GREEN_BG, DocxComponents::GREEN_BORDER, function (AbstractContainer $cell) use ($financial) {
            $cell->addText("\u{2713}  ORDER CONFIRMED", ['bold' => true, 'size' => 13, 'color' => DocxComponents::GREEN_BORDER]);
            $cell->addText('This order is confirmed upon receipt and clearance of ' . self::g($financial, 'advance_pct') . '% advance T/T payment against the referenced Proforma Invoice.', ['italic' => true, 'size' => 9.5, 'color' => DocxComponents::GREEN_VALUE]);
        });

        self::addDefaultSection1($section, $context, 'SELLER / EXPORTER');

        DocxComponents::addSectionTitle($section, '2. BUYER / CONSIGNEE DETAILS');
        DocxComponents::addKvTable($section, [
            ['label' => 'Company Legal Name *', 'value' => self::g($buyer, 'company_legal_name')],
            ['label' => 'Billing Address *', 'value' => self::g($buyer, 'billing_address')],
            ['label' => 'Consignee Name *', 'value' => self::g($buyer, 'consignee_name')],
            ['label' => 'Consignee Address *', 'value' => self::g($buyer, 'consignee_address')],
            ['label' => 'Contact Person *', 'value' => self::g($buyer, 'contact_person', 'TBC')],
            ['label' => 'Phone *', 'value' => self::g($buyer, 'phone', '—')],
            ['label' => 'Email *', 'value' => self::g($buyer, 'email', 'TBC')],
        ]);

        $productList = array_map(static fn(array $p) => ($p['description'] ?? '') . ' (Qty: ' . ($p['quantity'] ?? '') . ' ' . ($p['unit'] ?? '') . ')', $context['products'] ?? []);
        DocxComponents::addSectionTitle($section, '3. ORDER SUMMARY');
        DocxComponents::addKvTable($section, [
            ['label' => 'Quotation No. *', 'value' => self::g($order, 'quotation_ref', '—')],
            ['label' => 'Quotation Date *', 'value' => self::g($order, 'quotation_date', '—')],
            ['label' => 'Proforma Invoice No. *', 'value' => self::g($order, 'pi_ref', '—')],
            ['label' => 'Product(s) *', 'value' => implode('; ', $productList)],
            ['label' => 'Total Order Value *', 'value' => $currency . ' ' . self::g($financial, 'total_value')],
            ['label' => 'Incoterm', 'value' => self::g($order, 'incoterm_label')],
            ['label' => 'Port of Loading', 'value' => self::g($order, 'port_of_loading')],
            ['label' => 'Port of Discharge *', 'value' => self::g($order, 'port_of_discharge')],
            ['label' => 'Certificate of Origin', 'value' => self::g($order, 'coo_type')],
        ]);

        DocxComponents::addSectionTitle($section, '4. PAYMENT STATUS');
        $paymentRows = [
            ['label' => self::g($financial, 'advance_pct') . '% Advance (USD) *', 'value' => self::g($payment, 'advance_amount') !== '—' ? self::g($payment, 'advance_amount') : self::g($financial, 'advance_amount')],
            ['label' => 'Advance T/T Received On *', 'value' => self::g($payment, 'advance_remittance_received_at', 'TBC')],
            ['label' => 'Balance Due (USD) *', 'value' => (self::g($payment, 'balance_amount') !== '—' ? self::g($payment, 'balance_amount') : self::g($financial, 'balance_amount')) . '  —  ' . self::g($financial, 'balance_terms_text')],
            ['label' => 'Currency', 'value' => 'USD (always)'],
        ];
        if (empty($order['is_fob'])) {
            $paymentRows[] = [
                'label' => 'CFR / CIF Freight (if applicable)',
                'value' => 'A Freight Debit Note will be raised when cargo is packed and ready, stating the actual confirmed freight amount. Payment required within 3 working days of the Debit Note date. NexaCrest will confirm shipment booking and hand over cargo to the shipping line only upon receipt of full freight payment.',
                'labelBg' => DocxComponents::RED_BG,
                'valueBg' => DocxComponents::RED_BG,
            ];
        }
        DocxComponents::addKvTable($section, $paymentRows);

        DocxComponents::addSectionTitle($section, '5. PRODUCTION & ESTIMATED SHIPMENT');
        DocxComponents::addKvTable($section, [[
            'full' => true,
            'bg' => DocxComponents::GRAY_LIGHT,
            'value' => function (AbstractContainer $cell) use ($order) {
                $run = $cell->addTextRun(['spaceAfter' => 60]);
                $run->addText('Production status: ', ['bold' => true, 'color' => DocxComponents::NAVY]);
                $run->addText(self::g($order, 'production_status_text') . '   ', []);
                $run->addText('Estimated shipment: ', ['bold' => true, 'color' => DocxComponents::NAVY]);
                $run->addText(self::g($order, 'est_shipment_date_text'), ['italic' => true, 'color' => DocxComponents::MUTED]);
                $run->addText(' *', ['bold' => true, 'color' => 'C0392B']);
                $run2 = $cell->addTextRun();
                $run2->addText('Note: ', ['bold' => true, 'color' => DocxComponents::NAVY, 'size' => 9]);
                $run2->addText('Estimated shipment date is indicative and subject to production completion, packing, and port scheduling. A confirmed Bill of Lading date will be communicated once the shipment is booked.', ['italic' => true, 'color' => DocxComponents::MUTED, 'size' => 9]);
            },
        ]]);

        DocxComponents::addSectionTitle($section, '6. DOCUMENTS TO BE PROVIDED');
        DocxComponents::addKvTable($section, [[
            'full' => true,
            'value' => function (AbstractContainer $cell) {
                $cell->addText('Upon shipment, the following documents will be provided:', ['bold' => true]);
                foreach (['Commercial Invoice', 'Packing List', 'Certificate of Origin (CAPEXIL / Chamber of Commerce)', 'Bill of Lading', 'Fumigation Certificate — provided as standard with every shipment'] as $item) {
                    $cell->addText('•  ' . $item);
                }
            },
        ]]);

        DocxComponents::addTermsSection($section, $context, (int) ($context['terms_section_number'] ?? 7), (string) ($context['terms_section_title'] ?? 'ORDER CONDITIONS'));
        self::addAnnexureAppendixIfAny($section, $context);
        DocxComponents::addSignatureBlock($section, $context);
    }

    // ==================================================================
    // ANNEXA — Annexure A (standalone)
    // ==================================================================
    private static function renderAnnexa(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];

        DocxComponents::addHeader($section, $context, self::titleFor('ANNEXA'), null);
        DocxComponents::addMandatoryNote($section);
        DocxComponents::addMetaBar($section, [
            ['label' => 'ANNEXURE TO', 'value' => self::g($order, 'quotation_ref') !== '—' ? self::g($order, 'quotation_ref') : (self::g($order, 'pi_ref') !== '—' ? self::g($order, 'pi_ref') : self::g($order, 'buyer_inquiry_ref'))],
            ['label' => 'DATE', 'value' => self::g($meta, 'generated_date', '')],
            ['label' => 'BUYER INQUIRY REF', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);
        // section1 suppressed in the source template

        self::addAnnexureBody($section, $context);

        // terms_section suppressed in the source template
        DocxComponents::addSignatureBlock($section, $context);
    }

    private static function addAnnexureBody(Section $section, array $context): void
    {
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $products = $context['annexure_products'] ?? [];

        DocxComponents::addColorBox($section, DocxComponents::AMBER_BG2, DocxComponents::AMBER_BORDER, function (AbstractContainer $cell) {
            $run = $cell->addTextRun();
            $run->addText('Note: ', ['bold' => true, 'color' => DocxComponents::AMBER_BORDER, 'size' => 9]);
            $run->addText('Product images and drawings are for reference only and may show optional accessories or decorative elements not included in the quoted price. All binding specifications are as stated in each product section below. Actual product appearance may vary slightly due to the natural characteristics of granite.', ['italic' => true, 'color' => DocxComponents::AMBER_TEXT2, 'size' => 9]);
        }, 0);

        if (empty($products)) {
            DocxComponents::addPlainParagraph($section, 'No product entries have been added to this Annexure yet.', ['italic' => true, 'color' => DocxComponents::MUTED]);
        }

        foreach ($products as $i => $p) {
            DocxComponents::addSectionTitle($section, 'PRODUCT ' . ($i + 1) . '  —  ' . ($p['name'] ?? ''));
            $images = $p['images'] ?? [];
            $firstImage = $images[0]['data_uri'] ?? null;

            $table = $section->addTable(['unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT, 'width' => 5000]);
            $table->addRow();
            $imgCell = $table->addCell(1750, ['bgColor' => DocxComponents::BLACK, 'valign' => 'center']);
            if ($firstImage) {
                $imgPath = DocxComponents::decodeDataUriToTempFile($firstImage);
                if ($imgPath) {
                    $info = @getimagesize($imgPath);
                    $h = 140;
                    $w = $info && $info[1] > 0 ? round($h * $info[0] / $info[1], 1) : $h;
                    $w = min($w, 175);
                    $imgCell->addImage($imgPath, ['width' => $w, 'height' => $h, 'ratio' => true, 'align' => Jc::CENTER]);
                }
            }
            $textCell = $table->addCell(3250, ['valign' => 'top']);
            if (!empty($p['description'])) {
                $textCell->addText((string) $p['description'], ['bold' => true, 'color' => DocxComponents::NAVY]);
            }
            foreach ([['Surface Finish', 'finish'], ['Dimensions', 'dimensions']] as [$label, $key]) {
                if (!empty($p[$key])) {
                    $run = $textCell->addTextRun();
                    $run->addText("{$label}: ", ['bold' => true, 'color' => DocxComponents::NAVY]);
                    $run->addText((string) $p[$key]);
                }
            }
            if (!empty($p['components'])) {
                $run = $textCell->addTextRun();
                $run->addText('Includes: ', ['bold' => true, 'color' => DocxComponents::NAVY]);
                $run->addText((string) $p['components']);
            }
            if (!empty($p['technical_notes'])) {
                $textCell->addText((string) $p['technical_notes']);
            }
            $section->addTextBreak(1, 6);

            $extraImages = array_slice($images, 1);
            if (!empty($extraImages)) {
                $imgTable = $section->addTable(['unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT, 'width' => 5000]);
                $imgTable->addRow();
                foreach ($extraImages as $img) {
                    if (empty($img['data_uri'])) {
                        continue;
                    }
                    $cell = $imgTable->addCell((int) (5000 / max(1, count($extraImages))), ['borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY, 'valign' => 'center']);
                    $path = DocxComponents::decodeDataUriToTempFile($img['data_uri']);
                    if ($path) {
                        $cell->addImage($path, ['width' => 90, 'height' => 65, 'ratio' => true, 'align' => Jc::CENTER]);
                    }
                }
                $section->addTextBreak(1, 6);
            }
        }

        $refDoc = self::g($order, 'quotation_ref') !== '—' ? self::g($order, 'quotation_ref') : (self::g($order, 'pi_ref') !== '—' ? self::g($order, 'pi_ref') : 'the referenced document');
        DocxComponents::addColorBox($section, DocxComponents::BLUE_BG, DocxComponents::BLUE_BORDER2, function (AbstractContainer $cell) use ($refDoc, $meta) {
            $run = $cell->addTextRun();
            $run->addText('This Annexure A forms an integral part of ' . $refDoc . ' dated ' . self::g($meta, 'generated_date') . '. ', ['color' => DocxComponents::NAVY, 'size' => 9.5]);
            $run->addText('The commercial terms, pricing, payment conditions, and quantities stated in the main document take precedence in all cases. Product images and technical drawings in this annexure are for reference and identification purposes only. This Annexure is not valid as a standalone document.', ['italic' => true, 'color' => DocxComponents::BLUE_TEXT, 'size' => 9.5]);
        }, 0);
    }

    private static function addAnnexureAppendixIfAny(Section $section, array $context): void
    {
        if (empty($context['order']['include_annexure_a']) || empty($context['annexure_products'])) {
            return;
        }
        $section->addPageBreak();
        DocxComponents::addSectionTitle($section, 'ANNEXURE A  —  PRODUCT TECHNICAL SPECIFICATIONS');
        self::addAnnexureBody($section, $context);
    }

    // ==================================================================
    // BUYERPO — Purchase Order (Order Acceptance, issued to buyer)
    // ==================================================================
    private static function renderBuyerPo(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $buyer = $context['buyer'] ?? [];
        $financial = $context['financial'] ?? [];
        $signatory = $context['signatory'] ?? [];
        $company = $context['company'] ?? [];
        $products = $context['products'] ?? [];
        $currency = self::g($order, 'currency_code', '');

        DocxComponents::addHeader($section, $context, self::titleFor('BUYERPO'), 'ORDER ACCEPTANCE — Issued to Buyer for Signature and Return');
        DocxComponents::addPlainParagraph($section, 'This Purchase Order is prepared by NexaCrest and sent to the buyer for signature and return. Buyer fills Column B of Section 4 only. All other fields are pre-filled by NexaCrest.', ['italic' => true, 'color' => DocxComponents::MUTED, 'size' => 9]);
        DocxComponents::addMetaBar($section, [
            ['label' => 'PO NUMBER *', 'value' => self::g($meta, 'document_reference', '') . ' ' . self::g($meta, 'revision_label', '')],
            ['label' => 'DATE *', 'value' => self::g($meta, 'generated_date', '')],
            ['label' => 'QT REFERENCE *', 'value' => self::g($order, 'quotation_ref', '—')],
            ['label' => 'BUYER INQUIRY REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);
        DocxComponents::addColorBox($section, DocxComponents::AMBER_BG, DocxComponents::AMBER_BORDER, function (AbstractContainer $cell) use ($order) {
            $run = $cell->addTextRun();
            $run->addText("\u{23F1}  VALID UNTIL * ", ['bold' => true, 'color' => DocxComponents::AMBER_LABEL]);
            $run->addText(' ' . self::g($order, 'quotation_valid_until', 'TBC') . '  ', ['bold' => true, 'size' => 11, 'color' => DocxComponents::AMBER_VALUE]);
            $run->addText('This Purchase Order must be signed and returned before the Quotation validity expires.', ['italic' => true, 'size' => 8.5, 'color' => DocxComponents::AMBER_NOTE]);
        });

        self::addDefaultSection1($section, $context, 'SUPPLIER');

        DocxComponents::addSectionTitle($section, '2. BUYER / CONSIGNEE DETAILS  (Pre-filled by NexaCrest — buyer to confirm)');
        DocxComponents::addKvTable($section, [
            ['label' => 'Company Legal Name *', 'value' => self::g($buyer, 'company_legal_name')],
            ['label' => 'Billing Address *', 'value' => self::g($buyer, 'billing_address')],
            ['label' => 'Consignee Name *', 'value' => self::g($buyer, 'consignee_name')],
            ['label' => 'Consignee Address *', 'value' => self::g($buyer, 'consignee_address')],
            ['label' => 'VAT / EORI / Tax Reg. *', 'value' => self::g($buyer, 'vat_eori_tax_no', 'TBC')],
            ['label' => 'Contact Person *', 'value' => self::g($buyer, 'contact_person', 'TBC')],
            ['label' => 'Phone *', 'value' => self::g($buyer, 'phone', '—')],
            ['label' => 'Email *', 'value' => self::g($buyer, 'email', 'TBC')],
            ['label' => 'Country of Destination *', 'value' => self::g($buyer, 'country_of_destination', 'TBC')],
            ['label' => 'Port of Discharge *', 'value' => self::g($order, 'port_of_discharge')],
        ]);

        $productList = implode('; ', array_map(static fn(array $p) => $p['description'] ?? '', $products));
        $first = $products[0] ?? [];
        DocxComponents::addSectionTitle($section, '3. ORDER DETAILS  (Pre-filled from Quotation — buyer to confirm)');
        DocxComponents::addKvTable($section, [
            ['label' => 'Against Quotation No. *', 'value' => self::g($order, 'quotation_ref', '—') . ' (fixed)'],
            ['label' => 'Quotation Date *', 'value' => self::g($order, 'quotation_date', '—') . ' (fixed)'],
            ['label' => 'Product Description *', 'value' => $productList],
            ['label' => 'Quantity *', 'value' => $first['quantity'] ?? 'TBC'],
            ['label' => 'Unit *', 'value' => $first['unit'] ?? 'TBC'],
            ['label' => "Unit Price ({$currency}) *", 'value' => $first['unit_price'] ?? 'TBC'],
            ['label' => "Total FOB Value ({$currency}) *", 'value' => self::g($financial, 'fob_value')],
            ['label' => 'Incoterm', 'value' => self::g($order, 'incoterm_label')],
            ['label' => 'Port of Loading', 'value' => self::g($order, 'port_of_loading')],
            ['label' => 'Port of Discharge *', 'value' => self::g($order, 'port_of_discharge')],
            ['label' => 'Certificate of Origin *', 'value' => self::g($order, 'coo_type')],
            ['label' => 'Payment Terms', 'value' => self::g($financial, 'advance_pct') . '% advance T/T against Proforma Invoice before production. ' . self::g($financial, 'balance_pct') . '% balance T/T before shipment — ' . self::g($financial, 'balance_terms_text') . ' Freight & Insurance (CFR/CIF): invoiced separately by Freight Debit Note before shipment.'],
        ]);

        DocxComponents::addSectionTitle($section, '4. BUYER ACCEPTANCE  (Buyer fills this section, signs and returns)');
        $table = $section->addTable(['unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow();
        $table->addCell(2500, ['bgColor' => DocxComponents::NAVY_MID])->addText('Prepared & Issued by NexaCrest', ['bold' => true, 'color' => DocxComponents::WHITE]);
        $table->addCell(2500, ['bgColor' => DocxComponents::NAVY_MID])->addText('Accepted by Buyer  •  Fill, Sign & Return', ['bold' => true, 'color' => DocxComponents::WHITE]);
        $table->addRow();
        $leftCell = $table->addCell(2500, ['valign' => 'top', 'borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY]);
        $leftCell->addText((string) self::g($signatory, 'name', ''), ['bold' => true, 'color' => DocxComponents::NAVY], ['spaceBefore' => 160]);
        $leftCell->addText((string) self::g($signatory, 'designation', ''));
        $leftCell->addText((string) self::g($company, 'legal_name', ''), ['size' => 8.5, 'color' => '555555']);
        $rightCell = $table->addCell(2500, ['valign' => 'top', 'bgColor' => 'FFFDE7', 'borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY]);
        $rightCell->addText('Authorised Signature:', ['bold' => true]);
        $rightCell->addText('_________________________________', ['color' => '999999']);
        $rightCell->addText('Name: _____________________________');
        $rightCell->addText('Designation: ______________________');
        $rightCell->addText("Buyer's PO No.: __________________  (write NIL if not applicable)");
        $rightCell->addText('Date: _____________________________', ['bold' => true]);
        $rightCell->addText('Company Stamp: (if applicable)', ['italic' => true, 'size' => 8.5, 'color' => '888888']);
        $section->addTextBreak(1, 6);

        DocxComponents::addColorBox($section, 'E8F1FB', DocxComponents::BLUE_TEXT, function (AbstractContainer $cell) use ($context, $financial) {
            $run = $cell->addTextRun();
            $run->addText('By signing above, the buyer confirms acceptance of all order details, specifications and payment terms stated in this Purchase Order. ', ['color' => DocxComponents::BLUE_TEXT]);
            $run->addText('This signed Purchase Order authorises NexaCrest to issue the Proforma Invoice and commence production upon receipt of the ' . self::g($financial, 'advance_pct') . '% advance payment. ', ['bold' => true, 'color' => DocxComponents::BLUE_TEXT]);
            $run->addText('The following clauses are legally binding on both parties:', ['color' => DocxComponents::BLACK]);
            foreach (($context['terms'] ?? []) as $clause) {
                $parts = explode(': ', (string) $clause, 2);
                $r2 = $cell->addTextRun();
                $r2->addText('•  ', ['color' => DocxComponents::BLACK]);
                $r2->addText(($parts[0] ?? '') . ': ', ['bold' => true, 'color' => DocxComponents::BLACK]);
                $r2->addText($parts[1] ?? '', ['color' => DocxComponents::BLACK]);
            }
        }, 0);

        DocxComponents::addKvTable($section, [[
            'full' => true,
            'bg' => DocxComponents::GRAY_LIGHT,
            'value' => 'This Purchase Order form is prepared by NexaCrest International Private Limited for the exclusive use of the named buyer against Quotation No. ' . self::g($order, 'quotation_ref', '—') . ' only. It is not transferable and cannot be used with any other supplier. Any unauthorised use or modification of this document is prohibited.',
        ]]);

        // terms_section and signature_block are both suppressed in the source template — Section 4 above covers acceptance/signature.
        self::addAnnexureAppendixIfAny($section, $context);
    }

    // ==================================================================
    // SUPPO — Purchase Order (Material Procurement, issued to supplier)
    // ==================================================================
    private static function renderSupPo(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $sp = $context['supplier_po'] ?? [];
        $signatory = $context['signatory'] ?? [];
        $company = $context['company'] ?? [];
        $green = '1A4A08';

        DocxComponents::addHeader($section, $context, self::titleFor('SUPPO'), 'Issued to Supplier for Signature and Return');
        DocxComponents::addPlainParagraph($section, 'This Purchase Order is issued by NexaCrest International Private Limited to the named supplier for material procurement. Supplier fills Section 7 only. All other sections are issued by NexaCrest.', ['italic' => true, 'color' => DocxComponents::MUTED, 'size' => 9]);
        DocxComponents::addMetaBar($section, [
            ['label' => 'PO NUMBER *', 'value' => self::g($meta, 'document_reference', '') . ' ' . self::g($meta, 'revision_label', '')],
            ['label' => 'DATE *', 'value' => self::g($meta, 'generated_date', '')],
            ['label' => 'BUYER ORDER REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
            ['label' => 'EXPORT ORDER REF *', 'value' => self::g($order, 'pi_ref') !== '—' ? self::g($order, 'pi_ref') : self::g($order, 'quotation_ref', '—')],
        ]);

        self::addDefaultSection1($section, $context, 'BUYER (NexaCrest International Private Limited)');
        // Re-tint section 1's title green to match this document's extra_style override.
        DocxComponents::addSectionTitle($section, '2. SUPPLIER DETAILS  (Pre-filled by NexaCrest)', $green);
        DocxComponents::addKvTable($section, [
            ['label' => 'Supplier Legal Name *', 'value' => self::g($sp, 'supplier_legal_name')],
            ['label' => 'Address *', 'value' => self::g($sp, 'supplier_address')],
            ['label' => 'GSTIN *', 'value' => self::g($sp, 'supplier_gstin', 'TBC')],
            ['label' => 'PAN', 'value' => self::g($sp, 'supplier_pan', '—')],
            ['label' => 'Contact Person *', 'value' => self::g($sp, 'supplier_contact_person', 'TBC')],
            ['label' => 'Phone *', 'value' => self::g($sp, 'supplier_phone', 'TBC')],
            ['label' => 'Supplier Type *', 'value' => self::g($sp, 'supplier_type', 'TBC')],
        ]);

        DocxComponents::addSectionTitle($section, '3. MATERIAL SPECIFICATIONS  (All fields mandatory — no exceptions)', $green);
        DocxComponents::addColorBox($section, DocxComponents::RED_BG, DocxComponents::RED_BORDER, function (AbstractContainer $cell) {
            $run = $cell->addTextRun();
            $run->addText("\u{26A0}  All specifications below are binding. ", ['bold' => true, 'color' => DocxComponents::RED_TEXT, 'size' => 9]);
            $run->addText("Material that does not conform exactly to the specifications below will be rejected at NexaCrest's discretion. Replacement is at supplier's cost.", ['color' => DocxComponents::RED_SUB, 'size' => 9]);
        });
        DocxComponents::addKvTable($section, [
            ['label' => 'Material / Stone Type *', 'value' => self::g($sp, 'material_stone_type', 'TBC')],
            ['label' => 'Grade *', 'value' => self::g($sp, 'grade') . ' only — no mixed grades, no seconds'],
            ['label' => 'Surface Finish *', 'value' => self::g($sp, 'surface_finish', 'TBC')],
            ['label' => 'Dimensions *', 'value' => self::g($sp, 'dimensions', 'TBC')],
            ['label' => 'Dimensional Tolerance', 'value' => self::g($sp, 'dimensional_tolerance', '±2 mm on L and W · ±0.5 mm on thickness')],
            ['label' => 'Quantity *', 'value' => self::g($sp, 'quantity')],
            ['label' => 'Unit *', 'value' => self::g($sp, 'unit', 'TBC')],
            ['label' => 'Colour Reference', 'value' => self::g($sp, 'colour_reference')],
            ['label' => 'Special Requirements', 'value' => self::g($sp, 'special_requirements')],
        ]);

        DocxComponents::addSectionTitle($section, '4. COMMERCIAL TERMS', $green);
        $commercialRows = [
            ['Unit Price (INR) *', self::g($sp, 'unit_price_inr'), 'Rate as agreed'],
            ['Quantity *', self::g($sp, 'quantity') . ' ' . self::g($sp, 'unit', ''), 'Must match Section 3 exactly'],
            ['Basic Value *', self::g($sp, 'basic_value_inr'), 'Unit Price × Quantity'],
            ['GST *', self::g($sp, 'gst_rate_pct', 'X') . '% — ' . self::g($sp, 'gst_amount_inr'), 'CGST + SGST (intrastate) OR IGST (interstate)'],
            ['TOTAL PAYABLE *', self::g($sp, 'total_payable_inr'), 'Basic Value + GST', true],
            ['Advance (' . self::g($sp, 'advance_pct', 'X') . '%) *', self::g($sp, 'advance_amount_inr'), 'Payable before production commences'],
            ['Balance *', self::g($sp, 'balance_amount_inr'), 'Payable ONLY after delivery + inspection + written acceptance by NexaCrest', true],
        ];
        self::threeColFinanceTable($section, "Field", $commercialRows, $green);

        DocxComponents::addSectionTitle($section, '5. DELIVERY TERMS', $green);
        DocxComponents::addKvTable($section, [
            ['label' => 'Delivery Location *', 'value' => self::g($sp, 'delivery_location', 'TBC')],
            ['label' => 'Required Delivery Date *', 'value' => self::g($sp, 'required_delivery_date', 'TBC')],
            ['label' => 'Delivery Confirmation', 'value' => 'Supplier must confirm delivery readiness in writing (WhatsApp acceptable) at least 7 days before the required delivery date.'],
            ['label' => 'Time is of the Essence', 'value' => 'Delivery by the agreed date is of the essence of this Purchase Order. Failure to deliver by the agreed date may result in cancellation of this PO and / or recovery of losses incurred by NexaCrest as a result of the delay, including but not limited to demurrage, vessel rebooking charges and buyer penalties.'],
            ['label' => 'Packing *', 'value' => self::g($sp, 'packing_requirement')],
        ]);

        if (!empty($context['terms'])) {
            DocxComponents::addSectionTitle($section, '6. QUALITY & INSPECTION', $green);
            foreach ($context['terms'] as $clause) {
                $section->addListItem((string) $clause, 0, ['size' => 9.5], null, ['spaceAfter' => 60]);
            }
        }

        DocxComponents::addSectionTitle($section, '7. SUPPLIER ACCEPTANCE', $green);
        $table = $section->addTable(['unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow();
        $table->addCell(2500, ['bgColor' => $green])->addText('Issued by NexaCrest International Private Limited', ['bold' => true, 'color' => DocxComponents::WHITE]);
        $table->addCell(2500, ['bgColor' => $green])->addText('Accepted by Supplier  •  Sign, Stamp & Return', ['bold' => true, 'color' => DocxComponents::WHITE]);
        $table->addRow();
        $leftCell = $table->addCell(2500, ['valign' => 'top', 'borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY]);
        $leftCell->addText((string) self::g($signatory, 'name', ''), ['bold' => true, 'color' => DocxComponents::NAVY], ['spaceBefore' => 160]);
        $leftCell->addText((string) self::g($signatory, 'designation', ''));
        $leftCell->addText((string) self::g($company, 'legal_name', ''), ['size' => 8.5, 'color' => '555555']);
        $rightCell = $table->addCell(2500, ['valign' => 'top', 'bgColor' => DocxComponents::GREEN_BG, 'borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY]);
        $rightCell->addText('Authorised Signature:', ['bold' => true]);
        $rightCell->addText('_________________________________', ['color' => '999999']);
        $rightCell->addText('Name: _____________________________');
        $rightCell->addText('Designation: ______________________');
        $rightCell->addText('Date: _____________________________', ['bold' => true]);
        $rightCell->addText('Supplier Stamp: (mandatory)', ['bold' => true, 'color' => DocxComponents::RED_TEXT, 'size' => 8.5]);
        $section->addTextBreak(1, 6);
        DocxComponents::addColorBox($section, DocxComponents::GREEN_BG, $green, function (AbstractContainer $cell) use ($green) {
            $run = $cell->addTextRun();
            $run->addText('By signing above, the supplier confirms acceptance of all specifications, commercial terms, delivery terms and quality conditions stated in this Purchase Order. ', ['color' => $green]);
            $run->addText('Acceptance of advance payment constitutes full acceptance of all terms herein.', ['bold' => true, 'color' => $green]);
        }, 0);

        // terms_section and signature_block are both suppressed in the source template — Section 7 above covers acceptance/signature.
    }

    /** The 3-column "Field / Value / Remark" finance table shared by SUPPO §4 and CI §5. */
    private static function threeColFinanceTable(Section $section, string $headerText, array $rows, string $headerBg): void
    {
        $table = $section->addTable(['unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow();
        $table->addCell(5000, ['bgColor' => $headerBg, 'gridSpan' => 3])->addText($headerText, ['bold' => true, 'color' => DocxComponents::WHITE]);
        foreach ($rows as $row) {
            $label = $row[0];
            $value = $row[1];
            $remark = $row[2];
            $highlight = $row[3] ?? false;
            $table->addRow();
            $bg = ($highlight ?? false) ? DocxComponents::AMBER_BG : DocxComponents::WHITE;
            $table->addCell(1700, ['bgColor' => $bg, 'borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY])
                ->addText($label, ['bold' => true, 'color' => DocxComponents::NAVY, 'size' => 9.5]);
            $table->addCell(1300, ['bgColor' => $bg, 'borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY])
                ->addText((string) $value, ['bold' => (bool) ($highlight ?? false), 'color' => ($highlight ?? false) ? DocxComponents::RED_TEXT : DocxComponents::BLACK, 'size' => 9.5], ['alignment' => Jc::END]);
            $table->addCell(2000, ['borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY])
                ->addText((string) $remark, ['italic' => true, 'size' => 8.5, 'color' => '555555']);
        }
        $section->addTextBreak(1, 6);
    }

    // ==================================================================
    // FDN — Freight Debit Note
    // ==================================================================
    private static function renderFdn(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $buyer = $context['buyer'] ?? [];
        $freight = $context['freight'] ?? [];
        $company = $context['company'] ?? [];
        $currency = self::g($order, 'currency_code', '');

        DocxComponents::addHeader($section, $context, self::titleFor('FDN'), 'FREIGHT COST RECOVERY');
        DocxComponents::addMandatoryNote($section);
        DocxComponents::addMetaBar($section, [
            ['label' => 'DEBIT NOTE NO. *', 'value' => self::g($meta, 'document_reference', '') . ' ' . self::g($meta, 'revision_label', '')],
            ['label' => 'DATE *', 'value' => self::g($meta, 'generated_date', '')],
            ['label' => 'PI REFERENCE *', 'value' => self::g($order, 'pi_ref', '—')],
            ['label' => 'BUYER INQUIRY REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);
        DocxComponents::addColorBox($section, DocxComponents::RED_BG, DocxComponents::RED_BORDER, function (AbstractContainer $cell) {
            $cell->addText("\u{23F1}  PAYMENT REQUIRED BEFORE SHIPMENT", ['bold' => true, 'size' => 10, 'color' => DocxComponents::RED_TEXT], ['alignment' => Jc::CENTER]);
            $run = $cell->addTextRun(['alignment' => Jc::CENTER]);
            $run->addText('Payment of this Freight Debit Note is required within ', ['size' => 9.5, 'color' => DocxComponents::RED_SUB]);
            $run->addText('3 working days', ['bold' => true, 'size' => 9.5, 'color' => DocxComponents::RED_TEXT]);
            $run->addText(' of the date above. NexaCrest will confirm shipment booking and hand over cargo to the shipping line ', ['size' => 9.5, 'color' => DocxComponents::RED_SUB]);
            $run->addText('only upon receipt of full freight payment.', ['bold' => true, 'size' => 9.5, 'color' => DocxComponents::RED_TEXT]);
        });

        self::addDefaultSection1($section, $context, 'FROM (SELLER / EXPORTER)');

        DocxComponents::addSectionTitle($section, '2. TO (BUYER)');
        DocxComponents::addKvTable($section, [
            ['label' => 'Company Legal Name *', 'value' => self::g($buyer, 'company_legal_name')],
            ['label' => 'Billing Address *', 'value' => self::g($buyer, 'billing_address')],
            ['label' => 'Contact Person *', 'value' => self::g($buyer, 'contact_person', 'TBC')],
            ['label' => 'Email *', 'value' => self::g($buyer, 'email', 'TBC')],
        ]);

        $productList = implode('; ', array_map(static fn(array $p) => ($p['description'] ?? '') . ' (' . ($p['quantity'] ?? '') . ' ' . ($p['unit'] ?? '') . ')', $context['products'] ?? []));
        DocxComponents::addSectionTitle($section, '3. ORDER REFERENCE');
        DocxComponents::addKvTable($section, [
            ['label' => 'Proforma Invoice No. *', 'value' => self::g($order, 'pi_ref', '—')],
            ['label' => 'Product(s)', 'value' => $productList],
            ['label' => 'Incoterm *', 'value' => self::g($order, 'incoterm_label') . '. Freight recovery applies as agreed under the referenced Proforma Invoice.'],
            ['label' => 'Port of Loading *', 'value' => self::g($order, 'port_of_loading')],
            ['label' => 'Port of Discharge *', 'value' => self::g($order, 'port_of_discharge')],
            ['label' => 'Shipping Line', 'value' => self::g($freight, 'freight_forwarder_name', 'TBC')],
            ['label' => 'Cargo Status *', 'value' => self::g($context, 'packing.packing_date') !== '—' ? ('Packed and ready for shipment as of ' . self::g($context, 'packing.packing_date')) : 'TBC'],
        ]);

        DocxComponents::addSectionTitle($section, '4. FREIGHT & CHARGES');
        DocxComponents::addProductsTable($section, ['Description *', 'Container Type *', "Amount ({$currency}) *", 'Remarks'], [22, 18, 20, 40], [
            ['cells' => ['Ocean Freight', self::g($order, 'container_type'), self::g($freight, 'confirmed_freight_rate', 'TBC'), 'Confirmed rate — ' . self::g($order, 'port_of_loading') . ' to ' . self::g($order, 'port_of_discharge')], 'align' => [null, null, 'right', null]],
            ['cells' => ['Origin Charges (if any)', '', 'NIL', 'THC, documentation charges at ' . self::g($order, 'port_of_loading') . ' — if applicable'], 'align' => [null, null, 'right', null]],
            ['cells' => ['Insurance', '', self::g($order, 'incoterm_code') === 'CIF' ? self::g($freight, 'insurance_amount', 'TBC') : 'NIL', 'CIF only — if FOB/CFR enter NIL'], 'align' => [null, null, 'right', null]],
            ['cells' => ['GST / IGST (if applicable)', '', self::g($freight, 'gst_treatment') === 'IGST_18' ? '18% IGST' : 'NIL', 'As advised by CA — NIL if pure cost reimbursement'], 'align' => [null, null, 'right', null]],
        ]);
        DocxComponents::addTotalsTable($section, [
            ['label' => "TOTAL AMOUNT DUE ({$currency}) *  (incl. GST if applicable)", 'value' => self::g($freight, 'total_freight_and_insurance', 'TBC'), 'highlight' => true],
        ]);

        DocxComponents::addSectionTitle($section, '5. PAYMENT INSTRUCTIONS');
        DocxComponents::addColorBox($section, DocxComponents::RED_BG, DocxComponents::RED_BORDER, function (AbstractContainer $cell) use ($freight, $meta, $order) {
            $r1 = $cell->addTextRun();
            $r1->addText('Payment Due: ', ['bold' => true]);
            $r1->addText('Within 3 working days of this Debit Note date — by ' . self::g($freight, 'payment_due_date', 'TBC'), ['color' => DocxComponents::RED_SUB]);
            $r2 = $cell->addTextRun();
            $r2->addText('Payment Method: ', ['bold' => true]);
            $r2->addText('T/T (Telegraphic Transfer) to the NexaCrest bank account below', ['color' => DocxComponents::RED_SUB]);
            $r3 = $cell->addTextRun();
            $r3->addText('Reference: ', ['bold' => true]);
            $r3->addText('Quote Debit Note No. ' . self::g($meta, 'document_reference') . ' and PI No. ' . self::g($order, 'pi_ref', '—') . ' in the remittance', ['color' => DocxComponents::RED_SUB]);
            $r4 = $cell->addTextRun();
            $r4->addText("\u{26A0} Critical: ", ['bold' => true]);
            $r4->addText('Share bank remittance copy as per Section 1 contact details above immediately after payment. Shipment booking will be confirmed only upon verification of freight receipt.', ['color' => DocxComponents::RED_SUB]);
        });

        DocxComponents::addSectionTitle($section, '6. BANK DETAILS  (for freight T/T payment)');
        DocxComponents::addKvTable($section, self::bankDetailsRows(
            $context,
            'Please quote FDN No. ' . self::g($meta, 'document_reference', '') . ' in your wire transfer remarks.',
            self::g($company, 'rbi_purpose_code_freight') . ' — enter in the "Purpose of Remittance" field of your wire transfer form.'
        ));

        DocxComponents::addSignatureBlock($section, $context);
    }

    // ==================================================================
    // PL — Packing List
    // ==================================================================
    private static function renderPl(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $buyer = $context['buyer'] ?? [];
        $packing = $context['packing'] ?? [];
        $crates = $context['crates'] ?? [];
        $products = $context['products'] ?? [];

        DocxComponents::addHeader($section, $context, self::titleFor('PL'), null);
        DocxComponents::addMandatoryNote($section);
        DocxComponents::addMetaBar($section, [
            ['label' => 'PL NUMBER *', 'value' => self::g($meta, 'document_reference', '') . ' ' . self::g($meta, 'revision_label', '')],
            ['label' => 'DATE *', 'value' => self::g($meta, 'generated_date', '')],
            ['label' => 'PI REFERENCE *', 'value' => self::g($order, 'pi_ref', '—')],
            ['label' => 'BUYER INQUIRY REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);

        self::addDefaultSection1($section, $context, 'EXPORTER');

        DocxComponents::addSectionTitle($section, '2. CONSIGNEE / BUYER');
        $table = $section->addTable(['unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT, 'width' => 5000]);
        $table->addRow();
        $leftCell = $table->addCell(2500, ['valign' => 'top', 'borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY]);
        foreach ([['Company Legal Name *', 'company_legal_name'], ['Consignee Name *', 'consignee_name'], ['Consignee Address *', 'consignee_address'], ['VAT / EORI / Tax Reg. No. *', 'vat_eori_tax_no']] as [$label, $key]) {
            $run = $leftCell->addTextRun();
            $run->addText($label . "\n", ['bold' => true, 'color' => DocxComponents::NAVY]);
            $run->addText((string) self::g($buyer, $key, 'TBC'));
        }
        $rightCell = $table->addCell(2500, ['valign' => 'top', 'bgColor' => DocxComponents::GRAY_LIGHT, 'borderSize' => 4, 'borderColor' => DocxComponents::BORDER_GRAY]);
        $rightVals = [
            ['Incoterm *', self::g($order, 'incoterm_label')],
            ['Port of Loading *', self::g($order, 'port_of_loading')],
            ['Port of Discharge *', self::g($order, 'port_of_discharge')],
            ['Country of Final Destination *', self::g($buyer, 'country_of_destination', 'TBC')],
            ['Notify Party', self::g($buyer, 'notify_party', 'Same as consignee')],
        ];
        foreach ($rightVals as [$label, $val]) {
            $run = $rightCell->addTextRun();
            $run->addText($label . "\n", ['bold' => true, 'color' => DocxComponents::NAVY]);
            $run->addText((string) $val);
        }
        $section->addTextBreak(1, 6);

        $productList = implode('; ', array_map(static fn(array $p) => ($p['description'] ?? '') . (!empty($p['finish']) ? ' — ' . $p['finish'] : ''), $products));
        $hsCodeList = implode(', ', array_map(static fn(array $p) => $p['hs_code'] ?? '', $products));
        DocxComponents::addSectionTitle($section, '3. PRODUCT SUMMARY');
        DocxComponents::addKvTable($section, [
            ['label' => 'Product Description *', 'value' => $productList],
            ['label' => 'HS Code *', 'value' => $hsCodeList],
            ['label' => 'Country of Origin *', 'value' => 'India'],
            ['label' => 'Total Quantity *', 'value' => self::g($packing, 'actual_quantity_packed', 'TBC')],
            ['label' => 'Total No. of Crates *', 'value' => self::g($packing, 'crate_count', 'TBC')],
            ['label' => 'Total Net Weight *', 'value' => self::g($packing, 'total_net_weight_kg') !== '—' ? self::g($packing, 'total_net_weight_kg') . ' kg' : 'TBC'],
            ['label' => 'Total Gross Weight *', 'value' => self::g($packing, 'total_gross_weight_kg') !== '—' ? self::g($packing, 'total_gross_weight_kg') . ' kg' : 'TBC'],
            ['label' => 'Total CBM *', 'value' => self::g($packing, 'total_cbm') !== '—' ? self::g($packing, 'total_cbm') . ' m³' : 'TBC'],
        ]);

        DocxComponents::addSectionTitle($section, '4. CRATE-LEVEL BREAKDOWN  (from factory packing data)');
        DocxComponents::addPlainParagraph($section, "Filled from the factory packing supervisor's actual measurements. Each row = one physical crate.", ['italic' => true, 'size' => 9, 'color' => DocxComponents::MUTED]);
        DocxComponents::addRichParagraph($section, [
            ['Marks & Numbers format: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
            ['NEXACREST / ' . strtoupper((string) self::g($buyer, 'company_legal_name', '')) . ' / ' . strtoupper((string) self::g($order, 'port_of_discharge', '')) . ' / C-NNN/TOTAL / ' . self::g($order, 'pi_ref', 'PI NUMBER') . ' / MADE IN INDIA', ['color' => DocxComponents::MUTED]],
        ]);

        if (!empty($crates)) {
            $rows = [];
            foreach ($crates as $i => $c) {
                $lineColor = ($i % 2 === 0) ? '1D6FA8' : '2D7D56';
                $rows[] = [
                    'cells' => [(string) ($c['crate_no'] ?? ''), (string) ($c['marks_numbers'] ?? ''), (string) ($c['product_description'] ?? ''), (string) ($c['dimensions_lwh_cm'] ?? '—')],
                    'lineColor' => $lineColor,
                    'specText' => [
                        [($c['crate_no'] ?? '') . '  ', ['bold' => true, 'color' => $lineColor]],
                        ['Pcs: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
                        [($c['pcs'] ?? '—') . '   ·   ', []],
                        ['Net Wt: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
                        [(($c['net_weight_kg'] ?? null) ? $c['net_weight_kg'] . ' kg' : '—') . '   ·   ', []],
                        ['Gross Wt: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
                        [(($c['gross_weight_kg'] ?? null) ? $c['gross_weight_kg'] . ' kg' : '—') . '   ·   ', []],
                        ['CBM: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
                        [(($c['cbm'] ?? null) ? $c['cbm'] . ' m³' : '—') . '   ·   ', []],
                        ['HS Code: ', ['bold' => true, 'color' => DocxComponents::NAVY]],
                        [(string) ($c['hs_code'] ?? '—'), []],
                    ],
                ];
            }
            DocxComponents::addProductsTable($section, ['Crate No. *', 'Marks & Numbers *', 'Product Description & Finish *', 'Crate Size  L×W×H (cm) *'], [12, 33, 35, 20], $rows);
        } else {
            DocxComponents::addPlainParagraph($section, 'Crate-level breakdown not yet recorded.', ['italic' => true, 'size' => 8.7, 'color' => '5b6774']);
        }

        DocxComponents::addSectionTitle($section, '5. DECLARATION');
        DocxComponents::addKvTable($section, [[
            'full' => true,
            'value' => "We hereby declare that the particulars given above are true and correct to the best of our knowledge and belief, and that the goods described herein are of Indian origin.\nFumigation Certificate: A Fumigation Certificate is provided as standard with every NexaCrest shipment and will be included in the final document set couriered to the buyer.",
        ]]);

        DocxComponents::addTermsSection($section, $context, (int) ($context['terms_section_number'] ?? 9), (string) ($context['terms_section_title'] ?? 'TERMS & CONDITIONS'));
        self::addAnnexureAppendixIfAny($section, $context);
        DocxComponents::addSignatureBlock($section, $context);
    }

    // ==================================================================
    // BLI — Bill of Lading Instruction Sheet
    // ==================================================================
    private static function renderBli(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $buyer = $context['buyer'] ?? [];
        $company = $context['company'] ?? [];
        $shipping = $context['shipping'] ?? [];
        $packing = $context['packing'] ?? [];
        $freight = $context['freight'] ?? [];
        $bl = $context['bl'] ?? [];
        $products = $context['products'] ?? [];
        $currency = self::g($order, 'currency_code', '');

        DocxComponents::addHeader($section, $context, self::titleFor('BLI'), 'SHIPPING INSTRUCTION — FOR CHA / SHIPPING LINE');
        DocxComponents::addColorBox($section, DocxComponents::BLUE_BG, '042C53', function (AbstractContainer $cell) {
            $run = $cell->addTextRun();
            $run->addText('Send this sheet to your CHA or shipping line BEFORE shipment booking is confirmed. ', ['bold' => true]);
            $run->addText('All details must match the Commercial Invoice and Packing List exactly. Any mismatch on the issued BL is expensive and time-consuming to correct — amendments after BL issuance attract charges and delays.', ['italic' => true, 'color' => DocxComponents::BLUE_TEXT]);
        }, 0);
        DocxComponents::addMetaBar($section, [
            ['label' => 'BL INSTRUCTION NO. *', 'value' => self::g($meta, 'document_reference', '') . ' ' . self::g($meta, 'revision_label', '')],
            ['label' => 'DATE *', 'value' => self::g($meta, 'generated_date', '')],
            ['label' => 'PI REFERENCE *', 'value' => self::g($order, 'pi_ref', '—')],
            ['label' => 'BUYER INQUIRY REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);

        DocxComponents::addSectionTitle($section, '1. SHIPPER / EXPORTER  (appears on BL exactly as written)');
        DocxComponents::addKvTable($section, [
            ['label' => 'Company Name', 'value' => strtoupper((string) self::g($company, 'legal_name', '')), 'valueBg' => null],
            ['label' => 'Registered Address', 'value' => self::g($company, 'registered_office')],
            ['label' => 'Country', 'value' => 'India'],
            ['label' => 'GSTIN', 'value' => self::g($company, 'gstin')],
            ['label' => 'IEC', 'value' => self::g($company, 'iec_pan')],
            ['label' => 'Contact / Phone', 'value' => self::g($company, 'phone') . '  |  ' . self::g($company, 'email')],
        ], true);

        DocxComponents::addSectionTitle($section, '2. CONSIGNEE  (buyer — appears on BL exactly as written)');
        DocxComponents::addKvTable($section, [
            ['label' => 'Company Legal Name *', 'value' => self::g($buyer, 'company_legal_name')],
            ['label' => 'Full Address *', 'value' => self::g($buyer, 'consignee_address') !== '—' ? self::g($buyer, 'consignee_address') : self::g($buyer, 'billing_address')],
            ['label' => 'Country *', 'value' => self::g($buyer, 'country_of_destination', 'TBC')],
            ['label' => 'VAT / EORI / Tax Ref *', 'value' => self::g($buyer, 'vat_eori_tax_no', 'TBC')],
        ], true);

        DocxComponents::addSectionTitle($section, '3. NOTIFY PARTY');
        DocxComponents::addKvTable($section, [
            ['label' => 'Same as consignee?', 'value' => self::g($buyer, 'notify_party', 'SAME AS CONSIGNEE')],
        ], true);

        DocxComponents::addSectionTitle($section, '4. SHIPMENT DETAILS');
        DocxComponents::addKvTable($section, [
            ['label' => 'Port of Loading *', 'value' => self::g($order, 'port_of_loading')],
            ['label' => 'Port of Discharge *', 'value' => self::g($order, 'port_of_discharge')],
            ['label' => 'Place of Delivery', 'value' => 'Same as Port of Discharge unless buyer has an inland delivery arrangement'],
            ['label' => 'Vessel Name *', 'value' => self::g($shipping, 'vessel_name', 'TBC — to be confirmed by shipping line at time of booking')],
            ['label' => 'Voyage Number *', 'value' => self::g($shipping, 'voyage_number', 'TBC')],
            ['label' => 'ETD (Est. Departure) *', 'value' => self::g($shipping, 'etd', 'TBC')],
            ['label' => 'ETA (Est. Arrival) *', 'value' => self::g($shipping, 'eta', 'TBC')],
        ], true);

        $containerType = (string) self::g($order, 'container_type', '');
        DocxComponents::addSectionTitle($section, '5. CONTAINER & CARGO DETAILS');
        DocxComponents::addKvTable($section, [
            ['label' => 'Container Load Type *', 'value' => self::checkbox(true, 'FCL (Full Container Load)') . '    ' . self::checkbox(false, 'LCL (Less than Container Load)')],
            ['label' => 'Container Size *', 'value' =>
                self::checkbox(str_contains($containerType, '20ft'), '20ft Standard') . '   ' .
                self::checkbox(str_contains($containerType, '40ft') && !str_contains($containerType, 'High Cube'), '40ft Standard') . '   ' .
                self::checkbox(str_contains($containerType, 'High Cube'), '40ft High Cube'),
            ],
            ['label' => 'Container No. *', 'value' => self::g($shipping, 'container_no', 'TBC — to be confirmed by shipping line after stuffing')],
            ['label' => 'Seal No. *', 'value' => self::g($shipping, 'seal_no', 'TBC — to be confirmed after stuffing')],
            ['label' => 'No. of Packages *', 'value' => (self::g($packing, 'crate_count') !== '—' ? self::g($packing, 'crate_count') . ' Wooden Crates' : 'TBC') . ' — must match Packing List exactly'],
            ['label' => 'Gross Weight *', 'value' => self::g($packing, 'total_gross_weight_kg') !== '—' ? self::g($packing, 'total_gross_weight_kg') . ' kg' : 'TBC'],
            ['label' => 'Net Weight *', 'value' => self::g($packing, 'total_net_weight_kg') !== '—' ? self::g($packing, 'total_net_weight_kg') . ' kg' : 'TBC'],
            ['label' => 'Total CBM *', 'value' => self::g($packing, 'total_cbm') !== '—' ? self::g($packing, 'total_cbm') . ' m³' : 'TBC'],
        ], true);

        $productList = implode('; ', array_map(static fn(array $p) => ($p['description'] ?? '') . (!empty($p['finish']) ? ', ' . $p['finish'] : ''), $products));
        $hsCodeList = implode(', ', array_map(static fn(array $p) => $p['hs_code'] ?? '', $products));
        DocxComponents::addSectionTitle($section, '6. CARGO DESCRIPTION  (appears on BL exactly as written)');
        $crateCount = self::g($context, 'packing.crate_count', 'N');
        DocxComponents::addKvTable($section, [
            ['label' => 'Description of Goods *', 'value' => $productList . ' — must match Commercial Invoice exactly'],
            ['label' => 'HS Code *', 'value' => $hsCodeList],
            ['label' => 'Marks & Numbers *', 'value' => 'NEXACREST / ' . strtoupper((string) self::g($buyer, 'company_legal_name', '')) . ' / ' . strtoupper((string) self::g($order, 'port_of_discharge', '')) . " / C-001/{$crateCount} to C-{$crateCount}/{$crateCount} / " . self::g($order, 'pi_ref', '—') . ' / MADE IN INDIA'],
            ['label' => 'Country of Origin *', 'value' => 'India'],
        ], true);

        $isFob = !empty($order['is_fob']);
        DocxComponents::addSectionTitle($section, "7. FREIGHT & CHARGES ON BL  |  " . self::g($order, 'incoterm_label'));
        DocxComponents::addKvTable($section, [
            ['label' => 'Freight Terms *', 'value' =>
                self::checkbox(!$isFob, 'Freight Prepaid (seller pays freight — CFR/CIF)') . '    ' .
                self::checkbox($isFob, 'Freight Collect (buyer pays freight — FOB)'),
            ],
            ['label' => 'Freight Amount', 'value' => $isFob ? 'N/A — Freight Collect' : (self::g($freight, 'confirmed_freight_rate', 'TBC') . ' ' . $currency)],
        ], true);

        DocxComponents::addColorBox($section, DocxComponents::RED_BG, DocxComponents::RED_BORDER, function (AbstractContainer $cell) {
            $cell->addText("\u{26A0}  MANDATORY — DRAFT BL APPROVAL REQUIRED BEFORE ORIGINALS ARE ISSUED", ['bold' => true, 'size' => 10, 'color' => DocxComponents::RED_TEXT]);
            $cell->addText('CHA / Shipping Line must send the complete draft Bill of Lading to NexaCrest for written approval before issuing any original BL. No original BL may be issued without NexaCrest\'s prior written approval. Send draft BL to NexaCrest at contact details in Section 1 above.', ['size' => 9.5, 'color' => DocxComponents::RED_SUB]);
        });

        DocxComponents::addSectionTitle($section, '8. BILL OF LADING TYPE');
        DocxComponents::addColorBox($section, DocxComponents::RED_BG, DocxComponents::RED_BORDER, function (AbstractContainer $cell) use ($context) {
            $cell->addText('MANDATORY INSTRUCTION — DO NOT ISSUE SEA WAYBILL OR EXPRESS BL', ['bold' => true, 'size' => 11], ['spaceAfter' => 60]);
            $run = $cell->addTextRun();
            $run->addText('Always issue: ', ['bold' => true]);
            $run->addText('ORIGINAL NEGOTIABLE BILL OF LADING — 3 ORIGINALS', ['bold' => true, 'size' => 12]);
            $run2 = $cell->addTextRun();
            $run2->addText('Reason: ', ['bold' => true, 'color' => DocxComponents::RED_TEXT]);
            $run2->addText('NexaCrest payment terms are ' . self::g($context['financial'] ?? [], 'advance_pct') . '% advance + ' . self::g($context['financial'] ?? [], 'balance_pct') . '% balance payable against the original BL. NexaCrest retains all 3 original BLs and only releases them to the buyer AFTER the balance T/T is received and cleared in the NexaCrest bank account. Under a Sea Waybill or Express BL, the buyer can collect the cargo without surrendering any document — NexaCrest would lose all financial leverage and may not receive the balance. A Sea Waybill must NEVER be issued for NexaCrest shipments.', ['color' => DocxComponents::RED_SUB]);
        });
        DocxComponents::addKvTable($section, [
            ['label' => 'BL Type *', 'value' => self::g($bl, 'type_instruction')],
            ['label' => 'No. of Original Copies *', 'value' => '3 (three originals) — NexaCrest will hold all 3 originals until balance payment is received.'],
            ['label' => 'No. of Non-Negotiable Copies', 'value' => '3 — for buyer records and NexaCrest file; released freely.'],
            ['label' => 'BL Consignee Instruction *', 'value' => self::g($bl, 'consignee_instruction') . ' — ensures the BL is to NexaCrest\'s order; buyer cannot endorse or use the BL until NexaCrest endorses and releases it.'],
        ], true);
        DocxComponents::addColorBox($section, DocxComponents::BLUE_BG, '042C53', function (AbstractContainer $cell) {
            $run = $cell->addTextRun();
            $run->addText('Original BL release process: ', ['bold' => true]);
            $run->addText('Once all 3 originals are issued, CHA to hand them to NexaCrest only. NexaCrest will courier originals to the buyer after confirming receipt of the balance T/T payment in the NexaCrest bank account. NexaCrest retains all original BLs until balance payment is received and cleared. Cargo release at destination is subject to carrier and destination port procedures.', ['color' => DocxComponents::BLUE_TEXT]);
        }, 0);

        DocxComponents::addTermsSection($section, $context, (int) ($context['terms_section_number'] ?? 9), (string) ($context['terms_section_title'] ?? 'TERMS & CONDITIONS'));
        DocxComponents::addSignatureBlock($section, $context);
    }

    // ==================================================================
    // CI — Commercial Invoice
    // ==================================================================
    private static function renderCi(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $buyer = $context['buyer'] ?? [];
        $financial = $context['financial'] ?? [];
        $payment = $context['payment_status'] ?? [];
        $company = $context['company'] ?? [];
        $shipping = $context['shipping'] ?? [];
        $packing = $context['packing'] ?? [];
        $crates = $context['crates'] ?? [];
        $products = $context['products'] ?? [];
        $currency = self::g($order, 'currency_code', '');

        DocxComponents::addHeader($section, $context, self::titleFor('CI'), self::g($order, 'incoterm_code', null) ? (self::g($order, 'incoterm_code') . ' ' . strtoupper((string) self::g($order, 'port_of_loading', ''))) : null);
        DocxComponents::addMandatoryNote($section);
        DocxComponents::addMetaBar($section, [
            ['label' => 'INVOICE NO. *', 'value' => self::g($meta, 'document_reference', '') . ' ' . self::g($meta, 'revision_label', '')],
            ['label' => 'DATE *', 'value' => self::g($meta, 'generated_date', '')],
            ['label' => 'PI REFERENCE *', 'value' => self::g($order, 'pi_ref', '—')],
            ['label' => 'BUYER INQUIRY REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);
        DocxComponents::addColorBox($section, DocxComponents::GREEN_BG, DocxComponents::GREEN_BORDER, function (AbstractContainer $cell) use ($company) {
            $run = $cell->addTextRun();
            $run->addText('GST DECLARATION: ', ['bold' => true, 'color' => DocxComponents::GREEN_BORDER, 'size' => 9.5]);
            $run->addText('Supply meant for export under Letter of Undertaking (LUT) without payment of Integrated Tax (IGST). ', ['color' => DocxComponents::GREEN_VALUE, 'size' => 9.5]);
            $run->addText('LUT Order No.: ' . self::g($company, 'lut_number'), ['bold' => true, 'color' => DocxComponents::GREEN_BORDER, 'size' => 9.5]);
            $run->addText('   ·   Valid for ' . self::g($company, 'lut_valid_fy') . '   ·   GSTIN: ' . self::g($company, 'gstin'), ['color' => DocxComponents::GREEN_VALUE, 'size' => 9.5]);
        });

        self::addDefaultSection1($section, $context, 'EXPORTER / SELLER');

        DocxComponents::addSectionTitle($section, '2. BUYER / CONSIGNEE');
        DocxComponents::addKvTable($section, [
            ['label' => 'Company Legal Name *', 'value' => self::g($buyer, 'company_legal_name')],
            ['label' => 'Billing Address *', 'value' => self::g($buyer, 'billing_address')],
            ['label' => 'Consignee Name *', 'value' => self::g($buyer, 'consignee_name')],
            ['label' => 'Consignee Address *', 'value' => self::g($buyer, 'consignee_address')],
            ['label' => 'VAT / EORI / Tax Reg. No. *', 'value' => self::g($buyer, 'vat_eori_tax_no', 'TBC')],
            ['label' => 'Contact Person *', 'value' => self::g($buyer, 'contact_person', 'TBC')],
            ['label' => 'Email *', 'value' => self::g($buyer, 'email', 'TBC')],
            ['label' => 'Notify Party', 'value' => self::g($buyer, 'notify_party', 'SAME as consignee')],
            ['label' => 'Country of Final Destination *', 'value' => self::g($buyer, 'country_of_destination', 'TBC')],
        ]);

        DocxComponents::addSectionTitle($section, '3. SHIPPING DETAILS');
        DocxComponents::addKvTable($section, [
            ['label' => 'Incoterm *', 'value' => self::g($order, 'incoterm_label')],
            ['label' => 'Port of Loading *', 'value' => self::g($order, 'port_of_loading')],
            ['label' => 'Port of Discharge *', 'value' => self::g($order, 'port_of_discharge')],
            ['label' => 'Container No. *', 'value' => self::g($shipping, 'container_no', 'TBC')],
            ['label' => 'Bill of Lading No. *', 'value' => self::g($shipping, 'bl_number', 'TBC')],
            ['label' => 'Bill of Lading Date *', 'value' => self::g($shipping, 'bl_date', 'TBC — must match this invoice date')],
            ['label' => 'Vessel / Voyage *', 'value' => self::g($shipping, 'vessel_name', 'TBC') . (self::g($shipping, 'voyage_number') !== '—' ? ' / ' . self::g($shipping, 'voyage_number') : '')],
            ['label' => 'No. of Packages *', 'value' => (self::g($packing, 'crate_count') !== '—' ? self::g($packing, 'crate_count') . ' Wooden Crates' : 'TBC') . ' — must match Packing List'],
        ]);

        DocxComponents::addSectionTitle($section, '4. PRODUCT / ORDER DETAILS');
        DocxComponents::addPlainParagraph($section, 'Quantities and values below reflect ACTUAL shipment — must match Packing List. Must not exceed PI quantities.', ['italic' => true, 'size' => 9, 'color' => DocxComponents::MUTED]);
        DocxComponents::addProductsTable($section, self::productTableHeaders($currency), [6, 34, 10, 10, 20, 20], self::buildProductRows($products));
        $plRefLine = 'PL Reference: ' . self::g($order, 'pl_ref', 'TBC');
        if (!empty($crates)) {
            $plRefLine .= count($crates) > 1
                ? ' — Crates ' . ($crates[0]['crate_no'] ?? '') . ' to ' . ($crates[count($crates) - 1]['crate_no'] ?? '')
                : ' — Crate ' . ($crates[0]['crate_no'] ?? '');
        }
        DocxComponents::addPlainParagraph($section, $plRefLine . '.', ['size' => 9, 'color' => DocxComponents::MUTED]);

        DocxComponents::addSectionTitle($section, '5. INVOICE VALUE & PAYMENT SETTLEMENT');
        DocxComponents::addColorBox($section, 'E8F1FB', DocxComponents::BLUE_TEXT, function (AbstractContainer $cell) {
            $cell->addText('FOB Value is the basis for all payments and required for Indian Shipping Bill & IGST refund. Freight & Insurance (if applicable) are recovered separately via Freight Debit Note and are not included in this Commercial Invoice value.', ['color' => DocxComponents::BLUE_TEXT]);
        }, 0);

        $isFob = !empty($order['is_fob']);
        $freightRemark = $isFob
            ? 'FOB: buyer arranges — write NIL'
            : 'Paid separately via Freight Debit Note No. ' . self::g($order, 'fdn_ref', 'TBC') . ' dated ' . self::g($order, 'fdn_date', 'TBC') . '. Not included in CI value.';
        $sectionARows = [
            ['FOB Value (goods)', $currency . ' ' . self::g($financial, 'fob_value') . ' *', 'Sum of all product lines above — basis for all payments'],
            ['Freight & Insurance', ($isFob ? 'NIL — FOB order' : 'Paid via FDN') . ' *', $freightRemark],
            ['TOTAL COMMERCIAL INVOICE VALUE', $currency . ' ' . self::g($financial, 'fob_value') . ' *', 'Currency: ' . $currency],
        ];
        self::threeColFinanceTable($section, 'SECTION A — INVOICE VALUE', $sectionARows, DocxComponents::NAVY_MID);

        $advanceAmt = self::g($payment, 'advance_amount') !== '—' ? self::g($payment, 'advance_amount') : self::g($financial, 'advance_amount');
        $balanceAmt = self::g($payment, 'balance_amount') !== '—' ? self::g($payment, 'balance_amount') : self::g($financial, 'balance_amount');
        $freightStatus = $isFob ? 'NIL (FOB)' : (self::g($payment, 'freight_cleared_at') !== '—' ? 'PAID VIA FDN' : 'PENDING');
        $freightAmtCell = $isFob ? 'NIL — FOB' : ($currency . ' ' . self::g($payment, 'freight_amount', 'TBC') . ' *');
        $freightRemark2 = $isFob ? 'FOB: buyer arranges — write NIL' : ('CFR/CIF: Against FDN No. ' . self::g($order, 'fdn_ref', 'TBC') . ' dated ' . self::g($order, 'fdn_date', 'TBC'));
        $subtotalPaid = $currency . ' ' . $advanceAmt . (!$isFob && self::g($payment, 'freight_amount', null) !== '—' && self::g($payment, 'freight_amount', null) !== 'TBC' ? ' + ' . self::g($payment, 'freight_amount') . ' (freight)' : '');
        $sectionBRows = [
            ["\u{2713}  " . self::g($financial, 'advance_pct') . '% Advance — ' . (self::g($payment, 'advance_cleared_at') !== '—' ? 'RECEIVED' : 'PENDING'), $currency . ' ' . $advanceAmt . ' *', 'Received: ' . self::g($payment, 'advance_cleared_at', 'TBC') . "\nAgainst: PI No. " . self::g($order, 'pi_ref', 'TBC')],
            ["\u{2713}  Freight & Insurance — {$freightStatus}", $freightAmtCell, $freightRemark2],
            ['SUBTOTAL ALREADY PAID', $subtotalPaid, self::g($financial, 'advance_pct') . '% Advance' . (!$isFob ? ' + FDN (if applicable)' : '')],
            ["\u{21D2}  BALANCE DUE NOW", $currency . ' ' . $balanceAmt . ' *', '= FOB Value minus ' . self::g($financial, 'advance_pct') . "% advance received\nPayable by T/T within " . self::g($financial, 'balance_days') . " days of BL date\nAgainst scanned copy of Bill of Lading\nPayment Reference: Quote CI No. " . self::g($meta, 'document_reference'), true],
            ['VERIFICATION', "Advance + Balance = FOB Value\n{$advanceAmt} + {$balanceAmt} = " . self::g($financial, 'fob_value') . " \u{2713}", 'Freight & Insurance paid separately via FDN — not included in CI value or this verification.'],
        ];
        self::threeColFinanceTable($section, 'SECTION B — PAYMENT SETTLEMENT', $sectionBRows, DocxComponents::NAVY_MID);

        DocxComponents::addRichParagraph($section, [
            ['Total Invoice Value in Words (' . $currency . '): ', ['bold' => true]],
            [self::g($financial, 'fob_value_in_words', '_________________________________________________________'), ['italic' => true, 'color' => DocxComponents::MUTED]],
            [' *', ['bold' => true, 'color' => 'C0392B']],
        ]);

        DocxComponents::addSectionTitle($section, '6. BANK DETAILS  (for balance T/T payment)');
        DocxComponents::addKvTable($section, self::bankDetailsRows(
            $context,
            'Please quote CI No. ' . self::g($meta, 'document_reference', '') . ' in your wire transfer remarks.',
            self::g($company, 'rbi_purpose_code_balance') . ' — enter in the "Purpose of Remittance" field of your wire transfer form.'
        ));

        DocxComponents::addSectionTitle($section, '7. DOCUMENTS PROVIDED WITH THIS SHIPMENT');
        self::documentsProvidedParagraphs($section, 'The following documents are provided with this shipment:', [
            '1.  Commercial Invoice (this document — signed and stamped)',
            '2.  Packing List (signed and stamped)',
            '3.  Certificate of Origin — ' . self::g($order, 'coo_type') . ' — Issued by CAPEXIL',
            '4.  Bill of Lading — Original Negotiable, 3 originals — couriered to buyer after balance T/T is received and cleared',
            '5.  Fumigation Certificate — provided as standard with every shipment',
        ]);

        DocxComponents::addSectionTitle($section, '8. DECLARATION');
        DocxComponents::addPlainParagraph($section, 'We hereby declare that the goods described in this Commercial Invoice are of Indian origin and that the particulars given are true and correct.', ['italic' => true, 'color' => DocxComponents::MUTED]);
        DocxComponents::addPlainParagraph($section, 'This invoice is issued under Letter of Undertaking (LUT Order No. ' . self::g($company, 'lut_number') . ') for export of goods without payment of IGST under the provisions of the IGST Act, 2017.', ['italic' => true, 'color' => DocxComponents::MUTED]);

        DocxComponents::addTermsSection($section, $context, (int) ($context['terms_section_number'] ?? 9), (string) ($context['terms_section_title'] ?? 'TERMS & CONDITIONS'));
        self::addAnnexureAppendixIfAny($section, $context);
        DocxComponents::addSignatureBlock($section, $context);
    }

    // ==================================================================
    // COOPREP — Certificate of Origin Preparation Sheet (internal only)
    // ==================================================================
    private static function renderCooprep(PhpWord $phpWord, array $context): void
    {
        $section = DocxComponents::addSection($phpWord);
        DocxComponents::addWatermark($section, $context['watermark'] ?? []);
        $order = $context['order'] ?? [];
        $meta = $context['meta'] ?? [];
        $buyer = $context['buyer'] ?? [];
        $company = $context['company'] ?? [];
        $shipping = $context['shipping'] ?? [];
        $packing = $context['packing'] ?? [];
        $financial = $context['financial'] ?? [];
        $products = $context['products'] ?? [];

        DocxComponents::addHeader($section, $context, self::titleFor('COOPREP'), null);
        DocxComponents::addMandatoryNote($section);
        DocxComponents::addMetaBar($section, [
            ['label' => 'SHIPMENT REF *', 'value' => self::g($order, 'pi_ref', '—')],
            ['label' => 'DATE PREPARED *', 'value' => self::g($meta, 'generated_date', '')],
            ['label' => 'COO TYPE *', 'value' => self::g($order, 'coo_type')],
            ['label' => 'BUYER INQUIRY REF *', 'value' => self::g($order, 'buyer_inquiry_ref', '')],
        ]);
        DocxComponents::addColorBox($section, DocxComponents::BLUE_BG, 'B9CADA', function (AbstractContainer $cell) {
            $run = $cell->addTextRun();
            $run->addText('Internal use only — not sent to buyer. ', ['bold' => true, 'color' => DocxComponents::NAVY]);
            $run->addText('Complete this sheet before requesting COO from CAPEXIL. Hand to your CHA along with the documents listed below. COO is issued by CAPEXIL — not by NexaCrest.', ['italic' => true, 'color' => DocxComponents::NAVY]);
        }, 3);
        // section1 and signature_block are both suppressed in the source template (internal ops sheet, no company/signature blocks)

        DocxComponents::addSectionTitle($section, '1. DOCUMENTS TO SUBMIT TO CAPEXIL / CHA');
        DocxComponents::addPlainParagraph($section, 'Tick each item once collected and ready. Do not apply for COO until all mandatory items are ticked.', ['italic' => true, 'size' => 9, 'color' => DocxComponents::MUTED]);
        $docItems = [
            ['Commercial Invoice (signed copy)', 'Must be signed and stamped. Values, HS code, buyer/seller details must be final — no estimates.', self::g($order, 'ci_ref', 'NexaCrest_05_CommercialInvoice.docx')],
            ['Packing List (signed copy)', 'Crate count, weights, CBM must be actuals — not estimates from PI.', 'NexaCrest_04_PackingList.docx'],
            ['Fumigation Certificate (copy)', 'Provided as standard with every NexaCrest shipment — collect from fumigation agency and include with COO application.', 'Fumigation agency — standard every shipment'],
            ['Shipping Bill (copy)', 'Filed by CHA with Indian customs. Do not apply for COO before Shipping Bill is filed.', 'CHA provides after filing'],
            ['Bill of Lading (copy)', 'BL number, date, vessel name, port of loading/discharge must match CI and PL exactly.', 'Shipping line / CHA provides'],
            ['RCMC Certificate (copy)', 'NexaCrest RCMC issued by CAPEXIL — already obtained. Check validity date has not expired.', 'Already obtained — check validity'],
            ['IEC Certificate (copy)', 'NexaCrest IEC — ' . self::g($company, 'iec_pan') . ' — already obtained.', 'Already obtained'],
            ['GSP Declaration (if GSP Form A)', 'Self-declaration of origin criteria — required for GSP Form A only. CHA will advise the format.', 'Required for GSP Form A only'],
        ];
        DocxComponents::addChecklistTable($section, ["\u{2713}", 'Document', 'What to check before submitting', 'Where it comes from'], [6, 24, 45, 25], array_map(
            static fn(array $r) => ['cells' => ['[ ]', static function (AbstractContainer $c) use ($r) { $c->addText($r[0], ['bold' => true]); }, $r[1], $r[2]]],
            $docItems
        ));

        DocxComponents::addSectionTitle($section, '2. INFORMATION REQUIRED ON THE COO APPLICATION');
        DocxComponents::addPlainParagraph($section, 'All values below must match the Commercial Invoice and Packing List exactly. Any mismatch causes rejection.', ['italic' => true, 'size' => 9, 'color' => DocxComponents::MUTED]);
        $productList = implode('; ', array_map(static fn(array $p) => ($p['description'] ?? '') . (!empty($p['finish']) ? ', ' . $p['finish'] : ''), $products));
        $hsCodeList = implode(', ', array_map(static fn(array $p) => $p['hs_code'] ?? '', $products));
        $hardcoded = 'Hardcoded — never changes';
        $infoRows = [
            ['Exporter — Legal Name *', self::g($company, 'legal_name'), $hardcoded],
            ['Exporter — Registered Address *', self::g($company, 'registered_office'), $hardcoded],
            ['Exporter — GSTIN *', self::g($company, 'gstin'), $hardcoded],
            ['Exporter — IEC *', self::g($company, 'iec_pan'), $hardcoded],
            ['Consignee — Legal Name *', self::g($buyer, 'company_legal_name'), 'Commercial Invoice Section 2'],
            ['Consignee — Address *', self::g($buyer, 'consignee_address') !== '—' ? self::g($buyer, 'consignee_address') : self::g($buyer, 'billing_address'), 'Commercial Invoice Section 2'],
            ['Consignee — Country *', self::g($buyer, 'country_of_destination', 'TBC'), 'Commercial Invoice Section 2'],
            ['Vessel Name & Voyage No. *', self::g($shipping, 'vessel_name', 'TBC') . (self::g($shipping, 'voyage_number') !== '—' ? ' / V.' . self::g($shipping, 'voyage_number') : ''), 'Bill of Lading'],
            ['Port of Loading *', self::g($order, 'port_of_loading'), $hardcoded],
            ['Port of Discharge *', self::g($order, 'port_of_discharge'), 'Bill of Lading / Commercial Invoice Section 3'],
            ['Bill of Lading No. *', self::g($shipping, 'bl_number', 'TBC'), 'Bill of Lading'],
            ['Bill of Lading Date *', self::g($shipping, 'bl_date', 'TBC — same as CI date'), 'Bill of Lading / Commercial Invoice'],
            ['Product Description *', $productList, 'Commercial Invoice Section 4'],
            ['HS Code *', $hsCodeList, 'Hardcoded — verify against CI'],
            ['Country of Origin *', 'India', $hardcoded],
            ['No. of Packages *', self::g($packing, 'crate_count') !== '—' ? self::g($packing, 'crate_count') . ' Wooden Crates' : 'TBC', 'Packing List Section 3 / BL'],
            ['Gross Weight *', self::g($packing, 'total_gross_weight_kg') !== '—' ? self::g($packing, 'total_gross_weight_kg') . ' kg' : 'TBC', 'Packing List Section 3'],
            ['Net Weight *', self::g($packing, 'total_net_weight_kg') !== '—' ? self::g($packing, 'total_net_weight_kg') . ' kg' : 'TBC', 'Packing List Section 3'],
            ['Total CBM *', self::g($packing, 'total_cbm') !== '—' ? self::g($packing, 'total_cbm') . ' m³' : 'TBC', 'Packing List Section 3'],
            ['FOB Value *', self::g($order, 'currency_code') . ' ' . self::g($financial, 'fob_value'), 'Commercial Invoice Section 4'],
            ['Invoice No. & Date *', self::g($order, 'ci_ref', 'TBC') . ' — ' . self::g($order, 'ci_date', 'TBC'), 'Commercial Invoice meta bar'],
            ['Competent Authority Signature', 'Signed and stamped by CAPEXIL authorised officer — not by NexaCrest', 'CAPEXIL issues — not your responsibility'],
        ];
        DocxComponents::addChecklistTable($section, ['Field', 'Value for this shipment *', 'Source document'], [30, 40, 30], array_map(
            static fn(array $r) => ['cells' => [static function (AbstractContainer $c) use ($r) { $c->addText($r[0], ['bold' => true]); }, $r[1], $r[2]]],
            $infoRows
        ));

        DocxComponents::addSectionTitle($section, '3. STEP-BY-STEP PROCESS');
        $steps = [
            'Confirm COO type with buyer (GSP Form A or non-preferential) — ideally at Quotation/PI stage.',
            'Cargo packed. Packing List finalised with actuals. CHA files Shipping Bill with customs.',
            'Shipment booked. Bill of Lading issued by shipping line.',
            'Complete this preparation sheet — fill Section 2 values from CI, PL, and BL.',
            'Collect all documents listed in Section 1. Tick each checkbox.',
            'Submit complete package to CHA — or apply directly on the CAPEXIL portal (www.capexil.com). CHA handles this in most cases.',
            'CAPEXIL verifies and issues COO — typically 1–3 working days.',
            'COO received. Include with shipment documents sent to buyer (along with CI, PL, BL).',
        ];
        DocxComponents::addChecklistTable($section, ['Step', 'Instruction'], [15, 85], array_map(
            static fn(int $i, string $s) => ['cells' => [static function (AbstractContainer $c) use ($i) { DocxComponents::stepBadge($c, 'Step ' . ($i + 1)); }, $s]],
            array_keys($steps),
            $steps
        ));

        DocxComponents::addSectionTitle($section, '4. NEXACREST CAPEXIL REGISTRATION DETAILS');
        DocxComponents::addKvTable($section, [
            ['label' => 'Prepared By *', 'value' => 'Name of person completing this sheet'],
            ['label' => 'RCMC Issuing Body', 'value' => 'CAPEXIL — Chemicals and Allied Products Export Promotion Council'],
            ['label' => 'Member Company', 'value' => self::g($company, 'legal_name')],
            ['label' => 'IEC', 'value' => self::g($company, 'iec_pan')],
            ['label' => 'RCMC Number *', 'value' => self::g($company, 'rcmc_number')],
            ['label' => 'RCMC Valid Until *', 'value' => self::g($company, 'rcmc_valid_until')],
            ['label' => 'CAPEXIL Portal', 'value' => 'www.capexil.com — COO applications can be submitted online'],
            ['label' => 'CAPEXIL Office', 'value' => 'Chennai Regional Office handles granite/stone exporters from Karnataka — confirm with CHA'],
        ]);
        DocxComponents::addColorBox($section, DocxComponents::AMBER_BG2, DocxComponents::AMBER_BORDER, function (AbstractContainer $cell) {
            $run = $cell->addTextRun();
            $run->addText("\u{26A0} Annual renewal: ", ['bold' => true, 'color' => DocxComponents::AMBER_TEXT2]);
            $run->addText('RCMC must be renewed annually. If RCMC expires, CAPEXIL cannot issue a COO — your shipment documents will be incomplete and customs clearance will fail. Set a reminder 60 days before expiry.', ['color' => DocxComponents::AMBER_TEXT2]);
        }, 4);
    }
}
