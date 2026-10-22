<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report U-14: "Lists with equal timestamps sort differently
 * on PHP vs Node (orders, payments, aggregate reports)." MySQL/MariaDB
 * gives no ordering guarantee among rows that tie on the ORDER BY column
 * alone, and the two stacks' storage engines/optimizers broke that tie
 * differently in practice. Adding `id DESC` (the row's own insertion
 * order, identical meaning on both stacks) as a second sort key removes
 * the ambiguity entirely — this locks in that real listing queries
 * actually return newest-id-first among same-timestamp rows, not just
 * that the SQL text happens to mention a tie-breaker.
 */
final class OrderingTieBreakerTest extends DbTestCase
{
    private function setCreatedAt(string $table, int $id, string $timestamp): void
    {
        \App\Config\Database::connection()
            ->prepare("UPDATE {$table} SET created_at = :ts WHERE id = :id")
            ->execute(['ts' => $timestamp, 'id' => $id]);
    }

    public function testOrdersListBreaksTiesOnIdDescending(): void
    {
        $olderId = $this->createTestOrder($this->createTestClient());
        $newerId = $this->createTestOrder($this->createTestClient());
        $tiedTimestamp = '2026-01-01 12:00:00';
        $this->setCreatedAt('orders', $olderId, $tiedTimestamp);
        $this->setCreatedAt('orders', $newerId, $tiedTimestamp);

        $ids = array_column(OrderRepository::all(), 'id');
        $posOlder = array_search($olderId, $ids, true);
        $posNewer = array_search($newerId, $ids, true);

        self::assertNotFalse($posOlder);
        self::assertNotFalse($posNewer);
        self::assertLessThan($posOlder, $posNewer, 'among orders tied on created_at, the higher id must sort first, deterministically');
    }

    public function testClientPaymentReportsBreakTiesOnIdDescending(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $firstId = ClientPaymentReportRepository::create($orderId, 'advance', 'UTR-A', null, null, null, null);
        $secondId = ClientPaymentReportRepository::create($orderId, 'advance', 'UTR-B', null, null, null, null);
        $tiedTimestamp = '2026-01-01 12:00:00';
        \App\Config\Database::connection()
            ->prepare('UPDATE client_payment_reports SET reported_at = :ts WHERE id IN (:a, :b)')
            ->execute(['ts' => $tiedTimestamp, 'a' => $firstId, 'b' => $secondId]);

        $ids = array_column(ClientPaymentReportRepository::forOrder($orderId), 'id');

        self::assertSame([$secondId, $firstId], $ids, 'among reports tied on reported_at, the higher id must sort first, deterministically');
    }
}
