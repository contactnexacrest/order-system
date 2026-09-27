<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DbTestCase;

/**
 * QA-5 (CONC-01/CONC-02/CONC-03 — external QA report cross-verification):
 * ReferenceNumberService::nextSeq() used to increment its counter and read
 * the result back in two separate statements. Under autocommit, the
 * increment's row lock releases the instant it commits — before the
 * follow-up SELECT runs — so a third concurrent caller's own increment
 * could land in that gap, and two different callers could then both read
 * that same newer value back. orders.sequence_no had the same class of bug
 * one level up (a plain SELECT MAX()+1, no lock at all). Both are now
 * fixed with LAST_INSERT_ID(expr) / a single atomic UPSERT — see
 * ReferenceNumberService's docblocks.
 *
 * This spawns real, separate OS processes (proc_open, not just async
 * callbacks inside one PHPUnit process) so several of them genuinely hit
 * the same database at the same instant — the only way to exercise true
 * concurrent MySQL connections from PHP's process-per-request model.
 */
final class ReferenceNumberConcurrencyTest extends DbTestCase
{
    public function testClientUniqueNumberIsNeverHandedOutTwiceUnderConcurrentGeneration(): void
    {
        $outputs = $this->runConcurrentWorkers(20, ['client_unique']);

        self::assertCount(20, array_unique($outputs), 'every concurrently-generated client_unique_number must be distinct: ' . implode(', ', $outputs));
    }

    public function testOrderSequenceNumberIsNeverHandedOutTwiceForOneClientUnderConcurrentGeneration(): void
    {
        $clientId = $this->createTestClient();

        $outputs = $this->runConcurrentWorkers(20, ['order_seq', (string) $clientId]);

        self::assertCount(20, array_unique($outputs), 'every concurrently-generated order sequence number for one client must be distinct: ' . implode(', ', $outputs));
    }

    /**
     * @param array<int,string> $workerArgs
     * @return array<int,string> each worker's stdout
     */
    private function runConcurrentWorkers(int $count, array $workerArgs): array
    {
        $script = __DIR__ . '/../Support/concurrencyWorker.php';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $processes = [];
        for ($i = 0; $i < $count; $i++) {
            $cmd = array_merge(['php', $script], $workerArgs);
            $proc = proc_open($cmd, $descriptors, $pipes);
            self::assertIsResource($proc, 'could not start concurrency worker process');
            fclose($pipes[0]);
            $processes[] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }

        $outputs = [];
        foreach ($processes as $p) {
            $out = stream_get_contents($p['stdout']);
            $err = stream_get_contents($p['stderr']);
            fclose($p['stdout']);
            fclose($p['stderr']);
            $exitCode = proc_close($p['proc']);
            self::assertSame(0, $exitCode, "concurrency worker failed (exit {$exitCode}): {$err}");
            $outputs[] = trim((string) $out);
        }

        return $outputs;
    }
}
