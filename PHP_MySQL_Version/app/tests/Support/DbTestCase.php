<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need the real disposable test database
 * (nexacrest_phpunit_test — never nexacrest, the interactively-used
 * dev/demo DB with real sample data). The schema/seed rebuild happens once
 * per PHPUnit process, in bootstrap.php, not per test class — this class
 * just gives tests a PDO connection and small fixture helpers.
 */
abstract class DbTestCase extends TestCase
{
    private const DB_NAME = 'nexacrest_phpunit_test';

    public static function rebuildDatabase(): void
    {
        self::runSql('', sprintf(
            'DROP DATABASE IF EXISTS `%1$s`; CREATE DATABASE `%1$s` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;',
            self::DB_NAME
        ));

        $docsDir = __DIR__ . '/../../../docs';
        self::runSqlFile(self::DB_NAME, $docsDir . '/schema.sql');
        self::runSqlFile(self::DB_NAME, $docsDir . '/seed.sql');
    }

    public static function dropDatabase(): void
    {
        self::runSql('', sprintf('DROP DATABASE IF EXISTS `%s`;', self::DB_NAME));
    }

    private static function runSqlFile(string $database, string $filePath): void
    {
        self::runSql($database, (string) file_get_contents($filePath));
    }

    // Host/user/password come from the real app/.env (loaded by
    // tests/bootstrap.php before this ever runs) — never duplicated or
    // hardcoded here. Only the database name is test-specific.
    private static function runSql(string $database, string $sql): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $cmd = [
            'mysql',
            '-h', \App\Config\Env::get('DB_HOST', '127.0.0.1'),
            '-P', \App\Config\Env::get('DB_PORT', '3306'),
            '-u', \App\Config\Env::get('DB_USERNAME'),
            '-p' . \App\Config\Env::get('DB_PASSWORD', ''),
        ];
        if ($database !== '') {
            $cmd[] = $database;
        }
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start mysql CLI for test DB setup.');
        }
        fwrite($pipes[0], $sql);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException('mysql CLI failed (exit ' . $exitCode . '): ' . $stderr);
        }
    }

    /** Minimal valid client row, returns its id. */
    protected function createTestClient(): int
    {
        $pdo = \App\Config\Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO clients (client_unique_number, company_legal_name, billing_address, created_by)
             VALUES (:num, :name, :addr, NULL)'
        );
        $stmt->execute([
            'num'  => 'TEST-' . bin2hex(random_bytes(4)),
            'name' => 'PHPUnit Test Buyer Ltd',
            'addr' => '1 Test Street, Test City',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Minimal valid order + its 9 order_stages rows (via the real
     * OrderStageRepository::initializeForOrder(), not hand-crafted), in
     * the exact state a genuine new order is in: Stage 1 in_progress,
     * Stages 2-9 locked.
     */
    protected function createTestOrder(int $clientId, string $incotermCode = 'FOB'): int
    {
        $pdo = \App\Config\Database::connection();

        $incotermStmt = $pdo->prepare('SELECT id FROM incoterms WHERE code = :code');
        $incotermStmt->execute(['code' => $incotermCode]);
        $incotermId = (int) $incotermStmt->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
        $presetId = (int) $pdo->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO orders
                (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
                 currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status)
             VALUES
                (:ref, :client_id, 1, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, \'TBC\', \'NIL\',
                 CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), \'active\')'
        );
        $stmt->execute([
            'ref'         => 'PHPUNIT-TEST-' . bin2hex(random_bytes(4)),
            'client_id'   => $clientId,
            'inquiry_ref' => 'PHPUNIT-' . bin2hex(random_bytes(3)),
            'preset_id'   => $presetId,
            'incoterm_id' => $incotermId,
            'currency_id' => $currencyId,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        \App\Repositories\OrderStageRepository::initializeForOrder($orderId);

        return $orderId;
    }

    /** A minimal real staff user with the given role, returns its id. */
    protected function createTestUser(string $roleName): int
    {
        $pdo = \App\Config\Database::connection();
        $roleId = (int) $pdo->query('SELECT id FROM roles WHERE name = ' . $pdo->quote($roleName))->fetchColumn();
        $stmt = $pdo->prepare(
            "INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
             VALUES ('PHPUnit Test User', :email, NULL, 'x', :role_id, 1, 0, 0)"
        );
        $stmt->execute(['email' => 'phpunit-user-' . bin2hex(random_bytes(4)) . '@nexacrest.test', 'role_id' => $roleId]);
        return (int) $pdo->lastInsertId();
    }

    /** A minimal real document row for the given order + document type code, returns its id. */
    protected function createTestDocument(int $orderId, string $typeCode = 'QT', string $status = 'draft', ?int $pdfFileId = null): int
    {
        $pdo = \App\Config\Database::connection();
        $typeId = (int) $pdo->query('SELECT id FROM document_types WHERE code = ' . $pdo->quote($typeCode))->fetchColumn();
        $stmt = $pdo->prepare(
            "INSERT INTO documents (order_id, document_type_id, document_reference, status, pdf_file_id)
             VALUES (:order_id, :type_id, :ref, :status, :pdf_file_id)"
        );
        $stmt->execute([
            'order_id'    => $orderId,
            'type_id'     => $typeId,
            'ref'         => 'PHPUNIT-DOC-' . bin2hex(random_bytes(4)),
            'status'      => $status,
            'pdf_file_id' => $pdfFileId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** A minimal real file_store row (RECEIVED origin), returns its id. */
    protected function createTestFile(?int $orderId = null, ?int $clientId = null): int
    {
        $pdo = \App\Config\Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO file_store (client_id, order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
             VALUES (:client_id, :order_id, \'RECEIVED\', :path, :uuid, :orig, 1024, \'application/pdf\')'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'order_id'  => $orderId,
            'path'      => '/tmp/phpunit-test-file-' . bin2hex(random_bytes(4)) . '.pdf',
            'uuid'      => bin2hex(random_bytes(16)) . '.pdf',
            'orig'      => 'test-file.pdf',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int,string> stage_number => status */
    protected function stageStatuses(int $orderId): array
    {
        $pdo = \App\Config\Database::connection();
        $stmt = $pdo->prepare(
            'SELECT sm.stage_number, os.status FROM order_stages os
             JOIN stages_master sm ON sm.id = os.stage_id
             WHERE os.order_id = :order_id ORDER BY sm.stage_number'
        );
        $stmt->execute(['order_id' => $orderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['stage_number']] = $row['status'];
        }
        return $out;
    }
}
