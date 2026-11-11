<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DashboardController;
use App\Controllers\DivisionController;
use App\Controllers\UserController;
use App\Helpers\Flash;
use App\Helpers\PermittedLinks;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\DepartmentTaskRepository;
use App\Repositories\EmailLogRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ReportRepository;
use App\Services\CafinExportService;
use App\Services\DivisionService;
use App\Services\OrderAccessService;
use App\Services\PermissionService;
use App\Services\ReferenceNumberService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-257 (5 Nov 2026): divisions. A second division (DOM,
 * "planned" in the seed) is made ACTIVE inside this test only, to prove:
 * its staff never see Export's orders, clients, reports, counts, tasks or
 * CSV rows, and the reverse; company-level people see both and "All"; the
 * switcher, the report Division filter and the user Division field appear
 * only with two or more active divisions; numbering is separate and
 * Export's next number unchanged; a division's own setting applies only to
 * it; a module switched off hides its menu and refuses its address.
 * Node: divisionIsolation.test.js.
 */
final class DivisionIsolationTest extends DbTestCase
{
    private const DOM = 2;

    private int $expStaff;
    private int $domStaff;
    private int $md;
    private int $expClient;
    private int $domClient;
    private int $expOrder;
    private int $domOrder;
    private string $country;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Database::connection();
        $this->expStaff = $this->createTestUser('Sales/Export Manager');
        $this->domStaff = $this->createTestUser('Sales/Export Manager');
        $this->md = $this->createTestUser('Managing Director');
        $pdo->prepare('UPDATE users SET division_id = :d WHERE id = :id')->execute(['d' => self::DOM, 'id' => $this->domStaff]);
        $pdo->prepare('UPDATE users SET division_id = NULL WHERE id = :id')->execute(['id' => $this->md]);
        $this->expClient = $this->createTestClient();
        $this->domClient = $this->createTestClient();
        $pdo->prepare('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, 1)')->execute(['c' => $this->expClient]);
        $pdo->prepare('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)')->execute(['c' => $this->domClient, 'd' => self::DOM]);
        $this->expOrder = $this->createTestOrder($this->expClient);
        $this->domOrder = $this->createTestOrder($this->domClient);
        $this->country = 'Divisionland ' . bin2hex(random_bytes(3));
        $pdo->prepare('UPDATE orders SET division_id = :d, country_of_destination = :c WHERE id = :id')->execute(['d' => self::DOM, 'c' => $this->country, 'id' => $this->domOrder]);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        // Back to the seeded state: one active division; this test's records become ordinary Export test records.
        $pdo->exec("UPDATE divisions SET status = 'planned' WHERE id <> 1");
        $pdo->exec('UPDATE orders SET division_id = 1 WHERE division_id <> 1');
        $pdo->exec('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
        $pdo->exec('DELETE FROM client_divisions WHERE division_id <> 1');
        $pdo->exec("DELETE FROM division_settings WHERE setting_key IN ('fdn_overdue_days_c', 'require_product_pick')"); // only this test's rows: the seed holds DOM, IMP and MTT settings
        $pdo->exec("UPDATE division_modules SET is_enabled = 1 WHERE division_id = 1");
        $pdo->prepare("UPDATE division_modules SET is_enabled = 1 WHERE division_id = :d AND module_key = 'supplier_purchase'")->execute(['d' => self::DOM]);
        DivisionService::resetCache();
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function activateSecondDivision(): void
    {
        Database::connection()->prepare("UPDATE divisions SET status = 'active' WHERE id = :d")->execute(['d' => self::DOM]);
        DivisionService::resetCache();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    /** @param list<array<string,mixed>> $rows @return list<int> */
    private static function ids(array $rows, string $key = 'id'): array
    {
        return array_map(static fn (array $r): int => (int) $r[$key], $rows);
    }

    public function testWithOneActiveDivisionNothingChanges(): void
    {
        self::assertFalse(DivisionService::isMulti());
        $this->actAs($this->md);
        self::assertNull(DivisionService::currentIds(), 'company level, one division: no filter at all');
        self::assertFalse(DivisionService::showSwitcher());
        $page = $this->render(fn () => (new DashboardController())->index([]));
        self::assertStringNotContainsString('division-switcher', $page);
        self::assertStringNotContainsString('All divisions', $page);
        // D-320: the left menu is short; Divisions sits in the Settings section's menu bar, shown on the Settings pages.
        self::assertStringNotContainsString('href="/admin/divisions"', $page);
        $_SERVER['REQUEST_URI'] = '/settings';
        try {
            $settingsPage = $this->render(fn () => (new \App\Controllers\SettingsController())->index([]));
        } finally {
            unset($_SERVER['REQUEST_URI']);
        }
        self::assertStringContainsString('href="/admin/divisions"', $settingsPage, 'Admin › Divisions is the only visible place');

        $this->actAs($this->expStaff);
        self::assertSame([1], DivisionService::currentIds());
        $page = $this->render(fn () => (new DashboardController())->index([]));
        self::assertStringNotContainsString('division-switcher', $page);
        self::assertStringNotContainsString('/admin/divisions', $page);
        $edit = $this->render(fn () => (new UserController())->editForm(['id' => (string) $this->expStaff]));
        self::assertStringNotContainsString('user-division', $edit, 'no Division field with one active division');
    }

    public function testStaffOfEachDivisionSeeOnlyTheirOwnOrdersClientsReportsAndCounts(): void
    {
        $this->activateSecondDivision();
        $pdo = Database::connection();
        foreach ([$this->expOrder, $this->domOrder] as $orderId) {
            $pdo->prepare('UPDATE order_payment_status SET advance_amount = 100, advance_remittance_received_at = NOW() WHERE order_id = :o')->execute(['o' => $orderId]);
            $pdo->prepare("INSERT INTO email_log (order_id, recipient_email, subject, body_snapshot, status) VALUES (:o, 'buyer@division.test', 'PHPUnit division', 'x', 'pending_approval')")->execute(['o' => $orderId]);
        }
        $pdo->exec("UPDATE orders SET status = 'active' WHERE id IN ({$this->expOrder}, {$this->domOrder})");

        foreach ([[$this->expStaff, $this->expOrder, $this->domOrder, $this->expClient, $this->domClient], [$this->domStaff, $this->domOrder, $this->expOrder, $this->domClient, $this->expClient]] as [$user, $own, $other, $ownClient, $otherClient]) {
            $this->actAs($user);
            $roleId = (int) $pdo->query('SELECT role_id FROM users WHERE id = ' . $user)->fetchColumn();
            // Orders: list, board, archive and every page of an order.
            $list = self::ids(OrderRepository::allVisibleTo($user, true));
            self::assertContains($own, $list);
            self::assertNotContains($other, $list);
            self::assertNotContains($other, self::ids(OrderRepository::all()));
            self::assertNull(OrderAccessService::denialReason(OrderRepository::find($own), $user, $roleId));
            self::assertNotNull(OrderAccessService::denialReason(OrderRepository::find($other), $user, $roleId));
            self::assertNotNull(OrderAccessService::denialReasonForPath("/orders/{$other}/edit", $user, $roleId));
            self::assertNotNull(OrderAccessService::denialReasonForPath("/reports/order/{$other}", $user, $roleId));
            self::assertTrue(OrderAccessService::currentUserMaySeeOrderId($own));
            self::assertFalse(OrderAccessService::currentUserMaySeeOrderId($other));
            self::assertFalse(PermittedLinks::currentUserMayOpen("/orders/{$other}"));
            // Clients.
            $clients = self::ids(ClientRepository::all());
            self::assertContains($ownClient, $clients);
            self::assertNotContains($otherClient, $clients);
            self::assertFalse(DivisionService::allowsClient($otherClient));
            // Reports.
            $payments = self::ids(ReportRepository::paymentsReport(null, null)['rows']);
            self::assertContains($own, $payments);
            self::assertNotContains($other, $payments);
            self::assertSame($own === $this->domOrder, in_array($this->country, ReportRepository::distinctCountries(), true));
            self::assertSame([], ReportRepository::perOrder($other)['stages'] ?? [], 'the single-order report of another division is empty');
            // Dashboard, tasks and cockpit.
            $search = self::ids(DashboardRepository::search('PHPUnit Test Buyer'), 'order_id');
            self::assertNotContains($other, $search);
            $cockpitOrders = self::ids(DepartmentTaskRepository::open(), 'order_id');
            self::assertNotContains($other, $cockpitOrders);
            // CAFIN CSV order list (real orders only: mark both real for the check).
            $pdo->exec("UPDATE orders SET is_test_data = 0, is_sample_data = 0 WHERE id IN ({$this->expOrder}, {$this->domOrder})");
            $cafin = CafinExportService::orderIds(\App\Helpers\FinancialYear::current(), 'all');
            self::assertContains($own, $cafin);
            self::assertNotContains($other, $cafin);
            // Sidebar counts: the other division's waiting email is not counted.
            $this->actAs($user);
            $mine = EmailLogRepository::awaitingDispatchCount();
            $pdo->prepare("INSERT INTO email_log (order_id, recipient_email, subject, body_snapshot, status) VALUES (:o, 'buyer@division.test', 'PHPUnit division 2', 'x', 'pending_approval')")->execute(['o' => $other]);
            self::assertSame($mine, EmailLogRepository::awaitingDispatchCount(), 'another division\'s waiting email does not change the badge');
            self::assertNotContains($other, self::ids(EmailLogRepository::awaitingDispatch(), 'order_id'));
        }
    }

    public function testCompanyLevelSeesBothDivisionsAndCanPickOneOrAll(): void
    {
        $this->activateSecondDivision();
        $this->actAs($this->md);
        self::assertTrue(DivisionService::showSwitcher());
        self::assertNull(DivisionService::currentIds());
        $both = self::ids(OrderRepository::allVisibleTo($this->md, true));
        self::assertContains($this->expOrder, $both);
        self::assertContains($this->domOrder, $both);
        $page = $this->render(fn () => (new DashboardController())->index([]));
        self::assertStringContainsString('division-switcher', $page);
        self::assertStringContainsString('All divisions', $page);

        DivisionService::choose((string) self::DOM);
        $_SESSION[DivisionService::SESSION_CHOICE] = (string) self::DOM;
        $one = self::ids(OrderRepository::allVisibleTo($this->md, true));
        self::assertContains($this->domOrder, $one);
        self::assertNotContains($this->expOrder, $one);

        DivisionService::choose('all');
        $all = self::ids(OrderRepository::allVisibleTo($this->md, true));
        self::assertContains($this->expOrder, $all);
        self::assertContains($this->domOrder, $all);
        self::assertTrue(DivisionService::needsChoiceOnCreate(), 'with "All divisions" a new order asks which division');

        // Staff never get the switcher or a Division label.
        $this->actAs($this->domStaff);
        self::assertFalse(DivisionService::showSwitcher());
        $page = $this->render(fn () => (new DashboardController())->index([]));
        self::assertStringNotContainsString('division-switcher', $page);
        self::assertSame(self::DOM, DivisionService::forNewRecord(), 'a new record of a DOM staff member belongs to DOM');
    }

    public function testTheDivisionFieldAppearsOnlyWithTwoActiveDivisions(): void
    {
        $admin = $this->createTestUser('Admin');
        $this->actAs($admin);
        self::assertStringNotContainsString('user-division', $this->render(fn () => (new UserController())->editForm(['id' => (string) $this->expStaff])));
        $this->activateSecondDivision();
        $this->actAs($admin);
        self::assertStringContainsString('user-division', $this->render(fn () => (new UserController())->editForm(['id' => (string) $this->expStaff])));
    }

    public function testNumberingIsSeparateAndExportsNextNumberIsUnchanged(): void
    {
        $this->activateSecondDivision();
        $pdo = Database::connection();
        $key = 'order_ref:' . (new \DateTimeImmutable())->format('Ymd');
        $before = (int) ($pdo->query('SELECT last_seq FROM reference_sequences WHERE scope_key = ' . $pdo->quote($key))->fetchColumn() ?: 0);
        $dom = DivisionService::withDivision(self::DOM, static fn (): string => ReferenceNumberService::generateOrderReference(false));
        self::assertStringStartsWith('DOM/', $dom);
        self::assertSame($before, (int) ($pdo->query('SELECT last_seq FROM reference_sequences WHERE scope_key = ' . $pdo->quote($key))->fetchColumn() ?: 0), 'a DOM number does not move Export\'s counter');
        $exp = DivisionService::withDivision(1, static fn (): string => ReferenceNumberService::generateOrderReference(false));
        self::assertStringStartsWith('NC/SC/ORD/', $exp);
        self::assertStringEndsWith(str_pad((string) ($before + 1), 3, '0', STR_PAD_LEFT), $exp, 'Export\'s next number is the one it would have been');
    }

    public function testADivisionsOwnSettingAppliesOnlyToThatDivision(): void
    {
        $this->activateSecondDivision();
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO division_settings (division_id, scope, setting_key, setting_value) VALUES (:d, 'company', 'fdn_overdue_days_c', '99'), (:d2, 'pricing', 'require_product_pick', '0')")
            ->execute(['d' => self::DOM, 'd2' => self::DOM]);
        DivisionService::resetCache();
        $company = CompanySettingsRepository::companyValue('fdn_overdue_days_c');
        self::assertSame('99', DivisionService::withDivision(self::DOM, static fn () => CompanySettingsRepository::get('fdn_overdue_days_c')));
        self::assertSame($company, DivisionService::withDivision(1, static fn () => CompanySettingsRepository::get('fdn_overdue_days_c')));
        self::assertSame('0', DivisionService::withDivision(self::DOM, static fn () => PricingRepository::settings()['require_product_pick']));
        self::assertSame('1', DivisionService::withDivision(1, static fn () => PricingRepository::settings()['require_product_pick']));
    }

    public function testAModuleSwitchedOffHidesItsMenuAndRefusesItsAddress(): void
    {
        $this->activateSecondDivision();
        $pdo = Database::connection();
        $pdo->prepare("UPDATE division_modules SET is_enabled = 0 WHERE division_id = :d AND module_key = 'supplier_purchase'")->execute(['d' => self::DOM]);
        $proc = $this->createTestUser('Procurement Manager');
        $pdo->prepare('UPDATE users SET division_id = :d WHERE id = :id')->execute(['d' => self::DOM, 'id' => $proc]);
        $this->actAs($proc);
        self::assertNotNull(DivisionService::disabledModuleForPath('/procurement'));
        self::assertFalse(PermittedLinks::currentUserMayOpen('/procurement'));
        self::assertStringNotContainsString('href="/procurement"', $this->render(fn () => (new DashboardController())->index([])));
        // SessionAuth refuses the address.
        $_SERVER['REQUEST_URI'] = '/procurement';
        $_SESSION['_session_version'] = (int) $pdo->query('SELECT session_version FROM users WHERE id = ' . $proc)->fetchColumn();
        $out = $this->render(function () use (&$allowed): void {
            $allowed = (\App\Middleware\SessionAuth::required())([]);
        });
        self::assertFalse($allowed);
        self::assertStringContainsString('not in use here', $out);

        $pdo->prepare('UPDATE users SET division_id = 1 WHERE id = :id')->execute(['id' => $proc]);
        $this->actAs($proc);
        self::assertNull(DivisionService::disabledModuleForPath('/procurement'), 'Export keeps every module on');
        self::assertStringContainsString('href="/procurement"', $this->render(fn () => (new DashboardController())->index([])));
    }

    /** The documents rule: Export's document data is exactly the same whether or not a division scope is in force. */
    public function testExportDocumentDataIsUnchangedByTheDivisionLayer(): void
    {
        foreach (['QT', 'PI', 'OC', 'CI', 'PL'] as $code) {
            $plain = \App\Services\DocumentDataAssembler::assemble($this->expOrder, $code);
            $scoped = DivisionService::withDivision(1, fn (): array => \App\Services\DocumentDataAssembler::assemble($this->expOrder, $code));
            $this->actAs($this->expStaff);
            $asStaff = \App\Services\DocumentDataAssembler::assemble($this->expOrder, $code);
            $_SESSION = [];
            PermissionService::resetCache();
            unset($plain['generated_at'], $scoped['generated_at'], $asStaff['generated_at']);
            self::assertEquals($plain, $scoped, "{$code} data with the Export scope forced");
            self::assertEquals($plain, $asStaff, "{$code} data for Export staff");
        }
    }

    public function testActivationNeedsThePreflightChecklist(): void
    {
        $md = $this->md;
        $this->actAs($md);
        $_POST = ['confirm' => '1'];
        $this->render(fn () => (new DivisionController())->activate(['id' => (string) self::DOM]));
        DivisionService::resetCache();
        self::assertSame('planned', DivisionService::find(self::DOM)['status'], 'not activated while the checklist is open');
        self::assertStringContainsString('Finish the checklist first', implode(' ', array_column(Flash::pull(), 'message')));
        $page = $this->render(fn () => (new DivisionController())->show(['id' => (string) self::DOM]));
        self::assertStringContainsString('division-preflight', $page);
        self::assertStringContainsString('opacity:.6', $this->render(fn () => (new DivisionController())->index([])), 'planned divisions are greyed');
    }
}
