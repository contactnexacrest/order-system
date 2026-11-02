<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (1 Nov 2026): the order page's "All Documents — quick links"
 * panel lists every file uploaded for the order by anyone (client portal,
 * staff, associate), next to the generated documents — only the files the
 * person may download (money visibility, CAFIN never, associate agreements
 * only for manage_incentives). The Node suite has the same test
 * (orderAllDocumentsPanel.test.js).
 */
final class OrderAllDocumentsPanelTest extends DbTestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function upload(int $orderId, string $label, string $from, string $original = 'upload.pdf'): int
    {
        $fileId = $this->createTestFile($orderId);
        \App\Config\Database::connection()->prepare(
            'UPDATE file_store SET document_type_label = :l, received_from = :f, original_filename = :o, uploaded_at = :d WHERE id = :id'
        )->execute(['l' => $label, 'f' => $from, 'o' => $original, 'd' => '2026-10-30 10:15:00', 'id' => $fileId]);
        return $fileId;
    }

    /** @return string the "All Documents" panel's HTML */
    private function panel(int $userId, int $orderId): string
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
        \App\Services\ActiveDepartmentService::resetCache();
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        $start = strpos($html, '<details class="sidebar-docs-toggle">');
        self::assertNotFalse($start, 'panel present');
        return substr($html, $start, strpos($html, '</details>', $start) - $start);
    }

    public function testUploadedFilesAreListedWithTheGeneratedDocuments(): void
    {
        $md = $this->createTestUser('Managing Director');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $md);
        $this->createTestDocument($orderId, 'QT', 'approved', $this->createTestFile($orderId));
        $po = $this->upload($orderId, 'Buyer PO copy (client-uploaded)', 'Client (self-uploaded)', 'PO-4711.pdf');
        $shot = $this->upload($orderId, 'Client payment self-report screenshot', 'Client (self-reported)', 'transfer.png');
        $plain = $this->upload($orderId, '', '', 'loading-photo.jpg');
        \App\Config\Database::connection()->exec('UPDATE file_store SET uploaded_by = ' . $md . ' WHERE id = ' . $plain);
        // A file that belongs to the CAFIN document is never listed as an upload.
        $cafinFile = $this->upload($orderId, 'CAFIN SECRET FILE', 'x');
        $this->createTestDocument($orderId, 'CAFIN', 'approved', $cafinFile);
        // A removed file is not listed.
        $removed = $this->upload($orderId, 'Removed upload', 'Buyer');
        \App\Config\Database::connection()->exec('UPDATE file_store SET is_active = 0 WHERE id = ' . $removed);

        $html = $this->panel($md, $orderId);
        self::assertStringContainsString('Uploaded / received (3)', $html);
        self::assertStringContainsString('href="/file-store/' . $po . '/download">Buyer PO copy (client-uploaded)</a>', $html);
        self::assertStringContainsString('from Client (self-uploaded) &middot; 2026-10-30', $html);
        self::assertStringContainsString('href="/file-store/' . $shot . '/download">Client payment self-report screenshot</a>', $html);
        self::assertStringContainsString('href="/file-store/' . $plain . '/download">loading-photo.jpg</a>', $html, 'no label: the file name');
        self::assertStringContainsString('from PHPUnit Test User', $html, 'no sender recorded: the uploader');
        self::assertStringNotContainsString('CAFIN SECRET FILE', $html);
        self::assertStringNotContainsString('Removed upload', $html);
        self::assertStringContainsString('QT PHPUNIT-DOC-', $html, 'generated documents still listed');
        // Generated: QT (+ CAFIN only when the person holds view_cafin) — the summary adds the 3 uploads.
        self::assertMatchesRegularExpression('/All Documents \((\d+)\)/', $html);
        preg_match('/Generated \((\d+)\)/', $html, $g);
        preg_match('/All Documents \((\d+)\)/', $html, $all);
        self::assertSame((int) $g[1] + 3, (int) $all[1]);
    }

    public function testMoneyGatedUploadsAreHiddenFromWhoMayNotSeeThatMoney(): void
    {
        $logistics = $this->createTestUser('Logistics Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $logistics);
        $this->upload($orderId, 'Client payment self-report screenshot', 'Client (self-reported)');
        $this->upload($orderId, 'Fumigation certificate', 'Fumigation agency');

        $html = $this->panel($logistics, $orderId);
        self::assertStringNotContainsString('Client payment self-report screenshot', $html, 'buyer payment screenshot needs view_buyer_payments');
        self::assertStringContainsString('Fumigation certificate', $html);
        self::assertStringContainsString('Uploaded / received (1)', $html);
    }

    public function testEmptyState(): void
    {
        $md = $this->createTestUser('Managing Director');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $md);
        $html = $this->panel($md, $orderId);
        self::assertStringContainsString('No files uploaded yet.', $html);
        self::assertStringContainsString('Uploaded / received (0)', $html);
    }
}
