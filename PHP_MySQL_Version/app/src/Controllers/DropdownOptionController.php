<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DropdownOptionRepository;
use App\Services\AuthService;

/**
 * Admin screen for dropdown_options (docs/schema.sql Section BD) — see
 * DropdownOptionRepository's docblock for scope. Gated on
 * manage_dropdown_options, same tier as manage_hs_codes/
 * manage_logistics_partners. One bulk-save form per list_key (mirrors
 * Admin Overrides' per-section pattern) rather than per-row edit pages,
 * since each group is typically a handful of short values.
 */
final class DropdownOptionController
{
    public function index(array $params): void
    {
        View::render('dropdown_options/index', [
            'grouped' => DropdownOptionRepository::allGrouped(true),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $listKey = (string) ($params['listKey'] ?? '');
        $value = trim((string) ($_POST['option_value'] ?? ''));
        if ($value === '') {
            Flash::set('error', 'A value is required.');
            header('Location: /admin/dropdown-options');
            return;
        }

        $existing = DropdownOptionRepository::allGrouped(true)[$listKey] ?? [];
        $nextSortOrder = count($existing) > 0 ? max(array_column($existing, 'sort_order')) + 1 : 1;

        $user = AuthService::currentUser();
        $id = DropdownOptionRepository::create($listKey, $value, $nextSortOrder);
        AuditLogRepository::log((int) $user['id'], 'DROPDOWN_OPTION_ADDED', 'dropdown_options', $id, null, null, "{$listKey}: {$value}");
        Flash::set('success', "\"{$value}\" added to {$listKey}.");
        header('Location: /admin/dropdown-options');
    }

    public function update(array $params): void
    {
        $listKey = (string) ($params['listKey'] ?? '');
        $rows = DropdownOptionRepository::allGrouped(true)[$listKey] ?? [];
        if (empty($rows)) {
            Flash::set('error', 'Option list not found.');
            header('Location: /admin/dropdown-options');
            return;
        }

        $user = AuthService::currentUser();
        $valueInput = $_POST['option_value'] ?? [];
        $sortOrderInput = $_POST['sort_order'] ?? [];
        $activeInput = $_POST['is_active'] ?? [];
        $defaultId = (int) ($_POST['default_id'] ?? 0);

        $changedCount = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $newValue = trim((string) ($valueInput[$id] ?? $row['option_value']));
            $newSortOrder = (int) ($sortOrderInput[$id] ?? $row['sort_order']);
            $newActive = isset($activeInput[$id]) ? 1 : 0;

            if ($newValue !== $row['option_value'] || $newSortOrder !== (int) $row['sort_order']) {
                DropdownOptionRepository::update($id, $newValue, $newSortOrder);
                AuditLogRepository::log((int) $user['id'], 'DROPDOWN_OPTION_UPDATED', 'dropdown_options', $id, 'option_value', $row['option_value'], $newValue);
                $changedCount++;
            }
            if ($newActive !== (int) $row['is_active']) {
                DropdownOptionRepository::toggleActive($id);
                AuditLogRepository::log((int) $user['id'], $newActive ? 'DROPDOWN_OPTION_REACTIVATED' : 'DROPDOWN_OPTION_DEACTIVATED', 'dropdown_options', $id, 'is_active', (string) (int) $row['is_active'], (string) $newActive);
                $changedCount++;
            }
        }

        if ($defaultId > 0) {
            $currentDefault = null;
            foreach ($rows as $row) {
                if (!empty($row['is_default'])) {
                    $currentDefault = (int) $row['id'];
                }
            }
            if ($currentDefault !== $defaultId) {
                DropdownOptionRepository::setDefault($defaultId, $listKey);
                AuditLogRepository::log((int) $user['id'], 'DROPDOWN_OPTION_DEFAULT_CHANGED', 'dropdown_options', $defaultId, 'is_default', (string) $currentDefault, (string) $defaultId);
                $changedCount++;
            }
        }

        Flash::set('success', $changedCount > 0 ? "{$listKey} updated." : 'No changes were made.');
        header('Location: /admin/dropdown-options');
    }
}
