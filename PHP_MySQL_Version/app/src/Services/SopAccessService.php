<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DepartmentRepository;

/**
 * Owner decision D-266 (7 Nov 2026): who sees which SOP chapter.
 *
 * - "SOP library" on/off (company setting sop_library_enabled): off hides
 *   the SOP menu, every chapter, its screenshots and the Gallery from
 *   staff — except the people who always see everything.
 * - Each chapter has an "on for everyone" switch (sop_chapters.is_enabled).
 * - Each role has its ticked chapters (sop_role_chapters); a person sees
 *   the chapters of their own role and of every role they hold through a
 *   current department membership, plus their own extras
 *   (sop_user_chapters).
 * - Always: an effective Super Admin and whoever holds
 *   manage_company_settings (seeded: Admin, MD, ED, System Administrator)
 *   see every chapter, so nobody can hide the help from the people who
 *   manage it; 00-getting-started is visible to every staff member; a
 *   ca- chapter still also needs ca_module_view.
 * - A chapter file with no sop_chapters row is "New": only the people
 *   above see it until it is ticked for a role or a person, or switched.
 *
 * Node twin: src/services/sopAccessService.js (same rules, same wording).
 */
final class SopAccessService
{
    public const ALWAYS_VISIBLE = '00-getting-started';
    public const SETTING = 'sop_library_enabled';
    public const SETTING_CATEGORY = 'sop_access';
    public const CHAPTER_NOT_AVAILABLE = "This SOP chapter isn't available for your role. Ask your Admin to add it if you need it.";
    public const LIBRARY_OFF = 'The SOP library is switched off. Ask your Admin if you need it.';

    private static function docsDir(): string
    {
        return dirname(__DIR__, 3) . '/docs/SOP';
    }

