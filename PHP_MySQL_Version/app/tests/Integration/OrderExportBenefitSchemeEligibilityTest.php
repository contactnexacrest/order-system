<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderFinancialsController;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderExportBenefitSchemeRepository;
use App\Tests\Support\DbTestCase;

/**
 * Export-benefit scheme eligibility (docs/schema.sql's
 * order_export_benefit_schemes, added 2026-10-24d) — the same
 * "never a missing row standing in for not applicable" fix as the
 * Compliance Checklist, applied to RODTEP/Duty Drawback/RoSCTL/Other
 * scheme eligibility. Separate from CaExportBenefitRepository, which
 * records a real claim and is untouched by this.
 */
final class OrderExportBenefitSchemeEligibilityTest extends DbTestCase
{
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->orderId = $this->createTestOrder($this->createTestClient());
    }

    public function testInitializeForOrderWritesARealRowForEveryActiveSchemeAtCreation(): void
    {
        // Read the raw table directly, bypassing forOrder()'s COALESCE
        // entirely — a report against the raw table must never see "no
        // row" standing in for "not applicable."
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT status FROM order_export_benefit_schemes WHERE order_id = :order_id AND scheme_name = 'RODTEP'");
        $stmt->execute(['order_id' => $this->orderId]);
        $row = $stmt->fetch();

        self::assertNotFalse($row, 'a real row must exist from order creation, not just the LEFT JOIN default');
        self::assertSame('pending', $row['status']);
    }

    public function testForOrderListsEveryActiveSchemeDefaultingToPending(): void
    {
        $rows = OrderExportBenefitSchemeRepository::forOrder($this->orderId);
        $names = array_column($rows, 'scheme_name');

        self::assertContains('RODTEP', $names);
        self::assertContains('Duty Drawback', $names);
        foreach ($rows as $r) {
            self::assertSame('pending', $r['status']);
            self::assertNull($r['resolved_at']);
        }
    }

    public function testSetStatusThenForOrderReflectsTheNewStatus(): void
    {
        $userId = $this->createTestUser('Admin');
        OrderExportBenefitSchemeRepository::setStatus($this->orderId, 'RODTEP', 'eligible', null, $userId);

        $row = $this->findRow(OrderExportBenefitSchemeRepository::forOrder($this->orderId), 'RODTEP');
        self::assertSame('eligible', $row['status']);
        self::assertNotNull($row['resolved_at']);
        self::assertSame('PHPUnit Test User', $row['resolved_by_name']);
    }

    public function testSetStatusTwiceUpsertsRatherThanDuplicating(): void
    {
        $userId = $this->createTestUser('Admin');
        OrderExportBenefitSchemeRepository::setStatus($this->orderId, 'RODTEP', 'pending', null, $userId);
        OrderExportBenefitSchemeRepository::setStatus($this->orderId, 'RODTEP', 'eligible', null, $userId);

        $matches = array_filter(
            OrderExportBenefitSchemeRepository::forOrder($this->orderId),
            fn (array $r): bool => $r['scheme_name'] === 'RODTEP'
        );
        self::assertCount(1, $matches, 'one row per order+scheme pair — upsert, not insert');
    }

    public function testSummaryCountsEligibleAndNotEligibleAsResolved(): void
    {
        $userId = $this->createTestUser('Admin');
        $totalActiveSchemes = count(OrderExportBenefitSchemeRepository::forOrder($this->orderId));

        OrderExportBenefitSchemeRepository::setStatus($this->orderId, 'RODTEP', 'eligible', null, $userId);
        OrderExportBenefitSchemeRepository::setStatus($this->orderId, 'Duty Drawback', 'not_eligible', 'Product not covered under this scheme', $userId);

        $summary = OrderExportBenefitSchemeRepository::summaryForOrder($this->orderId);
        self::assertSame($totalActiveSchemes, $summary['total']);
        self::assertSame(2, $summary['resolved']);
        self::assertSame($totalActiveSchemes - 2, $summary['pending']);
    }

    public function testControllerRejectsNotEligibleWithoutAReason(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postUpdate(['scheme_name' => 'RODTEP', 'status' => 'not_eligible']);

        $row = $this->findRow(OrderExportBenefitSchemeRepository::forOrder($this->orderId), 'RODTEP');
        self::assertSame('pending', $row['status'], 'not_eligible requires a reason — must not silently succeed without one');
    }

    public function testControllerAcceptsNotEligibleWithAReasonAndAuditLogsIt(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postUpdate([
            'scheme_name' => 'RODTEP',
            'status' => 'not_eligible',
            'reason' => 'Buyer country excluded from this scheme',
        ]);

        $row = $this->findRow(OrderExportBenefitSchemeRepository::forOrder($this->orderId), 'RODTEP');
        self::assertSame('not_eligible', $row['status']);
        self::assertSame('Buyer country excluded from this scheme', $row['reason']);

        $logRows = array_values(array_filter(
            AuditLogRepository::forOrder($this->orderId),
            static fn (array $r): bool => $r['action_type'] === 'EXPORT_BENEFIT_SCHEME_STATUS_UPDATED'
        ));
        self::assertCount(1, $logRows);
        self::assertSame($userId, (int) $logRows[0]['user_id']);
    }

    public function testControllerAcceptsEligibleWithNoReasonRequired(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postUpdate(['scheme_name' => 'RODTEP', 'status' => 'eligible']);

        $row = $this->findRow(OrderExportBenefitSchemeRepository::forOrder($this->orderId), 'RODTEP');
        self::assertSame('eligible', $row['status']);
    }

    public function testControllerRejectsSettingStatusBackToPending(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postUpdate(['scheme_name' => 'RODTEP', 'status' => 'pending']);

        $row = $this->findRow(OrderExportBenefitSchemeRepository::forOrder($this->orderId), 'RODTEP');
        self::assertSame('pending', $row['status']);
        self::assertCount(
            0,
            array_filter(AuditLogRepository::forOrder($this->orderId), static fn (array $r): bool => $r['action_type'] === 'EXPORT_BENEFIT_SCHEME_STATUS_UPDATED'),
            '"pending" is not a real decision — the form must not accept re-submitting it as one'
        );
    }

    /** @param array<string, string> $fields */
    private function postUpdate(array $fields): void
    {
        $_POST = $fields;
        ob_start();
        (new OrderFinancialsController())->setExportBenefitSchemeStatus(['id' => (string) $this->orderId]);
        ob_end_clean();
    }

    /** @param array<int, array<string,mixed>> $rows @return array<string,mixed> */
    private function findRow(array $rows, string $schemeName): array
    {
        foreach ($rows as $r) {
            if ($r['scheme_name'] === $schemeName) {
                return $r;
            }
        }
        self::fail("No row found for scheme {$schemeName}");
    }
}
