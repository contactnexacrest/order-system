<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociatePortalController;
use App\Controllers\DataExportController;
use App\Controllers\DivisionController;
use App\Controllers\FileStoreController;
use App\Helpers\Flash;
use App\Middleware\ClientAuth;
use App\Middleware\SessionAuth;
use App\Repositories\CaExpenseRepository;
use App\Repositories\ClientLoginRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PiIntakeRepository;
use App\Services\ActiveDepartmentService;
use App\Services\ClientPortalService;
use App\Services\DivisionService;
use App\Services\MoneyVisibility;
use App\Services\PermissionService;
use App\Services\SuperAdminService;
use App\Tests\Support\DbTestCase;

/**
 * QA fix batch 1 (5 Nov 2026): the access-control (ACC-*) and UI (UI-*)
 * findings of the full QA review that no test covered. Each test fails
 * without its fix. Node twin: tests/integration/qaBatch1AccessUi.test.js.
 */
final class QaBatch1AccessUiTest extends DbTestCase
{
    private const DOM = 2;
    private const VIEWS = __DIR__ . '/../../views';
    private const NODE_VIEWS = __DIR__ . '/../../../../NODE_MySQL_Version/app/views';

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'planned' WHERE id <> 1");
        $pdo->exec('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
        unset($_SERVER['HTTP_REFERER']);
        DivisionService::resetCache();
        ActiveDepartmentService::resetCache();
        parent::tearDown();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => (int) \App\Repositories\UserRepository::findById($userId)['session_version']];
        PermissionService::resetCache();
        ActiveDepartmentService::resetCache();
        DivisionService::resetCache();
    }

    private function request(string $method, string $uri): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        http_response_code(200);
    }

    /** @return array{0: bool, 1: string} whether the middleware let the request through, and what it printed */
    private function through(callable $middleware): array
    {
        ob_start();
        try {
            $passed = $middleware([]);
        } finally {
            $body = (string) ob_get_clean();
        }
        return [$passed, $body];
    }

    private function designationId(string $title): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM department_designations WHERE title = :t');
        $stmt->execute(['t' => $title]);
        return (int) $stmt->fetchColumn();
    }

    /** ACC-2: "Preview as" is for looking only — every post but "Exit preview" / log out is refused. */
    public function testAPreviewCannotSaveAnything(): void
    {
        $admin = $this->createTestUser('Admin');
        $this->actAs($admin);
        self::assertTrue(ActiveDepartmentService::startPreview($admin, $this->designationId('Managing Director') ?: $this->designationId('Logistics Executive')));

        $this->request('POST', '/admin/permissions/grant');
        [$passed] = $this->through(SessionAuth::required());
        self::assertFalse($passed, 'a grant while previewing is refused');
        self::assertSame('You are previewing the app: nothing can be saved during a preview. Use "Exit preview" at the top first.', Flash::pull()[0]['message'] ?? null);

        $this->request('POST', '/preview/exit');
        [$passed] = $this->through(SessionAuth::required());
        self::assertTrue($passed, '"Exit preview" still works');

        $this->request('GET', '/');
        [$passed] = $this->through(SessionAuth::required());
        self::assertTrue($passed, 'looking around is still allowed');
    }

    /** ACC-16: a Super Admin previewing Procurement sees what Procurement sees — no client name. */
    public function testASuperAdminPreviewingProcurementSeesNoClient(): void
    {
        $admin = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$admin}");
        $this->actAs($admin);
        self::assertTrue(MoneyVisibility::seesClient());
        ActiveDepartmentService::startPreview($admin, $this->designationId('Procurement Manager'));
        self::assertFalse(MoneyVisibility::seesClient());
    }

    /** ACC-3: only a permanent Super Admin changes Super Admin access, never their own. */
    public function testADelegatedSuperAdminCannotMakeItselfPermanent(): void
    {
        $owner = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$owner}");
        $delegate = $this->createTestUser('Admin');
        $other = $this->createTestUser('Admin');
        SuperAdminService::grantDelegation($delegate, $owner, 'Cover while the owner travels', null);

        foreach ([
            fn () => SuperAdminService::setPermanentFlag($delegate, true, $delegate, 'Make myself permanent'),
            fn () => SuperAdminService::grantDelegation($other, $delegate, 'Hand out another one', null),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('a delegate must be refused');
            } catch (\RuntimeException $e) {
                self::assertSame('Only a permanent Super Admin can change Super Admin access (a delegated Super Admin cannot).', $e->getMessage());
            }
        }
        self::assertSame(0, (int) Database::connection()->query("SELECT is_super_admin FROM users WHERE id = {$delegate}")->fetchColumn());

        try {
            SuperAdminService::setPermanentFlag($owner, false, $owner, 'Remove my own access');
            self::fail('no one changes their own status');
        } catch (\RuntimeException $e) {
            self::assertSame('You cannot change your own Super Admin access — ask another Super Admin.', $e->getMessage());
        }
        Database::connection()->exec("UPDATE users SET is_active = 0 WHERE id = {$other}");
        try {
            SuperAdminService::setPermanentFlag($other, true, $owner, 'Promote an inactive user');
            self::fail('an inactive user is refused');
        } catch (\RuntimeException $e) {
            self::assertSame('Choose an active user.', $e->getMessage());
        }
    }

    /** ACC-5 / ACC-6: the CA lists, a typed order reference and the intake queue follow the division. */
    public function testCaAndIntakeListsFollowTheDivision(): void
    {
        $pdo = Database::connection();
        $client = $this->createTestClient();
        $order = $this->createTestOrder($client);
        $ref = (string) $pdo->query("SELECT order_reference FROM orders WHERE id = {$order}")->fetchColumn();
        $pdo->prepare("INSERT INTO ca_expenses (zoho_expense_id, category, description, amount, currency_code, expense_date, order_id) VALUES (:z, 'Other', 'QA batch 1 expense', 100, 'INR', CURDATE(), :o)")
            ->execute(['z' => 'TEST-QA1-' . bin2hex(random_bytes(5)), 'o' => $order]);
        $expenseId = (int) $pdo->lastInsertId();
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO pi_intake_submissions (order_id, access_token_hash, access_token_plain, access_token_expires_at, status, submitted_at) VALUES (:o, :h, :p, DATE_ADD(NOW(), INTERVAL 1 DAY), 'pending_review', NOW())")
            ->execute(['o' => $order, 'h' => hash('sha256', $token), 'p' => $token]);
        $intakeId = (int) $pdo->lastInsertId();

        self::assertSame($order, OrderRepository::findIdByReference($ref));
        self::assertContains($expenseId, array_map('intval', array_column(CaExpenseRepository::all(), 'id')));
        self::assertContains($intakeId, array_map('intval', array_column(PiIntakeRepository::pendingReview(), 'id')));

        DivisionService::withDivision(self::DOM, function () use ($ref, $expenseId, $intakeId): void {
            self::assertNull(OrderRepository::findIdByReference($ref), 'an Export order cannot be typed in from Domestic Trade');
            self::assertNotContains($expenseId, array_map('intval', array_column(CaExpenseRepository::all(), 'id')));
            self::assertNotContains($intakeId, array_map('intval', array_column(PiIntakeRepository::pendingReview(), 'id')));
        });
    }

    /** ACC-7: a client-level file needs the client's division; an agreement file needs the agreements right. */
    public function testFileStoreChecksClientDivisionAndAgreementsRight(): void
    {
        $pdo = Database::connection();
        $client = $this->createTestClient();
        $pdo->prepare('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, 1)')->execute(['c' => $client]);
        $file = $this->createTestFile(null, $client);
        $path = sys_get_temp_dir() . '/qa-batch1-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, '%PDF-1.4 test');
        $pdo->prepare('UPDATE file_store SET server_path = :p WHERE id = :f')->execute(['p' => $path, 'f' => $file]);
        $this->actAs($this->createTestUser('Admin'));

        $code = DivisionService::withDivision(self::DOM, function () use ($file): int {
            ob_start();
            (new FileStoreController())->download(['id' => (string) $file]);
            ob_end_clean();
            return (int) http_response_code();
        });
        self::assertSame(403, $code, 'an Export client\'s file is refused in Domestic Trade');

        $viewer = $this->createTestUser('Viewer / Auditor');
        $pdo->prepare("INSERT INTO agreements (party_type, party_id, agreement_type, file_id, signed_on, created_by) VALUES ('supplier', 1, 'NDA', :f, CURDATE(), :u)")
            ->execute(['f' => $file, 'u' => $viewer]);
        $pdo->prepare('UPDATE file_store SET client_id = NULL WHERE id = :f')->execute(['f' => $file]);
        $this->actAs($viewer);
        http_response_code(200);
        ob_start();
        (new FileStoreController())->download(['id' => (string) $file]);
        ob_end_clean();
        self::assertSame(403, http_response_code(), 'a signed agreement needs "View agreements"');
    }

    /** ACC-8: no self-move, no protected account, and only into your own division without "See all divisions". */
    public function testMovingAUserBetweenDivisionsIsChecked(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'active' WHERE id = " . self::DOM);
        DivisionService::resetCache();
        $hr = $this->createTestUser('Export Executive');
        $staff = $this->createTestUser('Export Executive');
        $pdo->prepare('UPDATE users SET division_id = 1 WHERE id IN (:a, :b)')->execute(['a' => $hr, 'b' => $staff]);

        $this->actAs($hr);
        $move = function (int $target, string $division): ?string {
            $_POST = ['division_id' => $division];
            ob_start();
            (new DivisionController())->setUserDivision(['id' => (string) $target]);
            ob_end_clean();
            return Flash::pull()[0]['message'] ?? null;
        };
        self::assertSame('You cannot change your own division — ask another administrator.', $move($hr, (string) self::DOM));
        self::assertSame('You can only place people in your own division.', $move($staff, (string) self::DOM));
        self::assertSame(1, (int) $pdo->query("SELECT division_id FROM users WHERE id = {$staff}")->fetchColumn());
        self::assertSame('Division saved.', $move($staff, '1'));
    }

    /** ACC-9: logging in as a client is for looking only, and ends when the staff member is deactivated. */
    public function testClientImpersonationIsReadOnlyAndEndsWithTheStaffMember(): void
    {
        $client = $this->createTestClient();
        $staff = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $staff];
        ClientPortalService::startImpersonation($staff, $client);

        $this->request('POST', '/client/orders/1/acknowledge-oc');
        [$passed] = $this->through(ClientAuth::required());
        self::assertFalse($passed);
        self::assertSame('You are logged in as this client to look only: nothing can be saved for the buyer. Use "End impersonation" first.', Flash::pull()[0]['message'] ?? null);

        $this->request('GET', '/client');
        self::assertTrue($this->through(ClientAuth::required())[0]);

        Database::connection()->exec("UPDATE users SET is_active = 0 WHERE id = {$staff}");
        self::assertFalse($this->through(ClientAuth::required())[0]);
        self::assertNull(ClientPortalService::currentClientId(), 'the impersonation ended');
    }

    /** ACC-10: a client session ends when the password is changed or reset elsewhere. */
    public function testAClientSessionEndsWhenThePasswordChanges(): void
    {
        $client = $this->createTestClient();
        $hash = password_hash('ClientPass123!', PASSWORD_DEFAULT);
        Database::connection()->prepare('INSERT INTO client_logins (client_id, password_hash, is_active, force_password_change) VALUES (:c, :h, 1, 0)')
            ->execute(['c' => $client, 'h' => $hash]);
        $_SESSION = ['_client_portal_client_id' => $client, '_client_portal_password_fp' => ClientPortalService::passwordFingerprint($hash)];
        $this->request('GET', '/client');
        self::assertTrue($this->through(ClientAuth::required())[0]);

        ClientLoginRepository::updatePassword($client, password_hash('ResetByStaff456!', PASSWORD_DEFAULT), true);
        self::assertFalse($this->through(ClientAuth::required())[0], 'the old session is signed out');
    }

    /** ACC-11: a module switched off in the order's division refuses that order's address. */
    public function testAModuleOffInTheOrdersDivisionRefusesItsOrderAddresses(): void
    {
        self::assertNull(DivisionService::disabledModuleForPath('/orders/5/shipping-bill'), 'all modules on for Export');
        $label = DivisionService::withDivision(self::DOM, fn () => DivisionService::disabledModuleForPath('/orders/5/shipping-bill'));
        self::assertSame('Export shipping (BL, shipping bill, CHA, COO)', $label);
        self::assertSame('ECGC', DivisionService::withDivision(self::DOM, fn () => DivisionService::disabledModuleForPath('/orders/5/ecgc-declaration')));
    }

    /** ACC-12: staff placed in a division that is not active cannot save anything. */
    public function testStaffInAPlannedDivisionCannotSave(): void
    {
        $staff = $this->createTestUser('Export Executive');
        Database::connection()->prepare('UPDATE users SET division_id = :d WHERE id = :id')->execute(['d' => self::DOM, 'id' => $staff]);
        $this->actAs($staff);
        $this->request('POST', '/clients');
        [$passed, $body] = $this->through(SessionAuth::required());
        self::assertFalse($passed);
        self::assertSame(403, http_response_code());
        self::assertStringContainsString('is not active yet: nothing can be saved until the MD activates it', $body);
    }

    /** ACC-15: a plain link to /associate/logout does not sign anyone out. */
    public function testAssociateLogoutNeedsThePostedForm(): void
    {
        $_SESSION = ['_associate_portal_id' => 123];
        $this->request('GET', '/associate/logout');
        ob_start();
        (new AssociatePortalController())->logout([]);
        ob_end_clean();
        self::assertSame(123, $_SESSION['_associate_portal_id'] ?? null);
    }

    /** ACC-18: the full data dump needs CAFIN and all-division rights too. */
    public function testTheDataDumpNeedsCafinAndAllDivisions(): void
    {
        // Owner decision A-8 already keeps data_export_run from System Administrator; this user is granted it alone.
        $sysAdmin = $this->createTestUser('System Administrator');
        Database::connection()->prepare("INSERT INTO user_permissions (user_id, permission_id, is_enabled) SELECT :u, id, 1 FROM permissions WHERE permission_key = 'data_export_run'")->execute(['u' => $sysAdmin]);
        $this->actAs($sysAdmin);
        http_response_code(200);
        ob_start();
        (new DataExportController())->downloadData([]);
        $body = (string) ob_get_clean();
        self::assertSame(403, http_response_code());
        self::assertStringContainsString('needs "View & download CAFIN" and "See all divisions" as well', $body);
    }

    /** UI-1 / UI-9: the reason-length rule is for typed reasons only, and a field's own minlength wins. */
    public function testTheReasonRuleSkipsDropDownsAndHonoursMinlength(): void
    {
        foreach ([dirname(__DIR__, 3) . '/public_html/assets/js/app.js', dirname(__DIR__, 4) . '/NODE_MySQL_Version/app/public/js/app.js'] as $js) {
            $text = (string) file_get_contents($js);
            self::assertStringContainsString("(el.tagName === 'TEXTAREA' || type === 'text') && /reason/i.test(name)", $text, $js);
            self::assertStringContainsString('var reasonMin = el.minLength > 0 ? el.minLength : REASON_MIN_LENGTH;', $text, $js);
            self::assertStringContainsString('initKeepReportDivision();', $text, 'UI-4');
            self::assertStringContainsString('initSwitchViewKeepsFilters();', $text, 'UI-11');
        }
    }

    /** UI-2: no confirm() handler puts free text into a JS string (data-confirm instead). */
    public function testNoConfirmHandlerInterpolatesFreeText(): void
    {
        $bad = [];
        foreach ([self::VIEWS => 'php', self::NODE_VIEWS => 'njk'] as $root => $ext) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file->getExtension() !== $ext) {
                    continue;
                }
                foreach (file($file->getPathname()) as $n => $line) {
                    if (!preg_match('/on(?:submit|click)="return confirm\(\'(.*)\'\);"/', $line, $m)) {
                        continue;
                    }
                    $exprs = $ext === 'php' ? (preg_match_all('/<\?=(.*?)\?>/', $m[1], $e) ? $e[1] : []) : (preg_match_all('/\{\{(.*?)\}\}/', $m[1], $e) ? $e[1] : []);
                    foreach ($exprs as $expr) {
                        $expr = trim($expr);
                        $safe = str_contains($expr, 'addslashes(') || str_contains($expr, '(int)') || str_contains($expr, '| dump')
                            || preg_match("/\\? '[^']*' : '[^']*'$/", $expr) || preg_match("/^'[^']*' if .* else '[^']*'$/", $expr)
                            || in_array($expr, ['c.code', 'fy', 'candidateCount', "htmlspecialchars(\$c['code'])", 'htmlspecialchars($fy)'], true);
                        if (!$safe) {
                            $bad[] = basename($file->getPathname()) . ':' . ($n + 1) . ' ' . $expr;
                        }
                    }
                }
            }
        }
        self::assertSame([], $bad, 'move these to data-confirm="…" (read by app.js), so a name with an apostrophe cannot break or inject into the handler');
    }

    /** UI-3: every pattern compiles under the "v" flag current browsers use (( ) / escaped in a class). */
    public function testEveryPatternIsValidInCurrentBrowsers(): void
    {
        $bad = [];
        foreach ([self::VIEWS, self::NODE_VIEWS] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if (!in_array($file->getExtension(), ['php', 'njk'], true)) {
                    continue;
                }
                preg_match_all('/\bpattern="([^"<{]*)"/', (string) file_get_contents($file->getPathname()), $m);
                foreach ($m[1] as $pattern) {
                    // Inside a character class, v-mode needs ( ) [ { } / | escaped.
                    preg_match_all('/\[((?:\\\\.|[^\]\\\\])*)\]/', $pattern, $classes);
                    foreach ($classes[1] as $class) {
                        if (preg_match('/(?<!\\\\)[()\/{}|\[]/', $class)) {
                            $bad[] = basename($file->getPathname()) . ': ' . $pattern;
                        }
                    }
                }
            }
        }
        self::assertSame([], array_values(array_unique($bad)));
    }

    /** UI-10 / UI-15 / UI-12: a decision is chosen, Node has the friendly 413 page, and the rate lists have menu entries. */
    public function testChooseFirstSelectsNode413AndRateMenus(): void
    {
        foreach (['orders/show.php' => '<select name="decision" required>', 'amendments/index.php' => '<select name="requested_by" required>'] as $view => $open) {
            self::assertMatchesRegularExpression('/' . preg_quote($open, '/') . '\s*<option value="">Choose…<\/option>/', (string) file_get_contents(self::VIEWS . '/' . $view), $view);
        }
        foreach (['orders/show.njk' => '<select name="decision" required>', 'amendments/index.njk' => '<select name="requested_by" required>'] as $view => $open) {
            self::assertMatchesRegularExpression('/' . preg_quote($open, '/') . '\s*<option value="">Choose…<\/option>/', (string) file_get_contents(self::NODE_VIEWS . '/' . $view), $view);
        }
        self::assertStringContainsString('(maximum {{ maxLabel }})', (string) file_get_contents(self::NODE_VIEWS . '/errors/413.njk'));
        // D-320 (point 26): the menu entries live in one config per stack (Settings › Lists › GST rates).
        self::assertStringContainsString('"href": "/admin/gst-rates"', (string) file_get_contents(self::VIEWS . '/../src/Config/navigation.json'));
        self::assertStringContainsString('"href": "/admin/gst-rates"', (string) file_get_contents(self::NODE_VIEWS . '/../src/config/navigation.json'));
        self::assertSame(['manage_divisions'], \App\Helpers\PermittedLinks::requiredFor('/admin/gst-rates'));
        self::assertSame(['view_purchase_prices'], \App\Helpers\PermittedLinks::requiredFor('/import'));
    }
}