    /** @return list<string> every chapter file present now (README excluded), in filename order */
    public static function allSlugs(): array
    {
        $files = glob(self::docsDir() . '/*.md') ?: [];
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $path) {
            $slug = basename($path, '.md');
            if ($slug !== 'README') {
                $out[] = $slug;
            }
        }
        return $out;
    }

    public static function libraryEnabled(): bool
    {
        return CompanySettingsRepository::get(self::SETTING) !== '0';
    }

    /** Effective Super Admin, or manage_company_settings: sees every chapter and manages SOP access. */
    public static function isPrivileged(int $userId, ?int $roleId): bool
    {
        return SuperAdminService::isEffective($userId) || PermissionService::can($userId, $roleId, 'manage_company_settings');
    }

    /** @return array<string, bool> slug => on for everyone (configured chapters only) */
    public static function chapterStates(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT slug, is_enabled FROM sop_chapters')->fetchAll() as $r) {
            $out[(string) $r['slug']] = (int) $r['is_enabled'] === 1;
        }
        return $out;
    }

    /** @return list<int> the person's own role plus every role of a current department membership */
    public static function roleIdsFor(int $userId, ?int $roleId): array
    {
        $ids = DepartmentRepository::activeRoleIdsForUser($userId);
        if ($roleId !== null) {
            $ids[] = $roleId;
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return list<string> the chapters ticked for this role */
    public static function roleSlugs(int $roleId): array
    {
        $stmt = Database::connection()->prepare('SELECT slug FROM sop_role_chapters WHERE role_id = :r ORDER BY slug');
        $stmt->execute(['r' => $roleId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> this person's extra chapters */
    public static function userExtras(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT slug FROM sop_user_chapters WHERE user_id = :u ORDER BY slug');
        $stmt->execute(['u' => $userId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The chapters this person may open, in filename order.
     *
     * @return list<string>
     */
    public static function visibleSlugs(int $userId, ?int $roleId, bool $canViewCa): array
    {
        $all = self::allSlugs();
        $caOk = static fn (string $s): bool => !str_starts_with($s, 'ca-') || $canViewCa;
        if (self::isPrivileged($userId, $roleId)) {
            return array_values(array_filter($all, $caOk));
        }
        if (!self::libraryEnabled()) {
            return in_array(self::ALWAYS_VISIBLE, $all, true) ? [self::ALWAYS_VISIBLE] : [];
        }
        $states = self::chapterStates();
        $ticked = self::userExtras($userId);
        foreach (self::roleIdsFor($userId, $roleId) as $rid) {
            $ticked = array_merge($ticked, self::roleSlugs($rid));
        }
        $ticked = array_flip($ticked);
        $out = [];
        foreach ($all as $slug) {
            if ($slug === self::ALWAYS_VISIBLE) {
                $out[] = $slug;
                continue;
            }
            if (!isset($ticked[$slug]) || ($states[$slug] ?? false) !== true || !$caOk($slug)) {
                continue; // not ticked, switched off, never configured ("New"), or a ca- chapter without the CA permission
            }
            $out[] = $slug;
        }
        return $out;
    }

    /** May this person see the SOP menu, the SOP home page and the Gallery? */
    public static function libraryVisibleTo(int $userId, ?int $roleId): bool
    {
        return self::libraryEnabled() || self::isPrivileged($userId, $roleId);
    }

    /**
     * A link elsewhere in a chapter (or the README) to a chapter this person
     * cannot open becomes plain text, so nobody clicks into a refusal.
     *
     * @param list<string> $visible
     */
    public static function filterChapterLinks(string $html, array $visible): string
    {
        $allowed = array_flip($visible);
        return preg_replace_callback(
            '#<a\s[^>]*href="/sop/([A-Za-z0-9_-]+)(?:\#[^"]*)?"[^>]*>(.*?)</a>#s',
            static fn (array $m): string => isset($allowed[$m[1]]) ? $m[0] : $m[2],
            $html
        ) ?? $html;
    }

    /**
     * May this person load this SOP screenshot? Only when one of the chapters
     * they can open (or the README) shows it; the privileged see all.
     *
     * @param list<string> $visible
     */
    public static function imageVisible(string $filename, int $userId, ?int $roleId, array $visible): bool
    {
        if (self::isPrivileged($userId, $roleId)) {
            return true;
        }
        if (!self::libraryEnabled()) {
            $visible = array_values(array_intersect($visible, [self::ALWAYS_VISIBLE]));
        } else {
            $visible[] = 'README';
        }
        $needle = './images/' . $filename;
        foreach ($visible as $slug) {
            $raw = @file_get_contents(self::docsDir() . '/' . $slug . '.md');
            if ($raw !== false && str_contains($raw, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * UI-005: a page outside the SOP viewer (Getting started) that shows SOP
     * screenshots drops each screenshot this person may not load (with its
     * caption), so nobody sees a broken image answered 403 by /sop-assets.
     *
     * @param list<string> $visible
     */
    public static function filterScreenshots(string $html, int $userId, ?int $roleId, array $visible): string
    {
        $ok = [];
        $allowed = static function (string $file) use (&$ok, $userId, $roleId, $visible): bool {
            return $ok[$file] ??= self::imageVisible(basename($file), $userId, $roleId, $visible);
        };
        $html = preg_replace_callback(
            '#<div class="gs-shot"[^>]*>\s*<img\b[^>]*\bsrc="/sop-assets/([^"]+)"[^>]*>\s*</div>(?:\s*<div class="gs-shot-cap">.*?</div>)?#s',
            static fn (array $m): string => $allowed($m[1]) ? $m[0] : '',
            $html
        ) ?? $html;
        return preg_replace_callback(
            '#<img\b[^>]*\bsrc="/sop-assets/([^"]+)"[^>]*>#',
            static fn (array $m): string => $allowed($m[1]) ? $m[0] : '',
            $html
        ) ?? $html;
    }

    // ---------------------------------------------------------------- Admin changes (all audited)

    /** A chapter becomes "configured" (no longer New) the first time it is ticked or switched. */
    private static function ensureConfigured(string $slug, int $byUserId): void
    {
        Database::connection()->prepare('INSERT IGNORE INTO sop_chapters (slug, is_enabled, updated_by) VALUES (:s, 1, :u)')
            ->execute(['s' => $slug, 'u' => $byUserId]);
    }

    public static function setLibraryEnabled(bool $on, int $byUserId): void
    {
        $old = self::libraryEnabled();
        if ($old === $on) {
            return;
        }
        CompanySettingsRepository::set(self::SETTING, $on ? '1' : '0', $byUserId);
        AuditLogRepository::log($byUserId, 'SOP_LIBRARY_SWITCHED', 'company_settings', null, self::SETTING, $old ? 'on' : 'off', $on ? 'on' : 'off');
    }

    public static function setChapterEnabled(string $slug, bool $on, int $byUserId): void
    {
        if (!in_array($slug, self::allSlugs(), true) || $slug === self::ALWAYS_VISIBLE) {
            throw new \InvalidArgumentException('Unknown SOP chapter.');
        }
        $states = self::chapterStates();
        $old = $states[$slug] ?? null;
        self::ensureConfigured($slug, $byUserId);
        Database::connection()->prepare('UPDATE sop_chapters SET is_enabled = :e, updated_by = :u WHERE slug = :s')
            ->execute(['e' => $on ? 1 : 0, 'u' => $byUserId, 's' => $slug]);
        if ($old !== $on) {
            AuditLogRepository::log($byUserId, 'SOP_CHAPTER_SWITCHED', 'sop_chapters', null, $slug, $old === null ? 'new' : ($old ? 'on' : 'off'), $on ? 'on' : 'off');
        }
    }

    /** @param list<string> $slugs the complete set of ticked chapters for the role */
    public static function saveRoleSlugs(int $roleId, array $slugs, int $byUserId): void
    {
        $known = self::allSlugs();
        $slugs = array_values(array_unique(array_filter($slugs, static fn ($s): bool => is_string($s) && in_array($s, $known, true))));
        sort($slugs);
        $old = self::roleSlugs($roleId);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM sop_role_chapters WHERE role_id = :r')->execute(['r' => $roleId]);
            $ins = $pdo->prepare('INSERT INTO sop_role_chapters (role_id, slug) VALUES (:r, :s)');
            foreach ($slugs as $s) {
                $ins->execute(['r' => $roleId, 's' => $s]);
                self::ensureConfigured($s, $byUserId);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($old !== $slugs) {
            AuditLogRepository::log($byUserId, 'SOP_ROLE_CHAPTERS_SAVED', 'roles', $roleId, 'sop_chapters', implode(', ', $old), implode(', ', $slugs));
        }
    }

    public static function addUserExtra(int $userId, string $slug, int $byUserId): void
    {
        if (!in_array($slug, self::allSlugs(), true)) {
            throw new \InvalidArgumentException('Unknown SOP chapter.');
        }
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO sop_user_chapters (user_id, slug, added_by) VALUES (:u, :s, :b)');
        $stmt->execute(['u' => $userId, 's' => $slug, 'b' => $byUserId]);
        self::ensureConfigured($slug, $byUserId);
        if ($stmt->rowCount() > 0) {
            AuditLogRepository::log($byUserId, 'SOP_USER_CHAPTER_ADDED', 'users', $userId, 'sop_chapters', null, $slug);
        }
    }

    public static function removeUserExtra(int $userId, string $slug, int $byUserId): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM sop_user_chapters WHERE user_id = :u AND slug = :s');
        $stmt->execute(['u' => $userId, 's' => $slug]);
        if ($stmt->rowCount() > 0) {
            AuditLogRepository::log($byUserId, 'SOP_USER_CHAPTER_REMOVED', 'users', $userId, 'sop_chapters', $slug, null);
        }
    }
}
