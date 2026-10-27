<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Helpers\MarkdownLite;
use App\Services\Docx\DocxComponents;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;
use Twig\TwigFilter;

/**
 * 90-item backlog Phase 6 Item C — a signature block/table must always
 * carry real, document-identifying text (document type + reference +
 * date + Buyer Inquiry Ref), never just a bare seal/signature with no
 * way to tell which document it belongs to. This guards against a
 * signature page being mistaken for, or substituted into, a different
 * document if it lands alone on its own page. Covers both the shared
 * _layout.html.twig signature_block (most document types) and the
 * BUYERPO/SUPPO templates' own separate acceptance tables.
 */
final class SignatureDocRefLineTest extends TestCase
{
    private function twigEnvironment(): TwigEnvironment
    {
        $twig = new TwigEnvironment(new TwigFilesystemLoader(__DIR__ . '/../../templates'), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addFilter(new TwigFilter('markdown_lite', [MarkdownLite::class, 'toHtml'], ['is_safe' => ['html']]));
        return $twig;
    }

    /** @return array<string,mixed> */
    private function minimalDocContext(): array
    {
        return [
            'company' => [], 'assets' => [], 'watermark' => ['enabled' => false],
            'meta' => ['document_reference' => 'QT/2026/00123', 'generated_date' => '21 October 2026'],
            'order' => ['quotation_ref' => 'QT-1', 'buyer_inquiry_ref' => 'NC/SC/2026/0099'],
            'financial' => ['advance_pct' => 30],
            'terms' => [], 'terms_section_number' => 9, 'terms_section_title' => 'TERMS & CONDITIONS',
            'doc_title' => 'QUOTATION', 'products' => [], 'buyer' => [], 'signatory' => [],
        ];
    }

    public function testCiTemplateSignatureBlockCarriesDocRefLine(): void
    {
        $context = $this->minimalDocContext();
        $html = $this->twigEnvironment()->render('CI/commercial_invoice.html.twig', $context);

        self::assertStringContainsString('This signature forms part of QUOTATION QT/2026/00123, dated 21 October 2026, Buyer Inquiry Ref NC/SC/2026/0099.', $html);
    }

    public function testBuyerPoAcceptanceTableCarriesDocRefLine(): void
    {
        $context = $this->minimalDocContext();
        $html = $this->twigEnvironment()->render('BUYERPO/buyer_po.html.twig', $context);

        self::assertStringContainsString('This signature forms part of QUOTATION QT/2026/00123, dated 21 October 2026, Buyer Inquiry Ref NC/SC/2026/0099.', $html);
    }

    public function testSupplierPoAcceptanceTableCarriesDocRefLine(): void
    {
        $context = $this->minimalDocContext();
        $html = $this->twigEnvironment()->render('SUPPO/supplier_po.html.twig', $context);

        // Owner rule (2026-10-27): the supplier never sees a client reference — no Buyer Inquiry Ref here.
        self::assertStringContainsString('This signature forms part of QUOTATION QT/2026/00123, dated 21 October 2026.', $html);
        self::assertStringNotContainsString('NC/SC/2026/0099', $html);
    }

    public function testDocRefLineOmitsMissingPiecesGracefully(): void
    {
        $context = $this->minimalDocContext();
        $context['meta'] = [];
        $context['order'] = [];
        $html = $this->twigEnvironment()->render('CI/commercial_invoice.html.twig', $context);

        self::assertStringContainsString('This signature forms part of QUOTATION.', $html);
    }

    public function testDocxAddSignatureDocRefLineProducesExpectedText(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        DocxComponents::addSignatureDocRefLine($section, [
            'doc_title' => 'PURCHASE ORDER',
            'meta' => ['document_reference' => 'PO/2026/00045', 'generated_date' => '21 October 2026'],
            'order' => ['buyer_inquiry_ref' => 'NC/SC/2026/0050'],
        ]);

        $elements = $section->getElements();
        $textRun = end($elements);
        $text = $textRun->getText();

        self::assertSame('This signature forms part of PURCHASE ORDER PO/2026/00045, dated 21 October 2026, Buyer Inquiry Ref NC/SC/2026/0050.', $text);
    }

    public function testDocxAddSignatureBlockIncludesTheDocRefLine(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $before = count($section->getElements());

        DocxComponents::addSignatureBlock($section, [
            'doc_title' => 'QUOTATION',
            'meta' => ['document_reference' => 'QT/2026/00123', 'generated_date' => '21 October 2026'],
            'order' => ['buyer_inquiry_ref' => 'NC/SC/2026/0099'],
            'company' => [], 'signatory' => [],
        ]);

        self::assertGreaterThan($before, count($section->getElements()));
    }
}
