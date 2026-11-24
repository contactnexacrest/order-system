<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\AssociatePaymentAdvice;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-412 (24 Nov 2026, point 6, confirmed twice): a corrected Commission Payment Advice (Rev 1, one more
 * banner) for a transfer of two orders fits one page — spacing only, no word or figure changes. Fixture: the
 * associate life-cycle scenario's October correction (test data). Node twin: tests/integration/paymentAdviceOnePageD412.test.js.
 */
final class PaymentAdviceOnePageD412Test extends DbTestCase
{
    private static function advice(): array
    {
        $c = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/cpa-rev1-two-orders.json'), true, 512, JSON_THROW_ON_ERROR);
        return ['advice_no' => $c['number'], 'revision' => 1, 'context_json' => json_encode($c), 'superseded_at' => null];
    }

    public function testACorrectedAdviceForTwoOrdersFitsOnePage(): void
    {
        $pdf = AssociatePaymentAdvice::pdf(self::advice());
        self::assertSame(1, preg_match_all('#/Type\s*/Page(?![a-z])#', $pdf), 'Rev 1 with two orders: one page');
    }

    public function testTheWordsAndFiguresStayTheSame(): void
    {
        $html = AssociatePaymentAdvice::html(self::advice());
        foreach (['Rev 1 — CORRECTED:', 'Bank reference typed wrong on the October transfer. It replaces the earlier advice with the same number.',
            '₹1,85,652.16', 'TOTAL PAID TO YOUR BANK', '4. HOW THE COMMISSION IS WORKED OUT', '5. TAX (TDS)', 'This is a computer-generated advice and needs no signature.'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringContainsString('table.kv td { padding: 2.5pt 7pt; }', $html, 'D-412: the spacing is the advice\'s own');
    }
}
