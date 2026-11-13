<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Config\Env;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-257 Phase 1 (5 Nov 2026): documents take their product
 * columns, quality documents, packing and T&C clauses from the category,
 * but a Natural Stone order must print exactly what it printed before.
 * The fixtures in tests/fixtures/stone_documents were captured from the
 * code BEFORE Phase 1 (PDF text and Word text, with the per-run parts
 * masked); this test proves every stone document still matches them.
 * Set CAPTURE_STONE_DOCS=1 to rewrite the fixtures (only ever from
 * pre-change code). The fixtures were captured on a fresh schema + seed
 * database, which is what the test renders against. Node twin: stoneDocumentsUnchanged.test.js (same fixtures).
 * Owner decision D-343 (12 Nov 2026): the Word fixtures of the QT, PI, OC, CI and PL were captured again once,
 * deliberately — the Word copy now numbers its sections as the PDF (Buyer / Consignee / Notify Party sections,
 * Legal Terms & Definitions); the PDF fixtures did not change.
 * Owner decision D-352 (12 Nov 2026): the mandatory-fields note and the red "*" (QT/PI payment terms, PI/OC estimated shipment,
 * CI money rows and amount in words) were removed from every PDF and Word fixture by hand; nothing else changed.
 * Owner decision D-353 (12 Nov 2026): the remaining plain "*" after field labels was removed from every PDF and Word
 * fixture by hand (every standalone "*"); nothing else changed.
 * Owner decision MTX-005 (13 Nov 2026, item 3 Option A, confirmed twice): the Word CI fixtures (FOB_CI_docx, CIF_CI_docx)
 * were captured again once — the Word CI now uses the PDF's wording (Debit Note, not FDN; "To Be Confirmed"; the PDF's
 * Bill of Lading line; no "+ — (freight)"); the PDF fixtures did not change.
 */
final class StoneDocumentsUnchangedTest extends DbTestCase
{
    private const CODES = ['QT', 'PI', 'OC', 'CI', 'PL', 'SUPPO', 'BLI', 'COOPREP'];

    public static function mask(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $patterns = [
            '#\b[A-Z]{2,}/[A-Z0-9]+(?:/[A-Z0-9-]+)+\b#' => '<REF>',
            '#\b\d{1,2} (?:January|February|March|April|May|June|July|August|September|October|November|December) \d{4}\b#' => '<DATE>',
            '#\b\d{1,2}[ -](?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[ -]\d{2,4}\b#' => '<DATE>',
            '#\b\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}(?::\d{2})?)?\b#' => '<DATE>',
            '#\b\d{2}[/.-]\d{2}[/.-]\d{4}\b#' => '<DATE>',
            '#[\w.+-]+@[\w.-]+#' => '<EMAIL>',
            '#Test Client \S+#' => 'Test Client <ID>',
            '#PHPUNIT- ?[0-9a-f]{6}#i' => 'PHPUNIT-<ID>',
            '#Rev\.\d+#' => 'Rev.<N>',
            '#Page \d+ of \d+#' => 'Page <N>',
        ];
        $text = (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
        return trim($text);
    }

    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        return (string) shell_exec('pdftotext -layout ' . escapeshellarg((string) $file['server_path']) . ' -');
    }

