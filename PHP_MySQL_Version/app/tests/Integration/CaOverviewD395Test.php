<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CaController;
use App\Repositories\OrderPaymentStatusRepository;
use App\Services\CaOverviewService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-395 (20 Nov 2026, mockup Option B): the CA / Accounting home page
 * shows "Needs attention" boxes first, then four section cards, then the INR Settlement
 * Register with a summary row and quick filters. Read only; each box only for a person
 * who may open the page it leads to.
 *
 * Node twin: NODE_MySQL_Version/app/tests/integration/caOverviewD395.test.js
 */
final class CaOverviewD395Test extends DbTestCase
{
    private const ZOHO_ID_PREFIX = 'PHPUNIT-D395-';

    protected function tearDown(): void
    {
        unset($_SESSION['_auth_user_id'], $_GET['show']);
        Database::connection()->prepare('DELETE FROM ca_expenses WHERE zoho_expense_id LIKE ?')->execute([self::ZOHO_ID_PREFIX . '%']);
        parent::tearDown();
    }

    /** @return array<string,mixed> a register row as CaRepository::settlementRegister() builds it */
    private static function row(array $over): array
    {
        return array_merge([
            'order_id' => 1, 'leg' => 'advance', 'inr_actual' => null, 'inr_estimated' => null,
            'forex_gain_loss' => null, 'firc_pending' => false, 'zoho_synced_at' => null,
        ], $over);
    }

    public function testSummaryCountsAndTotalsTheRowsShown(): void
    {
        $rows = [
            self::row(['inr_actual' => 100000.00, 'forex_gain_loss' => 1500.00, 'zoho_synced_at' => '2026-11-01 10:00:00']),
            self::row(['inr_actual' => 50000.50, 'forex_gain_loss' => -500.25, 'firc_pending' => true]),
            self::row(['inr_estimated' => 84100.00]),
        ];
        $s = CaOverviewService::registerSummary($rows);

        self::assertSame(3, $s['legs']);
        self::assertSame(150000.50, $s['inr_credited'], 'only rupees actually credited, not estimates');
        self::assertSame(999.75, $s['forex_net'], 'gains and losses net off');
        self::assertTrue($s['forex_known']);
        self::assertSame(1, $s['firc_pending']);
        self::assertSame(1, $s['not_in_zoho'], 'rupees recorded but not synced; an estimate is not counted');
        self::assertSame(1, $s['estimated']);
    }

    public function testNoForexFigureWhenNoLegHasOne(): void
    {
        $s = CaOverviewService::registerSummary([self::row(['inr_estimated' => 100.0])]);
        self::assertFalse($s['forex_known']);
        self::assertSame(0.0, $s['inr_credited']);
    }

    public function testQuickFiltersKeepOnlyTheirRows(): void
    {
        $rows = [
            self::row(['order_id' => 1, 'inr_actual' => 10.0, 'zoho_synced_at' => '2026-11-01 10:00:00']),
            self::row(['order_id' => 2, 'inr_actual' => 10.0, 'firc_pending' => true]),
            self::row(['order_id' => 3, 'inr_estimated' => 10.0]),
        ];
        $ids = static fn(array $r): array => array_column($r, 'order_id');

        self::assertSame([1, 2, 3], $ids(CaOverviewService::filterRegister($rows, 'all')));
        self::assertSame([2], $ids(CaOverviewService::filterRegister($rows, 'firc')));
        self::assertSame([2], $ids(CaOverviewService::filterRegister($rows, 'zoho')));
        self::assertSame([3], $ids(CaOverviewService::filterRegister($rows, 'estimated')));
        self::assertSame('all', CaOverviewService::normaliseFilter('anything-else'));
        self::assertSame('all', CaOverviewService::normaliseFilter(null));
        self::assertSame('firc', CaOverviewService::normaliseFilter('firc'));
    }

    public function testTdsIsDepositedByTheSeventhOfTheNextMonthAndMarchByThirtiethApril(): void
    {
        self::assertSame('2026-12-07', CaOverviewService::tdsDueDate('2026-11'));
        self::assertSame('2027-01-07', CaOverviewService::tdsDueDate('2026-12'));
        self::assertSame('2027-04-30', CaOverviewService::tdsDueDate('2027-03'));
        self::assertSame('2027-05-07', CaOverviewService::tdsDueDate('2027-04'));
    }

    public function testTdsForMonthAddsOnlyTdsApplicableExpensesOfThatMonth(): void
    {
        $pdo = Database::connection();
        $ins = $pdo->prepare(
            "INSERT INTO ca_expenses (zoho_expense_id, category, description, vendor_name, amount, currency_code, expense_date, is_tds_applicable, tds_amount)
             VALUES (?, 'CHA', 'D-395 test', 'TEST vendor', 10000, 'INR', ?, ?, ?)"
        );
        $ins->execute([self::ZOHO_ID_PREFIX . '1', '2031-07-05', 1, 1000.00]);
        $ins->execute([self::ZOHO_ID_PREFIX . '2', '2031-07-20', 1, 250.50]);
        $ins->execute([self::ZOHO_ID_PREFIX . '3', '2031-07-21', 0, 999.00]); // not TDS-applicable
        $ins->execute([self::ZOHO_ID_PREFIX . '4', '2031-08-01', 1, 777.00]); // next month

        $tds = CaOverviewService::tdsForMonth('2031-07-15');
        self::assertSame(1250.50, $tds['amount']);
        self::assertSame('2031-08-07', $tds['due']);
    }

