<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AdminOverrideRepository;
use App\Repositories\AuditLogRepository;
use App\Services\AuthService;

/** Spec Section 13 — see AdminOverrideRepository's docblock for exact scope. */
final class AdminOverrideController
{
    public function index(array $params): void
    {
        View::render('admin_overrides/index', [
            'documentTypes' => AdminOverrideRepository::documentTypes(),
            'tcClauses' => AdminOverrideRepository::tcClauses(),
            'paymentPresets' => AdminOverrideRepository::paymentPresets(),
        ], 'layout/base');
    }

    public function updateDocumentTypes(array $params): void
    {
        $user = AuthService::currentUser();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $rows = AdminOverrideRepository::documentTypes();
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $toApply = [];
        foreach (($_POST['ref_format'] ?? []) as $id => $newRefFormat) {
            $id = (int) $id;
            if (!isset($byId[$id])) {
                continue;
            }
            $newRefFormat = trim((string) $newRefFormat) ?: null;
            $newMinReviewers = max(0, (int) ($_POST['min_reviewers'][$id] ?? $byId[$id]['min_reviewers_default']));
            if ($newRefFormat !== $byId[$id]['ref_format'] || $newMinReviewers !== (int) $byId[$id]['min_reviewers_default']) {
                $toApply[$id] = ['old_ref' => $byId[$id]['ref_format'], 'new_ref' => $newRefFormat, 'old_min' => (int) $byId[$id]['min_reviewers_default'], 'new_min' => $newMinReviewers, 'code' => $byId[$id]['code']];
            }
        }

        if (empty($toApply)) {
            Flash::set('success', 'No changes were made.');
            header('Location: /admin/overrides');
            return;
        }
        if ($reason === '') {
            Flash::set('error', 'A reason is required to save this change — nothing was saved.');
            header('Location: /admin/overrides');
            return;
        }

        foreach ($toApply as $id => $c) {
            AdminOverrideRepository::updateDocumentTypeRefFormat($id, $c['new_ref'], $c['new_min']);
            if ($c['old_ref'] !== $c['new_ref']) {
                AuditLogRepository::log((int) $user['id'], 'FIELD_EDIT', 'document_types', $id, 'ref_format', $c['old_ref'], $c['new_ref'], $reason);
            }
            if ($c['old_min'] !== $c['new_min']) {
                AuditLogRepository::log((int) $user['id'], 'FIELD_EDIT', 'document_types', $id, 'min_reviewers_default', (string) $c['old_min'], (string) $c['new_min'], $reason);
            }
        }
        Flash::set('success', count($toApply) . ' document type(s) updated.');
        header('Location: /admin/overrides');
    }

    public function updateTcClauses(array $params): void
    {
        $user = AuthService::currentUser();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $unlockedRaw = $_POST['unlocked_clause'] ?? []; // { [id]: '1' }
        $unlockedIds = [];
        foreach ($unlockedRaw as $k => $v) {
            if ($v === '1') {
                $unlockedIds[(int) $k] = true;
            }
        }
        $rows = AdminOverrideRepository::tcClauses();
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $toApply = [];
        foreach (($_POST['clause_text'] ?? []) as $id => $newText) {
            $id = (int) $id;
            if (!isset($byId[$id])) {
                continue;
            }
            $newTitle = trim((string) ($_POST['clause_title'][$id] ?? $byId[$id]['clause_title']));
            $newText = trim((string) $newText);
            if ($newTitle !== $byId[$id]['clause_title'] || $newText !== $byId[$id]['clause_text']) {
                $toApply[$id] = ['old_title' => $byId[$id]['clause_title'], 'new_title' => $newTitle, 'old_text' => $byId[$id]['clause_text'], 'new_text' => $newText];
            }
        }

        if (empty($toApply)) {
            Flash::set('success', 'No changes were made.');
            header('Location: /admin/overrides');
            return;
        }
        if ($reason === '') {
            Flash::set('error', 'A reason is required to save this change — nothing was saved.');
            header('Location: /admin/overrides');
            return;
        }

        // Server-side enforcement of the unlock gesture — independent of
        // the client-side UI, which can be bypassed. A protected clause
        // changed without its unlock flag present fails the whole
        // submission (nothing partial is saved).
        $blockedProtected = [];
        foreach ($toApply as $id => $c) {
            if ($byId[$id]['is_protected'] && !isset($unlockedIds[$id])) {
                $blockedProtected[] = $id;
            }
        }
        if (!empty($blockedProtected)) {
            Flash::set('error', 'Protected clause(s) must be unlocked before editing (id ' . implode(', ', $blockedProtected) . ') — nothing was saved.');
            header('Location: /admin/overrides');
            return;
        }

        foreach ($toApply as $id => $c) {
            AdminOverrideRepository::updateTcClause($id, $c['new_title'], $c['new_text'], (int) $user['id']);
            if ($byId[$id]['is_protected']) {
                AuditLogRepository::log((int) $user['id'], 'PROTECTED_FIELD_UNLOCKED', 'tc_clauses', $id, 'clause_text', null, null, $reason);
            }
            AuditLogRepository::log((int) $user['id'], 'FIELD_EDIT', 'tc_clauses', $id, 'clause_text', $c['old_text'], $c['new_text'], $reason);
            if ($c['old_title'] !== $c['new_title']) {
                AuditLogRepository::log((int) $user['id'], 'FIELD_EDIT', 'tc_clauses', $id, 'clause_title', $c['old_title'], $c['new_title'], $reason);
            }
        }
        Flash::set('success', count($toApply) . ' clause(s) updated.');
        header('Location: /admin/overrides');
    }

