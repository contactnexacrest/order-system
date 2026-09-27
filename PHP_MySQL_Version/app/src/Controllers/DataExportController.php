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
        $this->stream(MysqlDumpService::dumpData(), 'data', 'DATA_EXPORT_DATA');
    }

    private function stream(string $tmpPath, string $kind, string $auditAction): void
    {
        $user = AuthService::currentUser();
        $filename = sprintf('nexacrest-%s-%s.sql', $kind, date('Ymd-His'));

        AuditLogRepository::log($user ? (int) $user['id'] : null, $auditAction, 'data_export', null, null, null, $filename);

        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($tmpPath));
        header('Cache-Control: no-store');
        readfile($tmpPath);
        unlink($tmpPath);
    }
}
