<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ApprovalsController;
use App\Controllers\DashboardController;
use App\Controllers\OrderController;
use App\Controllers\SetupChecklistController;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\ApprovalsInbox;
use App\Services\GstCalculator;
use App\Services\SelfApprovalRules;
use App\Services\SetupChecklist;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions 11 Nov 2026 — D-310 (point 27): one Approvals page, the
 * sidebar "Approvals (n)", the dashboard tiles and the order page's
 * "Waiting for your approval" bar; D-312 (point 4 B): Admin › Setup
 * checklist; D-313 (point 4): ready-to-use defaults and no company values in
 * the starting data. Parity: approvalsAndSetupD310.test.js.
 */
final class ApprovalsAndSetupD310Test extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_GET = [];
        ApprovalsInbox::resetCache();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
        ApprovalsInbox::resetCache();
        parent::tearDown();
    }

    private function render(int $userId, object $controller, string $action, array $params = [], array $get = []): string
    {
        $_SESSION = ['_auth_user_id' => $userId];
        $_GET = $get;
        \App\Services\PermissionService::resetCache();
        ApprovalsInbox::resetCache();
        ob_start();
        $controller->{$action}($params);
        return (string) ob_get_clean();
    }

    /** @return array<string, array<string,mixed>> the person's approvals by key */
    private function itemsOf(int $userId): array
    {
        ApprovalsInbox::resetCache();
        $u = UserRepository::findById($userId);
        return array_column(ApprovalsInbox::items($userId, $u['role_id'] !== null ? (int) $u['role_id'] : null), null, 'key');
    }

    /** A USD order with line 1 sent to the MD by $asker. @return array{0:int,1:int} order id, line id */
    private function lineWaitingForTheMd(int $asker): array
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$asker]);
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, price_set_by, price_approval_requested_at, price_approval_requested_by)
                       VALUES (:o, 1, 'Granite slabs', 10, 'PCS', 40, 400, :u, NOW(), :u2)")->execute(['o' => $orderId, 'u' => $asker, 'u2' => $asker]);
        return [$orderId, (int) $pdo->lastInsertId()];
    }

    public function testWaitingSinceIsWrittenPlainly(): void
    {
        self::assertSame('Today 10:42', ApprovalsInbox::sinceLabel('2026-11-11 10:42:00', '2026-11-11'));
        self::assertSame('Yesterday 16:05', ApprovalsInbox::sinceLabel('2026-11-10 16:05:00', '2026-11-11'));
        self::assertSame('Yesterday 09:00', ApprovalsInbox::sinceLabel('2026-03-01 09:00:00', '2026-03-02'));
        self::assertSame('9 Nov', ApprovalsInbox::sinceLabel('2026-11-09 08:00:00', '2026-11-11'));
        self::assertSame('9 Nov 2025', ApprovalsInbox::sinceLabel('2025-11-09', '2026-11-11'));
        self::assertSame('11 Nov, 10:42', ApprovalsInbox::when('2026-11-11 10:42:00'));
    }

    public function testALinePriceWaitsForTheMdOnEveryScreenAndTheAskerSeesItWaiting(): void
    {
        $sales = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        [$orderId, $lineId] = $this->lineWaitingForTheMd($sales);

        $item = $this->itemsOf($md)['price:' . $lineId] ?? null;
        self::assertNotNull($item, 'the MD (manage_pricing) sees the line');
        self::assertSame('prices', $item['tab']);
        self::assertSame("/orders/{$orderId}/products/{$lineId}/price-approval", $item['url'], 'the button opens the exact approval screen');
        self::assertSame('Review and approve', $item['button']);
        self::assertStringStartsWith('line 1 — USD 40.00 / PCS', $item['bar']);
        self::assertArrayNotHasKey('price:' . $lineId, $this->itemsOf($sales), 'the person who asked cannot approve it');

        $salesUser = UserRepository::findById($sales);
        $waiting = ApprovalsInbox::waitingOnOthers($sales, (int) $salesUser['role_id'], $orderId);
        self::assertStringStartsWith('Line 1 — sent to the MD for approval on ', $waiting[0] ?? '');
        self::assertStringEndsWith(' — waiting.', $waiting[0]);

        // The order page: a bar at the very top for the MD, the "waiting" note for the asker.
        $page = $this->render($md, new OrderController(), 'show', ['id' => $orderId]);
        self::assertStringContainsString('<strong>Waiting for your approval:</strong> line 1 — USD 40.00 / PCS', $page);
        self::assertStringContainsString('href="/orders/' . $orderId . '/products/' . $lineId . '/price-approval">Review and approve line 1</a>', $page);
        self::assertLessThan(strpos($page, '<h1>'), strpos($page, 'id="approval-bar"'), 'the bar is above the order heading');
        $page = $this->render($sales, new OrderController(), 'show', ['id' => $orderId]);
        self::assertStringNotContainsString('id="approval-bar"', $page);
        self::assertStringContainsString('Line 1 — sent to the MD for approval on ', $page);

        // The Approvals page, the menu and the dashboard.
        $page = $this->render($md, new ApprovalsController(), 'index');
        self::assertStringContainsString('Approvals waiting for you (', $page);
        self::assertStringContainsString('id="approval-price-' . $lineId . '"', $page);
        self::assertMatchesRegularExpression('#href="/approvals\?tab=prices">Prices [1-9]#', $page);
        self::assertStringContainsString('href="/approvals" class="active"', $page, 'the sidebar entry');
        $page = $this->render($md, new ApprovalsController(), 'index', [], ['tab' => 'emails']);
        self::assertStringNotContainsString('id="approval-price-' . $lineId . '"', $page, 'the E-mails tab shows only e-mails');
        $dash = $this->render($md, new DashboardController(), 'index');
        self::assertStringContainsString('Prices waiting for my approval', $dash);
        self::assertMatchesRegularExpression('#href="/approvals">Open my approvals \([1-9][0-9]*\)</a>#', $dash);
    }

    public function testTheSelfApprovalRulesDecideWhatIsShown(): void
    {
        $md = $this->createTestUser('Managing Director');
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO email_log (order_id, template_key, recipient_email, subject, body_snapshot, status, requested_by)
                       VALUES (:o, 'test', 'buyer@example.test', 'Proforma Invoice', 'Body', 'pending_approval', :u)")->execute(['o' => $orderId, 'u' => $md]);
        $emailId = (int) $pdo->lastInsertId();
        $item = $this->itemsOf($md)['email:' . $emailId] ?? null;
        self::assertNotNull($item, 'MD may approve their own send (seeded rule)');
        self::assertSame(['emails', '/email-approvals#email-' . $emailId, 'Review e-mail'], [$item['tab'], $item['url'], $item['button']]);
        $before = SelfApprovalRules::roleIds('email_send');
        try {
            SelfApprovalRules::set('email_send', []);
            self::assertArrayNotHasKey('email:' . $emailId, $this->itemsOf($md), 'with the rule off, someone else must approve it');
            self::assertArrayHasKey('email:' . $emailId, $this->itemsOf($this->createTestUser('Executive Director')));
        } finally {
            SelfApprovalRules::set('email_send', $before);
            $pdo->prepare("UPDATE email_log SET status = 'cancelled' WHERE id = :id")->execute(['id' => $emailId]);
        }
    }

    public function testAKanbanUserGetsTheSameRowsAsABoard(): void
    {
        $sales = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        [$orderId, $lineId] = $this->lineWaitingForTheMd($sales);
        UserRepository::updateUiTemplatePreference($md, (int) UiTemplateRepository::findByKey('kanban')['id']);
        $board = $this->render($md, new ApprovalsController(), 'index');
        $list = $this->render($md, new ApprovalsController(), 'index', [], ['view' => 'list']);
        self::assertStringContainsString('Kanban Board', $board);
        self::assertStringContainsString('Switch to list view', $board);
        self::assertStringContainsString('Switch to board view', $list);
        foreach ([$board, $list] as $html) {
            self::assertStringContainsString('href="/orders/' . $orderId . '/products/' . $lineId . '/price-approval"', $html);
            self::assertStringContainsString('href="/orders/' . $orderId . '"', $html);
            foreach (ApprovalsInbox::TABS as $label) {
                self::assertMatchesRegularExpression('#class="filter-chip[^"]*" href="/approvals\?tab=[a-z_]+[^"]*">' . preg_quote($label, '#') . ' \d+#', $html);
            }
        }
    }

    public function testApprovalEmailsLinkToTheApprovalsPage(): void
    {
        self::assertStringEndsWith('/approvals', NotificationRepository::emailBody('Order X needs your price approval.', 'staff_approvals'));
        self::assertStringContainsString("\n\nOpen your approvals: ", NotificationRepository::emailBody('Order X needs your price approval.', 'staff_approvals'));
        self::assertSame('Order X: payment received.', NotificationRepository::emailBody('Order X: payment received.', 'staff_counterparty'));
        self::assertSame('staff_approvals', \App\Services\EmailPolicy::kindForNotification('short_payment_approval'));
    }

    public function testTheMenuEntryIsForApproversOnly(): void
    {
        $viewer = $this->createTestUser('Viewer / Auditor');
        $u = UserRepository::findById($viewer);
        self::assertFalse(ApprovalsInbox::isApprover($viewer, (int) $u['role_id']));
        $md = $this->createTestUser('Managing Director');
        self::assertTrue(ApprovalsInbox::isApprover($md, (int) UserRepository::findById($md)['role_id']));
    }

    // ------------------------------------------------------------- D-312 / D-313

    public function testTheStartingDataHasPlaceholdersAndTheNewDefaults(): void
    {
        foreach (['PHP_MySQL_Version', 'NODE_MySQL_Version'] as $stack) {
            $seed = (string) file_get_contents(dirname(__DIR__, 4) . "/{$stack}/docs/seed.sql");
            $schema = (string) file_get_contents(dirname(__DIR__, 4) . "/{$stack}/docs/schema.sql");
            foreach (SetupChecklist::PLACEHOLDERS as $key => $values) {
                if ($values[0] === '') {
                    continue;
                }
                self::assertMatchesRegularExpression("/\\('{$key}',\\s+'" . preg_quote(str_replace("'", "''", $values[0]), '/') . "'/", $seed, "{$stack}: {$key} starts as its placeholder");
            }
            self::assertTrue(GstCalculator::isValidGstin(SetupChecklist::PLACEHOLDERS['gstin'][0]), 'the placeholder GSTIN passes the GSTIN check');
            self::assertStringStartsWith('29', SetupChecklist::PLACEHOLDERS['gstin'][0], 'state code 29 kept for the domestic GST split');
            self::assertStringContainsString("('password_expiry_days',  '0',", $seed);
            self::assertStringContainsString("SELECT 'doc_cleanup_draft_days' AS k, '90' AS v,", $seed);
            self::assertStringContainsString("UNION ALL SELECT 'doc_cleanup_superseded_days', '365',", $seed);
            self::assertStringContainsString("UNION ALL SELECT 'doc_cleanup_cafin_mode', 'latest_only',", $seed);
            self::assertStringContainsString("CAST(CONCAT(YEAR(CURDATE()) + 1, '-10-02') AS DATE), 'Gandhi Jayanti'", $seed);
            self::assertStringContainsString("INSERT INTO test_mode_settings (id, is_enabled, test_email) VALUES (1, 0, 'info@email.com');", $schema);
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS short_payment_requests', $schema);
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS setup_checklist_ticks', $schema);
            self::assertDoesNotMatchRegularExpression('/(ADD|DROP|CHANGE|MODIFY) COLUMN IF (NOT )?EXISTS|CREATE INDEX IF NOT EXISTS/i', substr($schema, (int) strpos($schema, 'Owner decision D-310')));
        }
    }

    public function testPlaceholdersAreFlaggedNeededUntilTheRealValuesAreEntered(): void
    {
        $admin = $this->createTestUser('Admin');
        $keys = array_merge(array_keys(SetupChecklist::IDENTITY_KEYS), array_keys(SetupChecklist::BANK_KEYS), ['gstin', 'iec_pan']);
        $before = [];
        foreach ($keys as $k) {
            $before[$k] = CompanySettingsRepository::get($k);
        }
        try {
            CompanySettingsRepository::set('bank_account_no', '000000000000');
            CompanySettingsRepository::set('md_name', 'Managing Director');
            $items = array_column(SetupChecklist::items($admin, (int) UserRepository::findById($admin)['role_id']), null, 'key');
            self::assertSame('needed', $items['company']['status']);
            self::assertStringContainsString('MD name', $items['company']['detail']);
            self::assertSame('needed', $items['bank']['status']);
            self::assertStringContainsString('Account number', $items['bank']['detail']);
            self::assertSame('/settings', $items['bank']['url']);

            $real = ['legal_name' => 'Example Exports Private Limited', 'registered_office' => '1 Example Road, Example City – 100001, India',
                'corporate_office' => '2 Example Road, Example City – 100001, India', 'md_name' => 'MD Full Name', 'director_name' => 'Director Full Name',
                'phone' => '+91-1234567890', 'email' => 'office@example.test', 'bank_name' => 'Example Bank', 'bank_branch' => 'Main Branch',
                'bank_account_no' => '123456789012', 'ifsc' => 'EXMP0001234', 'swift_bic' => 'EXMPINBB', 'bank_address' => '3 Bank Street, Example City', 'gstin' => '29AAAAA0000A1ZY', 'iec_pan' => 'ABCDE1234F'];
            foreach ($real as $k => $v) {
                CompanySettingsRepository::set($k, $v);
            }
            $items = array_column(SetupChecklist::items($admin, (int) UserRepository::findById($admin)['role_id']), null, 'key');
            self::assertSame('done', $items['company']['status']);
            self::assertSame('needed', $items['bank']['status'], 'the placeholder GSTIN is still flagged');
            CompanySettingsRepository::set('gstin', '29ABCDE1234F1Z' . GstCalculator::gstinCheckChar('29ABCDE1234F1Z'));
            $items = array_column(SetupChecklist::items($admin, (int) UserRepository::findById($admin)['role_id']), null, 'key');
            self::assertSame('done', $items['bank']['status']);
            self::assertContains($items['zoho']['status'], ['optional', 'done'], 'Zoho is optional, never Needed');
            self::assertSame('/settings#smtp-test', $items['smtp']['url']);
            self::assertSame('/admin/backups', $items['backups']['url']);
        } finally {
            foreach ($before as $k => $v) {
                CompanySettingsRepository::set($k, $v);
            }
        }
    }

    public function testApplyRecommendedNeedsAReasonAndIsAudited(): void
    {
        $admin = $this->createTestUser('Admin');
        $before = CompanySettingsRepository::get('password_expiry_days');
        try {
            CompanySettingsRepository::set('password_expiry_days', '90');
            $diff = array_column(SetupChecklist::differences(), null, 'key');
            self::assertSame(['key' => 'password_expiry_days', 'label' => 'Password expiry (days, 0 = off)', 'current' => '90', 'recommended' => '0'], $diff['password_expiry_days']);
            self::assertNotNull(SetupChecklist::applyRecommended(['password_expiry_days'], 'short', $admin), 'a reason of 10+ characters is required');
            self::assertSame('90', CompanySettingsRepository::get('password_expiry_days'));
            self::assertNull(SetupChecklist::applyRecommended(['password_expiry_days'], 'Setting up the new installation as recommended', $admin));
            self::assertSame('0', CompanySettingsRepository::get('password_expiry_days'));
            $audit = Database::connection()->query("SELECT old_value, new_value, reason FROM audit_log WHERE action_type = 'FIELD_EDIT' AND field_name = 'password_expiry_days' ORDER BY id DESC LIMIT 1")->fetch();
            self::assertSame(['old_value' => '90', 'new_value' => '0', 'reason' => 'Setting up the new installation as recommended'], $audit);
            self::assertSame('Nothing to change — these settings are already at the recommended values.', SetupChecklist::applyRecommended(['password_expiry_days'], 'Setting up the new installation as recommended', $admin));
        } finally {
            CompanySettingsRepository::set('password_expiry_days', $before);
        }
    }

    public function testTicksNationalHolidaysAndThePage(): void
    {
        $admin = $this->createTestUser('Admin');
        $role = (int) UserRepository::findById($admin)['role_id'];
        $exec = $this->createTestUser('Export Executive');
        self::assertFalse(SetupChecklist::mayTick('pricing_confirmed', $exec, (int) UserRepository::findById($exec)['role_id']), 'pricing is ticked by the MD (manage_pricing)');
        self::assertNotNull(SetupChecklist::setTick('pricing_confirmed', true, null, $exec, (int) UserRepository::findById($exec)['role_id']));
        $had = SetupChecklist::tick('tds_benefits_confirmed');
        try {
            self::assertNull(SetupChecklist::setTick('tds_benefits_confirmed', true, 'Confirmed with the CA by phone', $admin, $role));
            $items = array_column(SetupChecklist::items($admin, $role), null, 'key');
            self::assertSame('done', $items['tds_benefits_confirmed']['status']);
            self::assertStringContainsString('Confirmed with the CA by phone', $items['tds_benefits_confirmed']['detail']);
            self::assertStringContainsString('add the state and festival holidays for ' . date('Y'), $items['holidays']['detail']);
        } finally {
            if ($had === null) {
                SetupChecklist::setTick('tds_benefits_confirmed', false, null, $admin, $role);
            }
        }

        $pdo = Database::connection();
        $pdo->exec("DELETE FROM company_holidays WHERE holiday_date BETWEEN '2041-01-01' AND '2042-12-31'");
        self::assertSame(6, SetupChecklist::addNationalHolidays(2041, $admin));
        self::assertSame(0, SetupChecklist::addNationalHolidays(2041, $admin), 'added once only');
        self::assertSame('Gandhi Jayanti', $pdo->query("SELECT description FROM company_holidays WHERE holiday_date = '2042-10-02'")->fetchColumn());
        $pdo->exec("DELETE FROM company_holidays WHERE holiday_date BETWEEN '2041-01-01' AND '2042-12-31'");

        $page = $this->render($admin, new SetupChecklistController(), 'index');
        self::assertStringContainsString('<h1>Setup checklist</h1>', $page);
        self::assertStringContainsString('id="setup-lut_rcmc"', $page);
        self::assertStringContainsString('id="setup-md_email"', $page);
        self::assertStringContainsString('href="/admin/setup-checklist"', $page, 'Admin › Setup Checklist in the menu');
    }
}
