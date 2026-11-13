<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DropdownOptionRepository;
use App\Services\AuthService;
use App\Services\SurfaceFinishList;

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
        // D-357: the surface_finish list is the one list of finish names — its price column and
        // the box shown after a save that renamed a finish.
        $finishSaved = $_SESSION['_finish_saved'] ?? null;
        unset($_SESSION['_finish_saved']);
        View::render('dropdown_options/index', [
            'grouped' => DropdownOptionRepository::allGrouped(true),
            'finishPrices' => SurfaceFinishList::priceColumn(),
            'finishSaved' => is_array($finishSaved) ? $finishSaved : null,
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
        // D-357: a finish name is also the option name in Pricing › Specifications (up to 80 characters, once).
        if ($listKey === SurfaceFinishList::LIST_KEY && ($error = self::finishNameError([$value], array_column($existing, 'option_value'))) !== null) {
            Flash::set('error', $error);
            header('Location: /admin/dropdown-options');
            return;
        }
        $nextSortOrder = count($existing) > 0 ? max(array_column($existing, 'sort_order')) + 1 : 1;

        $user = AuthService::currentUser();
        $id = DropdownOptionRepository::create($listKey, $value, $nextSortOrder);
        AuditLogRepository::log((int) $user['id'], 'DROPDOWN_OPTION_ADDED', 'dropdown_options', $id, null, null, "{$listKey}: {$value}");
        if ($listKey === SurfaceFinishList::LIST_KEY) {
            SurfaceFinishList::sync([], (int) $user['id']);
            Flash::set('success', "\"{$value}\" added to {$listKey} — it is in Pick from Products and Pricing › Specifications now, with no price change; set its price in Pricing › Specifications.");
        } else {
            Flash::set('success', "\"{$value}\" added to {$listKey}.");
        }
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

        // QA Round 5, R5-30 / R5-28: a cleared name used to save an empty
        // option, and text in a sort order was saved as a number. Check every
        // row first; a problem saves nothing.
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (is_array($valueInput[$id] ?? null) || is_array($sortOrderInput[$id] ?? null)
                || trim((string) ($valueInput[$id] ?? $row['option_value'])) === ''
                || !preg_match('/^\d+$/', trim((string) ($sortOrderInput[$id] ?? $row['sort_order'])))) {
                Flash::set('error', 'Every option needs a name and a sort order that is a whole number (0 or more) — nothing was saved.');
                header('Location: /admin/dropdown-options');
                return;
            }
        }
        if ($listKey === SurfaceFinishList::LIST_KEY) {
            $names = [];
            foreach ($rows as $row) {
                $names[] = trim((string) ($valueInput[(int) $row['id']] ?? $row['option_value']));
            }
            if (($error = self::finishNameError($names, [])) !== null) {
                Flash::set('error', $error . ' Nothing was saved.');
                header('Location: /admin/dropdown-options');
                return;
            }
        }

        $changedCount = 0;
        $renames = [];
        $activeChanged = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $newValue = trim((string) ($valueInput[$id] ?? $row['option_value']));
            $newSortOrder = (int) ($sortOrderInput[$id] ?? $row['sort_order']);
            $newActive = isset($activeInput[$id]) ? 1 : 0;

            if ($newValue !== $row['option_value'] || $newSortOrder !== (int) $row['sort_order']) {
                DropdownOptionRepository::update($id, $newValue, $newSortOrder);
                AuditLogRepository::log((int) $user['id'], 'DROPDOWN_OPTION_UPDATED', 'dropdown_options', $id, 'option_value', $row['option_value'], $newValue);
                if ($newValue !== $row['option_value']) {
                    $renames[$id] = [(string) $row['option_value'], $newValue];
                }
                $changedCount++;
            }
            if ($newActive !== (int) $row['is_active']) {
                $activeChanged[] = $id;
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

        // D-357: the Surface finish options in Pricing › Specifications follow; a rename reaches
        // the order lines of orders whose quotation is not approved yet.
        if ($listKey === SurfaceFinishList::LIST_KEY && $changedCount > 0) {
            SurfaceFinishList::sync($activeChanged, (int) $user['id']);
            $box = ['messages' => [], 'renamed' => []];
            foreach ($renames as $id => [$old, $new]) {
                $r = SurfaceFinishList::renameOnDraftOrders($old, $new, (int) $user['id']);
                AuditLogRepository::log((int) $user['id'], 'SURFACE_FINISH_RENAMED', 'dropdown_options', $id, 'option_value', $old, $new,
                    "D-357: renamed everywhere — Pick from Products, Pricing › Specifications, {$r['lines']} line(s) on {$r['orders']} draft order(s); "
                    . "{$r['kept_orders']} order(s) with an approved quotation or later and every issued document keep the old name.");
                $box['messages'][] = SurfaceFinishList::renameMessage($old, $new, $r);
                $box['renamed'][$id] = $old;
            }
            if ($box['messages'] !== []) {
                $_SESSION['_finish_saved'] = $box;
                header('Location: /admin/dropdown-options#list-' . $listKey);
                return;
            }
        }

        Flash::set('success', $changedCount > 0 ? "{$listKey} updated." : 'No changes were made.');
        header('Location: /admin/dropdown-options');
    }

    /**
     * D-357: a finish name is also an option name in Pricing › Specifications — up to 80
     * characters, and each name once in the list (any letter case).
     *
     * @param list<string> $names the names being saved
     * @param list<string> $others names already in the list
     */
    private static function finishNameError(array $names, array $others): ?string
    {
        $seen = [];
        foreach (array_merge($others, $names) as $n) {
            $n = trim((string) $n);
            if (mb_strlen($n) > SurfaceFinishList::MAX_NAME) {
                return 'A surface finish name can be at most ' . SurfaceFinishList::MAX_NAME . ' characters ("' . mb_substr($n, 0, 30) . '…").';
            }
            $k = mb_strtolower($n);
            if (isset($seen[$k])) {
                return "\"{$n}\" is in the surface_finish list twice — each finish name can be used once.";
            }
            $seen[$k] = true;
        }
        return null;
    }
}
