<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\SettingValueValidator;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Services\AuthService;
use App\Services\EmailService;
use App\Services\SuperAdminService;
use App\Services\ZohoMailService;

final class SettingsController
{
    public function index(array $params): void
    {
        $settings = CompanySettingsRepository::all();
        $grouped = [];
        foreach ($settings as $row) {
            $grouped[$row['category']][] = $row;
        }
        $user = AuthService::currentUser();
        View::render('settings/index', [
            'grouped' => $grouped,
            'isSuperAdmin' => SuperAdminService::isEffective((int) $user['id']),
        ], 'layout/base');
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

        // QA-5 SET-02: company_settings.value_type was defined in the
        // schema but never actually enforced — any string could be saved
        // into a 'number' setting like session_timeout_minutes, parsing to
        // garbage everywhere it's later read. Same fail-closed contract as
        // the protected-field check below: one bad value fails the whole
        // submission.
        $valueErrors = [];
        foreach ($toApply as $key => $change) {
            $error = SettingValueValidator::check($byKey[$key]['value_type'], $change['new']);
            if ($error !== null) {
                $valueErrors[] = "{$key} {$error}";
            }
        }
        if (!empty($valueErrors)) {
            Flash::set('error', 'Invalid value(s) — nothing was saved: ' . implode('; ', $valueErrors));
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

        // docs/schema.sql Section AS: requires_super_admin fields (the mail
        // redirect CC list) refuse the edit outright for anyone who isn't
        // an effective Super Admin — no unlock gesture can override this,
        // unlike is_protected above.
        $blockedSuperAdmin = [];
        if (!SuperAdminService::isEffective((int) $user['id'])) {
            foreach ($toApply as $key => $change) {
                if ($byKey[$key]['requires_super_admin']) {
                    $blockedSuperAdmin[] = $key;
                }
            }
        }
        if (!empty($blockedSuperAdmin)) {
            Flash::set('error', 'Only a Super Admin can change: ' . implode(', ', $blockedSuperAdmin) . ' — nothing was saved.');
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

    /**
     * docs/schema.sql Section AI — calls ZohoMailService directly (never
     * through MailSenderService's fallback) so a real Zoho error surfaces
     * here instead of silently succeeding via SMTP, which would be
     * useless for actually verifying the Zoho credentials just entered.
     */
    public function testZohoEmail(array $params): void
    {
        $user = AuthService::currentUser();
        $to = trim((string) ($_POST['test_to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Enter a valid email address to send the test to.');
            header('Location: /settings');
            return;
        }

        try {
            ZohoMailService::send(
                $to,
                'NexaCrest — Zoho Mail test',
                "This is a test email sent from the NexaCrest order system's Zoho Mail integration.\n\nIf you received this, the connection is working.\n\nSent by {$user['name']}."
            );
            Flash::set('success', "Test email sent via Zoho Mail to {$to}.");
        } catch (\Throwable $e) {
            Flash::set('error', 'Zoho test send failed: ' . $e->getMessage());
        }
        header('Location: /settings');
    }

    /**
     * docs/schema.sql Section AS — calls EmailService::sendTestEmail()
     * directly (never through MailSenderService's Zoho-first fallback, and
     * never through Test Mode/Mail Redirect) so a real SMTP error surfaces
     * here instead of silently logging, which would be useless for
     * actually verifying the SMTP_* .env credentials just configured.
     */
    public function testSmtpEmail(array $params): void
    {
        $user = AuthService::currentUser();
        $to = trim((string) ($_POST['test_to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Enter a valid email address to send the test to.');
            header('Location: /settings');
            return;
        }

        try {
            EmailService::sendTestEmail(
                $to,
                'NexaCrest — SMTP test',
                "This is a test email sent from the NexaCrest order system's SMTP connection.\n\nIf you received this, the connection is working.\n\nSent by {$user['name']}."
            );
            Flash::set('success', "Test email sent via SMTP to {$to}.");
        } catch (\Throwable $e) {
            Flash::set('error', 'SMTP test send failed: ' . $e->getMessage());
        }
        header('Location: /settings');
    }
}
