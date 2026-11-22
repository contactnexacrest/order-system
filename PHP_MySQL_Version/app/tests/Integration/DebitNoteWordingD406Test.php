<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\OrderProductRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions of 22 Nov 2026 (each confirmed twice): D-406 — the Freight Debit Note's insurance remark names the
 * cover, "Shipping Line" reads "Freight Forwarder", and a box says how the Debit Note fits the order; D-407 — on the
 * QT / PI a freight or insurance figure not entered yet reads "To Be Confirmed" (no "XXX" / "XX"). Node twin:
 * debitNoteWordingD406.test.js.
 */
final class DebitNoteWordingD406Test extends DbTestCase
{
    public function testTheInsuranceRemarkNamesTheCover(): void
    {
        $order = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
        OrderProductRepository::add($order, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
        self::assertSame('ICC (A) incl. breakage, 110% of CIF value — actual premium', DocumentDataAssembler::dnInsuranceRemark(false, true, $order));
        self::assertSame('Buyer arranges insurance (CFR)', DocumentDataAssembler::dnInsuranceRemark(false, false, $order));
        self::assertSame('NIL — FOB order', DocumentDataAssembler::dnInsuranceRemark(true, false, $order));
    }

    public function testTheFitNoteSaysHowTheDebitNoteFitsTheOrder(): void
    {
        self::assertSame('Freight and insurance under Proforma Invoice No. SC/PI/26/0001. The Commercial Invoice will show this Debit Note as paid; the balance on the Commercial Invoice is for the goods (FOB value) only.',
            DocumentDataAssembler::dnFitNote(false, true, 'SC/PI/26/0001'));
        self::assertSame('Freight under Proforma Invoice No. SC/PI/26/0001. The Commercial Invoice will show this Debit Note as paid; the balance on the Commercial Invoice is for the goods (FOB value) only. Insurance is arranged by the buyer (CFR).',
            DocumentDataAssembler::dnFitNote(false, false, 'SC/PI/26/0001'));
        self::assertStringStartsWith('Freight and insurance under Proforma Invoice No. To Be Confirmed.', (string) DocumentDataAssembler::dnFitNote(false, true, null));
        self::assertNull(DocumentDataAssembler::dnFitNote(true, false, 'SC/PI/26/0001'));
    }

    public function testTheTemplatesCarryTheNewWording(): void
    {
        $fdn = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/FDN/freight_debit_note.html.twig');
        self::assertStringContainsString('<td class="k">Freight Forwarder</td>', $fdn);
        self::assertStringNotContainsString('Shipping Line', $fdn);
        self::assertStringContainsString('{{ order.dn_insurance_remark }}', $fdn);
        self::assertStringContainsString('How this Debit Note fits your order', $fdn);
        self::assertStringNotContainsString('CIF only — if FOB/CFR enter NIL', $fdn);
        foreach (['QT/quotation.html.twig', 'PI/proforma_invoice.html.twig'] as $t) {
            $html = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $t);
            self::assertStringNotContainsString("'XXX'", $html, $t);
            self::assertStringNotContainsString("'XX'", $html, $t);
            self::assertStringContainsString('To Be Confirmed — worked out once the freight is known; billed by Debit Note when cargo is ready.', $html, $t);
        }
        $docx = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/Docx/DocxDocumentBuilder.php');
        self::assertStringNotContainsString("'Shipping Line'", $docx);
        self::assertStringContainsString("'Freight Forwarder'", $docx);
        self::assertStringContainsString('How this Debit Note fits your order', $docx);
    }
}
