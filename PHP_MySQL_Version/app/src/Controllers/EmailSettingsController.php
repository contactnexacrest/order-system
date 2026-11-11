<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Services\AuthService;
use App\Services\EmailPolicy;
use App\Services\StaffRouting;

/**
 * Owner decision D-280 / D-281 (11 Nov 2026, point 3): Admin › E-mail settings
 * (permission manage_company_settings). One row per e-mail, one switch per
 * audience; MUST e-mails locked on; testing mode; and, for company-level
 * compliance alerts, the responsible person and the backup per item.
 * Every save needs a reason and is in the Audit Log.
 */
final class EmailSettingsController
{
    public function index(array $params): void
    {
        $staff = Database::connection()->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
        View::render('settings/email_settings', [
            'matrix' => EmailPolicy::matrix(),
            'testingMode' => EmailPolicy::testingMode(),
            'owners' => StaffRouting::companyOwners(),
            'staff' => $staff,
        ], 'layout/base');
    }

    public function save(array $params): void
    {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: /admin/email-settings');
            return;
        }
        $userId = (int) AuthService::currentUserId();
        $on = is_array($_POST['on'] ?? null) ? $_POST['on'] : [];
        $changes = EmailPolicy::save($on, ($_POST['testing_mode'] ?? '0') === '1', $userId, $reason);
        $owners = is_array($_POST['owners'] ?? null) ? $_POST['owners'] : [];
        $changes = array_merge($changes, array_map(static fn (string $l): string => 'Who is told: ' . $l, StaffRouting::saveCompanyOwners($owners, $userId, $reason)));
        Flash::set('success', $changes === [] ? 'Nothing changed.' : 'Saved: ' . implode('; ', $changes) . '.');
        header('Location: /admin/email-settings');
    }
}
