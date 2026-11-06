<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Q3 QA matrix (5 Nov 2026): every incoterm × every seeded payment preset.
 * One two-line USD order per combination; every document type the standard
 * generator handles is generated (PDF + Word). A document may be refused with
 * a business message (RuntimeException, e.g. "record the freight first"), but
 * it must never crash with a PHP Error, and the core sales documents must
 * carry the order's real total and the preset's payment split.
 * Node twin: tests/integration/qaIncotermPresetMatrix.test.js.
 * Set QA_REPORT=/path.json to write the per-combination outcome table.
 */
final class QaIncotermPresetMatrixTest extends DbTestCase
{
    // Order documents the standard generator renders. CHECKLIST*, STAGEGATE, WALLREF and SOP_* are
    // static reference documents ("No template mapped" in generate()); AMD, CAFIN and BLE have their own generators.
    private const CODES = ['QT', 'ANNEXA', 'BUYERPO', 'PI', 'OC', 'SUPPO', 'FDN', 'PL', 'BLI', 'CI', 'COOPREP'];

    /** Documents that must render for every combination and show the total + payment split. */
    private const CORE = ['QT', 'PI', 'OC', 'CI'];

    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        return (string) preg_replace('/\s+/u', ' ', (string) shell_exec('pdftotext -layout ' . escapeshellarg((string) $file['server_path']) . ' -'));
    }


    /**
     * Runs on a database of its own, freshly built from schema.sql + seed.sql and dropped
     * afterwards, so the orders, documents, e-mail rows and settings this matrix creates
     * never reach the shared test database other tests read (same pattern as
     * StoneDocumentsUnchangedTest).
     */
    private function onFreshDatabase(string $name, callable $work): mixed
    {
        $docs = dirname(__DIR__, 3) . '/docs';
        $mysql = static function (string $db, string $sql): void {
            $cmd = ['mysql', '-h', \App\Config\Env::get('DB_HOST', '127.0.0.1'), '-P', \App\Config\Env::get('DB_PORT', '3306'), '-u', (string) \App\Config\Env::get('DB_USERNAME'), '-p' . \App\Config\Env::get('DB_PASSWORD', '')];
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
        $original = (string) \App\Config\Env::get('DB_DATABASE');
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

    public function testEveryIncotermAndPresetGeneratesEveryDocument(): void
    {
        $this->onFreshDatabase('nexacrest_phpunit_qa_incoterm', fn () => $this->runMatrix());
    }

    private function runMatrix(): void
    {
        $pdo = Database::connection();
        $presets = $pdo->query('SELECT preset_name, advance_pct, balance_pct, payment_method FROM payment_presets ORDER BY id')->fetchAll();
        self::assertNotEmpty($presets);
        $userId = $this->createTestUser('Admin');
        $report = [];
        $crashes = [];
        $wrong = [];

        foreach (['FOB', 'CFR', 'CIF'] as $incoterm) {
            foreach ($presets as $preset) {
                $name = (string) $preset['preset_name'];
                $clientId = $this->createTestClient();
                $orderId = $this->createTestOrder($clientId, $incoterm, $name, 'USD');
                OrderProductRepository::add($orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
                OrderProductRepository::add($orderId, 2, 'Kashmir White Granite Tiles', '60 x 60 x 2 cm', 'Flamed', '80', false, 'SQM', '32.25');
                $pdo->prepare("UPDATE orders SET buyers_po_ref = 'PO-QA-1', country_of_destination = 'Georgia', contact_person = 'QA Buyer',
                                      estimated_net_weight_kg = 18500, estimated_package_count = 12 WHERE id = :id")->execute(['id' => $orderId]);

                foreach (self::CODES as $code) {
                    $key = "{$incoterm} | {$name} | {$code}";
                    try {
                        $r = DocumentGenerationService::generate($orderId, $code, $userId, null, true, true);
                        $report[$key] = 'ok' . (empty($r['docx_file_id']) ? ' (pdf only)' : '');
                        if (in_array($code, self::CORE, true)) {
                            $text = $this->pdfText((int) $r['pdf_file_id']);
                            if (!str_contains($text, '8,040.00')) {
                                $wrong[] = "{$key}: total 8,040.00 not printed";
                            }
                            if ($code !== 'CI') {
                                $adv = (int) round((float) $preset['advance_pct']);
                                $bal = (int) round((float) $preset['balance_pct']);
                                if ($preset['payment_method'] === 'LC') {
                                    if (!preg_match('/letter of credit|L\/C|\bLC\b/i', $text)) {
                                        $wrong[] = "{$key}: LC preset but no letter-of-credit wording";
                                    }
                                } elseif (!preg_match('/\b' . $adv . '\s?%/', $text) || !preg_match('/\b' . $bal . '\s?%/', $text)) {
                                    $wrong[] = "{$key}: {$adv}% / {$bal}% split not printed";
                                }
                            }
                        }
                    } catch (\RuntimeException $e) {
                        $report[$key] = 'refused: ' . $e->getMessage();
                        if (in_array($code, self::CORE, true)) {
                            $wrong[] = "{$key}: core document refused: " . $e->getMessage();
                        }
                    } catch (\Throwable $e) {
                        $report[$key] = 'CRASH ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                        $crashes[] = "{$key}: " . $report[$key];
                    }
                }
            }
        }

        if ($path = getenv('QA_REPORT')) {
            file_put_contents($path, json_encode(['stack' => 'php', 'outcomes' => $report, 'wrong' => $wrong, 'crashes' => $crashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        self::assertGreaterThanOrEqual(3 * count($presets) * count(self::CODES), count($report));
        self::assertSame([], $crashes, 'documents crashed');
        self::assertSame([], $wrong, 'core documents with a wrong total or payment split');
    }
}
