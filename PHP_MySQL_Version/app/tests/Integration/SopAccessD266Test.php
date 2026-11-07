<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\GalleryController;
use App\Controllers\SopController;
use App\Repositories\DepartmentRepository;
use App\Services\PermissionService;
use App\Services\SopAccessService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-266 (7 Nov 2026): who sees which SOP chapter — the
 * library switch, per-chapter "on for everyone", role ticks with the seeded
 * defaults, the union over department roles, a person's extras, MD/Admin
 * always, "New" chapters, the CA permission, screenshots, the Gallery, the
 * refusal page and the audit rows. Node twin: sopAccessD266.test.js.
 */
final class SopAccessD266Test extends DbTestCase
{
    private const EVERYONE = ['00-getting-started', '00-introduction', '12-client-portal', '17-order-edit-duplication', '19-complaints', '23-coordination'];

    private ?string $libraryBefore = null;
    /** @var array<string, int> */
    private array $chaptersBefore = [];
    /** @var list<int> */
    private array $rolesCreated = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        $pdo = Database::connection();
        $this->libraryBefore = (string) $pdo->query("SELECT setting_value FROM company_settings WHERE setting_key = 'sop_library_enabled'")->fetchColumn();
        foreach ($pdo->query('SELECT slug, is_enabled FROM sop_chapters')->fetchAll() as $r) {
            $this->chaptersBefore[(string) $r['slug']] = (int) $r['is_enabled'];
        }
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        $pdo->prepare("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'sop_library_enabled'")->execute(['v' => $this->libraryBefore]);
        $pdo->exec('DELETE FROM sop_chapters');
        $ins = $pdo->prepare('INSERT INTO sop_chapters (slug, is_enabled) VALUES (:s, :e)');
        foreach ($this->chaptersBefore as $slug => $on) {
            $ins->execute(['s' => $slug, 'e' => $on]);
        }
        foreach ($this->rolesCreated as $roleId) {
            $pdo->prepare('DELETE FROM sop_role_chapters WHERE role_id = :r')->execute(['r' => $roleId]);
        }
        $_SESSION = [];
        PermissionService::resetCache();
        parent::tearDown();
    }

    private function roleId(string $name): int
    {
        return (int) Database::connection()->query('SELECT id FROM roles WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
    }

    /** @return list<string> */
    private function visible(int $userId, bool $canViewCa = false): array
    {
        PermissionService::resetCache();
        $roleId = (int) Database::connection()->query('SELECT role_id FROM users WHERE id = ' . $userId)->fetchColumn();
        return SopAccessService::visibleSlugs($userId, $roleId, $canViewCa);
    }

    /** @return list<string> */
    private static function matching(string ...$prefixes): array
    {
        return array_values(array_filter(SopAccessService::allSlugs(), static function (string $s) use ($prefixes): bool {
            foreach ($prefixes as $p) {
                if ($s === $p || str_starts_with($s, $p . '-')) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** @return list<string> */
    private static function sorted(array $slugs): array
    {
        $slugs = array_values(array_unique($slugs));
        sort($slugs, SORT_STRING);
        return $slugs;
    }

    private function lastAudit(string $action): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM audit_log WHERE action_type = :a ORDER BY id DESC LIMIT 1');
        $stmt->execute(['a' => $action]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function customRole(): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO roles (name, description, is_system_role) VALUES (:n, :d, 0)')->execute(['n' => 'D266 test role ' . bin2hex(random_bytes(4)), 'd' => 'PHPUnit']);
        $id = (int) $pdo->lastInsertId();
        $this->rolesCreated[] = $id;
        return $id;
    }

    /** @return array{0:int,1:string} */
    private function render(callable $call): array
    {
        http_response_code(200);
        ob_start();
        try {
            $call();
        } finally {
            $html = (string) ob_get_clean();
        }
        return [(int) http_response_code(), $html];
    }

    public function testEveryShippedChapterIsConfiguredAndOnAtInstall(): void
    {
        self::assertSame(SopAccessService::allSlugs(), self::sorted(array_keys(array_filter($this->chaptersBefore, static fn (int $on): bool => $on === 1))));
        self::assertSame('1', $this->libraryBefore);
    }

    public function testSeededDefaultsPerRole(): void
    {
        self::assertSame(self::sorted([...self::EVERYONE, ...self::matching('06', '07', '09')]), $this->visible($this->createTestUser('Logistics Executive')));
        self::assertSame(self::sorted([...self::EVERYONE, ...self::matching('05', '22')]), $this->visible($this->createTestUser('Procurement Executive')));
        self::assertSame(self::sorted([...self::EVERYONE, ...self::matching('01', '02', '03', '04', '10', '11', '13', '24', '27')]), $this->visible($this->createTestUser('Export Executive')));
        self::assertSame(self::sorted([...self::EVERYONE, ...self::matching('01', '02', '03', '04', '05', '06', '07', '08', '09', '14')]), $this->visible($this->createTestUser('Viewer / Auditor')));
        $ca = array_values(array_filter(SopAccessService::allSlugs(), static fn (string $s): bool => str_starts_with($s, 'ca-')));
        self::assertSame(self::sorted([...self::EVERYONE, ...self::matching('03', '08', '14', '20'), ...$ca]), $this->visible($this->createTestUser('Finance Manager'), true));
        // Management and Admin: every chapter (ca- with the CA permission they hold).
        self::assertSame(SopAccessService::allSlugs(), $this->visible($this->createTestUser('Managing Director'), true));
        self::assertSame(SopAccessService::allSlugs(), $this->visible($this->createTestUser('Admin'), true));
    }

    public function testDepartmentRolesAddTheirChapters(): void
    {
        $user = $this->createTestUser('Logistics Executive');
        $designation = (int) Database::connection()->query(
            "SELECT dd.id FROM department_designations dd JOIN departments d ON d.id = dd.department_id JOIN roles r ON r.id = dd.role_id
             WHERE r.name = 'Procurement Executive' AND dd.is_active = 1 AND d.is_active = 1 ORDER BY dd.id LIMIT 1"
        )->fetchColumn();
        self::assertGreaterThan(0, $designation, 'a seeded Procurement designation');
        DepartmentRepository::addMembership($user, $designation, date('Y-m-d', strtotime('-1 day')), $user);
        $seen = $this->visible($user);
        foreach ([...self::matching('06', '07', '09'), ...self::matching('05', '22')] as $slug) {
            self::assertContains($slug, $seen);
        }
    }

    public function testExtraChaptersForOnePersonAreAddedAndRemovedWithAudit(): void
    {
        $admin = $this->createTestUser('Admin');
        $user = $this->createTestUser('Logistics Executive');
        $slug = self::matching('24')[0];
        self::assertNotContains($slug, $this->visible($user));
        SopAccessService::addUserExtra($user, $slug, $admin);
        self::assertContains($slug, $this->visible($user));
        $audit = $this->lastAudit('SOP_USER_CHAPTER_ADDED');
        self::assertSame([(string) $admin, (string) $user, $slug], [(string) $audit['user_id'], (string) $audit['entity_id'], (string) $audit['new_value']]);

        $_SESSION['_auth_user_id'] = $user;
        [$code, $html] = $this->render(static fn () => (new SopController())->index([]));
        self::assertSame(200, $code);
        self::assertStringContainsString('Extra for you', $html);

        SopAccessService::removeUserExtra($user, $slug, $admin);
        self::assertNotContains($slug, $this->visible($user));
        self::assertSame($slug, (string) $this->lastAudit('SOP_USER_CHAPTER_REMOVED')['old_value']);
    }

    public function testLibraryOffLeavesOnlyGettingStartedExceptForMdAndAdmin(): void
    {
        $admin = $this->createTestUser('Admin');
        $user = $this->createTestUser('Logistics Executive');
        SopAccessService::setLibraryEnabled(false, $admin);
        self::assertSame(['off', 'on'], [(string) $this->lastAudit('SOP_LIBRARY_SWITCHED')['new_value'], (string) $this->lastAudit('SOP_LIBRARY_SWITCHED')['old_value']]);

        self::assertSame(['00-getting-started'], $this->visible($user));
        self::assertSame(SopAccessService::allSlugs(), $this->visible($admin, true));

        $_SESSION['_auth_user_id'] = $user;
        [$code, $html] = $this->render(static fn () => (new SopController())->index([]));
        self::assertSame(403, $code);
        self::assertStringContainsString('The SOP library is switched off. Ask your Admin if you need it.', $html);
        [$code] = $this->render(static fn () => (new GalleryController())->index([]));
        self::assertSame(403, $code);
        [$code] = $this->render(static fn () => (new SopController())->show(['chapter' => '00-getting-started']));
        self::assertSame(200, $code);

        $_SESSION['_auth_user_id'] = $admin;
        PermissionService::resetCache();
        [$code] = $this->render(static fn () => (new SopController())->index([]));
        self::assertSame(200, $code);
    }

    public function testAChapterSwitchedOffIsHiddenFromEveryoneButMdAndAdmin(): void
    {
        $admin = $this->createTestUser('Admin');
        $user = $this->createTestUser('Logistics Executive');
        $slug = self::matching('06')[0];
        SopAccessService::setChapterEnabled($slug, false, $admin);
        self::assertNotContains($slug, $this->visible($user));
        self::assertContains($slug, $this->visible($admin, true));
        $audit = $this->lastAudit('SOP_CHAPTER_SWITCHED');
        self::assertSame([$slug, 'on', 'off'], [(string) $audit['field_name'], (string) $audit['old_value'], (string) $audit['new_value']]);
        SopAccessService::setChapterEnabled($slug, true, $admin);
        self::assertContains($slug, $this->visible($user));
    }

    public function testANewChapterIsOnlyForMdAndAdminUntilItIsTicked(): void
    {
        $admin = $this->createTestUser('Admin');
        $user = $this->createTestUser('Procurement Executive');
        $slug = self::matching('22')[0];
        Database::connection()->prepare('DELETE FROM sop_chapters WHERE slug = :s')->execute(['s' => $slug]); // as if the file had just been added
        self::assertNotContains($slug, $this->visible($user));
        self::assertContains($slug, $this->visible($admin, true));

        $role = $this->customRole();
        SopAccessService::saveRoleSlugs($role, [$slug, 'no-such-chapter'], $admin);
        self::assertSame([$slug], SopAccessService::roleSlugs($role));
        self::assertArrayHasKey($slug, SopAccessService::chapterStates());
        self::assertContains($slug, $this->visible($user)); // configured now, and still ticked for Procurement
        $audit = $this->lastAudit('SOP_ROLE_CHAPTERS_SAVED');
        self::assertSame([(string) $role, $slug], [(string) $audit['entity_id'], (string) $audit['new_value']]);
    }

    public function testACaChapterStillNeedsTheCaPermission(): void
    {
        $admin = $this->createTestUser('Admin');
        $role = $this->customRole();
        $ca = self::matching('ca-01')[0];
        SopAccessService::saveRoleSlugs($role, [$ca], $admin);
        $pdo = Database::connection();
        $pdo->prepare('UPDATE users SET role_id = :r WHERE id = :u')->execute(['r' => $role, 'u' => $user = $this->createTestUser('Viewer / Auditor')]);
        self::assertNotContains($ca, $this->visible($user, false));
        self::assertContains($ca, $this->visible($user, true));
    }

    public function testAHiddenChapterShowsTheRefusalPageInTheLayout(): void
    {
        $user = $this->createTestUser('Logistics Executive');
        $_SESSION['_auth_user_id'] = $user;
        [$code, $html] = $this->render(static fn () => (new SopController())->show(['chapter' => self::matching('15')[0]]));
        self::assertSame(403, $code);
        self::assertStringContainsString("This SOP chapter isn't available for your role. Ask your Admin to add it if you need it.", html_entity_decode($html, ENT_QUOTES));
        self::assertStringContainsString('Back to your SOP chapters', $html);
        self::assertStringContainsString('sidebar-nav', $html); // the normal layout, not a bare page

        [$code, $html] = $this->render(static fn () => (new SopController())->index([]));
        self::assertSame(200, $code);
        self::assertStringNotContainsString('href="/sop/' . self::matching('15')[0] . '"', $html);
    }

    public function testScreenshotsFollowTheChaptersThePersonMayOpen(): void
    {
        $user = $this->createTestUser('Logistics Executive');
        $visible = $this->visible($user);
        $dir = dirname(__DIR__, 3) . '/docs/SOP';
        $byImage = [];
        foreach ([...SopAccessService::allSlugs(), 'README'] as $slug) {
            preg_match_all('#\./images/([\w.-]+)#', (string) file_get_contents($dir . '/' . $slug . '.md'), $m);
            foreach (array_unique($m[1]) as $img) {
                $byImage[$img][] = $slug;
            }
        }
        $shown = $hidden = null;
        foreach ($byImage as $img => $slugs) {
            $open = array_intersect($slugs, [...$visible, 'README']);
            if ($open !== [] && $shown === null) {
                $shown = $img;
            }
            if ($open === [] && $hidden === null) {
                $hidden = $img;
            }
        }
        self::assertNotNull($shown);
        self::assertNotNull($hidden);
        $_SESSION['_auth_user_id'] = $user;
        [$code] = $this->render(static fn () => (new SopController())->asset(['file' => $shown]));
        self::assertSame(200, $code);
        [$code, $body] = $this->render(static fn () => (new SopController())->asset(['file' => $hidden]));
        self::assertSame([403, 'Not permitted.'], [$code, trim($body)]);
    }
}
