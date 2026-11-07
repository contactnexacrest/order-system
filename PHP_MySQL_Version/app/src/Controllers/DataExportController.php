<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Services\AuthService;
use App\Services\MysqlDumpService;

/**
 * Point 6 — "we will have a migrate data / export data button in our
 * application... visible to role+permission person and of course
 * superadmin always." Gated on the data_export_run permission at route
 * level (see public_html/index.php); a Super Admin already gets every
 * permission via PermissionService's unconditional bypass, so no extra
 * check is needed here.
 *
 * Every export is a real, streamed database dump of the live app
 * database — nothing is faked or sampled — so each run is logged to the
 * audit log (who, when, which file), matching how every other
 * sensitive/irreversible action in this app is tracked.
 */
final class DataExportController
{
    public function index(array $params): void
    {
        View::render('data_export/index', [
            'dbName' => Env::get('DB_DATABASE'),
        ], 'layout/base');
    }

    public function downloadSchema(array $params): void
    {
        $this->stream(MysqlDumpService::dumpSchema(), 'schema', 'DATA_EXPORT_SCHEMA');
    }

    public function downloadData(array $params): void
    {
        // QA ACC-18: the data dump holds every division's records, all money and
        // CAFIN — so it also needs those rights, not just data_export_run.
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        foreach (['view_cafin', 'see_all_divisions'] as $key) {
            if (!\App\Services\PermissionService::can((int) $user['id'], $roleId, $key)) {
                http_response_code(403);
                echo 'The data export holds every division\'s records, all money and CAFIN: it needs "View & download CAFIN" and "See all divisions" as well.';
                return;
            }
        }
        $this->stream(MysqlDumpService::dumpData(), 'data', 'DATA_EXPORT_DATA');
    }

    private function stream(string $tmpPath, string $kind, string $auditAction): void
    {
        // The dump is a large temporary file (it can be well over 1 GB): it must be deleted
        // even when the download is cancelled or the connection drops part-way, or each
        // aborted download leaves a full copy of the database on the server's disk.
        ignore_user_abort(true);
        register_shutdown_function(static function () use ($tmpPath): void {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        });
        $user = AuthService::currentUser();
        $filename = sprintf('nexacrest-%s-%s.sql', $kind, date('Ymd-His'));

        AuditLogRepository::log($user ? (int) $user['id'] : null, $auditAction, 'data_export', null, null, null, $filename);

        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($tmpPath));
        header('Cache-Control: no-store');
        try {
            readfile($tmpPath);
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
