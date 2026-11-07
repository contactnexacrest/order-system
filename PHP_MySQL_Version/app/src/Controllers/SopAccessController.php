<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Services\AuthService;
use App\Services\SopAccessService;

/**
 * Owner decision D-266 (7 Nov 2026): Admin › SOP access — the SOP library
 * switch, each chapter's "on for everyone" switch, the chapters each role
 * sees, and a person's extra chapters (from Users › Edit). Every route
 * needs manage_company_settings (seeded: Admin, MD, ED, System
 * Administrator — the people who always see every chapter). Node twin:
 * src/controllers/sopAccessController.js.
 */
final class SopAccessController
{
    private const SHOW = ['all' => 'All chapters', 'ticked' => 'Ticked for this role', 'new' => 'New (not assigned yet)', 'off' => 'Switched off'];

    private function userId(): int
    {
        return (int) (AuthService::currentUser()['id'] ?? 0);
    }

    /** @return array<int, array{id:int, name:string, sees_all:bool}> */
    private function roles(): array
    {
        $rows = Database::connection()->query(
            "SELECT r.id, r.name,
                    EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                            WHERE rp.role_id = r.id AND rp.is_enabled = 1 AND p.permission_key = 'manage_company_settings') AS sees_all
             FROM roles r ORDER BY r.name"
        )->fetchAll();
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'sees_all' => (int) $r['sees_all'] === 1], $rows);
    }

    /** @return array<string, string> slug => title */
    public static function titles(): array
    {
        $dir = dirname(__DIR__, 3) . '/docs/SOP';
        $out = [];
        foreach (SopAccessService::allSlugs() as $slug) {
            $title = $slug;
            $h = @fopen($dir . '/' . $slug . '.md', 'r');
            if ($h) {
                while (($line = fgets($h)) !== false) {
                    if (str_starts_with(trim($line), '# ')) {
                        $title = trim(substr(trim($line), 2));
                        break;
                    }
                }
                fclose($h);
            }
            $out[$slug] = $title;
        }
        return $out;
    }

    public function index(array $params): void
    {
        $roles = $this->roles();
        $roleIds = array_column($roles, 'id');
        $roleId = (int) ($_GET['role_id'] ?? 0);
        $roleId = in_array($roleId, $roleIds, true) ? $roleId : null;
        $copyFrom = (int) ($_GET['copy_from'] ?? 0);
        $copyFrom = in_array($copyFrom, $roleIds, true) ? $copyFrom : null;
        $show = array_key_exists((string) ($_GET['show'] ?? ''), self::SHOW) ? (string) $_GET['show'] : 'all';

        $states = SopAccessService::chapterStates();
        $ticked = $roleId !== null ? array_flip(SopAccessService::roleSlugs($copyFrom ?? $roleId)) : [];
        $rows = [];
        $hiddenTicked = []; // ticked chapters the "Show" filter leaves out — kept, so Save never drops them
        foreach (self::titles() as $slug => $title) {
            $state = $slug === SopAccessService::ALWAYS_VISIBLE ? 'always' : (!array_key_exists($slug, $states) ? 'new' : ($states[$slug] ? 'on' : 'off'));
            $isTicked = isset($ticked[$slug]) || $slug === SopAccessService::ALWAYS_VISIBLE;
            if (($show === 'ticked' && !$isTicked) || ($show === 'new' && $state !== 'new') || ($show === 'off' && $state !== 'off')) {
                if ($isTicked && $state !== 'always') {
                    $hiddenTicked[] = $slug;
                }
                continue;
            }
            $rows[] = ['slug' => $slug, 'title' => $title, 'state' => $state, 'ticked' => $isTicked, 'ca' => str_starts_with($slug, 'ca-')];
        }
        $selected = null;
        foreach ($roles as $r) {
            if ($r['id'] === $roleId) {
                $selected = $r;
            }
        }
        View::render('sop_access/index', [
            'libraryOn' => SopAccessService::libraryEnabled(),
            'roles' => $roles,
            'role' => $selected,
            'copyFrom' => $copyFrom,
            'show' => $show,
            'showOptions' => self::SHOW,
            'rows' => $rows,
            'hiddenTicked' => $hiddenTicked,
        ], 'layout/base');
    }

    public function saveLibrary(array $params): void
    {
        $on = ($_POST['library'] ?? '') === 'on';
        SopAccessService::setLibraryEnabled($on, $this->userId());
        Flash::set('success', $on ? 'The SOP library is on for staff.' : 'The SOP library is off. Only MD and Admin see it now.');
        header('Location: /admin/sop-access' . $this->back());
    }

    public function saveChapter(array $params): void
    {
        [$slug, $on] = array_pad(explode(':', (string) ($_POST['toggle'] ?? ''), 2), 2, '');
        try {
            SopAccessService::setChapterEnabled($slug, $on === '1', $this->userId());
            Flash::set('success', $on === '1' ? "Chapter {$slug} is on for everyone ticked." : "Chapter {$slug} is switched off for everyone except MD and Admin.");
        } catch (\InvalidArgumentException $e) {
            Flash::set('error', $e->getMessage());
        }
        header('Location: /admin/sop-access' . $this->back());
    }

    public function saveRole(array $params): void
    {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $name = null;
        foreach ($this->roles() as $r) {
            if ($r['id'] === $roleId) {
                $name = $r['name'];
            }
        }
        if ($name === null) {
            Flash::set('error', 'Choose a role.');
            header('Location: /admin/sop-access');
            return;
        }
        $slugs = is_array($_POST['slugs'] ?? null) ? array_map('strval', $_POST['slugs']) : [];
        SopAccessService::saveRoleSlugs($roleId, $slugs, $this->userId());
        Flash::set('success', "Saved the SOP chapters for {$name}.");
        header('Location: /admin/sop-access?role_id=' . $roleId);
    }

    public function addUserExtra(array $params): void
    {
        $userId = (int) ($params['id'] ?? 0);
        try {
            SopAccessService::addUserExtra($userId, (string) ($_POST['slug'] ?? ''), $this->userId());
            Flash::set('success', 'Extra SOP chapter added.');
        } catch (\InvalidArgumentException $e) {
            Flash::set('error', 'Choose a chapter.');
        }
        header('Location: /users/' . $userId . '/edit#sop-extras');
    }

    public function removeUserExtra(array $params): void
    {
        $userId = (int) ($params['id'] ?? 0);
        SopAccessService::removeUserExtra($userId, (string) ($_POST['slug'] ?? ''), $this->userId());
        Flash::set('success', 'Extra SOP chapter removed.');
        header('Location: /users/' . $userId . '/edit#sop-extras');
    }

    private function back(): string
    {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        return $roleId > 0 ? '?role_id=' . $roleId : '';
    }
}
