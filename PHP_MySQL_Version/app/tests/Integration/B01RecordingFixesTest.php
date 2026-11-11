<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CoordinationController;
use App\Controllers\DashboardController;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DepartmentTaskRepository;
use App\Repositories\OrderAssigneeRepository;
use App\Repositories\OrderCommentRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Services\ActiveDepartmentService;
use App\Services\OrderAccessService;
use App\Services\OrderCommentService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-268 (8 Nov 2026): four fixes found while recording the B01
 * tutorial (Export Executive). The Node suite has the same tests
 * (b01RecordingFixes.test.js).
 *  1. Dashboard Recent Activity: without the audit-log permission, only your
 *     own actions and actions on orders you can open — never someone else's login.
 *  2. Order Communication: the true reason a staff post was not e-mailed.
 *  3. New Order: the product's specification options never push "Pick from
 *     Products" down (CSS hook).
 *  4. My Tasks: no department membership = only the tasks on orders you own.
 */
final class B01RecordingFixesTest extends DbTestCase
{
    private ?string $mailSetting = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailSetting = CompanySettingsRepository::get('email_testing_mode');
        OrderAccessService::resetCache();
    }

    protected function tearDown(): void
    {
        Database::connection()->prepare("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'email_testing_mode'")
            ->execute(['v' => $this->mailSetting]);
        $_SESSION = [];
        $_GET = [];
        OrderAccessService::resetCache();
        parent::tearDown();
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
        ActiveDepartmentService::resetCache();
        OrderAccessService::resetCache();
    }

    private function render(object $controller, string $action, array $get = []): string
    {
        $_GET = $get;
        ob_start();
        $controller->{$action}([]);
        return (string) ob_get_clean();
    }

    private function setMailSending(string $value): void
    {
        Database::connection()->prepare("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'email_testing_mode'")
            ->execute(['v' => $value]);
    }

    // ---- 1. Dashboard Recent Activity ---------------------------------------------------------

    public function testAnExportExecutiveSeesOnlyTheirOwnActionsAndTheirOrdersOnTheDashboard(): void
    {
        $tag = strtoupper(bin2hex(random_bytes(3)));
        $exec = $this->createTestUser('Export Executive');
        $other = $this->createTestUser('Export Executive');
        $mine = $this->createTestOrder($this->createTestClient());
        $notMine = $this->createTestOrder($this->createTestClient());
        OrderAssigneeRepository::add($mine, $exec, null);

        AuditLogRepository::log($other, "T{$tag}_OTHER_LOGIN", 'users', $other);
        AuditLogRepository::log($other, "T{$tag}_OTHER_ON_HIDDEN_ORDER", 'orders', $notMine);
        AuditLogRepository::log($other, "T{$tag}_OTHER_ON_MY_ORDER", 'orders', $mine);
        AuditLogRepository::log($exec, "T{$tag}_MY_OWN_ACTION", 'users', $exec);

        $this->signIn($exec);
        $html = $this->render(new DashboardController(), 'index');
        self::assertStringContainsString('Recent Activity', $html);
        self::assertStringContainsString("T{$tag}_MY_OWN_ACTION", $html, 'their own action');
        self::assertStringContainsString("T{$tag}_OTHER_ON_MY_ORDER", $html, 'an action on an order they can open');
        self::assertStringNotContainsString("T{$tag}_OTHER_LOGIN", $html, "never another person's login");
        self::assertStringNotContainsString("T{$tag}_OTHER_ON_HIDDEN_ORDER", $html, 'never an order they cannot open');

        $admin = $this->createTestUser('Admin');
        $this->signIn($admin);
        $html = $this->render(new DashboardController(), 'index');
        foreach (['OTHER_LOGIN', 'OTHER_ON_HIDDEN_ORDER', 'OTHER_ON_MY_ORDER', 'MY_OWN_ACTION'] as $what) {
            self::assertStringContainsString("T{$tag}_{$what}", $html, "Admin (audit-log permission) keeps the full feed: {$what}");
        }
    }

    // ---- 2. Order Communication: the true reason ----------------------------------------------

    /** @return array{0:int,1:int} [order id, staff user id] */
    private function orderFor(?string $clientEmail): array
    {
        $orderId = $this->createTestOrder($this->createTestClient($clientEmail));
        Database::connection()->prepare('UPDATE orders SET client_email = :e WHERE id = :id')->execute(['e' => $clientEmail, 'id' => $orderId]);
        return [$orderId, $this->createTestUser('Admin')];
    }

    private function noteFor(int $commentId, int $orderId): string
    {
        foreach (OrderCommentRepository::forOrder($orderId) as $c) {
            if ((int) $c['id'] === $commentId) {
                return (string) $c['email_note'];
            }
        }
        self::fail('comment not found');
    }

    public function testSendingSwitchedOffIsGivenAsTheReasonNotAMissingEmail(): void
    {
        $this->setMailSending('1') /* D-280: testing mode on */;
        [$orderId, $staff] = $this->orderFor('b01-' . bin2hex(random_bytes(4)) . '@buyer.test');
        $id = OrderCommentService::postAsStaff($orderId, $staff, 'Your goods are packed.', []);
        $row = Database::connection()->query("SELECT email_sent, email_status FROM order_comments WHERE id = {$id}")->fetch();
        self::assertSame(0, (int) $row['email_sent']);
        self::assertSame('testing_mode', $row['email_status']);
        self::assertSame('not e-mailed — testing mode holds e-mails to buyers and suppliers', $this->noteFor($id, $orderId));

    }

    public function testNoClientEmailOnFileIsGivenAsTheReason(): void
    {
        $this->setMailSending('0') /* testing mode off */;
        [$orderId, $staff] = $this->orderFor(null);
        $id = OrderCommentService::postAsStaff($orderId, $staff, 'Your goods are packed.', []);
        self::assertSame('no_email', Database::connection()->query("SELECT email_status FROM order_comments WHERE id = {$id}")->fetchColumn());
        self::assertSame('not e-mailed — no client e-mail on file', $this->noteFor($id, $orderId));
    }

    public function testEveryNoteHasItsExactWording(): void
    {
        $staff = ['author_type' => 'staff'];
        self::assertSame('e-mailed', OrderCommentRepository::emailNote($staff + ['email_sent' => 1, 'email_status' => 'sent']));
        self::assertSame('not e-mailed — e-mail sending is switched off in Admin', OrderCommentRepository::emailNote($staff + ['email_sent' => 0, 'email_status' => 'sending_off']));
        self::assertSame('not e-mailed — no client e-mail on file', OrderCommentRepository::emailNote($staff + ['email_sent' => 0, 'email_status' => 'no_email']));
        self::assertSame('not e-mailed — the e-mail could not be sent', OrderCommentRepository::emailNote($staff + ['email_sent' => 0, 'email_status' => 'failed']));
        self::assertSame('not e-mailed', OrderCommentRepository::emailNote($staff + ['email_sent' => 0, 'email_status' => null]), 'an older post claims no reason');
        self::assertSame('', OrderCommentRepository::emailNote(['author_type' => 'client', 'email_sent' => 0, 'email_status' => null]), "a client's own post carries no note");

        $view = (string) file_get_contents(__DIR__ . '/../../views/orders/show.php');
        self::assertStringNotContainsString('no client email on file', $view, 'the old fixed label is gone');
        self::assertStringContainsString('OrderCommentRepository::emailNote($c)', $view);
    }

    // ---- 3. New Order: specification options never push the picker down ------------------------

    public function testSpecificationOptionsSitInAResponsiveGridBelowThePicker(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/css/app.css');
        self::assertStringContainsString('.product-row > label.full,.product-row > .spec-options{grid-column:1 / -1;}', $css);
        self::assertMatchesRegularExpression('/\.spec-options\{display:grid;grid-template-columns:repeat\(auto-fill, minmax\(\d+px, 1fr\)\)/', $css);
        $picker = (string) file_get_contents(__DIR__ . '/../../views/orders/_product_picker.php');
        self::assertMatchesRegularExpression('/<label class="full">Pick from Products.*<\/label>\s*<div class="full spec-options"/s', $picker, 'the options come after the picker');
    }

    // ---- 4. My Tasks without a department membership ------------------------------------------

    public function testWithNoDepartmentOnlyTasksOnOrdersYouOwnAreListed(): void
    {
        $exec = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 5000.0, date('Y-m-d'));
        OrderAssigneeRepository::add($orderId, $exec, null);
        $ref = (string) Database::connection()->query("SELECT order_reference FROM orders WHERE id = {$orderId}")->fetchColumn();

        $this->signIn($exec);
        foreach ([['view' => 'list'], []] as $get) {
            $html = $this->render(new CoordinationController(), 'myTasks', $get);
            self::assertStringNotContainsString($ref, $html, "another department's task on an order they do not own is not listed");
            self::assertStringContainsString('You are not a member of any department at the moment, so only tasks on orders you own are listed here.', $html);
            self::assertStringNotContainsString("All of my department's tasks", $html);
        }

        $fin = (int) Database::connection()->query("SELECT id FROM departments WHERE code = 'FIN'")->fetchColumn();
        DepartmentTaskRepository::setOwner($orderId, $fin, $exec, $exec);
        $this->signIn($exec);
        self::assertStringContainsString($ref, $this->render(new CoordinationController(), 'myTasks', ['view' => 'list']), 'a task on an order they own is listed');

        $admin = $this->createTestUser('Admin');
        DepartmentTaskRepository::setOwner($orderId, $fin, $admin, $admin);
        $this->signIn($admin);
        $html = $this->render(new CoordinationController(), 'myTasks', ['view' => 'list']);
        self::assertStringContainsString($ref, $html, 'Admin without a membership keeps the company-wide view');
        self::assertStringNotContainsString('You are not a member of any department', $html);

        $this->signIn($exec);
        self::assertStringNotContainsString($ref, $this->render(new CoordinationController(), 'myTasks', ['view' => 'list']), 'once someone else owns it, it leaves their list');
    }
}
