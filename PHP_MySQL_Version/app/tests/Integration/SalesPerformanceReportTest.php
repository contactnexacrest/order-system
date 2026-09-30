<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ReportController;
use App\Repositories\ReportRepository;
use App\Tests\Support\DbTestCase;

/**
 * Sales Performance report — reuses funnelActivity()'s own sent/won/lost
 * counts (never a second, divergent definition), so these tests exercise
 * the parts that are genuinely new: FOB-won-by-currency, the individual
 * lost-order detail rows with their own free-text reason, the
 * auto-derived previous-period comparison (only computed when both dates
 * are given), and the calendar-based preset date math.
 *
 * Every scenario that inserts fixture rows uses its own far-future
 * year (2081+) as a private date-range sandbox — the disposable test DB
 * isn't reset between tests in this file, and other fixtures elsewhere
 * default their created_at/sent_at to the real current date, so a
 * shared/real-world date range would silently pick up rows from
 * unrelated tests.
 */
final class SalesPerformanceReportTest extends DbTestCase
{
    private function sendDocument(int $orderId, string $typeCode, string $sentAt): int
    {
        $documentId = $this->createTestDocument($orderId, $typeCode, 'sent');
        Database::connection()->prepare(
            "INSERT INTO email_log (order_id, document_id, recipient_email, subject, body_snapshot, sent_at, status)
             VALUES (:order_id, :document_id, 'buyer@phpunit-test.test', 'Test', 'Test body', :sent_at, 'sent')"
        )->execute(['order_id' => $orderId, 'document_id' => $documentId, 'sent_at' => $sentAt]);
        return $documentId;
    }

    private function markWon(int $orderId, string $piDate): void
    {
        $this->sendDocument($orderId, 'PI', $piDate . ' 10:00:00');
        Database::connection()->prepare('UPDATE orders SET pi_date = :pi_date WHERE id = :id')
            ->execute(['pi_date' => $piDate, 'id' => $orderId]);
    }

