<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AmendmentRepository;
use App\Repositories\CaRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ReportRepository;
use App\Services\AmendmentService;
use App\Tests\Support\DbTestCase;

/**
 * QA-4 P1 (docs/QA/TEST_PLAN.md Section 6): financial data integrity — the
 * math itself, not authorization/state gates (that's P0's job). Each test
 * pins a formula that already exists in the code against a hand-computed
 * expected value, rather than re-deriving the formula and comparing it to
 * itself.
 */
final class FinancialIntegrityTest extends DbTestCase
{
    // ---------------------------------------------------------------
    // 1. Amendment overrides must never leave advance/balance out of sync
    //    with each other — AmendmentService::attachSignedCopyAndActivate()
    //    always derives balance_pct = 100 - advance_pct (AmendmentService.php
    //    ~line 194), rather than trusting a separately-submitted balance
    //    percentage that could drift from it.
    // ---------------------------------------------------------------

    public function testAmendmentActivationAlwaysLeavesAdvanceAndBalancePctSummingToOneHundred(): void
    {
        foreach ([0.0, 30.0, 55.5, 100.0] as $amendedAdvancePct) {
            $orderId = $this->createTestOrder($this->createTestClient());
            $amendmentId = AmendmentRepository::create(
                'AMD-TEST-' . bin2hex(random_bytes(4)),
                $orderId,
                'PHPUnit financial-integrity amendment',
                'importer',
                ['note' => 'snapshot'],
                $amendedAdvancePct, null, null, null, null, null, null
            );
            AmendmentService::approveByMd($amendmentId, 1);
            $documentId = $this->createTestDocument($orderId, 'QT');
            AmendmentRepository::attachDocument($amendmentId, $documentId);
            $fileId = $this->createTestFile($orderId);

            AmendmentService::attachSignedCopyAndActivate($amendmentId, $fileId, 1);

            $order = OrderRepository::find($orderId);
            self::assertEqualsWithDelta($amendedAdvancePct, (float) $order['advance_pct'], 0.001, "advance_pct must match the amendment for {$amendedAdvancePct}%");
            self::assertEqualsWithDelta(100.0, (float) $order['advance_pct'] + (float) $order['balance_pct'], 0.001, "advance_pct + balance_pct must sum to 100 for {$amendedAdvancePct}% advance");
        }
    }

    // ---------------------------------------------------------------
    // 2. CA settlement register's forex gain/loss
    //    (CaRepository::settlementRegister(), ~line 51-52): expected_inr =
    //    foreign_amount * assumed_exchange_rate; forex_gain_loss =
    //    inr_actual - expected_inr. Pinned against a hand-computed value.
    // ---------------------------------------------------------------

