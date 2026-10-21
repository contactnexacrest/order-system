<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderRepository;
use App\Services\OrderDuplicationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "order-ID prefix change to SC/ORD/{YYYY}/{NNN}": the
 * seeded order_ref_format default (and its hardcoded code-level fallback
 * in OrderController/OrderDuplicationService/SampleDataService) used to
 * read 'SC/OC/{YYYY}/{NNN}' — changed to 'SC/ORD/{YYYY}/{NNN}' so a new
 * order's reference reads e.g. "SC/ORD/2026/004-7" instead of
 * "SC/OC/2026/004-7". Existing orders' own order_reference values are
 * never touched — this only governs orders created from here on.
 */
final class OrderReferenceFormatTest extends DbTestCase
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

    public function testNewOrderReferenceUsesTheOrdPrefix(): void
    {
        $clientId = $this->createTestClient();

        $_POST = [
            'client_id'           => (string) $clientId,
            'incoterm_id'         => (string) $this->incotermId,
            'currency_id'         => (string) $this->currencyId,
            'payment_preset_id'   => (string) $this->paymentPresetId,
            'product_description' => ['Order ref format test granite slab'],
            'product_hs_code'     => ['680293'],
            'product_quantity'    => ['10'],
            'product_unit_price'  => ['5.00'],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();

        $orderId = (int) Database::connection()->query('SELECT MAX(id) FROM orders')->fetchColumn();
        $order = OrderRepository::find($orderId);

        self::assertMatchesRegularExpression('#^SC/ORD/\d{4}/\d{3}-\d+$#', $order['order_reference']);
    }

    public function testDuplicatedOrderReferenceAlsoUsesTheOrdPrefix(): void
    {
        $clientId = $this->createTestClient();
        $sourceOrderId = $this->createTestOrder($clientId);

        $newOrderId = OrderDuplicationService::duplicate($sourceOrderId, $this->userId);
        $newOrder = OrderRepository::find($newOrderId);

        self::assertMatchesRegularExpression('#^SC/ORD/\d{4}/\d{3}-\d+$#', $newOrder['order_reference']);
    }
}
