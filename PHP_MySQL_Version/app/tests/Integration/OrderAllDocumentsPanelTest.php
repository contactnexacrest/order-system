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

    /**
     * Owner request (2 Nov 2026): "all documents should show only the necessary
     * documents to the logged-in user as per his role — sales should not see
     * suppliers related any document". A document or file whose money the
     * person cannot see is left out of both lists entirely, and the counts
     * match what is listed.
     */
    public function testEachRoleSeesOnlyItsOwnSideOfTheOrder(): void
    {
        $md = $this->createTestUser('Managing Director');
        $sales = $this->createTestUser('Export Executive');
        $procurement = $this->createTestUser('Procurement Executive');
        $logistics = $this->createTestUser('Logistics Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $md);
        $db = \App\Config\Database::connection();
        foreach ([$sales, $procurement, $logistics] as $u) {
            $db->prepare('INSERT INTO order_assignees (order_id, user_id) VALUES (?, ?)')->execute([$orderId, $u]);
        }
        foreach (['QT', 'PI', 'SUPPO', 'BLI'] as $code) {
            $this->createTestDocument($orderId, $code, 'approved', $this->createTestFile($orderId));
        }
        $shot = $this->upload($orderId, 'Client payment self-report screenshot', 'Client (self-reported)');
        $buyerPo = $this->upload($orderId, 'Buyer PO copy', 'buyer');
        \App\Repositories\OrderBuyerPoDocumentRepository::attach($orderId, $buyerPo); // as the real upload does
        $ack = $this->upload($orderId, 'Supplier PO acknowledgment', 'supplier');
        $db->exec("INSERT INTO suppliers (supplier_legal_name) VALUES ('Panel Supplier')");
        $supplierId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference) VALUES ({$orderId}, {$supplierId}, 'Panel Supplier', 'PANEL-SPO')");
        $spoId = (int) $db->lastInsertId();
        $db->exec("INSERT INTO order_supplier_po_documents (order_supplier_po_id, file_id) VALUES ({$spoId}, {$ack})");
        $supplierBill = $this->upload($orderId, 'Supplier bill / invoice', 'Panel Supplier');
        $logisticsBill = $this->upload($orderId, 'Logistics partner bill', 'Panel Forwarder');
        foreach ([[$supplierBill, 'supplier', 'goods'], [$logisticsBill, 'logistics', 'cha']] as [$file, $kind, $for]) {
            $db->prepare("INSERT INTO order_vendor_bills (order_id, vendor_kind, bill_for, bill_no, bill_date, taxable_inr, total_inr, bill_file_id) VALUES (?, ?, ?, ?, '2026-11-02', 100, 118, ?)")
                ->execute([$orderId, $kind, $for, 'PANEL-' . $kind, $file]);
        }

        $expect = [
            'MD' => [$md, ['QT', 'PI', 'SUPPO', 'BLI'], [$shot, $buyerPo, $ack, $supplierBill, $logisticsBill]],
            'Sales' => [$sales, ['QT', 'PI'], [$shot, $buyerPo]],
            'Procurement' => [$procurement, ['SUPPO'], [$ack, $supplierBill]],
            'Logistics' => [$logistics, ['BLI'], [$logisticsBill]],
        ];
        $all = [$shot, $buyerPo, $ack, $supplierBill, $logisticsBill];
        foreach ($expect as $who => [$userId, $codes, $files]) {
            $html = $this->panel($userId, $orderId);
            foreach (['QT', 'PI', 'SUPPO', 'BLI'] as $code) {
                $listed = (bool) preg_match('/<li>\s*(?:<a [^>]*>)?' . $code . ' PHPUNIT-DOC-/', $html);
                self::assertSame(in_array($code, $codes, true), $listed, "{$who}: {$code} " . (in_array($code, $codes, true) ? 'listed' : 'not listed'));
            }
            foreach ($all as $file) {
                self::assertSame(in_array($file, $files, true), str_contains($html, '/file-store/' . $file . '/download'), "{$who}: file {$file}");
            }
            preg_match('/Generated \((\d+)\)/', $html, $g);
            preg_match('/Uploaded \/ received \((\d+)\)/', $html, $u);
            preg_match('/All Documents \((\d+)\)/', $html, $a);
            self::assertSame(count($codes), (int) $g[1], "{$who}: generated count");
            self::assertSame(count($files), (int) $u[1], "{$who}: uploaded count");
            self::assertSame(count($codes) + count($files), (int) $a[1], "{$who}: total");
            self::assertSame(count($codes), substr_count($html, 'PHPUNIT-DOC-'), "{$who}: nothing else listed unlinked");
        }
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
