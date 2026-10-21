<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\PortRouteController;
use App\Repositories\OrderRepository;
use App\Repositories\PortRepository;
use App\Repositories\PortRouteRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog item 15: a port-to-port sailing-days reference document
 * (`port_routes`), plus a new sailing_lead_time_min_days/max_days pair on
 * orders — split out from the existing lead_time_min_days/max_days, which
 * stays as "Production Lead Time". The order form's auto-suggest is pure
 * JS (see orders/create.njk's lookupSailingLeadTime()), so this covers
 * the data layer it calls into: PortRouteRepository's CRUD/lookup, the
 * JSON endpoint, and that orders/create and orders/edit actually persist
 * the new fields as plain, freely-editable numbers.
 */
final class PortRouteAndSailingLeadTimeTest extends DbTestCase
{
    private int $userId;
    private int $incotermId;
    private int $currencyId;
    private int $paymentPresetId;
    private int $loadingPortId;
    private int $dischargePortId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $this->userId;

        $pdo = Database::connection();
        $this->incotermId = (int) $pdo->query("SELECT id FROM incoterms WHERE code = 'FOB'")->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
        $this->paymentPresetId = (int) $pdo->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")->fetchColumn();

        $this->loadingPortId = PortRepository::create('PHPUnit Loading Port', null, null, 'loading', 1);
        $this->dischargePortId = PortRepository::create('PHPUnit Discharge Port', null, null, 'discharge', 1);
    }

    public function testCreateThenFindByPortsReturnsTheRoute(): void
    {
        $id = PortRouteRepository::create($this->loadingPortId, $this->dischargePortId, 18, 24, 'Direct service');

        self::assertNotNull($id);
        $route = PortRouteRepository::findByPorts($this->loadingPortId, $this->dischargePortId);
        self::assertNotNull($route);
        self::assertSame(18, (int) $route['sailing_days_min']);
        self::assertSame(24, (int) $route['sailing_days_max']);
        self::assertSame('Direct service', $route['notes']);
    }

    public function testFindByPortsReturnsNullForAnUnrecordedPair(): void
    {
        self::assertNull(PortRouteRepository::findByPorts($this->loadingPortId, $this->dischargePortId));
    }

    public function testFindByPortsIgnoresADeactivatedRoute(): void
    {
        $id = PortRouteRepository::create($this->loadingPortId, $this->dischargePortId, 18, 24, null);
        PortRouteRepository::toggleActive($id);

        self::assertNull(PortRouteRepository::findByPorts($this->loadingPortId, $this->dischargePortId), 'a deactivated route is no longer a reliable sailing-time estimate to offer');
    }

    public function testCreateRejectsADuplicatePortPair(): void
    {
        PortRouteRepository::create($this->loadingPortId, $this->dischargePortId, 18, 24, null);

        $secondId = PortRouteRepository::create($this->loadingPortId, $this->dischargePortId, 20, 26, null);

        self::assertNull($secondId, 'the same port pair must only ever be recorded once — edit the existing route instead');
    }

    public function testUpdateRejectsCollidingWithAnotherRoutesPortPair(): void
    {
        $otherDischarge = PortRepository::create('PHPUnit Other Discharge Port', null, null, 'discharge', 2);
        $routeA = PortRouteRepository::create($this->loadingPortId, $this->dischargePortId, 18, 24, null);
        PortRouteRepository::create($this->loadingPortId, $otherDischarge, 10, 15, null);

        $ok = PortRouteRepository::update($routeA, $this->loadingPortId, $otherDischarge, 18, 24, null);

        self::assertFalse($ok);
    }

    public function testLookupEndpointReturnsFoundTrueWithRange(): void
    {
        PortRouteRepository::create($this->loadingPortId, $this->dischargePortId, 18, 24, null);
        $_GET = ['from' => (string) $this->loadingPortId, 'to' => (string) $this->dischargePortId];

        ob_start();
        (new PortRouteController())->lookup([]);
        $body = ob_get_clean();

        $data = json_decode($body, true);
        self::assertTrue($data['found']);
        self::assertSame(18, $data['min']);
        self::assertSame(24, $data['max']);
    }

    public function testLookupEndpointReturnsFoundFalseForAnUnknownPair(): void
    {
        $_GET = ['from' => (string) $this->loadingPortId, 'to' => (string) $this->dischargePortId];

        ob_start();
        (new PortRouteController())->lookup([]);
        $body = ob_get_clean();

        self::assertSame(['found' => false], json_decode($body, true));
    }

    public function testCreateFormPersistsSailingLeadTimeDaysAlongsideProductionLeadTime(): void
    {
        $clientId = $this->createTestClient();

        $_POST = [
            'client_id'                   => (string) $clientId,
            'incoterm_id'                 => (string) $this->incotermId,
            'currency_id'                 => (string) $this->currencyId,
            'payment_preset_id'           => (string) $this->paymentPresetId,
            'port_of_loading_id'          => (string) $this->loadingPortId,
            'port_of_discharge_id'        => (string) $this->dischargePortId,
            'lead_time_min_days'          => '45',
            'lead_time_max_days'          => '60',
            'sailing_lead_time_min_days'  => '18',
            'sailing_lead_time_max_days'  => '24',
            'product_description'        => ['Sailing-lead-time test granite slab'],
            'product_hs_code'             => ['680293'],
            'product_quantity'            => ['10'],
            'product_unit_price'          => ['5.00'],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();

        $orderId = (int) Database::connection()->query('SELECT MAX(id) FROM orders')->fetchColumn();
        $order = OrderRepository::find($orderId);

        self::assertSame(45, (int) $order['lead_time_min_days'], 'production lead time must stay independent of the new sailing fields');
        self::assertSame(60, (int) $order['lead_time_max_days']);
        self::assertSame(18, (int) $order['sailing_lead_time_min_days']);
        self::assertSame(24, (int) $order['sailing_lead_time_max_days']);
    }

    public function testSailingLeadTimeDaysAreOptionalOnCreate(): void
    {
        $clientId = $this->createTestClient();

        $_POST = [
            'client_id'           => (string) $clientId,
            'incoterm_id'         => (string) $this->incotermId,
            'currency_id'         => (string) $this->currencyId,
            'payment_preset_id'   => (string) $this->paymentPresetId,
            'product_description' => ['No sailing-lead-time granite slab'],
            'product_hs_code'     => ['680293'],
            'product_quantity'    => ['10'],
            'product_unit_price'  => ['5.00'],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();

        $orderId = (int) Database::connection()->query('SELECT MAX(id) FROM orders')->fetchColumn();
        $order = OrderRepository::find($orderId);

        self::assertNull($order['sailing_lead_time_min_days']);
        self::assertNull($order['sailing_lead_time_max_days']);
    }

    public function testUpdateDetailsOverwritesSailingLeadTimeDays(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $_POST = [
            'incoterm_id'                => (string) $this->incotermId,
            'currency_id'                => (string) $this->currencyId,
            'sailing_lead_time_min_days' => '20',
            'sailing_lead_time_max_days' => '30',
        ];

        (new OrderController())->updateDetails(['id' => (string) $orderId]);

        $order = OrderRepository::find($orderId);
        self::assertSame(20, (int) $order['sailing_lead_time_min_days']);
        self::assertSame(30, (int) $order['sailing_lead_time_max_days']);
    }
}
