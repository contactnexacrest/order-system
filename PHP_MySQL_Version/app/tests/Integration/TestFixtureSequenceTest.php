<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DbTestCase;

/** The shared order fixture gives each order of a client its own sequence number (uq_client_sequence). */
final class TestFixtureSequenceTest extends DbTestCase
{
    public function testOneClientCanHaveSeveralTestOrders(): void
    {
        $client = $this->createTestClient();
        $first = $this->createTestOrder($client);
        $second = $this->createTestOrder($client);
        $seq = \App\Config\Database::connection()->query("SELECT sequence_no FROM orders WHERE id IN ({$first}, {$second}) ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(1, (int) $seq[1] - (int) $seq[0]);
    }
}
