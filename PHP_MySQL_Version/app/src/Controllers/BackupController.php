<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Services\BackupStatusService;

/** D-260: Admin › Backups — read-only status of the backup scripts (manage_company_settings). */
final class BackupController
{
    public function index(array $params): void
    {
        View::render('backups/index', [
            'jobs' => BackupStatusService::jobs(),
            'settings' => BackupStatusService::settings(),
            'problems' => BackupStatusService::problems(),
        ], 'layout/base');
    }
}
