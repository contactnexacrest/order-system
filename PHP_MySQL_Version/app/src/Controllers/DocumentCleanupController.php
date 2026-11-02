<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Services\AuthService;
use App\Services\DocumentCopyCleanupService as Cleanup;

/**
 * Owner points 5 / 8 / 9 (2 Nov 2026): Admin → Settings → Document copies
 * clean-up. Its own page (manage_company_settings) because each setting has
 * a fixed list of allowed values and the page shows what would be removed
 * before anything is. See DocumentCopyCleanupService for the rules.
 */
final class DocumentCleanupController
{
    public const CATEGORY = 'document_cleanup';
    private const PREVIEW_LIMIT = 200;

    public function index(array $params): void
    {
        $settings = Cleanup::settings();
        $candidates = Cleanup::anyOn($settings) ? Cleanup::candidates($settings) : [];
        View::render('settings/document_cleanup', [
            'settings' => $settings,
            'estimate' => Cleanup::estimate($settings),
            'candidates' => array_slice($candidates, 0, self::PREVIEW_LIMIT),
            'candidateCount' => count($candidates),
            'candidateBytes' => array_sum(array_map(static fn (array $r): int => (int) $r['file_size_bytes'], $candidates)),
            'previewLimit' => self::PREVIEW_LIMIT,
        ], 'layout/base');
    }

    public function save(array $params): void
    {
        $user = AuthService::currentUser();
        $draft = (int) ($_POST['draft_days'] ?? -1);
        $superseded = (int) ($_POST['superseded_days'] ?? -1);
        $cafin = (string) ($_POST['cafin_mode'] ?? '');
        if (!in_array($draft, Cleanup::DRAFT_OPTIONS, true)
            || !in_array($superseded, Cleanup::SUPERSEDED_OPTIONS, true)
            || !in_array($cafin, Cleanup::CAFIN_OPTIONS, true)) {
            Flash::set('error', 'Choose one of the listed options for each rule. Nothing was saved.');
            header('Location: /settings/document-cleanup');
            return;
        }
        $new = [Cleanup::KEY_DRAFT => (string) $draft, Cleanup::KEY_SUPERSEDED => (string) $superseded, Cleanup::KEY_CAFIN => $cafin];
        $changes = [];
        foreach ($new as $key => $value) {
            $old = CompanySettingsRepository::get($key);
            if ($old !== $value) {
                $changes[$key] = [$old, $value];
            }
        }
        if ($changes === []) {
            Flash::set('success', 'No changes were made.');
            header('Location: /settings/document-cleanup');
            return;
        }
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: /settings/document-cleanup');
            return;
        }
        foreach ($changes as $key => [$old, $value]) {
            CompanySettingsRepository::set($key, $value, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'FIELD_EDIT', 'company_settings', null, $key, $old, $value, $reason);
        }
        Flash::set('success', 'Document copies clean-up settings saved.' . (Cleanup::anyOn(Cleanup::settings()) ? ' The daily job removes what the preview below lists; you can also run it now.' : ' Every rule is off — nothing will be removed.'));
        header('Location: /settings/document-cleanup');
    }

    public function run(array $params): void
    {
        $user = AuthService::currentUser();
        if (($_POST['confirm_permanent'] ?? '') !== '1') {
            Flash::set('error', 'Tick "I understand removed copies cannot be restored" to run the clean-up.');
            header('Location: /settings/document-cleanup');
            return;
        }
        $result = Cleanup::run((int) $user['id'], 'manual');
        if ($result['skipped_off']) {
            Flash::set('error', 'Every rule is off — switch at least one on and save first. Nothing was removed.');
        } else {
            Flash::set('success', sprintf('Clean-up done: %d copy(ies) removed, %s freed.', $result['files'], Cleanup::formatBytes($result['bytes'])));
        }
        header('Location: /settings/document-cleanup');
    }
}
