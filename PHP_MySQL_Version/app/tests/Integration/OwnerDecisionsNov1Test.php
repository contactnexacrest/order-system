<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions of 1 Nov 2026 ("A all yes, B all yes, C all as
 * recommended"), driven through the real Router as the person concerned.
 * The Node suite has the same test (ownerDecisionsNov1.test.js).
 */
final class OwnerDecisionsNov1Test extends DbTestCase
{
    private static ?Router $router = null;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__owner-decisions-build__';
            ob_start();
            require dirname(__DIR__, 3) . '/public_html/index.php';
            ob_end_clean();
            restore_exception_handler();
            self::$router = $router;
        }
        return self::$router;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::router();
    }

    protected function tearDown(): void
    {
        Flash::pull();
        Flash::pullOld();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    /** @return array{code:int, body:string, flash:?string, type:?string} */
    private function call(int $as, string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_SESSION = ['_auth_user_id' => $as, '_session_version' => 0];
        $_SESSION['_session_version'] = AuthService::sessionVersion() ?? 0;
        PermissionService::resetCache();
        \App\Services\ActiveDepartmentService::resetCache();
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        ob_start();
        try {
            self::router()->dispatch($method, (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        $flash = Flash::pull()[0] ?? null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null, 'type' => $flash['type'] ?? null];
    }

    private function roleId(string $name): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM roles WHERE name = :n');
        $stmt->execute(['n' => $name]);
        return (int) $stmt->fetchColumn();
    }

    private function permissionId(string $key): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM permissions WHERE permission_key = :k');
        $stmt->execute(['k' => $key]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> */
    private function rolePermissionIds(int $roleId): array
    {
        return array_map('intval', Database::connection()->query("SELECT permission_id FROM role_permissions WHERE role_id = {$roleId}")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** A-8 (QA R7-N13): System Administrator has no full data export. */
    public function testSystemAdministratorCannotDownloadTheWholeDatabase(): void
    {
        $sysAdmin = $this->createTestUser('System Administrator');
        self::assertSame(403, $this->call($sysAdmin, 'GET', '/admin/data-export')['code']);
        self::assertSame(200, $this->call($this->createTestUser('Managing Director'), 'GET', '/admin/data-export')['code']);
    }

    /** A-8: nobody gives a permission they do not hold, nor edits a role they work under. */
    public function testRolePermissionsCannotBeRaisedBeyondTheEditorsOwn(): void
    {
        $sysAdmin = $this->createTestUser('System Administrator');
        $own = $this->roleId('System Administrator');
        $before = $this->rolePermissionIds($own);
        $r = $this->call($sysAdmin, 'POST', "/admin/roles/{$own}/permissions", ['permission_ids' => array_merge($before, [$this->permissionId('view_purchase_prices')])]);
        self::assertSame('You cannot change the permissions of "System Administrator": it is a role you work under. Ask another administrator.', $r['flash']);
        self::assertEqualsCanonicalizing($before, $this->rolePermissionIds($own));

        $other = $this->roleId('Viewer / Auditor');
        $otherBefore = $this->rolePermissionIds($other);
        $r = $this->call($sysAdmin, 'POST', "/admin/roles/{$other}/permissions", ['permission_ids' => array_merge($otherBefore, [$this->permissionId('view_cafin')])]);
        self::assertStringStartsWith('You cannot give the "', (string) $r['flash']);
        self::assertStringEndsWith('permission: you do not hold it yourself. Ask someone who holds it. Nothing was changed.', (string) $r['flash']);
        self::assertEqualsCanonicalizing($otherBefore, $this->rolePermissionIds($other));

        // Adding one they hold, or removing one, is fine.
        $r = $this->call($sysAdmin, 'POST', "/admin/roles/{$other}/permissions", ['permission_ids' => array_merge($otherBefore, [$this->permissionId('manage_ports')])]);
        self::assertSame('success', $r['type']);
        self::assertContains($this->permissionId('manage_ports'), $this->rolePermissionIds($other));

        // An Admin (who holds view_cafin) may give it to another role, not to their own.
        $admin = $this->createTestUser('Admin');
        self::assertSame('success', $this->call($admin, 'POST', "/admin/roles/{$other}/permissions", ['permission_ids' => array_merge($otherBefore, [$this->permissionId('view_cafin')])])['type']);
        $adminRole = $this->roleId('Admin');
        self::assertStringContainsString('it is a role you work under', (string) $this->call($admin, 'POST', "/admin/roles/{$adminRole}/permissions", ['permission_ids' => $this->rolePermissionIds($adminRole)])['flash']);
    }

    /** A-8: no personal exception for yourself, none you do not hold. */
    public function testPersonalExceptionsFollowTheSameRule(): void
    {
        $sysAdmin = $this->createTestUser('System Administrator');
        $colleague = $this->createTestUser('Export Executive');
        $base = ['reason' => 'Covering for a colleague this month', 'expires_at' => ''];
        self::assertSame('You cannot give yourself an extra permission. Ask another administrator.',
            $this->call($sysAdmin, 'POST', '/admin/permissions/grant', ['user_id' => $sysAdmin, 'permission_id' => $this->permissionId('manage_ports')] + $base)['flash']);
        self::assertStringContainsString('you do not hold it yourself',
            (string) $this->call($sysAdmin, 'POST', '/admin/permissions/grant', ['user_id' => $colleague, 'permission_id' => $this->permissionId('view_purchase_prices')] + $base)['flash']);
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM user_permissions WHERE user_id IN ({$sysAdmin}, {$colleague})")->fetchColumn());
        self::assertSame('success', $this->call($sysAdmin, 'POST', '/admin/permissions/grant', ['user_id' => $colleague, 'permission_id' => $this->permissionId('manage_ports')] + $base)['type']);
    }

    /** A-8: nobody changes their own role or gives a role / membership above their own rights. */
    public function testRolesAndMembershipsCannotLiftAnyoneAboveTheEditor(): void
    {
        $sysAdmin = $this->createTestUser('System Administrator');
        $self = Database::connection()->query("SELECT name, email FROM users WHERE id = {$sysAdmin}")->fetch();
        $r = $this->call($sysAdmin, 'POST', "/users/{$sysAdmin}/update", ['name' => $self['name'], 'email' => $self['email'], 'phone' => '', 'role_id' => (string) $this->roleId('Admin')]);
        self::assertSame('You cannot change your own role. Ask another administrator.', $r['flash']);

        $colleague = $this->createTestUser('Viewer / Auditor');
        $c = Database::connection()->query("SELECT name, email FROM users WHERE id = {$colleague}")->fetch();
        $r = $this->call($sysAdmin, 'POST', "/users/{$colleague}/update", ['name' => $c['name'], 'email' => $c['email'], 'phone' => '', 'role_id' => (string) $this->roleId('Finance Director')]);
        self::assertStringContainsString('you do not hold it yourself. Ask someone who holds it. The role was not changed.', (string) $r['flash']);
        self::assertSame($this->roleId('Viewer / Auditor'), (int) Database::connection()->query("SELECT role_id FROM users WHERE id = {$colleague}")->fetchColumn());

        $financeDirector = (int) Database::connection()->query("SELECT dd.id FROM department_designations dd WHERE dd.title = 'Finance Director'")->fetchColumn();
        self::assertSame('You cannot give yourself a department membership. Ask another administrator.',
            $this->call($sysAdmin, 'POST', "/users/{$sysAdmin}/memberships", ['designation_id' => (string) $financeDirector, 'starts_on' => date('Y-m-d')])['flash']);
        self::assertStringContainsString('you do not hold it yourself',
            (string) $this->call($sysAdmin, 'POST', "/users/{$colleague}/memberships", ['designation_id' => (string) $financeDirector, 'starts_on' => date('Y-m-d')])['flash']);
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM user_department_memberships WHERE user_id IN ({$sysAdmin}, {$colleague})")->fetchColumn());

        // An MD may give the colleague the Finance Director membership.
        self::assertSame('success', $this->call($this->createTestUser('Managing Director'), 'POST', "/users/{$colleague}/memberships", ['designation_id' => (string) $financeDirector, 'starts_on' => date('Y-m-d')])['type']);
    }

    /** A-7 / A-9: the Freight Cost report needs only freight costs; the Supplier report needs buying prices. */
    public function testEachReportNeedsOnlyTheMoneyItShows(): void
    {
        $logistics = $this->createTestUser('Logistics Manager');
        self::assertSame(200, $this->call($logistics, 'GET', '/reports/freight-cost')['code']);
        self::assertSame(403, $this->call($logistics, 'GET', '/reports/suppliers')['code']);
        $nav = $this->call($logistics, 'GET', '/')['body'];
        self::assertStringContainsString('href="/reports/freight-cost"', $nav);

        $sales = $this->createTestUser('Export Executive');
        self::assertSame(403, $this->call($sales, 'GET', '/reports/suppliers')['code']);
        self::assertStringNotContainsString('href="/reports/suppliers"', $this->call($sales, 'GET', '/reports')['body']);

        $procurement = $this->createTestUser('Procurement Executive');
        self::assertSame(200, $this->call($procurement, 'GET', '/reports/suppliers')['code']);
        self::assertSame(200, $this->call($this->createTestUser('Finance Manager'), 'GET', '/reports/suppliers')['code']);
    }

    /** C-8: the supplier master is Procurement's. */
    public function testProcurementExecutiveManagesSuppliersAndSalesDoesNot(): void
    {
        $rp = static fn (string $role): bool => (int) Database::connection()->query(
            "SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.name = '{$role}' AND p.permission_key = 'manage_suppliers'"
        )->fetchColumn() === 1;
        self::assertTrue($rp('Procurement Executive'));
        self::assertFalse($rp('Export Executive'));
        self::assertSame(0, (int) Database::connection()->query(
            "SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.name = 'System Administrator' AND p.permission_key = 'data_export_run'"
        )->fetchColumn());
    }

    /** C-4 (D-074): only a Letter of Credit preset runs without an advance. */
    public function testZeroAdvanceOnlyOnALetterOfCreditPreset(): void
    {
        $admin = $this->createTestUser('Admin');
        $usd = (int) Database::connection()->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
        $name = 'TEST-Zero ' . bin2hex(random_bytes(3));
        $preset = ['preset_name' => $name, 'advance_trigger_text' => 'against Proforma Invoice', 'payment_method' => 'CASH_ADVANCE', 'advance_pct' => '0', 'balance_pct' => '100',
            'balance_trigger_option' => 'A_BEFORE_SHIPMENT', 'balance_days' => '3', 'balance_trigger_wording' => '', 'currency_id' => (string) $usd];
        self::assertSame('Advance % is 0: tick "May run without an advance (0%)" (Letter of Credit presets only), or enter the advance %.', $this->call($admin, 'POST', '/payment-presets', $preset)['flash']);
        self::assertSame('Only a Letter of Credit preset may run without an advance — untick "May run without an advance (0%)" or choose Letter of Credit.',
            $this->call($admin, 'POST', '/payment-presets', ['allow_zero_advance' => '1'] + $preset)['flash']);
        self::assertSame('success', $this->call($admin, 'POST', '/payment-presets', ['allow_zero_advance' => '1', 'payment_method' => 'LC'] + $preset)['type']);
        $stmt = Database::connection()->prepare('SELECT allow_zero_advance FROM payment_presets WHERE preset_name = :n');
        $stmt->execute(['n' => $name]);
        self::assertSame(1, (int) $stmt->fetchColumn());
        self::assertSame(1, (int) Database::connection()->query("SELECT allow_zero_advance FROM payment_presets WHERE preset_name = 'Irrevocable Letter of Credit at Sight'")->fetchColumn(), 'the seeded LC preset carries the switch');

        // An amendment cannot take a cash-advance order to 0%.
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer');
        $r = $this->call($admin, 'POST', "/orders/{$orderId}/amendments", ['reason' => 'Buyer asked to drop the advance', 'requested_by' => 'importer', 'amended_advance_pct' => '0']);
        self::assertSame('Amended advance %: 0% is allowed only on a Letter of Credit order. Enter the advance %.', $r['flash']);
    }

    /** C-14 (O-14): the Zoho secrets never reach the page or the audit log. */
    public function testZohoSecretsAreMaskedOnSettingsAndInTheAuditLog(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE company_settings SET setting_value = 'super-secret-value-WXYZ' WHERE setting_key = 'zoho_books_client_secret'");
        $md = $this->createTestUser('Managing Director');
        $page = $this->call($md, 'GET', '/settings')['body'];
        self::assertStringNotContainsString('super-secret-value', $page);
        self::assertStringContainsString('•••• stored, ends in WXYZ', $page);

        // Left empty, the stored value stays; nothing to save.
        $this->call($md, 'POST', '/settings/update', ['settings' => ['zoho_books_client_secret' => ''], 'reason' => 'Checking the secret stays as it is']);
        self::assertSame('super-secret-value-WXYZ', $pdo->query("SELECT setting_value FROM company_settings WHERE setting_key = 'zoho_books_client_secret'")->fetchColumn());

        // A new value replaces it; the audit log keeps only the last 4 characters.
        $r = $this->call($md, 'POST', '/settings/update', ['settings' => ['zoho_books_client_secret' => 'brand-new-secret-ABCD'], 'unlocked' => ['zoho_books_client_secret' => '1'], 'reason' => 'Zoho secret rotated today']);
        self::assertSame('success', $r['type']);
        self::assertSame('brand-new-secret-ABCD', $pdo->query("SELECT setting_value FROM company_settings WHERE setting_key = 'zoho_books_client_secret'")->fetchColumn());
        $audit = $pdo->query("SELECT old_value, new_value FROM audit_log WHERE action_type = 'FIELD_EDIT' AND field_name = 'zoho_books_client_secret' ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame(['old_value' => '(secret, ends in WXYZ)', 'new_value' => '(secret, ends in ABCD)'], $audit);
        self::assertStringNotContainsString('brand-new-secret', $this->call($md, 'GET', '/audit-log?entity_type=company_settings')['body']);
    }

    /** C-7a (O-7): every order has an assignee — its creator — and always keeps at least one. */
    public function testEveryOrderKeepsAnAssignee(): void
    {
        $creator = $this->createTestUser('Export Executive');
        $orderId = \App\Services\OrderDuplicationService::duplicate($this->createTestOrder($this->createTestClient()), $creator);
        self::assertSame([$creator], array_map(static fn (array $a): int => (int) $a['user_id'], \App\Repositories\OrderAssigneeRepository::forOrder($orderId)));

        $md = $this->createTestUser('Managing Director');
        try {
            \App\Services\OrderAssignmentService::removeAssignee($orderId, $creator, $md);
            self::fail('the last assignee was removed');
        } catch (\RuntimeException $e) {
            self::assertSame('An order always keeps at least one assignee — add the person taking over first, then remove this one.', $e->getMessage());
        }
        \App\Services\OrderAssignmentService::addAssignee($orderId, $md, $md);
        \App\Services\OrderAssignmentService::removeAssignee($orderId, $creator, $md);
        self::assertSame([$md], array_map(static fn (array $a): int => (int) $a['user_id'], \App\Repositories\OrderAssigneeRepository::forOrder($orderId)));
    }

    /** C-7b (O-7): one digest a day per person, only when something waits on them. */
    public function testTheDailyDigestListsWhatWaitsOnEachPersonOncePerDay(): void
    {
        $pdo = Database::connection();
        $person = $this->createTestUser('Procurement Manager');
        $idle = $this->createTestUser('Viewer / Auditor');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$person]);
        $order = \App\Repositories\OrderRepository::find($orderId);
        $pr = \App\Repositories\PurchasingRepository::create($orderId, null, 'Granite slabs', null, 10.0, 'sqm', date('Y-m-d', strtotime('+10 days')), $person, null);
        $proc = (int) $pdo->query("SELECT id FROM departments WHERE code = 'PROC'")->fetchColumn();
        \App\Repositories\DepartmentTaskRepository::setOwner($orderId, $proc, $person, $person);
        $docType = (int) $pdo->query("SELECT id FROM document_types WHERE code = 'QT'")->fetchColumn();
        $pdo->prepare("INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, 'TEST-QT-DIGEST-1', 'draft')")->execute(['o' => $orderId, 't' => $docType]);
        \App\Repositories\DocumentReviewRepository::assign((int) $pdo->lastInsertId(), $person);

        $items = \App\Services\DailyDigestService::itemsFor($person);
        self::assertContains(['kind' => 'review', 'text' => 'TEST-QT-DIGEST-1 (' . $pdo->query("SELECT name FROM document_types WHERE code = 'QT'")->fetchColumn() . ') — waiting for your review'], $items);
        $taskTexts = array_column(array_filter($items, static fn (array $i): bool => $i['kind'] === 'task'), 'text');
        self::assertCount(1, array_filter($taskTexts, static fn (string $t): bool => str_starts_with($t, $order['order_reference'] . ' — ')));
        self::assertSame([], \App\Services\DailyDigestService::itemsFor($idle));

        $mail = \App\Services\DailyDigestService::compose('Managing Director', [['kind' => 'task', 'text' => 'A'], ['kind' => 'review', 'text' => 'B']], '2026-11-02');
        self::assertSame('NexaCrest — your day 2 Nov 2026: 1 task, 1 review', $mail['subject']);
        self::assertSame("Good morning Managing Director,\n\nThis is what waits on you today.\n\nTasks (My tasks):\n- A\n\nDocuments to review (My Reviews):\n- B\n\nOpen the app to act on them. You get this e-mail once a day, only when something waits on you.\n", $mail['body']);

        $date = '2099-01-0' . random_int(1, 9);
        \App\Services\DailyDigestService::setSender(static fn (): bool => true);
        try {
            \App\Services\DailyDigestService::run($date);
            \App\Services\DailyDigestService::run($date); // a second run the same day sends nothing new
        } finally {
            \App\Services\DailyDigestService::setSender(null);
        }
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM daily_digest_log WHERE user_id = {$person} AND digest_date = '{$date}'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM daily_digest_log WHERE user_id = {$idle}")->fetchColumn());
        self::assertGreaterThan(0, $pr);
    }
}
