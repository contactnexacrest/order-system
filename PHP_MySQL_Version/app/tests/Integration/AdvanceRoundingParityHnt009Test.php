<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\AmountInWords;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentDataAssembler;
use App\Services\PaymentActionService;
use App\Tests\Support\DbTestCase;

/**
 * HNT-009 (owner-confirmed twice, 13 Nov 2026): PHP is the reference for the
 * advance, the balance and the amount in words; the fix was on Node only.
 * This pins PHP's figures so they stay as they are: USD 4,159.23 × 50 % →
 * advance 2,079.62 / balance 2,079.61, 9,017.30 × 45 % → 4,057.79 / 4,959.51,
 * and the same 297 half-cent cases and words cases the Node twin
 * (hnt009AdvanceRoundingParity.test.js) checks against the same fixture.
 */
final class AdvanceRoundingParityHnt009Test extends DbTestCase
{
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/hnt009_half_cent_cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testFixtureHoldsBothNamedExamples(): void
    {
        $cases = self::cases();
        $this->assertGreaterThanOrEqual(250, count($cases['advance']));
        $this->assertSame(['fob' => '4159.23', 'pct' => '50', 'advance' => '2079.62', 'balance' => '2079.61'], $cases['advance'][0]);
        $this->assertSame(['fob' => '9017.30', 'pct' => '45', 'advance' => '4057.79', 'balance' => '4959.51'], $cases['advance'][1]);
    }

    public function testPhpAdvanceAndBalanceFiguresUnchanged(): void
    {
        foreach (self::cases()['advance'] as $c) {
            $label = "{$c['fob']} × {$c['pct']} %";
            $this->assertSame($c['advance'], number_format(round((float) $c['fob'] * (float) $c['pct'] / 100, 2), 2, '.', ''), $label);
            $this->assertSame($c['balance'], number_format(OrderPaymentStatusRepository::balanceDue((float) $c['fob'], (float) $c['pct'], null), 2, '.', ''), $label);
        }
    }

    public function testPhpAmountInWordsUnchanged(): void
    {
        $this->assertSame('US Dollars One and One Cents Only', AmountInWords::format(1.005, 'USD'));
        foreach (self::cases()['words'] as $c) {
            $this->assertSame($c['usd'], AmountInWords::format((float) $c['amount'], 'USD'), $c['amount']);
            $this->assertSame($c['indian'], AmountInWords::formatIndian((float) $c['amount']), $c['amount']);
        }
    }

    /** @dataProvider piExamples */
    public function testPiPrintsTheSameFiguresAsBefore(string $fob, int $pct, string $advance, string $balance): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderProductRepository::add($orderId, 1, 'Test granite slab', null, null, '1', false, 'PCS', $fob);
        Database::connection()->prepare('UPDATE orders SET advance_pct = ?, balance_pct = ? WHERE id = ?')->execute([$pct, 100 - $pct, $orderId]);
        if (OrderPaymentStatusRepository::find($orderId) === null) {
            OrderPaymentStatusRepository::initializeForOrder($orderId);
        }

        $data = DocumentDataAssembler::assemble($orderId, 'PI');
        $this->assertSame($advance, $data['financial']['advance_amount']);
        $this->assertSame($balance, $data['financial']['balance_amount']);
        $this->assertSame($balance, $data['payment_status']['balance_amount']);

        $order = Database::connection()->query('SELECT * FROM orders WHERE id = ' . $orderId)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($advance, number_format((float) PaymentActionService::amountDue($order, 'advance'), 2));
    }

    public static function piExamples(): array
    {
        return [
            'FOB 4,159.23 at 50 %' => ['4159.23', 50, '2,079.62', '2,079.61'],
            'FOB 9,017.30 at 45 %' => ['9017.30', 45, '4,057.79', '4,959.51'],
        ];
    }
}
