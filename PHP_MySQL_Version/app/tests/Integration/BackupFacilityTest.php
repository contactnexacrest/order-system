<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\BackupController;
use App\Services\BackupStatusService;
use App\Services\DailyDigestService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions D-260 (6 Nov 2026): the 10-year backup facility.
 *  - scripts/tests/backup_scripts_test.sh proves, in a temp folder with fake data: retention (10 years),
 *    thinning to one copy a month after 120 days, documents stored once (hard links), live files untouched,
 *    the guard against a backup folder inside the live storage, only backup-named files removed, encryption,
 *    checksums, test-restore refusing the live database.
 *  - The app only reads the scripts' status files: Admin › Backups and the daily-digest warning.
 * Node twin: tests/integration/backupFacility.test.js.
 */
final class BackupFacilityTest extends DbTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/nexacrest_backup_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/status', 0700, true);
        $_ENV['BACKUP_DIR'] = $this->dir;
        BackupStatusService::setNow(strtotime('2026-11-06 09:00:00 +0530'));
    }

    protected function tearDown(): void
    {
        unset($_ENV['BACKUP_DIR'], $_ENV['APP_ENV'], $_ENV['BACKUP_ENCRYPTION_KEY_FILE']);
        putenv('BACKUP_DIR');
        BackupStatusService::setNow(null);
        array_map('unlink', glob($this->dir . '/status/*') ?: []);
        @rmdir($this->dir . '/status');
        @rmdir($this->dir);
        $_SESSION = [];
        parent::tearDown();
    }

    private function writeStatus(string $job, string $result, string $finished, string $encrypted = 'yes', string $offsite = 'not configured'): void
    {
        file_put_contents("{$this->dir}/status/{$job}.json", json_encode([
            'job' => $job, 'result' => $result, 'finished_at' => $finished, 'message' => "{$job} message",
            'file' => "nexacrest_{$job}_2026-11-06.sql.gz.enc", 'size_bytes' => 1536000, 'encrypted' => $encrypted, 'offsite' => $offsite,
        ]));
    }

    public function testTheBackupScriptsSelfTestPasses(): void
    {
        $script = dirname(__DIR__, 3) . '/scripts/tests/backup_scripts_test.sh';
        self::assertFileExists($script);
        exec('bash ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
        $text = implode("\n", $out);
        self::assertSame(0, $rc, $text);
        self::assertStringContainsString('0 failed', $text);
        self::assertStringContainsString('PASS unchanged file stored once', $text);
        self::assertStringContainsString('PASS live documents untouched', $text);
        self::assertStringContainsString('PASS backup dir inside storage is refused', $text);
    }

    public function testStatusStatesAndTheDigestWarnings(): void
    {
        $this->writeStatus('db', 'ok', '2026-11-06T02:00:05+0530');
        $this->writeStatus('storage', 'ok', '2026-11-04T02:30:00+0530', 'no');           // 54 hours old
        $this->writeStatus('restore_test', 'failed', '2026-11-01T04:00:00+0530');
        $jobs = BackupStatusService::jobs();
        self::assertSame('ok', $jobs['db']['state']);
        self::assertSame(6, $jobs['db']['age_hours']);
        self::assertSame('stale', $jobs['storage']['state']);
        self::assertSame('Last good run was 2 days ago — the job has stopped running.', $jobs['storage']['message']);
        self::assertSame('failed', $jobs['restore_test']['state']);
        self::assertSame([
            'Documents copy (nightly): Last good run was 2 days ago — the job has stopped running.',
            'Test-restore (monthly): Last run FAILED: restore_test message',
        ], BackupStatusService::problems());

        $this->writeStatus('storage', 'ok', '2026-11-06T02:30:00+0530', 'no', 'failed: rclone copy returned an error');
        $this->writeStatus('restore_test', 'ok', '2026-11-01T04:00:00+0530');
        self::assertSame([
            'Documents copy (nightly): made, but NOT encrypted (set BACKUP_ENCRYPTION_KEY_FILE).',
            'Documents copy (nightly): off-site copy failed: rclone copy returned an error.',
        ], BackupStatusService::problems());

        unlink("{$this->dir}/status/db.json");
        self::assertSame('missing', BackupStatusService::jobs()['db']['state']);

        $_ENV['BACKUP_DIR'] = '';
        $_ENV['APP_ENV'] = 'local';
        self::assertSame([], BackupStatusService::problems(), 'a dev copy without backups is not warned about');
        $_ENV['APP_ENV'] = 'production';
        self::assertSame(['Backups are not set up: BACKUP_DIR is empty in app/.env, so no backup copies are being made.'], BackupStatusService::problems());
        self::assertSame('not_set', BackupStatusService::jobs()['db']['state']);
    }

    public function testTheDigestWarnsOnlyThoseWhoManageCompanySettings(): void
    {
        $this->writeStatus('db', 'failed', '2026-11-06T02:00:05+0530');
        $this->writeStatus('storage', 'ok', '2026-11-06T02:30:00+0530');
        $this->writeStatus('restore_test', 'ok', '2026-11-01T04:00:00+0530');
        $md = $this->createTestUser('Managing Director');
        $exec = $this->createTestUser('Export Executive');
        $mdItems = array_values(array_filter(DailyDigestService::itemsFor($md), static fn (array $i): bool => $i['kind'] === 'backup'));
        self::assertSame([['kind' => 'backup', 'text' => 'Database copy (nightly): Last run FAILED: db message']], $mdItems);
        self::assertSame([], array_values(array_filter(DailyDigestService::itemsFor($exec), static fn (array $i): bool => $i['kind'] === 'backup')));
        $mail = DailyDigestService::compose('Asha', $mdItems, '2026-11-06');
        self::assertSame('NexaCrest — your day 6 Nov 2026: 0 tasks, 0 reviews — BACKUP WARNING', $mail['subject']);
        self::assertStringContainsString("Backups need attention (Admin › Backups):\n- Database copy (nightly): Last run FAILED: db message", $mail['body']);
    }

    public function testTheAdminBackupsPageShowsTheRunsAndSettings(): void
    {
        $this->writeStatus('db', 'ok', '2026-11-06T02:00:05+0530');
        $this->writeStatus('storage', 'ok', '2026-11-06T02:30:00+0530');
        $_ENV['BACKUP_ENCRYPTION_KEY_FILE'] = '/home/x/.backup_key';
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Managing Director')];
        ob_start();
        (new BackupController())->index([]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('<h1>Backups</h1>', $html);
        self::assertStringContainsString('data-job="db"', $html);
        self::assertStringContainsString('1.5 MB', $html);
        self::assertStringContainsString('No run yet', $html, 'the test-restore has not run');
        self::assertStringContainsString($this->dir, $html);
        self::assertStringContainsString('10 years', $html);
        self::assertStringContainsString('On (key file set', $html);
        self::assertStringContainsString('Not chosen yet — copies stay on this server only', $html);
        self::assertStringNotContainsString('/home/x/.backup_key', $html, 'the key file path is never shown');
        self::assertStringContainsString('href="/admin/backups"', $html, 'Admin menu link');
    }
}