    private function markLost(int $orderId, string $lostAt, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE orders SET status = 'lost', lost_at = :lost_at, lost_reason = :reason WHERE id = :id"
        )->execute(['lost_at' => $lostAt, 'reason' => $reason, 'id' => $orderId]);
    }

    private function addProduct(int $orderId, float $fobValue): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_products (order_id, line_no, description, quantity, unit_price, fob_value, is_active)
             VALUES (:order_id, 1, :description, 1, :unit_price, :fob_value, 1)'
        )->execute(['order_id' => $orderId, 'description' => 'PHPUnit Test Product', 'unit_price' => $fobValue, 'fob_value' => $fobValue]);
    }

    /** A fresh order under its own fresh client, since a single client can't hold two orders with sequence_no 1. */
    private function newOrder(string $incotermCode = 'FOB'): int
    {
        return $this->createTestOrder($this->createTestClient(), $incotermCode);
    }

    public function testWonOrderCountsAndSumsFobByCurrency(): void
    {
        $orderId = $this->newOrder();
        $this->addProduct($orderId, 1500.00);
        $this->markWon($orderId, '2031-05-10');

        $report = ReportRepository::salesPerformanceReport('2031-05-01', '2031-05-31');

        self::assertSame(1, $report['current']['won']);
        self::assertSame(1500.0, $report['current']['fob_won_by_currency']['USD']);
    }

    public function testLostBeforePiIsDistinctFromLostAfterPi(): void
    {
        $beforePiOrder = $this->newOrder();
        $this->markLost($beforePiOrder, '2032-05-15 09:00:00', 'Price too high');

        $afterPiOrder = $this->newOrder();
        $this->markWon($afterPiOrder, '2032-05-05');
        $this->markLost($afterPiOrder, '2032-05-16 09:00:00', 'Buyer went silent after PI');

        $report = ReportRepository::salesPerformanceReport('2032-05-01', '2032-05-31');

        self::assertSame(1, $report['current']['lost_before_pi']);
        self::assertSame(1, $report['current']['lost_after_pi']);
        self::assertSame(2, $report['current']['lost_total']);

        $byId = [];
        foreach ($report['lost_orders'] as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertFalse($byId[$beforePiOrder]['reached_pi']);
        self::assertSame('Price too high', $byId[$beforePiOrder]['lost_reason']);
        self::assertTrue($byId[$afterPiOrder]['reached_pi']);
        self::assertSame('Buyer went silent after PI', $byId[$afterPiOrder]['lost_reason']);
    }

    public function testWinRatePctIsNullWithoutDivisionByZeroWhenNothingWasSent(): void
    {
        $report = ReportRepository::salesPerformanceReport('2099-01-01', '2099-01-02'); // empty window
        self::assertSame(0, $report['current']['quotations_sent']);
        self::assertNull($report['current']['win_rate_pct']);
        self::assertSame([], $report['current']['fob_won_by_currency']);
    }

    public function testWinRatePctComputesWhenQuotationsWereSent(): void
    {
        $wonOrder = $this->newOrder();
        $this->sendDocument($wonOrder, 'QT', '2033-06-05 09:00:00');
        $this->markWon($wonOrder, '2033-06-06');

        $lostOrder = $this->newOrder();
        $this->sendDocument($lostOrder, 'QT', '2033-06-07 09:00:00');
        $this->markLost($lostOrder, '2033-06-08 09:00:00', 'Went with a competitor');

        $report = ReportRepository::salesPerformanceReport('2033-06-01', '2033-06-30');

        self::assertSame(2, $report['current']['quotations_sent']);
        self::assertSame(1, $report['current']['won']);
        self::assertSame(50.0, $report['current']['win_rate_pct']);
    }

    public function testPreviousPeriodIsOnlyComputedWhenBothDatesAreGiven(): void
    {
        $reportOpenEnded = ReportRepository::salesPerformanceReport(null, '2034-06-30');
        self::assertNull($reportOpenEnded['previous']);
        self::assertNull($reportOpenEnded['comparison']);
        self::assertNull($reportOpenEnded['previous_range']);

        $reportBounded = ReportRepository::salesPerformanceReport('2034-06-01', '2034-06-30');
        self::assertNotNull($reportBounded['previous']);
        self::assertNotNull($reportBounded['comparison']);
        self::assertNotNull($reportBounded['previous_range']);
    }

    public function testPreviousPeriodIsTheImmediatelyPrecedingRangeOfEqualLength(): void
    {
        // 2035-06-01..2035-06-30 is 30 days, so the previous period must be
        // the 30 days immediately before: 2035-05-02..2035-05-31.
        $report = ReportRepository::salesPerformanceReport('2035-06-01', '2035-06-30');
        self::assertSame(['2035-05-02', '2035-05-31'], $report['previous_range']);
    }

    public function testComparisonPctChangeReflectsGrowthBetweenPeriods(): void
    {
        // Previous period: one won order.
        $prevOrder = $this->newOrder();
        $this->markWon($prevOrder, '2036-05-10');

        // Current period: two won orders (100% growth).
        $curOrder1 = $this->newOrder();
        $this->markWon($curOrder1, '2036-06-10');
        $curOrder2 = $this->newOrder();
        $this->markWon($curOrder2, '2036-06-11');

        $report = ReportRepository::salesPerformanceReport('2036-06-01', '2036-06-30');

        self::assertSame(1, $report['previous']['won']);
        self::assertSame(2, $report['current']['won']);
        self::assertSame(100.0, $report['comparison']['won']);
    }

    public function testComparisonIsNullForAMetricWhosePreviousValueWasZero(): void
    {
        $curOrder = $this->newOrder();
        $this->markWon($curOrder, '2037-06-10');

        // No won orders at all in the previous (May) period.
        $report = ReportRepository::salesPerformanceReport('2037-06-01', '2037-06-30');

        self::assertSame(0, $report['previous']['won']);
        self::assertSame(1, $report['current']['won']);
        self::assertNull($report['comparison']['won'], 'a 0 -> N change has no defined percentage and must be null, not an infinite/fake value');
    }

    public function testFobByCurrencyNeverSumsAcrossDifferentCurrencies(): void
    {
        $usdOrder = $this->newOrder();
        $this->addProduct($usdOrder, 1000.00);
        $this->markWon($usdOrder, '2031-07-10');

        $eurCurrencyId = (int) Database::connection()->query("SELECT id FROM currencies WHERE code = 'EUR'")->fetchColumn();
        $eurOrder = $this->newOrder();
        Database::connection()->prepare('UPDATE orders SET currency_id = :cid WHERE id = :id')
            ->execute(['cid' => $eurCurrencyId, 'id' => $eurOrder]);
        $this->addProduct($eurOrder, 2000.00);
        $this->markWon($eurOrder, '2031-07-11');

        $report = ReportRepository::salesPerformanceReport('2031-07-01', '2031-07-31');

        self::assertSame(1000.0, $report['current']['fob_won_by_currency']['USD']);
        self::assertSame(2000.0, $report['current']['fob_won_by_currency']['EUR']);
    }

    public function testOrdersCreatedCountIsScopedToTheDateRange(): void
    {
        $orderId = $this->newOrder();
        Database::connection()->prepare('UPDATE orders SET created_at = :created_at WHERE id = :id')
            ->execute(['created_at' => '2032-08-15 12:00:00', 'id' => $orderId]);

        $inRange = ReportRepository::salesPerformanceReport('2032-08-01', '2032-08-31');
        self::assertSame(1, $inRange['current']['orders_created']);

        $outOfRange = ReportRepository::salesPerformanceReport('2032-09-01', '2032-09-30');
        self::assertSame(0, $outOfRange['current']['orders_created']);
    }

    /** @return array{0:string,1:string}|null */
    private static function presetRange(string $preset): ?array
    {
        $reflection = new \ReflectionClass(ReportController::class);
        $method = $reflection->getMethod('presetRange');
        $method->setAccessible(true);

        // presetRange() computes off `new \DateTimeImmutable('today')`
        // internally, which we can't inject — so these tests only assert
        // the *shape* and internal *relationships* of the ranges (start <=
        // end, correct span), never a hardcoded date, so they stay valid
        // on whatever day this runs.
        return $method->invoke(null, $preset);
    }

    public function testThisMonthPresetSpansFirstToLastDayOfTheCurrentMonth(): void
    {
        [$from, $to] = self::presetRange('this_month');
        $fromDt = new \DateTimeImmutable($from);
        $toDt = new \DateTimeImmutable($to);

        self::assertSame('01', $fromDt->format('d'));
        self::assertSame($fromDt->format('Y-m'), $toDt->format('Y-m'));
        self::assertSame($toDt->format('t'), $toDt->format('d'), 'the end date must be the last calendar day of that month');
    }

    public function testLastMonthPresetEndsTheDayBeforeThisMonthStarts(): void
    {
        [, $lastMonthTo] = self::presetRange('last_month');
        [$thisMonthFrom] = self::presetRange('this_month');

        $lastMonthToDt = new \DateTimeImmutable($lastMonthTo);
        $thisMonthFromDt = new \DateTimeImmutable($thisMonthFrom);

        self::assertSame($thisMonthFromDt->modify('-1 day')->format('Y-m-d'), $lastMonthToDt->format('Y-m-d'));
    }

    public function testThisQuarterPresetSpansExactlyThreeCalendarMonths(): void
    {
        [$from, $to] = self::presetRange('this_quarter');
        $fromDt = new \DateTimeImmutable($from);
        $toDt = new \DateTimeImmutable($to);

        self::assertSame('01', $fromDt->format('d'));
        self::assertContains((int) $fromDt->format('n'), [1, 4, 7, 10], 'a quarter must start on Jan/Apr/Jul/Oct 1st');
        self::assertSame(2, (int) $fromDt->diff($toDt)->m, 'a calendar quarter spans 3 months (2 whole months after the start month)');
    }

    public function testThisYearPresetIsJan1ToDec31OfTheCurrentYear(): void
    {
        $currentYear = (new \DateTimeImmutable('today'))->format('Y');
        [$from, $to] = self::presetRange('this_year');

        self::assertSame($currentYear . '-01-01', $from);
        self::assertSame($currentYear . '-12-31', $to);
    }

    public function testLastYearPresetIsTheFullPriorCalendarYear(): void
    {
        $lastYear = (int) (new \DateTimeImmutable('today'))->format('Y') - 1;
        [$from, $to] = self::presetRange('last_year');

        self::assertSame($lastYear . '-01-01', $from);
        self::assertSame($lastYear . '-12-31', $to);
    }

    public function testUnknownPresetReturnsNull(): void
    {
        self::assertNull(self::presetRange('not_a_real_preset'));
    }
}