    private function docxText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) $file['server_path']) . ' word/document.xml');
        return html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** A stone order with two stone lines (dimensions + finish), deterministic data. */
    private function stoneOrder(string $incoterm): int
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, $incoterm, 'Standard — New Buyer', 'USD');
        OrderProductRepository::add($orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
        OrderProductRepository::add($orderId, 2, 'Kashmir White Granite Tiles', '60 x 60 x 2 cm', 'Flamed', '80', false, 'SQM', '32.25');
        Database::connection()->prepare(
            "UPDATE orders SET buyers_po_ref = 'PO-STONE-1', billing_address_line1 = '7 Harbour Road', billing_city = 'Poti',
                    billing_postcode = '4400', country_of_destination = 'Georgia', contact_person = 'Head Buyer',
                    estimated_net_weight_kg = 18500, estimated_package_count = 12 WHERE id = :id"
        )->execute(['id' => $orderId]);
        return $orderId;
    }

    /** @return array<string,string> "<incoterm>_<code>_<format>" => masked text */
    private function render(string $incoterm): array
    {
        $orderId = $this->stoneOrder($incoterm);
        $userId = $this->createTestUser('Admin');
        $out = [];
        foreach (self::CODES as $code) {
            $r = DocumentGenerationService::generate($orderId, $code, $userId, null, true, true);
            $out[$incoterm . '_' . $code . '_pdf'] = self::mask($this->pdfText((int) $r['pdf_file_id']));
            if (!empty($r['docx_file_id'])) {
                $out[$incoterm . '_' . $code . '_docx'] = self::mask($this->docxText((int) $r['docx_file_id']));
            }
        }
        return $out;
    }

    /**
     * Renders against a database of its own, freshly built from schema.sql +
     * seed.sql, so settings, watermarks or clauses another test changed in the
     * shared test database cannot alter the text being compared.
     */
    private function onFreshDatabase(callable $work): mixed
    {
        $name = 'nexacrest_phpunit_stone';
        $docs = dirname(__DIR__, 3) . '/docs';
        $mysql = static function (string $db, string $sql): void {
            $cmd = ['mysql', '-h', Env::get('DB_HOST', '127.0.0.1'), '-P', Env::get('DB_PORT', '3306'), '-u', (string) Env::get('DB_USERNAME'), '-p' . Env::get('DB_PASSWORD', '')];
            if ($db !== '') {
                $cmd[] = $db;
            }
            $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], $sql);
            fclose($pipes[0]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($p) !== 0) {
                throw new \RuntimeException('mysql failed: ' . $err);
            }
        };
        $mysql('', "DROP DATABASE IF EXISTS `{$name}`; CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
        $mysql($name, (string) file_get_contents($docs . '/schema.sql'));
        $mysql($name, (string) file_get_contents($docs . '/seed.sql'));
        $connection = new \ReflectionProperty(Database::class, 'connection');
        $original = (string) Env::get('DB_DATABASE');
        $switch = static function (string $db) use ($connection): void {
            $connection->setValue(null, null);
            putenv('DB_DATABASE=' . $db);
            $_ENV['DB_DATABASE'] = $db;
            $_SERVER['DB_DATABASE'] = $db;
            \App\Services\PermissionService::resetCache();
            \App\Services\ActiveDepartmentService::resetCache();
        };
        $switch($name);
        try {
            return $work();
        } finally {
            $switch($original);
            $mysql('', "DROP DATABASE IF EXISTS `{$name}`;");
        }
    }

    /** @return array<string,int> every word with its count, sorted — independent of reading order */
    private static function words(string $text): array
    {
        $counts = array_count_values(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        ksort($counts, SORT_STRING);
        return $counts;
    }

    public function testStoneDocumentsPrintExactlyAsBeforePhase1(): void
    {
        $dir = dirname(__DIR__) . '/fixtures/stone_documents';
        $capture = getenv('CAPTURE_STONE_DOCS') === '1';
        $all = $this->onFreshDatabase(fn (): array => $this->render('FOB') + $this->render('CIF'));
        foreach ($all as $name => $text) {
            $file = $dir . '/' . $name . '.txt';
            if ($capture) {
                file_put_contents($file, $text . "\n");
                continue;
            }
            self::assertFileExists($file, $name);
            $expected = trim((string) file_get_contents($file));
            if (str_ends_with($name, '_pdf')) {
                // pdftotext -layout orders words differently between poppler versions (the DRAFT watermark letters and
                // two-column boxes come out in another order on CI). Compare every word and how often it appears, so
                // any added, removed or changed word still fails, independent of the PDF tool's reading order.
                self::assertSame(self::words($expected), self::words($text), "{$name} changed for a Natural Stone order");
                continue;
            }
            self::assertSame($expected, $text, "{$name} changed for a Natural Stone order");
        }
        self::assertGreaterThanOrEqual(16, count($all));
    }
}
