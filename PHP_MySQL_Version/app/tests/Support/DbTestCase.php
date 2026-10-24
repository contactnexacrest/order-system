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
    protected function createTestClient(?string $email = null): int
    {
        $pdo = \App\Config\Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO clients (client_unique_number, company_legal_name, billing_address, email, created_by)
             VALUES (:num, :name, :addr, :email, NULL)'
        );
        $stmt->execute([
            'num'  => 'TEST-' . bin2hex(random_bytes(4)),
            'name' => 'PHPUnit Test Buyer Ltd',
            'addr' => '1 Test Street, Test City',
            'email' => $email,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Minimal valid order + its 9 order_stages rows (via the real
     * OrderStageRepository::initializeForOrder(), not hand-crafted), in
     * the exact state a genuine new order is in: Stage 1 in_progress,
     * Stages 2-9 locked.
     */
    protected function createTestOrder(
        int $clientId,
        string $incotermCode = 'FOB',
        string $presetName = 'Standard — New Buyer',
        string $currencyCode = 'USD',
        ?int $portOfLoadingId = null,
        ?int $portOfDischargeId = null
    ): int {
        $pdo = \App\Config\Database::connection();

        $incotermStmt = $pdo->prepare('SELECT id, code FROM incoterms WHERE code = :code');
        $incotermStmt->execute(['code' => $incotermCode]);
        $incoterm = $incotermStmt->fetch();
        $incotermId = (int) $incoterm['id'];
        $currencyStmt = $pdo->prepare('SELECT id, code FROM currencies WHERE code = :code');
        $currencyStmt->execute(['code' => $currencyCode]);
        $currency = $currencyStmt->fetch();
        $currencyId = (int) $currency['id'];
        $presetStmt = $pdo->prepare(
            "SELECT id, preset_name, advance_pct, advance_trigger_text, payment_method, balance_pct, balance_trigger_option,
                    balance_days, balance_trigger_wording, show_bl_instructions
             FROM payment_presets WHERE preset_name = :preset_name"
        );
        $presetStmt->execute(['preset_name' => $presetName]);
        $preset = $presetStmt->fetch();
        $presetId = (int) $preset['id'];

        $portOfLoading = ['name' => null, 'city' => null];
        if ($portOfLoadingId !== null) {
            $portStmt = $pdo->prepare('SELECT name, city FROM ports WHERE id = :id');
            $portStmt->execute(['id' => $portOfLoadingId]);
            $portOfLoading = $portStmt->fetch() ?: $portOfLoading;
        }
        $portOfDischarge = ['name' => null, 'city' => null];
        if ($portOfDischargeId !== null) {
            $portStmt = $pdo->prepare('SELECT name, city FROM ports WHERE id = :id');
            $portStmt->execute(['id' => $portOfDischargeId]);
            $portOfDischarge = $portStmt->fetch() ?: $portOfDischarge;
        }

        // 90-item backlog "standalone order" follow-on: mirrors
        // OrderRepository::insertRow()'s own resolveClientSnapshot().
        $clientStmt = $pdo->prepare(
            'SELECT company_legal_name, billing_address, billing_address_line1, billing_address_line2,
                    billing_city, billing_postcode, billing_country, vat_eori_tax_no, contact_person,
                    email, phone, country_of_destination,
                    consignee_same_as_buyer, consignee_name, consignee_address, consignee_address_line1,
                    consignee_address_line2, consignee_city, consignee_postcode, consignee_country,
                    consignee_vat_eori_tax_no, consignee_contact_person, consignee_phone, consignee_email,
                    notify_party_same_as_consignee, notify_party, notify_party_address_line1,
                    notify_party_address_line2, notify_party_city, notify_party_postcode, notify_party_country,
                    notify_party_contact_person, notify_party_phone, notify_party_email
             FROM clients WHERE id = :id'
        );
        $clientStmt->execute(['id' => $clientId]);
        $client = $clientStmt->fetch() ?: [];

        // 90-item backlog "standalone order": these columns mirror
        // exactly what OrderRepository::insertRow()'s own resolveSnapshot()
        // does in production, so a test-created order behaves identically
        // to a real one — snapshotted once here, never re-joined.
        $stmt = $pdo->prepare(
            'INSERT INTO orders
                (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
                 currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status,
                 port_of_loading_id, port_of_discharge_id,
                 incoterm_code, currency_code, preset_name, advance_pct, advance_trigger_text, payment_method,
                 balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, show_bl_instructions,
                 port_of_loading_name, port_of_loading_city, port_of_discharge_name, port_of_discharge_city,
                 company_legal_name, billing_address, billing_address_line1, billing_address_line2,
                 billing_city, billing_postcode, billing_country, vat_eori_tax_no, contact_person,
                 client_email, client_phone, country_of_destination,
                 consignee_same_as_buyer, consignee_name, consignee_address, consignee_address_line1,
                 consignee_address_line2, consignee_city, consignee_postcode, consignee_country,
                 consignee_vat_eori_tax_no, consignee_contact_person, consignee_phone, consignee_email,
                 notify_party_same_as_consignee, notify_party, notify_party_address_line1,
                 notify_party_address_line2, notify_party_city, notify_party_postcode, notify_party_country,
                 notify_party_contact_person, notify_party_phone, notify_party_email)
             VALUES
                (:ref, :client_id, 1, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, \'TBC\', \'NIL\',
                 CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), \'active\',
                 :port_of_loading_id, :port_of_discharge_id,
                 :incoterm_code, :currency_code, :preset_name, :advance_pct, :advance_trigger_text, :payment_method,
                 :balance_pct, :balance_trigger_option, :balance_days, :balance_trigger_wording, :show_bl_instructions,
                 :port_of_loading_name, :port_of_loading_city, :port_of_discharge_name, :port_of_discharge_city,
                 :company_legal_name, :billing_address, :billing_address_line1, :billing_address_line2,
                 :billing_city, :billing_postcode, :billing_country, :vat_eori_tax_no, :contact_person,
                 :client_email, :client_phone, :country_of_destination,
                 :consignee_same_as_buyer, :consignee_name, :consignee_address, :consignee_address_line1,
                 :consignee_address_line2, :consignee_city, :consignee_postcode, :consignee_country,
                 :consignee_vat_eori_tax_no, :consignee_contact_person, :consignee_phone, :consignee_email,
                 :notify_party_same_as_consignee, :notify_party, :notify_party_address_line1,
                 :notify_party_address_line2, :notify_party_city, :notify_party_postcode, :notify_party_country,
                 :notify_party_contact_person, :notify_party_phone, :notify_party_email)'
        );
        $stmt->execute([
            'ref'         => 'PHPUNIT-TEST-' . bin2hex(random_bytes(4)),
            'client_id'   => $clientId,
            'inquiry_ref' => 'PHPUNIT-' . bin2hex(random_bytes(3)),
            'preset_id'   => $presetId,
            'incoterm_id' => $incotermId,
            'currency_id' => $currencyId,
            'port_of_loading_id'      => $portOfLoadingId,
            'port_of_discharge_id'    => $portOfDischargeId,
            'incoterm_code'           => $incoterm['code'],
            'currency_code'           => $currency['code'],
            'preset_name'             => $preset['preset_name'],
            'advance_pct'             => $preset['advance_pct'],
            'advance_trigger_text'    => $preset['advance_trigger_text'],
            'payment_method'          => $preset['payment_method'],
            'balance_pct'             => $preset['balance_pct'],
            'balance_trigger_option'  => $preset['balance_trigger_option'],
            'balance_days'            => $preset['balance_days'],
            'balance_trigger_wording' => $preset['balance_trigger_wording'],
            'show_bl_instructions'    => $preset['show_bl_instructions'],
            'port_of_loading_name'    => $portOfLoading['name'],
            'port_of_loading_city'    => $portOfLoading['city'],
            'port_of_discharge_name'  => $portOfDischarge['name'],
            'port_of_discharge_city'  => $portOfDischarge['city'],
            'company_legal_name'      => $client['company_legal_name'] ?? null,
            'billing_address'         => $client['billing_address'] ?? null,
            'billing_address_line1'  => $client['billing_address_line1'] ?? null,
            'billing_address_line2'  => $client['billing_address_line2'] ?? null,
            'billing_city'            => $client['billing_city'] ?? null,
            'billing_postcode'        => $client['billing_postcode'] ?? null,
            'billing_country'         => $client['billing_country'] ?? null,
            'vat_eori_tax_no'         => $client['vat_eori_tax_no'] ?? null,
            'contact_person'          => $client['contact_person'] ?? null,
            'client_email'            => $client['email'] ?? null,
            'client_phone'            => $client['phone'] ?? null,
            'country_of_destination'  => $client['country_of_destination'] ?? null,
            'consignee_same_as_buyer' => $client['consignee_same_as_buyer'] ?? 1,
            'consignee_name'          => $client['consignee_name'] ?? null,
            'consignee_address'       => $client['consignee_address'] ?? null,
            'consignee_address_line1' => $client['consignee_address_line1'] ?? null,
            'consignee_address_line2' => $client['consignee_address_line2'] ?? null,
            'consignee_city'          => $client['consignee_city'] ?? null,
            'consignee_postcode'      => $client['consignee_postcode'] ?? null,
            'consignee_country'       => $client['consignee_country'] ?? null,
            'consignee_vat_eori_tax_no' => $client['consignee_vat_eori_tax_no'] ?? null,
            'consignee_contact_person'  => $client['consignee_contact_person'] ?? null,
            'consignee_phone'         => $client['consignee_phone'] ?? null,
            'consignee_email'         => $client['consignee_email'] ?? null,
            'notify_party_same_as_consignee' => $client['notify_party_same_as_consignee'] ?? 1,
            'notify_party'            => $client['notify_party'] ?? null,
            'notify_party_address_line1' => $client['notify_party_address_line1'] ?? null,
            'notify_party_address_line2' => $client['notify_party_address_line2'] ?? null,
            'notify_party_city'       => $client['notify_party_city'] ?? null,
            'notify_party_postcode'   => $client['notify_party_postcode'] ?? null,
            'notify_party_country'    => $client['notify_party_country'] ?? null,
            'notify_party_contact_person' => $client['notify_party_contact_person'] ?? null,
            'notify_party_phone'      => $client['notify_party_phone'] ?? null,
            'notify_party_email'      => $client['notify_party_email'] ?? null,
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
