<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Repositories\IncentiveRepository;
use App\Services\CaFinancialSnapshotService;
use App\Services\DocumentGenerationService;
use App\Services\IncentiveService;
use App\Services\OrderProfitabilityService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 4 Nov 2026 (D-245, CAFIN change confirmed twice): under
 * E. PROFIT the totals stay locked & paid figures only; while the order is not
 * locked, the estimated associate commission and team incentive are shown in
 * their own box, never added to the totals. Node twin: cafinEstimatedBox.test.js.
 */
final class CafinEstimatedBoxTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->admin;
        IncentiveRepository::setSetting('program_enabled', '1', $this->admin);
        $this->sales = $this->createTestUser('Export Executive');
        IncentiveRepository::replaceUserRoles([$this->sales => ['sales']]);
    }

    private int $sales;

    protected function tearDown(): void
    {
        IncentiveRepository::replaceUserRoles([]);
        IncentiveRepository::setSetting('program_enabled', '0', $this->admin);
        $_SESSION = [];
        parent::tearDown();
    }

    /** A USD order: products USD 4,500 fully paid (₹1,50,000 + ₹2,50,000). */
    private function paidOrder(): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->sales]);
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare("UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = '2026-10-10 10:00:00', advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
            balance_amount = 2700, balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = 250000, balance_exchange_rate = 92.5926 WHERE order_id = :o")->execute(['o' => $orderId]);
        return $orderId;
    }

    private static function grand(array $s): array
    {
        return array_values(array_filter($s['pl'], static fn (array $r): bool => $r[1] === 'ORDER PROFIT'))[0];
    }

    public function testTheEstimatedIncentiveIsShownApartAndTheTotalsDoNotChange(): void
    {
        $orderId = $this->paidOrder();
        IncentiveRepository::saveOrder($orderId, ['incentive_mode' => 'fixed', 'incentive_value' => 20000], $this->admin);

        $s = CaFinancialSnapshotService::build($orderId);
        $profit = OrderProfitabilityService::computeForOrder($orderId, false);
        $grand = self::grand($s);
        self::assertSame([number_format($profit['profit_inr'], 2), 'locked & paid figures only'], [$grand[3], $grand[2]], 'the totals are unchanged');
        self::assertNotNull($s['pl_estimated']);
        self::assertNull($s['pl_estimated']['commission'], 'no associate on this order');
        self::assertSame('20,000.00', $s['pl_estimated']['incentive']);
        self::assertSame(number_format($profit['profit_inr'] - 20000, 2), $s['pl_estimated']['after']);
        // Same figures as the order page's profit layer.
        $layer = OrderProfitabilityService::computeForOrder($orderId);
        self::assertSame(number_format($layer['profit_after_incentive_inr'], 2), $s['pl_estimated']['after']);

        // The PDF itself.
        $result = DocumentGenerationService::generateCaInternalAnnexure($orderId, $this->admin);
        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        $text = (string) preg_replace('/\s+/', ' ', (string) shell_exec('pdftotext -layout ' . escapeshellarg((string) $file['server_path']) . ' -'));
        @unlink((string) $file['server_path']);
        self::assertStringContainsString('locked & paid figures only', $text);
        self::assertStringContainsString('Not yet locked — estimated, NOT included in the totals above', $text);
        self::assertMatchesRegularExpression('/Team incentive Estimated 20,000\.00/u', $text);
        self::assertStringNotContainsString('Associate commission Estimated', $text);
        self::assertStringContainsString('Profit if these lock as estimated ' . $s['pl_estimated']['after'], $text);
        self::assertStringContainsString('this box disappears', $text);

        // Locked: the amount moves into C3 and the totals; the box disappears.
        IncentiveService::lock($orderId, '2026-10-25', $this->admin);
        $locked = CaFinancialSnapshotService::build($orderId);
        self::assertNull($locked['pl_estimated']);
        self::assertSame(number_format($profit['profit_inr'] - 20000, 2), self::grand($locked)[3], 'locked incentive is now inside the totals, once');
    }

    public function testNoBoxWhenNothingIsEstimated(): void
    {
        IncentiveRepository::setSetting('program_enabled', '0', $this->admin);
        $orderId = $this->paidOrder();
        $s = CaFinancialSnapshotService::build($orderId);
        self::assertNull($s['pl_estimated'], 'program off, no associate, no order incentive');

        $result = DocumentGenerationService::generateCaInternalAnnexure($orderId, $this->admin);
        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg((string) $file['server_path']) . ' -');
        @unlink((string) $file['server_path']);
        self::assertStringNotContainsString('Not yet locked', $text);
        self::assertStringContainsString('locked & paid figures only', $text);
    }
}
