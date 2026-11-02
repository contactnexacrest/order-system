<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DocumentController;
use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\DepartmentTaskRepository;
use App\Repositories\OrderExportBenefitSchemeRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Services\MoneyVisibility;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-226 (2 Nov 2026, "3 yes"): the Export Compliance
 * department — Executive, Manager, Director — for government and agency
 * work: the compliance checklist, the COO Prep Sheet, export benefit
 * eligibility and claims, government documents on the order and their own
 * work queue; never purchase prices, logistics costs, buyer payment
 * amounts, profit, incentives or CAFIN. Node twin: exportCompliance.test.js.
 */
final class ExportComplianceTest extends DbTestCase
{
    private const ROLES = ['Export Compliance Executive', 'Export Compliance Manager', 'Export Compliance Director'];

    /** permission => [Executive, Manager, Director] */
    private const MATRIX = [
        'view_orders' => [1, 1, 1],
        'download_pdf' => [1, 1, 1],
        'view_selling_prices' => [1, 1, 1],
        'manage_compliance_checklist' => [1, 1, 1],
        'manage_export_benefit_claims' => [1, 1, 1],
        'generate_compliance_documents' => [1, 1, 1],
        'upload_order_documents' => [1, 1, 1],
        'view_all_orders' => [0, 1, 1],
        'assign_department_owner' => [0, 1, 1],
        'approve_documents' => [0, 1, 1],
        'view_cockpit' => [0, 0, 1],
        // never
        'view_buyer_payments' => [0, 0, 0],
        'view_purchase_prices' => [0, 0, 0],
        'view_logistics_costs' => [0, 0, 0],
        'manage_order_financials' => [0, 0, 0],
        'manage_incentives' => [0, 0, 0],
        'view_cafin' => [0, 0, 0],
        'generate_documents' => [0, 0, 0],
        'close_orders' => [0, 0, 0],
        'manage_orders' => [0, 0, 0],
    ];

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
        \App\Services\ActiveDepartmentService::resetCache();
    }

    private function call(object $controller, string $action, array $params): string
    {
        http_response_code(200);
        ob_start();
        $controller->{$action}($params);
        return (string) ob_get_clean();
    }

    private function roleId(string $name): int
    {
        return (int) Database::connection()->query('SELECT id FROM roles WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
    }

    public function testTheDepartmentItsLadderAndThePermissionsOfEachRole(): void
    {
        $pdo = Database::connection();
        $rows = $pdo->query(
            "SELECT dd.title, dd.level, r.name AS role FROM department_designations dd JOIN departments d ON d.id = dd.department_id
              JOIN roles r ON r.id = dd.role_id WHERE d.code = 'EXPORT_COMPLIANCE' ORDER BY dd.sort_order"
        )->fetchAll();
        self::assertSame([
            ['title' => 'Export Compliance Executive', 'level' => 'executive', 'role' => 'Export Compliance Executive'],
            ['title' => 'Export Compliance Manager', 'level' => 'manager', 'role' => 'Export Compliance Manager'],
            ['title' => 'Export Compliance Director', 'level' => 'director', 'role' => 'Export Compliance Director'],
        ], $rows);
        self::assertSame('Export Compliance', $pdo->query("SELECT name FROM departments WHERE code = 'EXPORT_COMPLIANCE'")->fetchColumn());

        foreach (self::ROLES as $i => $role) {
            $roleId = $this->roleId($role);
            foreach (self::MATRIX as $key => $expected) {
                self::assertSame((bool) $expected[$i], PermissionService::can(0, $roleId, $key), "{$role}: {$key}");
            }
        }
    }

    public function testEveryRoleThatClosedOrdersKeepsTheChecklist(): void
    {
        $missing = Database::connection()->query(
            "SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
              WHERE p.permission_key = 'close_orders' AND rp.is_enabled = 1
                AND NOT EXISTS (SELECT 1 FROM role_permissions rp2 JOIN permissions p2 ON p2.id = rp2.permission_id
                                 WHERE rp2.role_id = rp.role_id AND p2.permission_key = 'manage_compliance_checklist' AND rp2.is_enabled = 1)"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $missing);
    }

    public function testTheOrderPageGivesTheirToolsAndHidesEveryOtherMoney(): void
    {
        $user = $this->createTestUser('Export Compliance Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $user);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 7777.11, '2026-06-01');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-06-02 10:00:00', $user);
        $this->signIn($user);

        self::assertTrue(MoneyVisibility::can(MoneyVisibility::SELLING));
        self::assertFalse(MoneyVisibility::can(MoneyVisibility::BUYER_PAYMENTS));
        self::assertFalse(MoneyVisibility::can(MoneyVisibility::PURCHASE));
        self::assertFalse(MoneyVisibility::can(MoneyVisibility::LOGISTICS));

        $html = $this->call(new OrderController(), 'show', ['id' => (string) $orderId]);
        self::assertSame(200, http_response_code());
        self::assertStringContainsString('id="compliance-checklist"', $html, 'the compliance checklist');
        self::assertStringContainsString('action="/orders/' . $orderId . '/additional-documents"', $html, 'attach government documents (Step 14)');
        self::assertDoesNotMatchRegularExpression('/7,777\.11/', $html, 'no buyer payment amount');
        self::assertStringNotContainsString('id="order-financials"', $html, 'no Step 12 (profit)');
        self::assertStringNotContainsString('/ca-internal', $html, 'no CAFIN');
    }

    public function testTheyGenerateOnlyTheCooPrepSheet(): void
    {
        $user = $this->createTestUser('Export Compliance Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $user);
        $this->signIn($user);

        $_POST = ['document_type' => 'QT', 'generate_pdf' => '1'];
        $this->call(new DocumentController(), 'generate', ['id' => (string) $orderId]);
        self::assertStringContainsString('You can generate only the COO Prep Sheet', implode(' ', array_column(Flash::pull(), 'message')));

        $_POST = ['document_type' => 'COOPREP', 'generate_pdf' => '1'];
        $this->call(new DocumentController(), 'generate', ['id' => (string) $orderId]);
        self::assertStringNotContainsString('You can generate only the COO Prep Sheet', implode(' ', array_column(Flash::pull(), 'message')), 'COOPREP passes the department check (the stage rules still apply)');
    }

    public function testTheyAnswerSchemeEligibility(): void
    {
        $user = $this->createTestUser('Export Compliance Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $user);
        $schemes = OrderExportBenefitSchemeRepository::forOrder($orderId);
        self::assertNotEmpty($schemes);
        $this->signIn($user);

        $_POST = ['schemes' => [['scheme_name' => $schemes[0]['scheme_name'], 'status' => 'eligible', 'reason' => '']]];
        $this->call(new OrderFinancialsController(), 'setExportBenefitSchemeStatus', ['id' => (string) $orderId]);
        $after = array_column(OrderExportBenefitSchemeRepository::forOrder($orderId), 'status', 'scheme_name');
        self::assertSame('eligible', $after[$schemes[0]['scheme_name']]);
    }

    public function testTheirQueueHoldsTheChecklistAndTheEbrcFollowUp(): void
    {
        $user = $this->createTestUser('Export Compliance Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $user);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.0, '2026-06-01');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-06-02 10:00:00', $user);

        $tasks = array_values(array_filter(DepartmentTaskRepository::open(['EXPORT_COMPLIANCE']), static fn(array $t): bool => (int) $t['order_id'] === $orderId));
        $keys = array_unique(array_column($tasks, 'task_key'));
        self::assertContains('compliance_checklist_item', $keys);
        self::assertContains('ebrc_followup', $keys);
        foreach ($tasks as $t) {
            self::assertSame('EXPORT_COMPLIANCE', $t['department_code']);
            self::assertDoesNotMatchRegularExpression('/\d,\d{3}\.\d{2}/', (string) $t['subject'], 'no amounts in a task');
        }

        OrderPaymentStatusRepository::setAdvanceFirc($orderId, 'FIRC-TEST-1', '2026-06-03');
        $keys = array_column(array_filter(DepartmentTaskRepository::open(['EXPORT_COMPLIANCE']), static fn(array $t): bool => (int) $t['order_id'] === $orderId), 'task_key');
        self::assertNotContains('ebrc_followup', $keys, 'the eBRC task closes once the FIRC is on record');
    }
}
