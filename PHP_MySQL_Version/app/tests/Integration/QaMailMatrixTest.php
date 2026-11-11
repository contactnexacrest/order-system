<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Services\EmailDispatchService;
use App\Tests\Support\DbTestCase;

/**
 * Q3 QA mail matrix (5 Nov 2026). For a CIF order on a cash-advance preset and
 * an FOB order on the LC preset:
 *  - every "send_<code>" document template renders against its approved
 *    document with a subject, the buyer as recipient and no unreplaced {token};
 *    the PI / Debit Note / CI mails carry the payment link;
 *  - every generic order template either renders cleanly or refuses with a
 *    business message (bl_copy_sent without a BL copy must refuse);
 *  - with the approval queue on and sending off, each document send queues,
 *    is approved and dispatches without an exception;
 *  - an internal document (Supplier PO) and CAFIN can never be mailed.
 * Node twin: tests/integration/qaMailMatrix.test.js.
 */
final class QaMailMatrixTest extends DbTestCase
{
    private const DOC_TEMPLATES = ['QT' => 'send_qt', 'ANNEXA' => 'send_annexa', 'BUYERPO' => 'send_buyerpo', 'PI' => 'send_pi',
        'OC' => 'send_oc', 'FDN' => 'send_fdn', 'PL' => 'send_pl', 'CI' => 'send_ci'];
    private const GENERIC = ['payment_followup', 'shipment_readiness', 'balance_receipt_confirmation', 'bl_copy_sent'];

    private function setting(string $key, string $value): void
    {
        Database::connection()->prepare('UPDATE company_settings SET setting_value = :v WHERE setting_key = :k')->execute(['v' => $value, 'k' => $key]);
    }

    private function order(string $incoterm, string $preset): int
    {
        $orderId = $this->createTestOrder($this->createTestClient('qa-mail-' . bin2hex(random_bytes(4)) . '@buyer.test'), $incoterm, $preset, 'USD');
        OrderProductRepository::add($orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
        Database::connection()->prepare("UPDATE orders SET buyers_po_ref = 'PO-QA-MAIL', contact_person = 'QA Buyer' WHERE id = :id")->execute(['id' => $orderId]);
        return $orderId;
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

    public function testEveryMailTemplateAndCondition(): void
    {
        $this->onFreshDatabase('nexacrest_phpunit_qa_mail', fn () => $this->runMatrix());
    }

    private function runMatrix(): void
    {
        $pdo = Database::connection();
        $before = $pdo->query("SELECT setting_key, setting_value FROM company_settings WHERE setting_key IN ('mail_sending_enabled','mail_approval_queue_enabled')")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $userId = $this->createTestUser('Admin');
        $approverId = $this->createTestUser('Managing Director');
        $problems = [];
        try {
            $this->setting('mail_sending_enabled', '0');
            $this->setting('mail_approval_queue_enabled', '1');
            foreach ([['CIF', 'Standard — New Buyer'], ['FOB', 'Irrevocable Letter of Credit at Sight']] as [$incoterm, $preset]) {
                $orderId = $this->order($incoterm, $preset);
                $label = "{$incoterm}/{$preset}";
                foreach (self::DOC_TEMPLATES as $code => $template) {
                    try {
                        $doc = DocumentGenerationService::generate($orderId, $code, $userId, null, false, true);
                    } catch (\RuntimeException $e) {
                        $problems[] = "{$label} {$code}: could not generate ({$e->getMessage()})";
                        continue;
                    }
                    $pdo->prepare("UPDATE documents SET status = 'approved' WHERE id = :id")->execute(['id' => $doc['document_id']]);
                    try {
                        $p = EmailDispatchService::buildPreview($orderId, (int) $doc['document_id'], $template, $userId);
                    } catch (\RuntimeException $e) {
                        // An LC quotation waits for the MD/ED to approve the payment terms (expected).
                        if (!($code === 'QT' && str_contains($e->getMessage(), 'need approval before the Quotation'))) {
                            $problems[] = "{$label} {$template}: preview refused: {$e->getMessage()}";
                        }
                        continue;
                    }
                    if (trim($p['subject']) === '') {
                        $problems[] = "{$label} {$template}: empty subject";
                    }
                    if (!str_contains((string) $p['recipient_email'], '@')) {
                        $problems[] = "{$label} {$template}: no recipient";
                    }
                    if (preg_match_all('/\{[a-z_]+\}/', $p['subject'] . "\n" . $p['body'], $m)) {
                        $problems[] = "{$label} {$template}: unreplaced " . implode(', ', array_unique($m[0]));
                    }
                    if (in_array($code, ['PI', 'FDN', 'CI'], true) && !str_contains($p['body'], '/payment-action/')) {
                        $problems[] = "{$label} {$template}: no payment link";
                    }
                    try {
                        $sent = EmailDispatchService::requestSend($orderId, (int) $doc['document_id'], $template, null, $userId);
                        EmailDispatchService::approveSend((int) $sent['id'], $approverId);
                    } catch (\Throwable $e) {
                        $problems[] = "{$label} {$template}: queue/approve/dispatch failed: " . get_class($e) . ' ' . $e->getMessage();
                    }
                }
                foreach (self::GENERIC as $template) {
                    try {
                        $p = EmailDispatchService::buildPreview($orderId, null, $template, $userId);
                        if ($template === 'bl_copy_sent') {
                            $problems[] = "{$label} bl_copy_sent: rendered without a BL copy on file";
                        }
                        if (preg_match_all('/\{[a-z_]+\}/', $p['subject'] . "\n" . $p['body'], $m)) {
                            $problems[] = "{$label} {$template}: unreplaced " . implode(', ', array_unique($m[0]));
                        }
                    } catch (\RuntimeException $e) {
                        if ($template !== 'bl_copy_sent') {
                            $problems[] = "{$label} {$template}: refused: {$e->getMessage()}";
                        }
                    }
                }
                // Internal documents never go to the buyer.
                $suppo = DocumentGenerationService::generate($orderId, 'SUPPO', $userId, null, false, true);
                $pdo->prepare("UPDATE documents SET status = 'approved' WHERE id = :id")->execute(['id' => $suppo['document_id']]);
                try {
                    EmailDispatchService::buildPreview($orderId, (int) $suppo['document_id'], 'send_suppo', $userId);
                    $problems[] = "{$label}: the Supplier PO could be mailed to the buyer";
                } catch (\RuntimeException) {
                }
                $cafin = DocumentGenerationService::generateCaInternalAnnexure($orderId, $userId);
                $pdo->prepare("UPDATE documents SET status = 'approved' WHERE id = :id")->execute(['id' => $cafin['document_id']]);
                foreach (['send_cafin', 'send_ci', 'send_pi'] as $t) {
                    try {
                        EmailDispatchService::buildPreview($orderId, (int) $cafin['document_id'], $t, $userId);
                        $problems[] = "{$label}: CAFIN could be mailed with {$t}";
                    } catch (\RuntimeException) {
                    }
                }
            }
        } finally {
            foreach ($before as $k => $v) {
                $this->setting($k, (string) $v);
            }
        }
        if ($path = getenv('QA_REPORT')) {
            file_put_contents($path, json_encode(['stack' => 'php', 'problems' => $problems], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        self::assertSame([], $problems);
    }
}
