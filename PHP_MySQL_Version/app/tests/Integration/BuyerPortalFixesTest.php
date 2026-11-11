<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderRepository;
use App\Services\BuyerActionService;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Three buyer-portal issues found while recording the buyer tutorials (7 Nov 2026):
 *  1. the Order Confirmation acknowledgement table showed "—" for Port of Discharge
 *     when the order only had a typed (free-text) port;
 *  2. "Reorder This" pre-selected PCS when an order line's unit differed only in
 *     case or spacing from the unit list ("sqm" vs "SQM");
 *  3. the Buyer PO upload form was still offered on a cancelled or lost order.
 * The Node suite has the same test (buyerPortalFixes.test.js).
 */
final class BuyerPortalFixesTest extends DbTestCase
{
    private ClientPortalController $controller;
    private int $client;
    private int $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ClientPortalController();
        $this->client = $this->createTestClient();
        $this->order = $this->createTestOrder($this->client);
        $_SESSION['_client_portal_client_id'] = $this->client;
        $_POST = [];
        $_FILES = [];
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_client_portal_client_id']);
        parent::tearDown();
    }

    private function call(string $method, array $params): string
    {
        ob_start();
        $this->controller->{$method}($params);
        return (string) ob_get_clean();
    }

    private function line(string $unit): void
    {
        Database::connection()->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type)
            VALUES (:o, 1, 'Granite slab', 100, :u, 10, 1000, 'goods')")->execute(['o' => $this->order, 'u' => $unit]);
    }

    public function testOcAcknowledgementShowsTheTypedPortOfDischarge(): void
    {
        Database::connection()->prepare("UPDATE orders SET port_of_discharge_id = NULL, port_of_discharge_name = NULL, port_of_discharge_city = NULL, port_of_discharge_text = 'Port of Antwerp' WHERE id = :id")
            ->execute(['id' => $this->order]);
        $doc = $this->createTestDocument($this->order, 'OC', 'sent');
        OrderOcAcknowledgmentRepository::recordSent($this->order, $doc, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+2 days')));

        $html = $this->call('showOrder', ['id' => $this->order]);

        self::assertStringContainsString('Yes, this is correct — please go ahead', $html);
        self::assertMatchesRegularExpression('#<th>Port where the goods arrive</th><td>Port of Antwerp</td>#', $html);
    }

    public function testReorderMatchesTheUnitWhateverItsCaseOrSpacing(): void
    {
        $this->line(' sqm ');

        $html = $this->call('showReorderForm', ['id' => $this->order]);

        self::assertMatchesRegularExpression('#<option value="SQM"\s+selected>#', $html);
        self::assertDoesNotMatchRegularExpression('#<option value="PCS"\s+selected>#', $html);
    }

    public function testReorderNeverSwitchesAnUnknownUnitToPcsButAsksTheBuyerToPick(): void
    {
        $this->line('Crates');

        $html = $this->call('showReorderForm', ['id' => $this->order]);

        self::assertDoesNotMatchRegularExpression('#<option value="PCS"\s+selected>#', $html);
        self::assertStringContainsString('<option value="" selected>Choose… (your earlier unit: Crates)</option>', $html);
        self::assertMatchesRegularExpression('#<select name="product_unit\[0\]" required>#', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function closedStatuses(): array
    {
        return ['cancelled' => ['cancelled'], 'lost' => ['lost']];
    }

    #[DataProvider('closedStatuses')]
    public function testBuyerPoUploadIsClosedOnACancelledOrLostOrder(string $status): void
    {
        Database::connection()->prepare('UPDATE orders SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $this->order]);

        $html = $this->call('showOrder', ['id' => $this->order]);
        self::assertStringNotContainsString('/buyer-po" enctype="multipart/form-data"', $html);
        $word = $status === 'lost' ? 'This order did not go ahead' : 'This order was cancelled'; // D-330
        self::assertStringContainsString($word . ', so you can no longer upload a purchase order.', $html);

        $result = BuyerActionService::uploadBuyerPo(OrderRepository::find($this->order));
        self::assertFalse($result['ok']);
        self::assertSame($word . ', so you can no longer upload a purchase order. If you have a question, please contact your NexaCrest contact person.', $result['message']);
        $count = (int) Database::connection()->query('SELECT COUNT(*) FROM order_buyer_po_documents WHERE order_id = ' . $this->order)->fetchColumn();
        self::assertSame(0, $count);
    }

    public function testBuyerPoUploadFormStillShowsOnAnOpenOrder(): void
    {
        $html = $this->call('showOrder', ['id' => $this->order]);
        self::assertStringContainsString('/buyer-po" enctype="multipart/form-data"', $html);
    }
}
