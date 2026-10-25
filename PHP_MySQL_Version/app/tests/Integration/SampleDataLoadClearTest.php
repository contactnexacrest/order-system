<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Services\SampleDataService;
use App\Tests\Support\DbTestCase;

/**
 * "Clear sample data" failed on every installation: SampleDataRepository::
 * clearAll() never deleted order_assignees, order_compliance_tasks and ~16
 * other order-scoped tables added after it was written, so its final
 * DELETE FROM orders hit a foreign key — and every sample order has an
 * assignee and compliance tasks. Also pins the sample PI intakes matching
 * where their orders actually are (owner report 2026-10-25: Order B was at
 * Stage 8 with its Step 5 intake still pending).
 */
final class SampleDataLoadClearTest extends DbTestCase
{
    protected function tearDown(): void
    {
        if (SampleDataService::isLoaded()) {
            SampleDataService::clear();
        }
        parent::tearDown();
    }

    public function testLoadedSampleDataClearsCompletely(): void
    {
        $userId = $this->createTestUser('Admin');
        SampleDataService::load($userId);
        self::assertTrue(SampleDataService::isLoaded());

        $result = SampleDataService::clear();

        self::assertSame(10, $result['orders']);
        $pdo = Database::connection();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE is_sample_data = 1')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM clients WHERE is_sample_data = 1')->fetchColumn());
        self::assertFalse(SampleDataService::isLoaded());
    }

    public function testSamplePiIntakesMatchTheirOrdersProgress(): void
    {
        SampleDataService::load($this->createTestUser('Admin'));

        $rows = Database::connection()->query(
            "SELECT p.status, sm.stage_number
             FROM pi_intake_submissions p
             JOIN orders o ON o.id = p.order_id
             JOIN stages_master sm ON sm.id = o.current_stage_id
             WHERE o.is_sample_data = 1
             ORDER BY sm.stage_number"
        )->fetchAll();

        self::assertCount(2, $rows);
        self::assertSame(['pending_review', 3], [$rows[0]['status'], (int) $rows[0]['stage_number']], 'the pending one sits on an order at Step 5 (PI)');
        self::assertSame(['applied', 8], [$rows[1]['status'], (int) $rows[1]['stage_number']], 'an order far past Step 5 has its intake applied');
    }
}
