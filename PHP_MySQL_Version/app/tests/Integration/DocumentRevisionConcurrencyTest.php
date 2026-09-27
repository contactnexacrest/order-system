<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (CONC-04 — external QA report cross-verification): revision_number
 * was computed with a plain `$existing ? $existing['revision_number'] + 1 : 0`
 * read, and the actual INSERT didn't land until AFTER the PDF (and,
 * optionally, DOCX) had been fully rendered — a much longer unlocked
 * window than the other CONC races, since rendering is not fast. There is
 * no UNIQUE constraint on (order_id, document_type_id, revision_number)
 * either, so two concurrent regenerations of the same document type for
 * the same order didn't even fail loudly — they silently left two
 * `documents` rows sharing one revision number. Fixed by reserving the
 * number atomically up front
 * (ReferenceNumberService::nextDocumentRevisionNumber()) before any
 * rendering starts.
 *
 * Spawns real, separate OS processes (proc_open) so several of them
 * genuinely generate a document for the same order at once — PHP's
 * process-per-request model can't produce true concurrency from async
 * callbacks inside one PHPUnit process the way Node can.
 */
final class DocumentRevisionConcurrencyTest extends DbTestCase
{
    public function testEveryConcurrentlyGeneratedDocumentOfTheSameTypeForOneOrderGetsADistinctRevisionNumber(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $script = __DIR__ . '/../Support/documentConcurrencyWorker.php';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $count = 6;
        $processes = [];
        for ($i = 0; $i < $count; $i++) {
            $proc = proc_open(['php', $script, (string) $orderId], $descriptors, $pipes);
            self::assertIsResource($proc, 'could not start document-generation worker process');
            fclose($pipes[0]);
            $processes[] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }

        $documentIds = [];
        foreach ($processes as $p) {
            $out = trim((string) stream_get_contents($p['stdout']));
            $err = stream_get_contents($p['stderr']);
            fclose($p['stdout']);
            fclose($p['stderr']);
            $exitCode = proc_close($p['proc']);
            self::assertSame(0, $exitCode, "document-generation worker failed (exit {$exitCode}): {$err}");
            $documentIds[] = (int) $out;
        }

        self::assertCount($count, array_unique($documentIds), 'every concurrent generate() call must produce its own document row');

        $placeholders = implode(',', $documentIds);
        $rows = Database::connection()
            ->query("SELECT revision_number FROM documents WHERE id IN ({$placeholders})")
            ->fetchAll();
        self::assertCount($count, $rows);

        $revisionNumbers = array_map(static fn(array $r) => (int) $r['revision_number'], $rows);
        self::assertCount($count, array_unique($revisionNumbers), 'every concurrently-generated document must have a distinct revision_number: ' . implode(', ', $revisionNumbers));
    }
}
