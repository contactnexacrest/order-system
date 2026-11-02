<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentRepository;
use App\Services\DocumentCopyCleanupService as Cleanup;
use App\Tests\Support\DbTestCase;

/**
 * Owner points 5 / 8 / 9 (2 Nov 2026): Admin → Settings → Document copies
 * clean-up. Each rule removes exactly the copies it describes and nothing it
 * promises to keep. Node twin: tests/integration/documentCopyCleanup.test.js.
 */
final class DocumentCopyCleanupTest extends DbTestCase
{
    /** generated_at in FY 2023-24, a year no other test locks */
    private const GENERATED_AT = '2023-07-01 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings(0, 0, 'keep_all');
    }

    protected function tearDown(): void
    {
        $this->settings(0, 0, 'keep_all');
        Database::connection()->exec("UPDATE ca_fy_locks SET unlocked_at = NOW() WHERE financial_year = '2022-23' AND unlocked_at IS NULL");
        parent::tearDown();
    }

    private function settings(int $draft, int $superseded, string $cafin): void
    {
        CompanySettingsRepository::set(Cleanup::KEY_DRAFT, (string) $draft);
        CompanySettingsRepository::set(Cleanup::KEY_SUPERSEDED, (string) $superseded);
        CompanySettingsRepository::set(Cleanup::KEY_CAFIN, $cafin);
    }

    private function doc(int $orderId, string $code, string $status, string $generatedAt = self::GENERATED_AT): int
    {
        $id = $this->createTestDocument($orderId, $code, $status);
        Database::connection()->prepare('UPDATE documents SET generated_at = :g WHERE id = :id')->execute(['g' => $generatedAt, 'id' => $id]);
        return $id;
    }

    /** A real file on disk + its generated file_store row linked to the revision, made $ageDays ago. */
    private function file(int $orderId, int $documentId, string $name, int $ageDays, string $mime = 'application/pdf', bool $sent = false): int
    {
        $path = sys_get_temp_dir() . '/phpunit-cleanup-' . bin2hex(random_bytes(6)) . ($mime === 'application/pdf' ? '.pdf' : '.docx');
        file_put_contents($path, str_repeat('x', 2048));
        $pdo = Database::connection();
        $pdo->prepare(
            "INSERT INTO file_store (order_id, file_origin, generation_method, linked_document_id, sent_to_client, server_path, uuid_filename,
                                     original_filename, file_size_bytes, mime_type, uploaded_at)
             VALUES (:o, 'GENERATED_AUTO', 'twig+dompdf/phpword', :d, :s, :p, :u, :n, 2048, :m, DATE_SUB(NOW(), INTERVAL :age DAY))"
        )->execute(['o' => $orderId, 'd' => $documentId, 's' => $sent ? 1 : 0, 'p' => $path, 'u' => basename($path), 'n' => $name, 'm' => $mime, 'age' => $ageDays]);
        return (int) $pdo->lastInsertId();
    }

    private function setCurrent(int $documentId, int $pdfFileId, ?int $docxFileId = null): void
    {
        Database::connection()->prepare('UPDATE documents SET pdf_file_id = :p, docx_file_id = :x WHERE id = :id')
            ->execute(['p' => $pdfFileId, 'x' => $docxFileId, 'id' => $documentId]);
    }

    /** @return int[] */
    private function candidateIds(): array
    {
        return array_map(static fn (array $r): int => (int) $r['file_id'], Cleanup::candidates());
    }

    private function active(int $fileId): bool
    {
        return (int) Database::connection()->query('SELECT is_active FROM file_store WHERE id = ' . $fileId)->fetchColumn() === 1;
    }

    /** An approved revision: draft PDF, Word copy, final PDF (current). */
    private function approvedRevision(int $orderId, int $ageDays, string $status = 'approved', bool $finalSent = false): array
    {
        $d = $this->doc($orderId, 'QT', $status);
        $draft = $this->file($orderId, $d, 'QT draft.pdf', $ageDays);
        $docx = $this->file($orderId, $d, 'QT (internal).docx', $ageDays, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $final = $this->file($orderId, $d, 'QT final.pdf', $ageDays, 'application/pdf', $finalSent);
        $this->setCurrent($d, $final, $docx);
        return ['doc' => $d, 'draft' => $draft, 'docx' => $docx, 'final' => $final];
    }

    public function testEverythingOffRemovesNothing(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $r = $this->approvedRevision($order, 400);

        self::assertSame([], Cleanup::candidates(), 'all rules off → empty preview');
        $result = Cleanup::run(null, 'manual');
        self::assertTrue($result['skipped_off']);
        self::assertTrue($this->active($r['draft']));
    }

    public function testDraftRuleRemovesOnlyTheDraftOfAnApprovedDocumentAfterTheChosenDays(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $old = $this->approvedRevision($order, 40);
        $recent = $this->approvedRevision($order, 10);
        $inReview = $this->doc($order, 'QT', 'in_review');
        $onlyDraft = $this->file($order, $inReview, 'QT in review.pdf', 200);
        $this->setCurrent($inReview, $onlyDraft);
        $uploaded = $this->createTestFile($order);

        $this->settings(30, 0, 'keep_all');
        $ids = $this->candidateIds();

        self::assertContains($old['draft'], $ids, 'draft of a document approved 40 days ago');
        foreach ([$old['final'], $old['docx'], $recent['draft'], $recent['final'], $onlyDraft, $uploaded] as $kept) {
            self::assertNotContains($kept, $ids);
        }
    }

    public function testSupersededRuleKeepsSentRevisionsAndTheSupersededStampedCopy(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        // Never sent: draft + Word + final, then the SUPERSEDED-stamped copy (current), superseded 100 days ago.
        $never = $this->approvedRevision($order, 100, 'superseded');
        $neverStamp = $this->file($order, $never['doc'], 'QT (SUPERSEDED).pdf', 100);
        $this->setCurrent($never['doc'], $neverStamp, $never['docx']);
        // Sent (final copy flagged): nothing of it may go.
        $sent = $this->approvedRevision($order, 100, 'superseded', true);
        $sentStamp = $this->file($order, $sent['doc'], 'QT (SUPERSEDED).pdf', 100);
        $this->setCurrent($sent['doc'], $sentStamp, $sent['docx']);
        // Sent by e-mail (no file flag): nothing of it may go.
        $mailed = $this->approvedRevision($order, 100, 'superseded');
        $mailedStamp = $this->file($order, $mailed['doc'], 'QT (SUPERSEDED).pdf', 100);
        $this->setCurrent($mailed['doc'], $mailedStamp, $mailed['docx']);
        Database::connection()->prepare(
            "INSERT INTO email_log (document_id, order_id, recipient_email, subject, body_snapshot, status, sent_at) VALUES (:d, :o, 'b@example.test', 'QT', 'QT attached', 'sent', NOW())"
        )->execute(['d' => $mailed['doc'], 'o' => $order]);

        $this->settings(0, 90, 'keep_all');
        $ids = $this->candidateIds();

        foreach (['draft', 'docx', 'final'] as $k) {
            self::assertContains($never[$k], $ids, "never-sent superseded revision: {$k}");
            self::assertNotContains($sent[$k], $ids, "sent revision: {$k}");
            self::assertNotContains($mailed[$k], $ids, "e-mailed revision: {$k}");
        }
        foreach ([$neverStamp, $sentStamp, $mailedStamp] as $stamp) {
            self::assertNotContains($stamp, $ids, 'the SUPERSEDED-stamped copy stays');
        }
    }

    public function testCafinLatestOnlyKeepsTheLatestPerOrder(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $older = $this->doc($order, 'CAFIN', 'draft', date('Y-m-d H:i:s', strtotime('-90 days')));
        $olderFile = $this->file($order, $older, 'CAFIN old.pdf', 90);
        $this->setCurrent($older, $olderFile);
        $newer = $this->doc($order, 'CAFIN', 'draft', date('Y-m-d H:i:s', strtotime('-40 days')));
        $newerFile = $this->file($order, $newer, 'CAFIN new.pdf', 40);
        $this->setCurrent($newer, $newerFile);
        // A replacement made only 5 days ago: the one before it waits.
        $order2 = $this->createTestOrder($this->createTestClient());
        $a = $this->doc($order2, 'CAFIN', 'draft', date('Y-m-d H:i:s', strtotime('-60 days')));
        $aFile = $this->file($order2, $a, 'CAFIN a.pdf', 60);
        $this->setCurrent($a, $aFile);
        $b = $this->doc($order2, 'CAFIN', 'draft', date('Y-m-d H:i:s', strtotime('-5 days')));
        $this->setCurrent($b, $this->file($order2, $b, 'CAFIN b.pdf', 5));

        $this->settings(0, 0, 'latest_only');
        $ids = $this->candidateIds();

        self::assertContains($olderFile, $ids);
        self::assertNotContains($newerFile, $ids, 'the latest CAFIN stays');
        self::assertNotContains($aFile, $ids, 'replaced only 5 days ago — kept until 30 days');
    }

    public function testOpenDisputeAndLockedFinancialYearKeepEverything(): void
    {
        $disputed = $this->createTestOrder($this->createTestClient());
        $d = $this->approvedRevision($disputed, 200);
        Database::connection()->prepare(
            "INSERT INTO disputes (order_id, notice_date, from_party, description, status) VALUES (:o, CURDATE(), 'Buyer', 'Quality claim', 'Open')"
        )->execute(['o' => $disputed]);

        $lockedOrder = $this->createTestOrder($this->createTestClient());
        $lockedDoc = $this->doc($lockedOrder, 'QT', 'approved', '2022-08-01 10:00:00');
        $lockedDraft = $this->file($lockedOrder, $lockedDoc, 'QT draft.pdf', 200);
        $this->setCurrent($lockedDoc, $this->file($lockedOrder, $lockedDoc, 'QT final.pdf', 200));
        Database::connection()->prepare("INSERT INTO ca_fy_locks (financial_year, locked_by) VALUES ('2022-23', :u)")
            ->execute(['u' => $this->createTestUser('Admin')]);

        $this->settings(30, 90, 'latest_only');
        $ids = $this->candidateIds();

        self::assertNotContains($d['draft'], $ids, 'order with an open dispute');
        self::assertNotContains($lockedDraft, $ids, 'document in a CA-locked financial year');
    }

    public function testRunRemovesExactlyThePreviewAndAuditsEachFile(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $r = $this->approvedRevision($order, 45);
        $path = (string) Database::connection()->query('SELECT server_path FROM file_store WHERE id = ' . $r['draft'])->fetchColumn();

        $this->settings(30, 0, 'keep_all');
        $preview = $this->candidateIds();
        self::assertContains($r['draft'], $preview);

        $result = Cleanup::run(null, 'manual');
        self::assertFalse($result['skipped_off']);
        self::assertSame(count($preview), $result['files'], 'the run removes exactly what the preview listed');
        self::assertSame([], $this->candidateIds(), 'nothing left to remove');

        self::assertFalse($this->active($r['draft']));
        self::assertFileDoesNotExist($path);
        self::assertTrue($this->active($r['final']));
        $row = Database::connection()->query('SELECT removed_at, removed_reason FROM file_store WHERE id = ' . $r['draft'])->fetch();
        self::assertNotNull($row['removed_at']);
        self::assertSame('Draft copy of an approved document', $row['removed_reason']);

        $audit = Database::connection()->query("SELECT reason FROM audit_log WHERE action_type = 'DOCUMENT_COPY_REMOVED' AND entity_id = {$r['draft']}")->fetchColumn();
        self::assertStringContainsString('Draft copy of an approved document — QT', (string) $audit);
        self::assertGreaterThan(0, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'DOCUMENT_COPY_CLEANUP_RUN'")->fetchColumn());
    }

    public function testSendingADocumentFlagsTheExactFileSoItIsNeverRemoved(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $d = $this->doc($order, 'QT', 'approved');
        $final = $this->file($order, $d, 'QT final.pdf', 1);
        $this->setCurrent($d, $final);

        DocumentRepository::markSent($d);

        $row = Database::connection()->query("SELECT sent_to_client, sent_at FROM file_store WHERE id = {$final}")->fetch();
        self::assertSame(1, (int) $row['sent_to_client']);
        self::assertNotNull($row['sent_at']);
    }

    public function testEstimateShowsWhatAnOffRuleWouldRemove(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $this->approvedRevision($order, 45);

        $estimate = Cleanup::estimate();
        self::assertTrue($estimate['draft']['hypothetical']);
        self::assertGreaterThanOrEqual(1, $estimate['draft']['files']);
        self::assertGreaterThanOrEqual(2048, $estimate['draft']['bytes']);
    }
}