    public function testBoxesAppearOnlyForThoseWhoMayOpenTheirPage(): void
    {
        $summary = CaOverviewService::registerSummary([]);
        $keys = static fn(array $boxes): array => array_column($boxes, 'key');

        self::assertSame(['firc', 'bank', 'zoho', 'tds', 'benefits'], $keys(CaOverviewService::attention($summary, true, true, '2026-11-20')));
        self::assertSame(['firc', 'bank', 'tds', 'benefits'], $keys(CaOverviewService::attention($summary, false, true, '2026-11-20')), 'Zoho needs CA Manage');
        self::assertSame(['firc', 'zoho', 'benefits'], $keys(CaOverviewService::attention($summary, true, false, '2026-11-20')), 'bank and TDS need the All level');

        $tds = CaOverviewService::attention($summary, false, true, '2026-11-20')[2];
        self::assertStringContainsString('deposit by 7 Dec 2026', $tds['label']);
        self::assertSame('/ca/tds-summary', $tds['href']);
    }

    public function testATonedBoxTurnsGreenWhenNothingIsWaiting(): void
    {
        $boxes = CaOverviewService::attention(['firc_pending' => 0, 'not_in_zoho' => 3], true, false, '2026-11-20');
        $byKey = array_column($boxes, null, 'key');
        self::assertSame('ok', $byKey['firc']['tone']);
        self::assertSame('warn', $byKey['zoho']['tone']);
        self::assertSame('3', $byKey['zoho']['value']);
    }

    private function renderHub(int $userId, ?string $show = null): string
    {
        $_SESSION['_auth_user_id'] = $userId;
        if ($show !== null) {
            $_GET['show'] = $show;
        }
        ob_start();
        (new CaController())->index([]);
        return (string) ob_get_clean();
    }

    public function testTheManagingDirectorSeesEveryBoxTheCardsAndTheRegisterSummary(): void
    {
        $userId = $this->createTestUser('Managing Director');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.00, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-15', $userId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 90.5, $userId);
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 91200.00, $userId);

        $html = $this->renderHub($userId);

        self::assertStringContainsString('Needs attention', $html);
        foreach (['firc', 'bank', 'zoho', 'tds', 'benefits'] as $key) {
            self::assertStringContainsString('data-ca-box="' . $key . '"', $html);
        }
        foreach (['Revenue &amp; benefits', 'Expenses &amp; TDS', 'Bank &amp; reconciliation', 'Zoho Books &amp; year lock'] as $card) {
            self::assertStringContainsString($card, $html);
        }
        self::assertStringContainsString('href="/ca/zoho-sync"', $html);
        self::assertStringContainsString('id="register"', $html);
        self::assertStringContainsString('data-ca-summary', $html);
        self::assertStringContainsString('legs cleared', $html);
        self::assertMatchesRegularExpression('#class="btn-sm btn-secondary is-active" href="/ca\#register"#', $html, 'All is the active filter by default');
    }

    public function testAFilterShowsOnlyItsLegsAndIsMarkedActive(): void
    {
        $userId = $this->createTestUser('Managing Director');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.00, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-15', $userId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 77.7777, $userId); // estimate only: no INR credited

        $ref = (string) Database::connection()->query('SELECT buyer_inquiry_ref FROM orders WHERE id = ' . $orderId)->fetchColumn();

        $estimated = $this->renderHub($userId, 'estimated');
        self::assertStringContainsString('class="btn-sm btn-secondary is-active" href="/ca?show=estimated#register"', $estimated);
        self::assertStringContainsString('77.7777', $estimated, 'the estimated leg is listed under Estimated only');

        $notInZoho = $this->renderHub($userId, 'zoho');
        self::assertStringNotContainsString('77.7777', $notInZoho, 'an estimate has no rupees recorded, so it is not "not in Zoho"');
        self::assertNotSame('', $ref);
    }

    public function testTheCaWithoutManageSeesNoZohoBoxAndNoZohoCard(): void
    {
        $userId = $this->createTestUser('CA / Chartered Accountant');
        $html = $this->renderHub($userId);

        self::assertStringContainsString('Needs attention', $html);
        self::assertStringNotContainsString('data-ca-box="zoho"', $html);
        self::assertStringNotContainsString('href="/ca/zoho-sync"', $html);
        self::assertStringNotContainsString('href="/ca/fy-locks"', $html);
        self::assertStringContainsString('data-ca-box="firc"', $html);
        self::assertStringContainsString('data-ca-box="benefits"', $html);
    }
}
