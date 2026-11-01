<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DepartmentAdminController;
use App\Controllers\DepartmentController;
use App\Helpers\Flash;
use App\Repositories\DepartmentRepository;
use App\Repositories\PermissionRepository;
use App\Services\ActiveDepartmentService;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-214 (2026-10-31), phase 1: departments, designations
 * (each its own role), memberships, "Log in as", switching, Admin's
 * "Preview as", the department recorded on every audit row, and personal
 * exceptions with an end date. The Node suite has the same test
 * (departments.test.js).
 */
final class DepartmentsTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        ActiveDepartmentService::resetCache();
        PermissionService::resetCache();
        parent::tearDown();
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        ActiveDepartmentService::resetCache();
        PermissionService::resetCache();
    }

    private function designationId(string $title): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM department_designations WHERE title = :t');
        $stmt->execute(['t' => $title]);
        return (int) $stmt->fetchColumn();
    }

    private function join(int $userId, string $title, ?string $from = null): int
    {
        return DepartmentRepository::addMembership($userId, $this->designationId($title), $from ?? date('Y-m-d'), $this->admin);
    }

    /** @return array{type:?string,message:string} */
    private function post(object $controller, string $action, array $body, array $params = []): array
    {
        $_POST = $body;
        $controller->{$action}($params);
        $_POST = [];
        $all = Flash::pull();
        return end($all) ?: ['type' => null, 'message' => ''];
    }

    private function can(int $userId, string $key): bool
    {
        $user = AuthService::currentUser();
        return PermissionService::can($userId, $user['role_id'] !== null ? (int) $user['role_id'] : null, $key);
    }

    private function lastAudit(string $action): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM audit_log WHERE action_type = :a ORDER BY id DESC LIMIT 1');
        $stmt->execute(['a' => $action]);
        return $stmt->fetch() ?: [];
    }

    public function testTheMigrationGivesEveryDepartmentItsOwnLadder(): void
    {
        $titles = array_column(DepartmentRepository::designations(), 'title');
        foreach (['Sales/Export Executive', 'Sales/Export Manager', 'Sales Director', 'Procurement Executive', 'Procurement Manager', 'Procurement Director',
            'Logistics Executive', 'Logistics Manager', 'Logistics Director', 'Accounts Executive', 'Finance Manager', 'Finance Director',
            'Managing Director', 'Executive Director', 'Internal Auditor', 'External CA', 'System Administrator', 'Order Coordinator'] as $t) {
            self::assertContains($t, $titles);
        }
        $codes = array_column(DepartmentRepository::departments(true), 'code');
        self::assertNotContains('OPS', $codes, 'the Order Desk starts switched off');
    }

    public function testSomeoneInTwoDepartmentsChoosesOneAndGetsThatDepartmentsRights(): void
    {
        $ravi = $this->createTestUser('Export Executive');
        $sales = $this->join($ravi, 'Sales/Export Executive');
        $proc = $this->join($ravi, 'Procurement Manager');
        $this->signIn($ravi);

        self::assertSame('/choose-department', ActiveDepartmentService::afterLogin($ravi));
        self::assertTrue(ActiveDepartmentService::needsChoice($ravi));

        self::assertSame(['type' => 'error', 'message' => 'Choose one of your departments.'],
            $this->post(new DepartmentController(), 'choose', ['membership_id' => '999999']));
        $this->post(new DepartmentController(), 'choose', ['membership_id' => (string) $proc]);
        self::assertFalse(ActiveDepartmentService::needsChoice($ravi));
        self::assertTrue($this->can($ravi, 'manage_suppliers'), 'Procurement Manager may manage suppliers');
        self::assertFalse($this->can($ravi, 'manage_payments'), 'and does not see buyer payments');
        self::assertSame('Procurement Manager · Procurement', $this->lastAudit('DEPARTMENT_SELECTED')['acting_as']);

        self::assertSame(['type' => 'success', 'message' => 'You are now working as Sales/Export Executive · Sales & Export.'],
            $this->post(new DepartmentController(), 'switch', ['membership_id' => (string) $sales]));
        self::assertTrue($this->can($ravi, 'manage_payments'));
        self::assertFalse($this->can($ravi, 'manage_suppliers'));
        $row = $this->lastAudit('DEPARTMENT_SWITCHED');
        self::assertSame('Procurement Manager · Procurement', $row['old_value']);
        self::assertSame('Sales/Export Executive · Sales & Export', $row['acting_as']);
    }

    public function testOneMembershipOrARememberedDefaultSkipsTheChoice(): void
    {
        $one = $this->createTestUser('Viewer / Auditor');
        $this->join($one, 'Logistics Executive');
        $this->signIn($one);
        self::assertNull(ActiveDepartmentService::afterLogin($one));
        self::assertSame('Logistics Executive · Logistics', ActiveDepartmentService::actingLabel($one));

        $two = $this->createTestUser('Viewer / Auditor');
        $this->join($two, 'Logistics Executive');
        $proc = $this->join($two, 'Procurement Executive');
        DepartmentRepository::setDefault($two, $proc);
        $this->signIn($two);
        self::assertNull(ActiveDepartmentService::afterLogin($two));
        self::assertSame('Procurement Executive · Procurement', ActiveDepartmentService::actingLabel($two));

        $none = $this->createTestUser('Export Executive');
        $this->signIn($none);
        self::assertNull(ActiveDepartmentService::afterLogin($none));
        self::assertTrue($this->can($none, 'manage_payments'), 'no department: the own role applies');
    }

    public function testNobodyCanSwitchIntoSomeoneElsesOrAnEndedMembership(): void
    {
        $a = $this->createTestUser('Export Executive');
        $b = $this->createTestUser('Export Executive');
        $this->join($a, 'Sales/Export Executive');
        $bMembership = $this->join($b, 'Procurement Manager');
        $this->signIn($a);
        self::assertSame('That department is not one of yours (or it has ended).',
            $this->post(new DepartmentController(), 'switch', ['membership_id' => (string) $bMembership])['message']);

        $this->signIn($b);
        ActiveDepartmentService::activate($b, $bMembership);
        self::assertTrue($this->can($b, 'manage_suppliers'));
        Database::connection()->exec("UPDATE user_department_memberships SET starts_on = DATE_SUB(CURDATE(), INTERVAL 5 DAY) WHERE id = {$bMembership}");
        DepartmentRepository::endMembership($bMembership, $this->admin, date('Y-m-d', strtotime('-1 day')));
        $this->signIn($b);
        $_SESSION[ActiveDepartmentService::SESSION_MEMBERSHIP] = $bMembership;
        self::assertNull(ActiveDepartmentService::current($b), 'an ended membership stops at once');
        self::assertFalse($this->can($b, 'manage_suppliers'));
    }

    public function testAPreviewShowsExactlyTheDesignationAndEndsCleanly(): void
    {
        PermissionRepository::grantOverride($this->admin, (int) PermissionRepository::findByKey('manage_payments')['id'], $this->admin, 'own exception for the test');
        self::assertTrue($this->can($this->admin, 'manage_permissions'));

        $result = $this->post(new DepartmentController(), 'previewStart', ['designation_id' => (string) $this->designationId('Procurement Manager')]);
        self::assertSame('Previewing the app as Procurement Manager · Procurement. Use "Exit preview" at the top to return.', $result['message']);
        self::assertTrue($this->can($this->admin, 'manage_suppliers'));
        self::assertFalse($this->can($this->admin, 'manage_permissions'), 'no Admin rights while previewing');
        self::assertFalse($this->can($this->admin, 'manage_payments'), 'own exceptions are off in a preview');
        self::assertSame('Preview: Procurement Manager · Procurement', $this->lastAudit('DEPARTMENT_PREVIEW_STARTED')['acting_as']);

        self::assertSame('Preview ended.', $this->post(new DepartmentController(), 'previewEnd', [])['message']);
        self::assertTrue($this->can($this->admin, 'manage_permissions'));
        self::assertNotEmpty($this->lastAudit('DEPARTMENT_PREVIEW_ENDED'));
    }

    public function testAdminAddsDepartmentsAndDesignationsWithChecks(): void
    {
        $c = new DepartmentAdminController();
        $tag = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        self::assertSame('Enter a short code: 2 to 20 capital letters, digits or _ (e.g. QUALITY).',
            $this->post($c, 'createDepartment', ['name' => "Quality {$tag}", 'code' => '9X', 'color' => '#336699'])['message']);
        self::assertSame("Department \"Quality {$tag}\" created. Add its designations below.",
            $this->post($c, 'createDepartment', ['name' => "Quality {$tag}", 'code' => "q{$tag}", 'color' => '#336699'])['message']);
        self::assertSame("A department named \"Quality {$tag}\" already exists.",
            $this->post($c, 'createDepartment', ['name' => "Quality {$tag}", 'code' => "R{$tag}", 'color' => '#336699'])['message']);
        $deptId = (int) Database::connection()->query("SELECT id FROM departments WHERE code = 'Q{$tag}'")->fetchColumn();
        self::assertGreaterThan(0, $deptId, 'the code is stored in capitals');

        $body = ['department_id' => (string) $deptId, 'title' => "QC Manager {$tag}", 'level' => 'manager', 'role_id' => 'new'];
        self::assertSame('Choose the level.', $this->post($c, 'createDesignation', ['level' => 'boss'] + $body)['message']);
        self::assertSame("\"QC Manager {$tag}\" added to Quality {$tag}. Its role has no permissions yet — set them with \"Permissions\".",
            $this->post($c, 'createDesignation', $body)['message']);
        self::assertSame("\"QC Manager {$tag}\" already exists in Quality {$tag}.", $this->post($c, 'createDesignation', $body)['message']);
        $d = DepartmentRepository::findDesignation($this->designationId("QC Manager {$tag}"));
        self::assertSame("QC Manager {$tag}", $d['role_name'], 'a new role named after the designation');

        // QA Round 8, R8-N10: approval limits change only with "Manage approval limits" (MD, ED).
        $this->signIn($this->createTestUser('Managing Director'));
        self::assertSame('Enter the approval limit in rupees, or leave it empty for none.',
            $this->post($c, 'updateDesignation', ['title' => $d['title'], 'level' => 'manager', 'approval_limit_inr' => '-5', 'is_active' => '1'], ['id' => $d['id']])['message']);
        $this->post($c, 'updateDesignation', ['title' => $d['title'], 'level' => 'manager', 'approval_limit_inr' => '500000', 'is_active' => '1'], ['id' => $d['id']]);
        self::assertSame('500000.00', (string) DepartmentRepository::findDesignation((int) $d['id'])['approval_limit_inr']);
        self::assertStringContainsString('limit 500000', (string) $this->lastAudit('DESIGNATION_UPDATED')['new_value']);
    }

    /** QA Round 8, R8-N10: a designation's approval limit and level are MD/ED only, never on their own designation, and audited old → new. */
    public function testApprovalLimitsChangeOnlyByMdOrEdAndNeverOnTheirOwnDesignation(): void
    {
        $c = new DepartmentAdminController();
        $pm = DepartmentRepository::findDesignation($this->designationId('Procurement Manager'));
        $before = $pm['approval_limit_inr'];
        $body = ['title' => $pm['title'], 'level' => $pm['level'], 'is_active' => '1'];

        $this->signIn($this->createTestUser('System Administrator'));
        self::assertSame(DepartmentAdminController::LIMITS_REFUSED,
            $this->post($c, 'updateDesignation', ['approval_limit_inr' => '999999999'] + $body, ['id' => $pm['id']])['message']);
        self::assertSame(DepartmentAdminController::LIMITS_REFUSED,
            $this->post($c, 'updateDesignation', ['level' => 'director', 'approval_limit_inr' => (string) $before] + $body, ['id' => $pm['id']])['message']);
        $after = DepartmentRepository::findDesignation((int) $pm['id']);
        self::assertSame($before, $after['approval_limit_inr'], 'the System Administrator could not lift the limit');
        self::assertSame($pm['level'], $after['level']);
        self::assertSame('"' . $pm['title'] . '" saved.',
            $this->post($c, 'updateDesignation', ['approval_limit_inr' => (string) $before] + $body, ['id' => $pm['id']])['message'], 'the rest of the form still saves');
        self::assertSame(DepartmentAdminController::LIMITS_REFUSED, $this->post($c, 'createDesignation',
            ['department_id' => (string) $pm['department_id'], 'title' => 'Deputy Boss ' . bin2hex(random_bytes(2)), 'level' => 'director', 'role_id' => 'new'])['message']);
        self::assertSame("Only the MD or ED can change an approval limit or make a designation 'director'.", DepartmentAdminController::LIMITS_REFUSED);
        ob_start();
        $c->index([]);
        $sysPage = (string) ob_get_clean();
        self::assertStringContainsString('Only the MD or ED changes approval limits.', $sysPage);
        self::assertStringNotContainsString('name="approval_limit_inr" min="0"', $sysPage);

        $md = $this->createTestUser('Managing Director');
        $this->signIn($md);
        self::assertSame('"' . $pm['title'] . '" saved.',
            $this->post($c, 'updateDesignation', ['approval_limit_inr' => '750000'] + $body, ['id' => $pm['id']])['message']);
        self::assertSame('750000.00', (string) DepartmentRepository::findDesignation((int) $pm['id'])['approval_limit_inr']);
        $audit = $this->lastAudit('APPROVAL_LIMIT_CHANGED');
        self::assertSame($md, (int) $audit['user_id']);
        self::assertSame('approval_limit_inr', $audit['field_name']);
        self::assertSame($pm['level'] . ' · ' . ($before === null ? 'no limit' : number_format((float) $before, 2, '.', '')), $audit['old_value']);
        self::assertSame($pm['level'] . ' · 750000.00', $audit['new_value']);
        self::assertSame("You cannot change the approval limit of a designation you hold yourself.", DepartmentAdminController::OWN_LIMIT_REFUSED);
        $mdDesignation = DepartmentRepository::findDesignation($this->designationId('Managing Director'));
        self::assertSame(DepartmentAdminController::OWN_LIMIT_REFUSED, $this->post($c, 'updateDesignation',
            ['title' => $mdDesignation['title'], 'level' => $mdDesignation['level'], 'approval_limit_inr' => '1', 'is_active' => '1'], ['id' => $mdDesignation['id']])['message']);
        ob_start();
        $c->index([]);
        self::assertStringContainsString('Your own designation — another MD/ED changes it.', (string) ob_get_clean());

        // A Procurement Director cannot lift their own designation's limit — whether or not they also hold MD/ED rights.
        $pd = DepartmentRepository::findDesignation($this->designationId('Procurement Director'));
        $pdBody = ['title' => $pd['title'], 'level' => $pd['level'], 'approval_limit_inr' => '999999999', 'is_active' => '1'];
        $this->signIn($this->createTestUser('Procurement Director'));
        self::assertSame(DepartmentAdminController::LIMITS_REFUSED, $this->post($c, 'updateDesignation', $pdBody, ['id' => $pd['id']])['message']);
        $director = $this->createTestUser('Procurement Director');
        foreach (['manage_permissions', 'manage_approval_limits'] as $key) {
            Database::connection()->prepare("INSERT INTO user_permissions (user_id, permission_id, is_enabled, reason) SELECT :u, id, 1, 'PHPUnit' FROM permissions WHERE permission_key = :k")
                ->execute(['u' => $director, 'k' => $key]);
        }
        $this->signIn($director);
        self::assertSame(DepartmentAdminController::OWN_LIMIT_REFUSED, $this->post($c, 'updateDesignation', $pdBody, ['id' => $pd['id']])['message']);
        self::assertSame($pd['approval_limit_inr'], DepartmentRepository::findDesignation((int) $pd['id'])['approval_limit_inr']);

        DepartmentRepository::updateDesignation((int) $pm['id'], $pm['title'], $pm['level'], true, $before === null ? null : (float) $before);
    }

    public function testMembershipsAreAddedAndEndedOnThePersonsPage(): void
    {
        $c = new DepartmentAdminController();
        $ravi = $this->createTestUser('Export Executive');
        $name = 'PHPUnit Test User';
        $designation = (string) $this->designationId('Procurement Manager');
        self::assertSame("{$name} is now Procurement Manager in Procurement (from 2026-11-01).",
            $this->post($c, 'addMembership', ['designation_id' => $designation, 'starts_on' => '2026-11-01'], ['id' => $ravi])['message']);
        self::assertSame("{$name} is already Procurement Manager in Procurement.",
            $this->post($c, 'addMembership', ['designation_id' => $designation, 'starts_on' => '2026-11-02'], ['id' => $ravi])['message']);
        self::assertSame('Enter the date the membership starts.',
            $this->post($c, 'addMembership', ['designation_id' => (string) $this->designationId('Logistics Manager'), 'starts_on' => '2026-02-30'], ['id' => $ravi])['message']);

        $m = DepartmentRepository::membershipsForUser($ravi)[0];
        self::assertSame('Enter the last day of the membership (not before it started).',
            $this->post($c, 'endMembership', ['ends_on' => '2026-10-31'], ['id' => $ravi, 'membershipId' => $m['id']])['message']);
        self::assertSame('Membership Procurement Manager · Procurement ends on 2026-12-31.',
            $this->post($c, 'endMembership', ['ends_on' => '2026-12-31'], ['id' => $ravi, 'membershipId' => $m['id']])['message']);

        $founder = $this->createTestUser('Managing Director');
        Database::connection()->exec("UPDATE users SET is_protected_account = 1 WHERE id = {$founder}");
        self::assertStringContainsString('protected founder account',
            $this->post($c, 'addMembership', ['designation_id' => $designation], ['id' => $founder])['message']);
    }

    public function testAuditRowsRecordTheDepartmentTheChangeWasMadeIn(): void
    {
        $ravi = $this->createTestUser('Export Executive');
        $proc = $this->join($ravi, 'Procurement Manager');
        $this->signIn($ravi);
        ActiveDepartmentService::activate($ravi, $proc, false);
        \App\Repositories\AuditLogRepository::log($ravi, 'PHPUNIT_DEPT_CHECK', 'users', $ravi);
        self::assertSame('Procurement Manager · Procurement', $this->lastAudit('PHPUNIT_DEPT_CHECK')['acting_as']);
        \App\Repositories\AuditLogRepository::log($this->admin, 'PHPUNIT_DEPT_OTHER', 'users', $ravi);
        self::assertNull($this->lastAudit('PHPUNIT_DEPT_OTHER')['acting_as'], 'only the signed-in person\'s rows carry their department');
    }

    public function testAPersonalExceptionStopsAfterItsEndDate(): void
    {
        $ravi = $this->createTestUser('Viewer / Auditor');
        $permissionId = (int) PermissionRepository::findByKey('manage_suppliers')['id'];
        PermissionRepository::grantOverride($ravi, $permissionId, $this->admin, 'covering for a colleague', date('Y-m-d'));
        self::assertTrue(PermissionRepository::effectivePermissions($ravi, null)['manage_suppliers'] ?? false);
        Database::connection()->exec("UPDATE user_permissions SET expires_at = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE user_id = {$ravi}");
        self::assertFalse(PermissionRepository::effectivePermissions($ravi, null)['manage_suppliers'] ?? false);

        $_POST = ['user_id' => (string) $ravi, 'permission_id' => (string) $permissionId, 'reason' => 'covering for a colleague', 'expires_at' => '2020-01-01'];
        (new \App\Controllers\PermissionAdminController())->grantOverride([]);
        $all = Flash::pull();
        self::assertSame('The "Until" date must be today or later (or leave it empty for no end date).', end($all)['message']);
    }

    public function testNotificationsReachWhoeverHoldsThePermissionInAnyOfTheirDepartments(): void
    {
        $ravi = $this->createTestUser('Viewer / Auditor');
        self::assertNotContains($ravi, PermissionRepository::usersWithPermission('manage_suppliers'));
        $this->join($ravi, 'Logistics Executive');
        $this->join($ravi, 'Procurement Manager');
        self::assertContains($ravi, PermissionRepository::usersWithPermission('manage_suppliers'));
    }
}
