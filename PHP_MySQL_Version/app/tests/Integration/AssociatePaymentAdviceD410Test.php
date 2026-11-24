<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\AssociateRepository;
use App\Services\AssociatePaymentAdvice;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-410 point 8 (24 Nov 2026, M14 confirmed twice): the Commission Payment Advice.
 *  - one advice per bank transfer (same associate, day and bank reference); the number counts per associate per
 *    financial year: NC/SC/CPA/A0000/YY/NNN; made once; frozen; "Rev n" for an MD correction, the old one superseded;
 *  - company details from Admin, no TAN; only the associate's commission.
 * Node twin: tests/integration/associatePaymentAdviceD410.test.js.
 */
final class AssociatePaymentAdviceD410Test extends DbTestCase
{
    private int $md;

    protected function setUp(): void
    {
        parent::setUp();
        $this->md = $this->createTestUser('Managing Director');
    }

    private function associate(): int
    {
        return AssociateRepository::create(['name' => 'CPA Associate ' . bin2hex(random_bytes(2)), 'based_in' => 'india', 'country' => 'India', 'currency_code' => 'INR',
            'email' => null, 'phone' => null, 'tax_id' => null, 'agreement_signed_on' => '2025-12-01', 'status' => 'active', 'notes' => null], $this->md);
    }

    /** A paid associate payout on a new order of a new client. */
    private function paidPayout(int $associateId, string $paidOn, string $ref, float $paidInr, float $tdsInr = 0.0): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 90, 9000)")->execute(['o' => $orderId]);
        $pdo->prepare("INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, status, paid_on, paid_inr, tds_inr, tds_section, paid_via, paid_reference)
                       VALUES (:o, 'associate', :a, 'associate', :amt, :fc, 'USD', :m, 'paid', :d, :p, :t, :sec, 'bank', :ref)")
            ->execute(['o' => $orderId, 'a' => $associateId, 'amt' => $paidInr + $tdsInr, 'fc' => 100, 'm' => substr($paidOn, 0, 7), 'd' => $paidOn, 'p' => $paidInr, 't' => $tdsInr,
                'sec' => $tdsInr > 0 ? '194H' : null, 'ref' => $ref]);
        return (int) $pdo->lastInsertId();
    }

    public function testOneAdvicePerBankTransferNumberedPerAssociatePerFinancialYear(): void
    {
        $a = $this->associate();
        $p1 = $this->paidPayout($a, '2026-07-31', 'UTR-A', 9000.0, 200.0);
        $p2 = $this->paidPayout($a, '2026-07-31', 'UTR-A', 5000.0);
        $p3 = $this->paidPayout($a, '2026-08-31', 'UTR-B', 4000.0);
        $p4 = $this->paidPayout($a, '2027-04-30', 'UTR-C', 3000.0); // the next financial year: numbering starts again
        $ids = AssociatePaymentAdvice::afterPaid([$p1, $p2, $p3, $p4], $this->md);
        self::assertCount(3, $ids, 'UTR-A (two orders), UTR-B and UTR-C');
        $no = sprintf('A%04d', $a);
        $numbers = array_map(static fn (int $id): string => AssociatePaymentAdvice::find($id)['advice_no'], $ids);
        sort($numbers);
        self::assertSame(["NC/SC/CPA/{$no}/26/001", "NC/SC/CPA/{$no}/26/002", "NC/SC/CPA/{$no}/27/001"], $numbers);
        $utrA = AssociatePaymentAdvice::find($ids[0]);
        $c = AssociatePaymentAdvice::storedContext($utrA);
        self::assertCount(2, $c['lines']);
        self::assertSame('₹14,200.00', $c['total_gross']);
        self::assertSame('₹200.00', $c['total_tds']);
        self::assertSame('₹14,000.00', $c['total_paid']);
        self::assertSame([], AssociatePaymentAdvice::afterPaid([$p1, $p2], $this->md), 'made once: a second call makes none');
    }

    public function testTheAdviceIsFrozenAndAnMdCorrectionMakesRev1(): void
    {
        $a = $this->associate();
        $p = $this->paidPayout($a, '2026-07-31', 'UTR-WRONG', 9000.0);
        [$id] = AssociatePaymentAdvice::afterPaid([$p], $this->md);
        Database::connection()->exec("UPDATE incentive_payouts SET paid_reference = 'UTR-RIGHT' WHERE id = {$p}");
        self::assertSame('UTR-WRONG', AssociatePaymentAdvice::storedContext(AssociatePaymentAdvice::find($id))['paid_reference'], 'frozen when made');
        self::assertStringContainsString('10 to 300 characters', (string) AssociatePaymentAdvice::revise($id, 'short', $this->md));
        self::assertNull(AssociatePaymentAdvice::revise($id, 'Bank reference typed wrong', $this->md));
        $old = AssociatePaymentAdvice::find($id);
        self::assertNotNull($old['superseded_at']);
        $rev = AssociatePaymentAdvice::forAssociate($a)[0];
        self::assertSame([1, $old['advice_no'], 'UTR-RIGHT'], [(int) $rev['revision'], $rev['advice_no'], AssociatePaymentAdvice::storedContext($rev)['paid_reference']]);
        self::assertStringContainsString('correct the latest one', (string) AssociatePaymentAdvice::revise($id, 'Second try on the old one', $this->md));
    }

    public function testThePdfShowsNoTanAndNoCosts(): void
    {
        $a = $this->associate();
        [$id] = AssociatePaymentAdvice::afterPaid([$this->paidPayout($a, '2026-07-31', 'UTR-A', 9000.0)], $this->md);
        $html = AssociatePaymentAdvice::html(AssociatePaymentAdvice::find($id));
        self::assertStringContainsString('COMMISSION PAYMENT ADVICE', $html);
        $html = (string) preg_replace(['/<style.*?<\/style>/s', '/<[^>]+>/'], ' ', $html); // the printed text only
        self::assertDoesNotMatchRegularExpression('/\bTAN\b/', $html, 'no company TAN (owner, 24 Nov 2026)');
        foreach (['Supplier', 'Margin (', 'Cost (FOB)', 'CAFIN'] as $never) {
            self::assertStringNotContainsString($never, $html);
        }
        self::assertStringStartsWith('%PDF', AssociatePaymentAdvice::pdf(AssociatePaymentAdvice::find($id)));
        self::assertStringStartsWith('PK', AssociatePaymentAdvice::docx(AssociatePaymentAdvice::find($id)));
    }
}