    public function updatePaymentPresets(array $params): void
    {
        $user = AuthService::currentUser();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $unlockedRaw = $_POST['unlocked_preset'] ?? []; // { [id]: '1' }
        $unlockedIds = [];
        foreach ($unlockedRaw as $k => $v) {
            if ($v === '1') {
                $unlockedIds[(int) $k] = true;
            }
        }
        $rows = AdminOverrideRepository::paymentPresets();
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $toApply = [];
        foreach (($_POST['advance_pct'] ?? []) as $id => $advancePct) {
            $id = (int) $id;
            if (!isset($byId[$id])) {
                continue;
            }
            $new = [
                'advance_pct' => (float) $advancePct,
                'advance_trigger_text' => trim((string) ($_POST['advance_trigger_text'][$id] ?? '')),
                'balance_pct' => (float) ($_POST['balance_pct'][$id] ?? 0),
                'balance_trigger_option' => in_array($_POST['balance_trigger_option'][$id] ?? '', ['A_BEFORE_SHIPMENT', 'B_AGAINST_BL'], true)
                    ? $_POST['balance_trigger_option'][$id] : $byId[$id]['balance_trigger_option'],
                'balance_days' => (int) ($_POST['balance_days'][$id] ?? 0),
            ];
            $old = $byId[$id];
            $changedFields = [];
            foreach ($new as $field => $value) {
                $oldValue = $field === 'advance_pct' || $field === 'balance_pct' ? (float) $old[$field] : $old[$field];
                if ($field === 'balance_days') {
                    $oldValue = (int) $old[$field];
                }
                if ($oldValue !== $value) {
                    $changedFields[$field] = ['old' => $oldValue, 'new' => $value];
                }
            }
            if ($changedFields) {
                $toApply[$id] = ['new' => $new, 'changed' => $changedFields];
            }
        }

        if (empty($toApply)) {
            Flash::set('success', 'No changes were made.');
            header('Location: /admin/overrides');
            return;
        }
        if ($reason === '') {
            Flash::set('error', 'A reason is required to save this change — nothing was saved.');
            header('Location: /admin/overrides');
            return;
        }

        $blockedProtected = [];
        foreach ($toApply as $id => $c) {
            if ($byId[$id]['is_protected'] && !isset($unlockedIds[$id])) {
                $blockedProtected[] = $id;
            }
        }
        if (!empty($blockedProtected)) {
            Flash::set('error', 'Protected preset(s) must be unlocked before editing (id ' . implode(', ', $blockedProtected) . ') — nothing was saved.');
            header('Location: /admin/overrides');
            return;
        }

        foreach ($toApply as $id => $c) {
            AdminOverrideRepository::updatePaymentPreset(
                $id,
                $c['new']['advance_pct'],
                $c['new']['advance_trigger_text'],
                $c['new']['balance_pct'],
                $c['new']['balance_trigger_option'],
                $c['new']['balance_days']
            );
            if ($byId[$id]['is_protected']) {
                AuditLogRepository::log((int) $user['id'], 'PROTECTED_FIELD_UNLOCKED', 'payment_presets', $id, null, null, null, $reason);
            }
            foreach ($c['changed'] as $field => $vals) {
                AuditLogRepository::log((int) $user['id'], 'FIELD_EDIT', 'payment_presets', $id, $field, (string) $vals['old'], (string) $vals['new'], $reason);
            }
        }
        Flash::set('success', count($toApply) . ' payment preset(s) updated.');
        header('Location: /admin/overrides');
    }
}
