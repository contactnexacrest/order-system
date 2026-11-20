<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Controllers\ClientIntakeReviewController;
use App\Controllers\CoordinationController;
use App\Controllers\NotificationController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Helpers\SettingValueValidator;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\ClientRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\ProspectService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-394 (20 Nov 2026): quick-quote prospects — the client
 * added with only a name, an e-mail and a country; its badge and filter on
 * the Clients list and on the Orders list (Standard and Kanban alike); one
 * reminder before it expires; the daily expiry that moves its quotation
 * order to "Did not go ahead" (never one Mark Lost would refuse) and
 * deactivates it; Keep for N more days; reactivating with a fresh date; a
 * Quotation request from its e-mail updating it; the settings. Nothing is
 * ever deleted. Node twin: tests/integration/prospectD394.test.js.
 */
final class ProspectD394Test extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
        $pdo = Database::connection();
        $pdo->exec("UPDATE company_settings SET setting_value = '60' WHERE setting_key = 'prospect_expiry_days'");
        $pdo->exec("UPDATE company_settings SET setting_value = '7' WHERE setting_key = 'prospect_reminder_days'");
        $pdo->exec("UPDATE company_settings SET setting_value = '1' WHERE setting_key = 'prospect_auto_close'");
        // Every earlier prospect is out of the way, so each test sees only its own.
        $pdo->exec('UPDATE clients SET is_prospect = 0 WHERE is_prospect = 1');
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function call(callable $fn): string
    {
        $_SESSION['_auth_user_id'] = $_SESSION['_auth_user_id'] ?? $this->userId;
        \App\Services\PermissionService::resetCache();
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private static function flash(string $type = 'success'): ?string
    {
        foreach (Flash::pull() as $f) {
            if ($f['type'] === $type) {
                return $f['message'];
            }
        }
        return null;
    }

    private static function email(): string
    {
        return 'prospect-' . bin2hex(random_bytes(4)) . '@buyer.example';
    }

    /** A prospect added through + Add client (only the three required details). */
    private function addProspect(string $name = 'Quick Quote Granite Ltd', ?string $email = null): int
    {
        $_POST = ['is_prospect' => '1', 'company_legal_name' => $name, 'email' => $email ?? self::email(), 'country_of_destination' => 'Germany',
            'consignee_same_as_buyer' => '1', 'notify_party_same_as_consignee' => '1'];
        $this->call(fn () => (new ClientController())->store([]));
        Flash::pull();
        return (int) Database::connection()->query('SELECT MAX(id) FROM clients')->fetchColumn();
    }

    private function setExpiry(int $clientId, string $date, ?string $remindedOn = null): void
    {
        Database::connection()->prepare('UPDATE clients SET prospect_expires_on = :d, prospect_reminded_on = :r WHERE id = :id')
            ->execute(['d' => $date, 'r' => $remindedOn, 'id' => $clientId]);
    }

    // ---------------------------------------------------------------- 1. + Add client

    public function testAProspectNeedsOnlyTheNameEmailAndCountry(): void
    {
        $email = self::email();
        $id = $this->addProspect('Quick Quote Granite Ltd', $email);
        $c = ClientRepository::find($id);
        self::assertSame('Quick Quote Granite Ltd', $c['company_legal_name']);
        self::assertSame(1, (int) $c['is_prospect']);
        self::assertSame(date('Y-m-d', strtotime('+60 days')), $c['prospect_expires_on']);
        self::assertSame('To Be Confirmed', $c['billing_address'], 'an empty address prints "To Be Confirmed" as before');
        self::assertNull($c['billing_address_line1']);
        self::assertSame('Germany', $c['country_of_destination']);
        self::assertSame(1, (int) $c['is_active']);

        // Without an e-mail (or a country) a prospect is refused on the server too.
        $before = (int) Database::connection()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
        $_POST = ['is_prospect' => '1', 'company_legal_name' => 'No Mail Ltd', 'country_of_destination' => 'Germany'];
        $this->call(fn () => (new ClientController())->store([]));
        self::assertSame(ProspectService::REQUIRED_MESSAGE, self::flash('error'));
        $_POST = ['is_prospect' => '1', 'company_legal_name' => 'No Country Ltd', 'email' => self::email()];
        $this->call(fn () => (new ClientController())->store([]));
        self::assertSame(ProspectService::REQUIRED_MESSAGE, self::flash('error'));
        // Without the box the billing address is still required, as before (D-346).
        $_POST = ['company_legal_name' => 'Ordinary Ltd', 'email' => self::email(), 'country_of_destination' => 'Germany'];
        $this->call(fn () => (new ClientController())->store([]));
        self::assertSame(ClientController::REQUIRED_MESSAGE, self::flash('error'));
        self::assertSame($before, (int) Database::connection()->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        // D-129: one e-mail per client, prospects included.
        $_POST = ['is_prospect' => '1', 'company_legal_name' => 'Same Mail Ltd', 'email' => strtoupper($email), 'country_of_destination' => 'Germany'];
        $this->call(fn () => (new ClientController())->store([]));
        self::assertStringContainsString('Another client already uses the email', (string) self::flash('error'));
    }

    public function testTheFormChecksTheProspectRulesInTheBrowser(): void
    {
        $html = $this->call(fn () => (new ClientController())->create([]));
        self::assertStringContainsString('Quick quote — the buyer has not sent their details yet (prospect)', $html);
        self::assertMatchesRegularExpression('/name="billing_address_line1"[^>]*required data-required-unless-ticked="is_prospect"/', $html);
        self::assertMatchesRegularExpression('/name="billing_city"[^>]*required data-required-unless-ticked="is_prospect"/', $html);
        self::assertMatchesRegularExpression('/name="billing_country"[^>]*required data-required-unless-ticked="is_prospect"/', $html);
        self::assertMatchesRegularExpression('/name="email"[^>]*data-required-if-ticked="is_prospect"/', $html);
        self::assertMatchesRegularExpression('/name="country_of_destination"[^>]*data-required-if-ticked="is_prospect"/', $html);
        self::assertStringContainsString('optional for a prospect', $html);
        $js = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/js/app.js');
        self::assertStringContainsString('function requiredWhenTickedApply(form)', $js);
        self::assertStringContainsString("var need = el.hasAttribute('data-required-if-ticked') ? ticked : !ticked;", $js);
        self::assertMatchesRegularExpression('/function init\(\) \{.*initRequiredWhenTicked\(\);/s', $js);
    }

    public function testFillingInEveryDetailEndsTheProspect(): void
    {
        $id = $this->addProspect();
        $c = ClientRepository::find($id);
        $base = ['company_legal_name' => $c['company_legal_name'], 'email' => $c['email'], 'country_of_destination' => 'Germany',
            'consignee_same_as_buyer' => '1', 'notify_party_same_as_consignee' => '1',
            'edit_fingerprint' => \App\Helpers\EditFingerprint::of($c, ClientController::editFields())];
        // Still partial, the box ticked: saved, still a prospect.
        $_POST = $base + ['is_prospect' => '1', 'phone' => '+49 30 000000'];
        $this->call(fn () => (new ClientController())->update(['id' => (string) $id]));
        self::assertNotNull(self::flash());
        self::assertSame(1, (int) ClientRepository::find($id)['is_prospect']);

        $c = ClientRepository::find($id);
        $base['edit_fingerprint'] = \App\Helpers\EditFingerprint::of($c, ClientController::editFields());
        $_POST = $base + ['is_prospect' => '1', 'billing_address_line1' => '1 Quarry Road', 'billing_city' => 'Berlin', 'billing_country' => 'Germany'];
        $this->call(fn () => (new ClientController())->update(['id' => (string) $id]));
        self::assertStringContainsString('no longer a prospect', (string) self::flash());
        $c = ClientRepository::find($id);
        self::assertSame(0, (int) $c['is_prospect']);
        self::assertNull($c['prospect_expires_on']);
        self::assertSame('1 Quarry Road, Berlin, Germany', $c['billing_address']);
    }

    public function testToBeConfirmedCountsAsEmptySoTheProspectStays(): void
    {
        // Owner, 20 Nov 2026 (option A): the "To be confirmed" placeholder staff typed
        // before D-394 is not a real detail.
        foreach (['', '  ', 'To be confirmed', 'TO BE CONFIRMED', 'to be  confirmed.', ' To Be Confirmed '] as $v) {
            self::assertTrue(\App\Services\ProspectService::isPlaceholder($v), "'{$v}' is a placeholder");
        }
        foreach (['1 Quarry Road', 'To be confirmed by phone', 'Confirmed'] as $v) {
            self::assertFalse(\App\Services\ProspectService::isPlaceholder($v), "'{$v}' is a real detail");
        }

        $id = $this->addProspect();
        $c = ClientRepository::find($id);
        $_POST = ['company_legal_name' => $c['company_legal_name'], 'email' => $c['email'], 'country_of_destination' => 'Germany',
            'consignee_same_as_buyer' => '1', 'notify_party_same_as_consignee' => '1', 'is_prospect' => '1',
            'billing_address_line1' => 'To be confirmed', 'billing_city' => 'To Be Confirmed', 'billing_country' => 'To be confirmed',
            'edit_fingerprint' => \App\Helpers\EditFingerprint::of($c, ClientController::editFields())];
        $this->call(fn () => (new ClientController())->update(['id' => (string) $id]));
        self::assertStringNotContainsString('no longer a prospect', (string) self::flash());
        $c = ClientRepository::find($id);
        self::assertSame(1, (int) $c['is_prospect']);
        self::assertNotNull($c['prospect_expires_on']);
    }

    // ---------------------------------------------------------------- 2. Clients list

    public function testTheClientsListFiltersProspectsAndShowsTheirBadgeAndDate(): void
    {
        $prospect = $this->addProspect('Badge Prospect Ltd');
        $this->setExpiry($prospect, date('Y-m-d', strtotime('+5 days')));
        $ordinary = $this->createTestClient(self::email());

        $all = $this->call(fn () => (new ClientController())->index([]));
        self::assertMatchesRegularExpression('/href="\/clients\?show=prospects">Prospects \(1\)/', $all);
        self::assertStringContainsString('Badge Prospect Ltd</span></a>' . "\n" . '        <span class="tag tag-gold" data-testid="prospect-badge">Prospect</span>', $all);
        self::assertStringContainsString('prospect-expiry prospect-expiry-urgent', $all, 'red within the reminder days');
        self::assertStringContainsString('Quote expires ' . \App\Helpers\Dates::short(date('Y-m-d', strtotime('+5 days'))) . ' (in 5 days)', $all);

        $_GET = ['show' => 'prospects'];
        $only = $this->call(fn () => (new ClientController())->index([]));
        self::assertStringContainsString("/clients/{$prospect}\"", $only);
        self::assertStringNotContainsString("/clients/{$ordinary}\"", $only);
        $_GET = ['show' => 'clients'];
        $clients = $this->call(fn () => (new ClientController())->index([]));
        self::assertStringNotContainsString("/clients/{$prospect}\"", $clients);
        self::assertStringContainsString("/clients/{$ordinary}\"", $clients);
    }

    public function testTheProspectPageExplainsTheDatesAndOffersItsButtons(): void
    {
        $id = $this->addProspect('Page Prospect Ltd');
        $orderId = $this->createTestOrder($id, 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);
        $html = $this->call(fn () => (new ClientController())->show(['id' => (string) $id]));
        $exp = \App\Helpers\Dates::short(date('Y-m-d', strtotime('+60 days')));
        self::assertStringContainsString('Prospect — details not confirmed yet.', $html);
        self::assertStringContainsString('/orders/' . $orderId . '"', $html);
        self::assertStringContainsString('this client to Deactivated on ' . $exp . ' (in 60 days)', $html);
        self::assertStringContainsString('reminded on ' . \App\Helpers\Dates::short(date('Y-m-d', strtotime('+53 days'))), $html);
        self::assertStringContainsString('Fill in the details', $html);
        self::assertStringContainsString("/clients/{$id}/send-quotation-form", $html);
        self::assertStringContainsString('Keep for 60 more days', $html);
    }

    public function testTheQuotationFormEmailIsHeldInTestingModeAndLogged(): void
    {
        $id = $this->addProspect();
        Database::connection()->exec("UPDATE company_settings SET setting_value = '1' WHERE setting_key = 'email_testing_mode'");
        $this->call(fn () => (new ClientController())->sendQuotationForm(['id' => (string) $id]));
        self::assertStringContainsString('Not e-mailed: testing mode holds e-mails to buyers and suppliers', (string) self::flash('error'));
        $row = Database::connection()->query("SELECT action_type FROM audit_log WHERE entity_type = 'clients' AND entity_id = {$id} AND action_type LIKE 'CLIENT_QUOTATION_FORM%'")->fetchColumn();
        self::assertSame('CLIENT_QUOTATION_FORM_NOT_EMAILED', $row);
        self::assertStringContainsString('/quotation-details', ProspectService::quotationFormEmailBody('X Ltd', 'x@buyer.example', 'https://example.test/quotation-details'));
    }

    // ---------------------------------------------------------------- 4/5. Orders list, Standard and Kanban

    public function testTheOrdersListAndBoardBothFilterProspectsWithTheSameBadgeAndLine(): void
    {
        $prospect = $this->addProspect('Board Prospect Ltd');
        $this->setExpiry($prospect, date('Y-m-d', strtotime('+3 days')));
        $pOrder = $this->createTestOrder($prospect, 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);
        $other = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);

        $_GET = ['view' => 'list', 'status' => 'prospects'];
        $list = $this->call(fn () => (new OrderController())->index([]));
        UserRepository::updateUiTemplatePreference($this->userId, (int) UiTemplateRepository::findByKey('kanban')['id']);
        $_GET = ['status' => 'prospects'];
        $board = $this->call(fn () => (new OrderController())->index([]));
        self::assertStringContainsString('kanban-board', $board);
        foreach (['list' => $list, 'board' => $board] as $which => $html) {
            self::assertMatchesRegularExpression('/class="filter-chip active" href="\/orders\?status=prospects[^"]*">Prospects \(1\)/', $html, $which);
            self::assertStringContainsString("/orders/{$pOrder}", $html, $which);
            self::assertStringNotContainsString("/orders/{$other}\"", $html, $which);
            self::assertStringNotContainsString("/orders/{$other}?", $html, $which);
            self::assertStringContainsString('data-testid="prospect-badge">Prospect</span>', $html, $which);
            self::assertStringContainsString('prospect-expiry prospect-expiry-urgent">No reply yet · expires in 3 days', $html, $which);
        }
        // The ordinary order has no badge on the "All" list.
        $_GET = ['view' => 'list', 'status' => 'all'];
        $all = $this->call(fn () => (new OrderController())->index([]));
        self::assertSame(1, substr_count($all, 'data-testid="prospect-badge"'));
    }

    // ---------------------------------------------------------------- 6. Reminder

    public function testTheOwnerIsRemindedOnceWithANotificationATaskAndAnEmail(): void
    {
        $owner = $this->createTestUser('Export Executive');
        $id = $this->addProspect('Remind Prospect Ltd');
        $orderId = $this->createTestOrder($id, 'FOB', 'Standard — New Buyer', 'USD', null, null, [$owner]);
        $this->setExpiry($id, date('Y-m-d', strtotime('+20 days')));
        self::assertSame(0, ProspectService::sendReminders(), 'not yet: more than 7 days away');
        $this->setExpiry($id, date('Y-m-d', strtotime('+7 days')));
        self::assertSame(1, ProspectService::sendReminders());
        self::assertSame(0, ProspectService::sendReminders(), 'only once per expiry date');

        $n = Database::connection()->query("SELECT * FROM notifications WHERE type = 'prospect_expiry_reminder' AND related_order_id = {$orderId}")->fetchAll();
        self::assertCount(1, $n);
        self::assertSame($owner, (int) $n[0]['user_id']);
        self::assertStringStartsWith('Reminder: quotation for Remind Prospect Ltd expires on ' . \App\Helpers\Dates::short(date('Y-m-d', strtotime('+7 days'))), $n[0]['message']);
        self::assertStringContainsString('Nothing is deleted, and the client can be activated again.', $n[0]['message']);
        self::assertSame('staff_compliance', \App\Services\EmailPolicy::kindForNotification('prospect_expiry_reminder'), 'the e-mail follows the compliance alerts switch');
        self::assertSame(date('Y-m-d'), ClientRepository::find($id)['prospect_reminded_on']);

        // My tasks (Sales) shows it.
        $tasks = \App\Repositories\DepartmentTaskRepository::open(['SALES']);
        $mine = array_values(array_filter($tasks, static fn (array $t): bool => $t['task_key'] === 'prospect_quote_expiring' && (int) $t['order_id'] === $orderId));
        self::assertCount(1, $mine);
        self::assertStringContainsString('Remind Prospect Ltd — quotation expires on', $mine[0]['subject']);

        // The notification offers "Open the order" and "Keep for 60 more days".
        $_SESSION['_auth_user_id'] = $owner;
        Database::connection()->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled) SELECT u.role_id, p.id, 1 FROM users u JOIN permissions p ON p.permission_key = \'manage_orders\' WHERE u.id = :u')->execute(['u' => $owner]);
        $html = $this->call(fn () => (new NotificationController())->index([]));
        self::assertStringContainsString("<a href=\"/orders/{$orderId}\">Open the order</a>", $html);
        self::assertStringContainsString("/clients/{$id}/prospect-keep", $html);
        self::assertStringContainsString('Keep for 60 more days', $html);
    }

    // ---------------------------------------------------------------- 7. Automatic expiry

    public function testTheDailyJobClosesTheQuotationOrderAndDeactivatesTheProspect(): void
    {
        $id = $this->addProspect('Expire Prospect Ltd');
        $orderId = $this->createTestOrder($id);
        $this->setExpiry($id, date('Y-m-d'));
        $result = ProspectService::expireDue();
        self::assertSame(1, $result['clients']);
        self::assertSame(1, $result['orders']);

        $o = OrderRepository::find($orderId);
        self::assertSame('lost', $o['status']);
        self::assertSame(1, (int) $o['is_locked']);
        self::assertSame('Quotation expired — no reply in 60 days', $o['lost_reason']);
        self::assertSame('quotation_expired', $o['lost_category']);
        self::assertNull($o['lost_by'], 'closed by the system');
        $c = ClientRepository::find($id);
        self::assertSame(0, (int) $c['is_active']);
        self::assertSame(1, (int) $c['is_prospect'], 'still a prospect, so it can come back');
        self::assertSame('Quote expired ' . \App\Helpers\Dates::short(date('Y-m-d')) . ' — no reply in 60 days', $c['prospect_expired_reason']);
        $audit = Database::connection()->query("SELECT action_type FROM audit_log WHERE (entity_type = 'orders' AND entity_id = {$orderId}) OR (entity_type = 'clients' AND entity_id = {$id}) ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('ORDER_MARKED_LOST', $audit);
        self::assertContains('CLIENT_DEACTIVATED', $audit);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM clients WHERE id = {$id}")->fetchColumn(), 'nothing deleted');

        // Idempotent.
        self::assertSame(['clients' => 0, 'orders' => 0, 'skipped' => []], ProspectService::expireDue());

        // The order page says it was closed automatically and points to Duplicate order.
        $html = $this->call(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('Closed automatically on ' . \App\Helpers\Dates::short(date('Y-m-d')), $html);
        self::assertStringContainsString('use Duplicate order for a fresh quotation', $html);

        // Deactivated Clients: the badge, why, and "Reactivate (fresh 60 days)".
        $inactive = $this->call(fn () => (new ClientController())->inactiveIndex([]));
        self::assertStringContainsString('Expire Prospect Ltd <span class="tag tag-gold" data-testid="prospect-badge">Prospect</span>', $inactive);
        self::assertStringContainsString('Quote expired ' . \App\Helpers\Dates::short(date('Y-m-d')) . ' — no reply in 60 days', $inactive);
        self::assertStringContainsString('Reactivate (fresh 60 days)', $inactive);
    }

    public function testTheDailyJobNeverClosesAnOrderMarkLostWouldRefuseOrOnePastTheQuotation(): void
    {
        // Paid: Mark Lost refuses, so the client is left as it is.
        $paid = $this->addProspect('Paid Prospect Ltd');
        $paidOrder = $this->createTestOrder($paid);
        Database::connection()->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 1000, '2026-11-01', 84, 84000, 'UTR-D394-1')")->execute(['o' => $paidOrder]);
        // Past the quotation (Stage 1 gate passed).
        $ahead = $this->addProspect('Ahead Prospect Ltd');
        $aheadOrder = $this->createTestOrder($ahead);
        \App\Services\StageGateService::passAndUnlockNext($aheadOrder, 1, $this->userId);
        // Automatic closing off: nothing closes.
        foreach ([$paid, $ahead] as $c) {
            $this->setExpiry($c, date('Y-m-d', strtotime('-1 day')));
        }
        $result = ProspectService::expireDue();
        self::assertSame(0, $result['clients']);
        self::assertCount(2, $result['skipped']);
        self::assertSame('active', OrderRepository::find($paidOrder)['status']);
        self::assertSame('active', OrderRepository::find($aheadOrder)['status']);
        self::assertSame(1, (int) ClientRepository::find($paid)['is_active']);
        self::assertSame(1, (int) ClientRepository::find($ahead)['is_active']);

        $quiet = $this->addProspect('Off Prospect Ltd');
        $quietOrder = $this->createTestOrder($quiet);
        $this->setExpiry($quiet, date('Y-m-d', strtotime('-1 day')));
        Database::connection()->exec("UPDATE company_settings SET setting_value = '0' WHERE setting_key = 'prospect_auto_close'");
        \App\Repositories\CompanySettingsRepository::setOverlay([]);
        self::assertSame(['clients' => 0, 'orders' => 0, 'skipped' => []], ProspectService::expireDue());
        self::assertSame('active', OrderRepository::find($quietOrder)['status']);
    }

    // ---------------------------------------------------------------- 3/9. Keep, reactivate

    public function testKeepForMoreDaysAndReactivateGiveAFreshDate(): void
    {
        $id = $this->addProspect('Keep Prospect Ltd');
        $this->setExpiry($id, date('Y-m-d', strtotime('+3 days')), date('Y-m-d'));
        $this->call(fn () => (new ClientController())->keepProspect(['id' => (string) $id]));
        self::assertStringContainsString('is kept until ' . \App\Helpers\Dates::short(date('Y-m-d', strtotime('+63 days'))), (string) self::flash());
        $c = ClientRepository::find($id);
        self::assertSame(date('Y-m-d', strtotime('+63 days')), $c['prospect_expires_on']);
        self::assertNull($c['prospect_reminded_on'], 'a new reminder for the new date');
        self::assertSame('CLIENT_PROSPECT_EXTENDED', Database::connection()->query("SELECT action_type FROM audit_log WHERE entity_type = 'clients' AND entity_id = {$id} ORDER BY id DESC LIMIT 1")->fetchColumn());

        // Expired and deactivated → Reactivate: a fresh 60 days and the message.
        $this->setExpiry($id, date('Y-m-d'));
        ProspectService::expireDue();
        $this->call(fn () => (new ClientController())->toggleActive(['id' => (string) $id]));
        $exp = date('Y-m-d', strtotime('+60 days'));
        self::assertSame('Keep Prospect Ltd is active again. It stays a prospect until its details are filled in; it now expires on ' . \App\Helpers\Dates::short($exp)
            . ' unless an order goes ahead. To quote again, open the old order (Did not go ahead) and use Duplicate order.', self::flash());
        $c = ClientRepository::find($id);
        self::assertSame([1, 1, $exp, null], [(int) $c['is_active'], (int) $c['is_prospect'], $c['prospect_expires_on'], $c['prospect_expired_reason']]);
    }

    // ---------------------------------------------------------------- 10. Quotation requests

    private function submission(string $email, string $name = 'Returning Buyer GmbH'): int
    {
        return ClientIntakeRepository::create([
            'company_legal_name' => $name, 'billing_address' => '5 Stone Street, Hamburg 20095, Germany', 'billing_address_line1' => '5 Stone Street', 'billing_address_line2' => null,
            'billing_city' => 'Hamburg', 'billing_postcode' => '20095', 'billing_country' => 'Germany', 'vat_eori_tax_no' => 'DE000000000', 'contact_person' => 'Purchasing Manager',
            'email' => $email, 'phone' => '+49 40 000000', 'country_of_destination' => 'Germany',
            'port_of_discharge_text' => null, 'coo_type' => null, 'incoterm_preference' => null, 'container_type_text' => null, 'buyer_own_reference' => null, 'notes' => null,
        ], '127.0.0.1');
    }

    public function testARequestFromTheProspectsEmailUpdatesThatProspect(): void
    {
        $email = self::email();
        $id = $this->addProspect('Quick Name', $email);
        $orderId = $this->createTestOrder($id);
        $this->setExpiry($id, date('Y-m-d'));
        ProspectService::expireDue(); // deactivated, order lost
        $ref = ClientRepository::find($id)['client_unique_number'];
        $sid = $this->submission(strtoupper($email));

        $list = $this->call(fn () => (new ClientIntakeReviewController())->index(['view' => 'list']));
        self::assertStringContainsString('Matches prospect', $list);
        self::assertStringContainsString("/client-intake/{$sid}/update-prospect", $list);
        self::assertStringContainsString('Accept as a new client', $list);

        $preview = $this->call(fn () => (new ClientIntakeReviewController())->updateProspectForm(['id' => (string) $sid]));
        self::assertMatchesRegularExpression('/Company Legal Name<\/td>\s*<td class="muted">Quick Name<\/td>\s*<td>&rarr;<\/td>\s*<td><strong>Returning Buyer GmbH<\/strong>/', $preview);
        self::assertStringContainsString('Billing Address Line 1', $preview);

        // Accept as a new client keeps today's refusal (one e-mail per client, D-129).
        $this->call(fn () => (new ClientIntakeReviewController())->accept(['id' => (string) $sid]));
        self::assertStringContainsString('Another client already uses the email', (string) self::flash('error'));

        $_POST = ['client_id' => (string) $id];
        $this->call(fn () => (new ClientIntakeReviewController())->updateProspect(['id' => (string) $sid]));
        self::assertStringContainsString("the same Buyer Inquiry Ref {$ref}", (string) self::flash());
        $c = ClientRepository::find($id);
        self::assertSame(['Returning Buyer GmbH', '5 Stone Street', 'Hamburg', 'DE000000000', $ref, 0, 1, null],
            [$c['company_legal_name'], $c['billing_address_line1'], $c['billing_city'], $c['vat_eori_tax_no'], $c['client_unique_number'], (int) $c['is_prospect'], (int) $c['is_active'], $c['prospect_expires_on']]);
        self::assertSame($id, (int) OrderRepository::find($orderId)['client_id'], 'its orders stay with it');
        $s = ClientIntakeRepository::find($sid);
        self::assertSame(['converted', $id], [$s['status'], (int) $s['converted_client_id']]);
        self::assertSame(1, (int) Database::connection()->query('SELECT COUNT(*) FROM clients WHERE LOWER(email) = ' . Database::connection()->quote(strtolower($email)))->fetchColumn(), 'no second client');
    }

    // ---------------------------------------------------------------- 11. Settings

    public function testTheSettingsAreValidatedAndLabelled(): void
    {
        self::assertNull(SettingValueValidator::check('number', '60', 'prospect_expiry_days'));
        self::assertNotNull(SettingValueValidator::check('number', '6', 'prospect_expiry_days'));
        self::assertNotNull(SettingValueValidator::check('number', '366', 'prospect_expiry_days'));
        self::assertNull(SettingValueValidator::check('number', '30', 'prospect_reminder_days'));
        self::assertNotNull(SettingValueValidator::check('number', '0', 'prospect_reminder_days'));
        self::assertNotNull(SettingValueValidator::check('number', '31', 'prospect_reminder_days'));
        self::assertSame('Quick-quote prospects', \App\Helpers\SettingLabels::sectionTitle('prospects'));
        self::assertSame('Close a prospect\'s quotation after (days)', \App\Helpers\SettingLabels::label('prospect_expiry_days'));
        self::assertSame('Remind the order\'s owner before (days)', \App\Helpers\SettingLabels::label('prospect_reminder_days'));
        self::assertSame('Automatic closing', \App\Helpers\SettingLabels::label('prospect_auto_close'));
        self::assertSame('30', \App\Repositories\CompanySettingsRepository::get('quotation_validity_days'), 'the Quotation\'s printed validity is untouched');

        $html = $this->call(fn () => (new \App\Controllers\SettingsController())->index([]));
        self::assertMatchesRegularExpression('/min="7" max="365" step="1" required\s+name="settings\[prospect_expiry_days\]"[^>]*value="60"[^>]*placeholder="e.g. 60"/', $html);
        self::assertMatchesRegularExpression('/min="1" max="30" step="1" required\s+name="settings\[prospect_reminder_days\]"[^>]*value="7"/', $html);
        self::assertStringContainsString('data-key="prospect_auto_close"', $html);
    }
}
