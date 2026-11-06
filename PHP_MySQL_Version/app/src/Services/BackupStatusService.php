<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Owner decisions D-260 (6 Nov 2026): the app only READS what the backup scripts
 * (scripts/backup_db.sh, backup_storage.sh, backup_test_restore.sh) write to
 * BACKUP_DIR/status/*.json. It never runs, changes or deletes a backup.
 * Used by Admin › Backups and by the daily digest (a warning to whoever can
 * manage company settings when a backup is missing, failed or too old).
 */
final class BackupStatusService
{
    public const STALE_HOURS = 26;
    public const RESTORE_TEST_STALE_DAYS = 35;
    public const JOBS = [
        'db' => 'Database copy (nightly)',
        'storage' => 'Documents copy (nightly)',
        'restore_test' => 'Test-restore (monthly)',
    ];

    /** @var int|null tests only: "now" as a Unix time */
    private static ?int $now = null;

    public static function setNow(?int $now): void
    {
        self::$now = $now;
    }

    public static function backupDir(): string
    {
        return rtrim((string) Env::get('BACKUP_DIR', ''), '/');
    }

    /** Settings as the scripts read them (same defaults). @return array<string,string> */
    public static function settings(): array
    {
        return [
            'backup_dir' => self::backupDir(),
            'keep_years' => (string) Env::get('BACKUP_KEEP_YEARS', '10'),
            'thin_after_days' => (string) Env::get('BACKUP_THIN_AFTER_DAYS', '120'),
            'offsite_target' => (string) Env::get('BACKUP_OFFSITE_TARGET', ''),
            'encryption_key_file' => (string) Env::get('BACKUP_ENCRYPTION_KEY_FILE', ''),
        ];
    }

    /**
     * One row per job: state ok | stale | failed | missing | not_set, with a plain message.
     * @return array<string, array<string,mixed>>
     */
    public static function jobs(): array
    {
        $dir = self::backupDir();
        $now = self::$now ?? time();
        $out = [];
        foreach (self::JOBS as $job => $label) {
            $row = ['job' => $job, 'label' => $label, 'state' => 'not_set', 'message' => 'BACKUP_DIR is not set in app/.env — no backups are being made.', 'status' => null, 'age_hours' => null];
            if ($dir !== '') {
                $file = "{$dir}/status/{$job}.json";
                $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                if (!is_array($status)) {
                    $row['state'] = 'missing';
                    $row['message'] = 'No run recorded yet — check the cron line for this job.';
                } else {
                    $finished = strtotime((string) ($status['finished_at'] ?? '')) ?: 0;
                    $ageHours = $finished > 0 ? (int) floor(($now - $finished) / 3600) : null;
                    $limit = $job === 'restore_test' ? self::RESTORE_TEST_STALE_DAYS * 24 : self::STALE_HOURS;
                    $row['status'] = $status;
                    $row['age_hours'] = $ageHours;
                    if (($status['result'] ?? '') !== 'ok') {
                        $row['state'] = 'failed';
                        $row['message'] = 'Last run FAILED: ' . (string) ($status['message'] ?? '');
                    } elseif ($ageHours === null || $ageHours > $limit) {
                        $row['state'] = 'stale';
                        $row['message'] = 'Last good run was ' . ($ageHours === null ? 'at an unknown time' : self::ago($ageHours)) . ' — the job has stopped running.';
                    } else {
                        $row['state'] = 'ok';
                        $row['message'] = (string) ($status['message'] ?? '');
                    }
                }
            }
            $out[$job] = $row;
        }
        return $out;
    }

    /**
     * Warnings for the daily digest. Empty when all is well. "Not configured" warns only on a
     * production server (a dev or test copy has no backups on purpose).
     * @return list<string>
     */
    public static function problems(): array
    {
        if (self::backupDir() === '') {
            return strtolower((string) Env::get('APP_ENV', 'production')) === 'production'
                ? ['Backups are not set up: BACKUP_DIR is empty in app/.env, so no backup copies are being made.']
                : [];
        }
        $problems = [];
        foreach (self::jobs() as $row) {
            if ($row['state'] !== 'ok') {
                $problems[] = $row['label'] . ': ' . $row['message'];
            } elseif ($row['job'] !== 'restore_test' && ($row['status']['encrypted'] ?? 'no') !== 'yes') {
                $problems[] = $row['label'] . ': made, but NOT encrypted (set BACKUP_ENCRYPTION_KEY_FILE).';
            }
            if (str_starts_with((string) ($row['status']['offsite'] ?? ''), 'failed')) {
                $problems[] = $row['label'] . ': off-site copy ' . $row['status']['offsite'] . '.';
            }
        }
        return $problems;
    }

    public static function ago(int $hours): string
    {
        return $hours < 48 ? "{$hours} hours ago" : intdiv($hours, 24) . ' days ago';
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['bytes', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $bytes : number_format($v, 1)) . ' ' . $units[$i];
    }
}
