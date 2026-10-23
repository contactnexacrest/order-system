<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CaController;
use App\Repositories\CaFyLockRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report N-08, Cluster C: the CA module's Zoho sync, bank
 * statement upload, and financial-year lock/unlock actions never called
 * AuditLogRepository::log(), so none of them ever notified anyone either.
 */
final class N08AuditLogCoverageClusterCTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
    }

    private function lastAuditLogAction(): ?array
    {
        $stmt = Database::connection()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
        return $stmt->fetch() ?: null;
    }

    public function testRunZohoSyncIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new CaController())->runZohoSync([]);
        ob_end_clean();

        self::assertSame('CA_ZOHO_SYNC_RUN', $this->lastAuditLogAction()['action_type']);
    }

    public function testUploadBankStatementIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        $csvPath = tempnam(sys_get_temp_dir(), 'n08-bank-statement-') . '.csv';
        file_put_contents($csvPath, "Date,Description,Reference,Credit,Debit\n2026-10-20,Test credit,REF123,1000.00,\n");
        $_FILES['statement'] = ['name' => 'statement.csv', 'tmp_name' => $csvPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csvPath)];

        ob_start();
        (new CaController())->uploadBankStatement([]);
        ob_end_clean();

        self::assertSame('CA_BANK_STATEMENT_UPLOADED', $this->lastAuditLogAction()['action_type']);
        unlink($csvPath);
    }

    public function testLockFinancialYearIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['financial_year' => '2025-26'];

        ob_start();
        (new CaController())->lockFinancialYear([]);
        ob_end_clean();

        $audit = $this->lastAuditLogAction();
        self::assertSame('CA_FINANCIAL_YEAR_LOCKED', $audit['action_type']);
        self::assertSame('2025-26', $audit['new_value']);
    }

    public function testUnlockFinancialYearIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        CaFyLockRepository::lock('2024-25', $userId);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['financial_year' => '2024-25', 'unlock_reason' => 'Correcting a posting error found during audit.'];

        ob_start();
        (new CaController())->unlockFinancialYear([]);
        ob_end_clean();

        $audit = $this->lastAuditLogAction();
        self::assertSame('CA_FINANCIAL_YEAR_UNLOCKED', $audit['action_type']);
        self::assertSame('2024-25', $audit['old_value']);
    }
}
