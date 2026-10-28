<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Services\DocumentDataAssembler;
use App\Services\OrderProfitabilityService;
use App\Services\PaymentActionService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, Q-R5-2 (owner decision: "Buyer insures"). Under Incoterms
 * 2020 only CIF puts insurance on the seller; under CFR the seller pays
 * freight only. A CFR order that has an insurance figure stored (entered
 * before this rule, or by hand) must never show it on a document, charge
 * it on the Debit Note or its payment link, or count it as a cost. CIF
 * keeps everything exactly as before.
 */
final class CfrBuyerInsuresTest extends DbTestCase
{
    private function orderWithFreight(string $incoterm): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), $incoterm);
        OrderProductRepository::add($orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '1825.00');
        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => '1200.00', 'insurance_amount' => '150.00']);
        return $orderId;
    }

    public function testCfrDocumentsCarryFreightOnly(): void
    {
        $orderId = $this->orderWithFreight('CFR');

        $ci = DocumentDataAssembler::assemble($orderId, 'CI');
        self::assertFalse($ci['order']['is_cif']);
        self::assertSame('1,200.00', $ci['financial']['freight_insurance_amount'], 'CFR: freight only, no insurance');
        self::assertSame('19,450.00', $ci['financial']['total_commercial_invoice_value']);

        $fdn = DocumentDataAssembler::assemble($orderId, 'FDN');
        self::assertSame('NIL', $fdn['freight']['insurance_amount']);
        self::assertSame('1,200.00', $fdn['freight']['total_freight_and_insurance'], 'the Debit Note asks for freight only');
    }

    public function testCifDocumentsStillIncludeInsurance(): void
    {
        $orderId = $this->orderWithFreight('CIF');

        $ci = DocumentDataAssembler::assemble($orderId, 'CI');
        self::assertTrue($ci['order']['is_cif']);
        self::assertSame('1,350.00', $ci['financial']['freight_insurance_amount']);

        $fdn = DocumentDataAssembler::assemble($orderId, 'FDN');
        self::assertSame('150.00', $fdn['freight']['insurance_amount']);
        self::assertSame('1,350.00', $fdn['freight']['total_freight_and_insurance']);
    }

    public function testDebitNotePaymentLinkAmountIsFreightOnlyOnCfr(): void
    {
        $cfr = OrderRepository::find($this->orderWithFreight('CFR'));
        $cif = OrderRepository::find($this->orderWithFreight('CIF'));

        self::assertSame(1200.0, PaymentActionService::amountDue($cfr, 'freight'));
        self::assertSame(1350.0, PaymentActionService::amountDue($cif, 'freight'));
    }

    public function testProfitIgnoresInsuranceOnCfr(): void
    {
        self::assertSame(0.0, OrderProfitabilityService::computeForOrder($this->orderWithFreight('CFR'))['insurance_cost_inr']);
        self::assertSame(150.0, OrderProfitabilityService::computeForOrder($this->orderWithFreight('CIF'))['insurance_cost_inr']);
    }

    public function testQuotationAndProformaPrintBuyerInsuresOnCfr(): void
    {
        foreach (['QT/quotation.html.twig', 'PI/proforma_invoice.html.twig'] as $template) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
            self::assertStringContainsString("{% elseif not order.is_cif %}NIL — buyer arranges insurance (CFR).", $src, $template);
            self::assertStringContainsString("{% if not order.is_cif %}Buyer's responsibility.", $src, $template);
            self::assertStringContainsString('Freight (CFR order — buyer arranges insurance)', $src, $template);
        }
        $ci = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/CI/commercial_invoice.html.twig');
        self::assertStringContainsString('<td class="k">Freight{% if order.is_cif or order.is_fob %} &amp; Insurance{% endif %}</td>', $ci);
    }

    /** QA Round 5, R5-12: PHP pages break like Node's, and no heading is left alone at a page foot. */
    public function testLayoutKeepsHeadingsWithTheirContentAndMatchesNodeLinePitch(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/_layout.html.twig');
        self::assertMatchesRegularExpression('/\.section-title \{[^}]*page-break-after: avoid;/', $layout);
        self::assertMatchesRegularExpression('/\.section-title-highlight \{[^}]*page-break-after: avoid;/', $layout);
        self::assertMatchesRegularExpression('/body \{[^}]*line-height: 0\.86;/', $layout);
    }
}
