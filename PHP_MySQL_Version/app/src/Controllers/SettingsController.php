<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Services\AuthService;

final class SettingsController
{
    public function index(array $params): void
    {
        $settings = CompanySettingsRepository::all();
        $grouped = [];
        foreach ($settings as $row) {
            $grouped[$row['category']][] = $row;
        }
        View::render('settings/index', ['grouped' => $grouped], 'layout/base');
    }

    public function update(array $params): void
    {
        $user = AuthService::currentUser();
        $submitted = $_POST['settings'] ?? [];
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $unlocked = $_POST['unlocked'] ?? []; // { [setting_key]: '1' } — set only by the explicit unlock gesture

        if (!is_array($submitted)) {
            Flash::set('error', 'Malformed submission.');
            header('Location: /settings');
            return;
        }

        $all = CompanySettingsRepository::all();
        $byKey = [];
        foreach ($all as $row) {
            $byKey[$row['setting_key']] = $row;
        }

        // First pass: find what actually changed, without writing anything
        // yet — a reason is only mandatory when there's something to
        // justify (Section 13: "EVERY SUCH EDIT: Reason text: mandatory").
        $toApply = [];
        foreach ($submitted as $key => $newValue) {
            if (!isset($byKey[$key])) {
                continue; // unknown key submitted — ignore rather than trust client input
            }
            $oldValue = $byKey[$key]['setting_value'];
            $newValue = trim((string) $newValue);
            if ($oldValue !== $newValue) {
                $toApply[$key] = ['old' => $oldValue, 'new' => $newValue, 'id' => (int) $byKey[$key]['id']];
            }
        }

        if (empty($toApply)) {
            Flash::set('success', 'No changes were made.');
            header('Location: /settings');
            return;
        }

        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: /settings');
            return;
        }

        // Server-side enforcement of the unlock gesture — independent of
        // the client-side UI, which can be bypassed. A protected field
        // changed without its unlock flag present fails the whole
        // submission (nothing partial is saved), same fail-closed
        // behavior as a missing reason.
        $blockedProtected = [];
        foreach ($toApply as $key => $change) {
            if ($byKey[$key]['is_protected'] && ($unlocked[$key] ?? null) !== '1') {
                $blockedProtected[] = $key;
            }
        }
        if (!empty($blockedProtected)) {
            Flash::set('error', 'Protected field(s) must be unlocked before editing (' . implode(', ', $blockedProtected) . ') — nothing was saved.');
            header('Location: /settings');
            return;
        }

        foreach ($toApply as $key => $change) {
            CompanySettingsRepository::set($key, $change['new'], (int) $user['id']);
            if ($byKey[$key]['is_protected']) {
                AuditLogRepository::log((int) $user['id'], 'PROTECTED_FIELD_UNLOCKED', 'company_settings', $change['id'], $key, null, null, $reason);
            }
            AuditLogRepository::log(
                (int) $user['id'],
                'FIELD_EDIT',
                'company_settings',
                $change['id'],
                $key,
                $change['old'],
                $change['new'],
                $reason
            );
        }

        Flash::set('success', count($toApply) . ' setting(s) updated.');
        header('Location: /settings');
    }
}
