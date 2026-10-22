<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Controllers\ClientPortalController;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog Phase 6 Item B — a two-level visibility config for
 * whether a client sees their own full order history (including
 * completed/lost orders) on the "My Orders" portal dashboard, vs.
 * active/disputed orders only: a global default
 * (company_settings.client_portal_show_order_history) with a per-client
 * override (clients.order_history_visibility_override, NULL = inherit).
 */
final class ClientOrderHistoryVisibilityTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    /** createTestOrder() hardcodes sequence_no=1, so a second order for the same client needs its own sequence number. */
    private function createTestOrderWithSequence(int $clientId, int $sequenceNo): int
    {
        $pdo = Database::connection();
        $incoterm = $pdo->query("SELECT id, code FROM incoterms WHERE code = 'FOB'")->fetch();
        $currency = $pdo->query("SELECT id, code FROM currencies WHERE code = 'USD'")->fetch();
        $preset = $pdo->query(
            "SELECT id, preset_name, advance_pct, advance_trigger_text, balance_pct, balance_trigger_option,
                    balance_days, balance_trigger_wording, show_bl_instructions
             FROM payment_presets WHERE preset_name = 'Standard — New Buyer'"
        )->fetch();

        // 90-item backlog "standalone order" follow-on: mirrors
        // OrderRepository::insertRow()'s own resolveClientSnapshot(), so
        // this order doesn't leak a NULL company_legal_name into
        // global queries like OrderRepository::all() that other tests
        // in the same process also render.
        $clientStmt = $pdo->prepare('SELECT company_legal_name FROM clients WHERE id = :id');
        $clientStmt->execute(['id' => $clientId]);
        $client = $clientStmt->fetch() ?: [];

        $stmt = $pdo->prepare(
            'INSERT INTO orders
                (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
                 currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status,
                 incoterm_code, currency_code, preset_name, advance_pct, advance_trigger_text,
                 balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, show_bl_instructions,
                 company_legal_name)
             VALUES
                (:ref, :client_id, :seq, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, \'TBC\', \'NIL\',
                 CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), \'active\',
                 :incoterm_code, :currency_code, :preset_name, :advance_pct, :advance_trigger_text,
                 :balance_pct, :balance_trigger_option, :balance_days, :balance_trigger_wording, :show_bl_instructions,
                 :company_legal_name)'
        );
        $stmt->execute([
            'ref'         => 'PHPUNIT-TEST-' . bin2hex(random_bytes(4)),
            'client_id'   => $clientId,
            'seq'         => $sequenceNo,
            'inquiry_ref' => 'PHPUNIT-' . bin2hex(random_bytes(3)),
            'preset_id'   => $preset['id'],
            'incoterm_id' => $incoterm['id'],
            'currency_id' => $currency['id'],
            'incoterm_code'           => $incoterm['code'],
            'currency_code'           => $currency['code'],
            'preset_name'             => $preset['preset_name'],
            'advance_pct'             => $preset['advance_pct'],
            'advance_trigger_text'    => $preset['advance_trigger_text'],
            'balance_pct'             => $preset['balance_pct'],
            'balance_trigger_option'  => $preset['balance_trigger_option'],
            'balance_days'            => $preset['balance_days'],
            'balance_trigger_wording' => $preset['balance_trigger_wording'],
            'show_bl_instructions'    => $preset['show_bl_instructions'],
            'company_legal_name'      => $client['company_legal_name'] ?? null,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        \App\Repositories\OrderStageRepository::initializeForOrder($orderId);
        return $orderId;
    }

    public function testOverrideNullFollowsGlobalDefault(): void
    {
        $clientId = $this->createTestClient();
        $client = ClientRepository::find($clientId);
        self::assertNull($client['order_history_visibility_override']);

        CompanySettingsRepository::set('client_portal_show_order_history', '1');
        self::assertTrue(ClientRepository::orderHistoryVisible(ClientRepository::find($clientId)));

        CompanySettingsRepository::set('client_portal_show_order_history', '0');
        self::assertFalse(ClientRepository::orderHistoryVisible(ClientRepository::find($clientId)));
    }

    public function testPerClientOverrideWinsOverTheGlobalDefault(): void
    {
        $clientId = $this->createTestClient();

        CompanySettingsRepository::set('client_portal_show_order_history', '0');
        ClientRepository::setOrderHistoryVisibilityOverride($clientId, true);
        self::assertTrue(ClientRepository::orderHistoryVisible(ClientRepository::find($clientId)), 'client override forces full history even though the global default is off');

        CompanySettingsRepository::set('client_portal_show_order_history', '1');
        ClientRepository::setOrderHistoryVisibilityOverride($clientId, false);
        self::assertFalse(ClientRepository::orderHistoryVisible(ClientRepository::find($clientId)), 'client override forces active-only even though the global default is on');
    }

    public function testForClientActiveOnlyHidesCompletedAndLostOrders(): void
    {
        $clientId = $this->createTestClient();
        $activeId = $this->createTestOrder($clientId);
        $disputedId = $this->createTestOrderWithSequence($clientId, 2);
        $completeId = $this->createTestOrderWithSequence($clientId, 3);
        $lostId = $this->createTestOrderWithSequence($clientId, 4);

        $pdo = Database::connection();
        $pdo->prepare("UPDATE orders SET status = 'disputed' WHERE id = :id")->execute(['id' => $disputedId]);
        $pdo->prepare("UPDATE orders SET status = 'complete' WHERE id = :id")->execute(['id' => $completeId]);
        $pdo->prepare("UPDATE orders SET status = 'lost' WHERE id = :id")->execute(['id' => $lostId]);

        $full = OrderRepository::forClient($clientId);
        self::assertCount(4, $full);

        $activeOnly = OrderRepository::forClient($clientId, true);
        $ids = array_column($activeOnly, 'id');
        self::assertCount(2, $activeOnly);
        self::assertContains($activeId, $ids);
        self::assertContains($disputedId, $ids);
        self::assertNotContains($completeId, $ids);
        self::assertNotContains($lostId, $ids);
    }

    public function testPortalDashboardHidesHistoryWhenNotVisible(): void
    {
        $clientId = $this->createTestClient();
        $activeId = $this->createTestOrder($clientId);
        $completeId = $this->createTestOrderWithSequence($clientId, 2);
        Database::connection()->prepare("UPDATE orders SET status = 'complete' WHERE id = :id")->execute(['id' => $completeId]);

        ClientRepository::setOrderHistoryVisibilityOverride($clientId, false);
        $_SESSION = [];
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->dashboard([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('current orders', $html);
        self::assertStringNotContainsString('all your orders', $html);
    }

    public function testPortalDashboardShowsFullHistoryWhenVisible(): void
    {
        $clientId = $this->createTestClient();
        $activeId = $this->createTestOrder($clientId);
        $completeId = $this->createTestOrderWithSequence($clientId, 2);
        Database::connection()->prepare("UPDATE orders SET status = 'complete' WHERE id = :id")->execute(['id' => $completeId]);

        ClientRepository::setOrderHistoryVisibilityOverride($clientId, true);
        $_SESSION = [];
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->dashboard([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('all your orders', $html);
    }

    public function testSetOverrideControllerAction404sForAMissingClient(): void
    {
        $_POST = ['order_history_visibility_override' => '1'];
        ob_start();
        (new ClientController())->setOrderHistoryVisibilityOverride(['id' => '999999999']);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    public function testSetOverrideControllerActionWritesTheValueAndAuditLog(): void
    {
        $clientId = $this->createTestClient();
        $_POST = ['order_history_visibility_override' => '0'];

        ob_start();
        (new ClientController())->setOrderHistoryVisibilityOverride(['id' => (string) $clientId]);
        ob_end_clean();

        $client = ClientRepository::find($clientId);
        self::assertSame(0, (int) $client['order_history_visibility_override']);

        $stmt = Database::connection()->prepare(
            "SELECT * FROM audit_log WHERE action_type = 'CLIENT_ORDER_HISTORY_VISIBILITY_CHANGED' AND entity_type = 'clients' AND entity_id = :id"
        );
        $stmt->execute(['id' => $clientId]);
        self::assertNotFalse($stmt->fetch());
    }

    public function testSetOverrideControllerActionClearsBackToInheritOnEmptyPost(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::setOrderHistoryVisibilityOverride($clientId, true);

        $_POST = ['order_history_visibility_override' => ''];
        ob_start();
        (new ClientController())->setOrderHistoryVisibilityOverride(['id' => (string) $clientId]);
        ob_end_clean();

        $client = ClientRepository::find($clientId);
        self::assertNull($client['order_history_visibility_override']);
    }
}
