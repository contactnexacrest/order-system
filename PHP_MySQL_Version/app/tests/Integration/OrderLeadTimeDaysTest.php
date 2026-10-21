<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "dual lead-time fields in days": alongside the existing
 * free-text est_lead_time_text (unchanged, still the only thing printed on
 * the Quotation), orders/create and orders/edit now also capture
 * lead_time_min_days/lead_time_max_days as plain numbers — internal
 * reporting/sorting only, never shown on any customer-facing document.
 */
final class OrderLeadTimeDaysTest extends DbTestCase
{
    private int $userId;
    private int $incotermId;
    private int $currencyId;
    private int $paymentPresetId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $this->userId;

        $pdo = Database::connection();
        $this->incotermId = (int) $pdo->query("SELECT id FROM incoterms WHERE code = 'FOB'")->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
        $this->paymentPresetId = (int) $pdo->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")->fetchColumn();
    }

    public function testCreateFormPersistsLeadTimeMinAndMaxDays(): void
    {
        $clientId = $this->createTestClient();

        $_POST = [
            'client_id'           => (string) $clientId,
            'incoterm_id'         => (string) $this->incotermId,
            'currency_id'         => (string) $this->currencyId,
            'payment_preset_id'   => (string) $this->paymentPresetId,
            'est_lead_time_text'  => '45-60 days from advance receipt',
            'lead_time_min_days'  => '45',
            'lead_time_max_days'  => '60',
            'product_description' => ['Lead-time test granite slab'],
            'product_hs_code'     => ['680293'],
            'product_quantity'    => ['10'],
            'product_unit_price'  => ['5.00'],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();

        $orderId = (int) Database::connection()->query('SELECT MAX(id) FROM orders')->fetchColumn();
        $order = OrderRepository::find($orderId);

        self::assertSame(45, (int) $order['lead_time_min_days']);
        self::assertSame(60, (int) $order['lead_time_max_days']);
        self::assertSame('45-60 days from advance receipt', $order['est_lead_time_text'], 'the free-text field must still carry its own independent value, unaffected by the new numeric fields');
    }

    public function testLeadTimeDaysAreOptionalOnCreate(): void
    {
        $clientId = $this->createTestClient();

        $_POST = [
            'client_id'           => (string) $clientId,
            'incoterm_id'         => (string) $this->incotermId,
            'currency_id'         => (string) $this->currencyId,
            'payment_preset_id'   => (string) $this->paymentPresetId,
            'product_description' => ['No lead-time granite slab'],
            'product_hs_code'     => ['680293'],
            'product_quantity'    => ['10'],
            'product_unit_price'  => ['5.00'],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();

        $orderId = (int) Database::connection()->query('SELECT MAX(id) FROM orders')->fetchColumn();
        $order = OrderRepository::find($orderId);

        self::assertNull($order['lead_time_min_days']);
        self::assertNull($order['lead_time_max_days']);
    }

    public function testUpdateDetailsOverwritesLeadTimeDays(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $_POST = [
            'incoterm_id'          => (string) $this->incotermId,
            'currency_id'          => (string) $this->currencyId,
            'lead_time_min_days'   => '20',
            'lead_time_max_days'   => '30',
        ];

        (new OrderController())->updateDetails(['id' => (string) $orderId]);

        $order = OrderRepository::find($orderId);
        self::assertSame(20, (int) $order['lead_time_min_days']);
        self::assertSame(30, (int) $order['lead_time_max_days']);
    }
}