    public function testCaSettlementRegisterForexGainLossMatchesHandComputedValue(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Accounts Executive');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.00, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-15', $userId);
        OrderPaymentStatusRepository::setAssumedExchangeRate($orderId, 90.5, $userId);
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 91200.00, $userId);

        $row = $this->findSettlementRow($orderId, 'advance');

        self::assertNotNull($row, 'the cleared advance leg must appear in the settlement register');
        // Hand-computed: expected_inr = 1000.00 * 90.5 = 90500.00
        self::assertEqualsWithDelta(90500.00, $row['expected_inr'], 0.001);
        // Hand-computed: forex_gain_loss = 91200.00 - 90500.00 = 700.00
        self::assertEqualsWithDelta(700.00, $row['forex_gain_loss'], 0.001);
    }

    public function testCaSettlementRegisterForexLossIsNegativeWhenInrActualIsBelowExpected(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Accounts Executive');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 2000.00, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-15', $userId);
        OrderPaymentStatusRepository::setAssumedExchangeRate($orderId, 85.0, $userId);
        // Hand-computed: expected_inr = 2000.00 * 85.0 = 170000.00; actual came
        // in lower, a real forex loss.
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 168500.00, $userId);

        $row = $this->findSettlementRow($orderId, 'advance');

        self::assertNotNull($row);
        self::assertEqualsWithDelta(170000.00, $row['expected_inr'], 0.001);
        self::assertEqualsWithDelta(-1500.00, $row['forex_gain_loss'], 0.001);
    }

    // ---------------------------------------------------------------
    // 3. Payments report aggregation (ReportRepository::paymentsReport(),
    //    ~line 355-410) — outstanding = invoiced - cleared, per row and
    //    per currency. Measured as a before/after delta rather than an
    //    absolute total, since the disposable test DB accumulates orders
    //    from every other Integration test class run in this same
    //    process/currency (all via the shared USD-default fixture) — a
    //    delta is immune to that shared state, an absolute total is not.
    // ---------------------------------------------------------------

    public function testPaymentsReportOutstandingDeltaMatchesHandComputedValue(): void
    {
        $before = ReportRepository::paymentsReport(null, null)['by_currency']['USD']
            ?? ['advance_invoiced' => 0.0, 'advance_cleared' => 0.0, 'balance_invoiced' => 0.0, 'balance_cleared' => 0.0];

        $orderId = $this->createTestOrder($this->createTestClient()); // USD by default
        $userId = $this->createTestUser('Accounts Executive');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        // Advance: invoiced 1000, cleared in full -> 0 outstanding.
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.00, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-15', $userId);
        // Balance: invoiced 1500, never cleared -> fully outstanding.
        OrderPaymentStatusRepository::recordBalanceReceived($orderId, 1500.00, '2026-02-01');

        $after = ReportRepository::paymentsReport(null, null)['by_currency']['USD'];

        self::assertEqualsWithDelta(1000.00, $after['advance_invoiced'] - $before['advance_invoiced'], 0.001);
        self::assertEqualsWithDelta(1000.00, $after['advance_cleared'] - $before['advance_cleared'], 0.001);
        self::assertEqualsWithDelta(1500.00, $after['balance_invoiced'] - $before['balance_invoiced'], 0.001);
        self::assertEqualsWithDelta(0.0, $after['balance_cleared'] - $before['balance_cleared'], 0.001);
        self::assertEqualsWithDelta(0.0, $after['advance_outstanding'] - $before['advance_outstanding'], 0.001, 'the cleared advance must contribute nothing to outstanding');
        self::assertEqualsWithDelta(1500.00, $after['balance_outstanding'] - $before['balance_outstanding'], 0.001, 'the uncleared balance must be fully outstanding');

        // Row-level check (no shared-state contamination risk — matched by
        // this order's own id): advance_outstanding must be 0 once cleared,
        // balance_outstanding must equal the full invoiced amount while
        // uncleared.
        $row = $this->findPaymentsReportRow($orderId);
        self::assertNotNull($row);
        self::assertEqualsWithDelta(0.0, (float) $row['advance_outstanding'], 0.001);
        self::assertEqualsWithDelta(1500.00, (float) $row['balance_outstanding'], 0.001);
    }

    // ---------------------------------------------------------------
    // 4. Audit log must be genuinely append-only — no route anywhere
    //    issues an UPDATE or DELETE against audit_log. Static source scan
    //    rather than a DB-level test, since the invariant is "no code path
    //    exists," not "a given code path behaves a certain way."
    // ---------------------------------------------------------------

    public function testNoSourceFileEverIssuesAnUpdateOrDeleteAgainstAuditLog(): void
    {
        $srcDir = __DIR__ . '/../../src';
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/\b(UPDATE|DELETE\s+FROM)\s+audit_log\b/i', $contents)) {
                $offenders[] = $file->getPathname();
            }
        }
        self::assertSame([], $offenders, 'audit_log must never be UPDATEd or DELETEd from — found in: ' . implode(', ', $offenders));
    }

    private function findSettlementRow(int $orderId, string $leg): ?array
    {
        foreach (CaRepository::settlementRegister() as $row) {
            if ($row['order_id'] === $orderId && $row['leg'] === $leg) {
                return $row;
            }
        }
        return null;
    }

    private function findPaymentsReportRow(int $orderId): ?array
    {
        foreach (ReportRepository::paymentsReport(null, null)['rows'] as $row) {
            if ((int) $row['id'] === $orderId) {
                return $row;
            }
        }
        return null;
    }
}
