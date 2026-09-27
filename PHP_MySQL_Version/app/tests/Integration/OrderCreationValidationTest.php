<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\ClientRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 ORD-04/ORD-05/ORD-06: order creation used to (a) accept an inactive
 * client via a direct/forged POST, since store() re-looked the client up
 * with ClientRepository::find() (no is_active filter) rather than trusting
 * the create-form's own active-only dropdown, (b) crash with an uncaught
 * PDOException on a non-numeric unit price — by which point the order row
 * and its stage/payment-status rows were already committed with no
 * transaction wrapping them, leaving a real half-created order behind —
 * and (c) accept a negative quantity outright, silently producing a
 * negative FOB value.
 */
final class OrderCreationValidationTest extends DbTestCase
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

    public function testInactiveClientIsRejected(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::setActive($clientId, false);

        $before = $this->orderCount();
        $this->attemptCreate($clientId, ['quantity' => '10', 'unit_price' => '5.00']);

        self::assertSame($before, $this->orderCount(), 'no order must be created for an inactive client');
    }

    public function testActiveClientIsAccepted(): void
    {
        $clientId = $this->createTestClient();

        $before = $this->orderCount();
        $this->attemptCreate($clientId, ['quantity' => '10', 'unit_price' => '5.00']);

        self::assertSame($before + 1, $this->orderCount(), 'a valid submission for an active client must create an order');
    }

    public function testNonNumericUnitPriceIsRejectedWithoutCrashingOrLeavingAHalfCreatedOrder(): void
    {
        $clientId = $this->createTestClient();

        $before = $this->orderCount();
        $this->attemptCreate($clientId, ['quantity' => '10', 'unit_price' => 'abc']);

        self::assertSame($before, $this->orderCount(), 'a non-numeric price must reject the whole submission, not leave a half-created order');
    }

    public function testNegativeQuantityIsRejected(): void
    {
        $clientId = $this->createTestClient();

        $before = $this->orderCount();
        $this->attemptCreate($clientId, ['quantity' => '-5', 'unit_price' => '5.00']);

        self::assertSame($before, $this->orderCount(), 'a negative quantity must reject the whole submission');
    }

    public function testZeroQuantityIsRejected(): void
    {
        $clientId = $this->createTestClient();

        $before = $this->orderCount();
        $this->attemptCreate($clientId, ['quantity' => '0', 'unit_price' => '5.00']);

        self::assertSame($before, $this->orderCount(), 'a zero quantity must reject the whole submission');
    }

    public function testNegativeUnitPriceIsRejected(): void
    {
        $clientId = $this->createTestClient();

        $before = $this->orderCount();
        $this->attemptCreate($clientId, ['quantity' => '10', 'unit_price' => '-5.00']);

        self::assertSame($before, $this->orderCount(), 'a negative unit price must reject the whole submission');
    }

    /** @param array{quantity:string, unit_price:string} $line */
    private function attemptCreate(int $clientId, array $line): void
    {
        $_POST = [
            'client_id'           => (string) $clientId,
            'incoterm_id'         => (string) $this->incotermId,
            'currency_id'         => (string) $this->currencyId,
            'payment_preset_id'   => (string) $this->paymentPresetId,
            'product_description' => ['ORD-04/05/06 regression test granite slab'],
            'product_hs_code'     => ['680293'],
            'product_quantity'    => [$line['quantity']],
            'product_unit_price'  => [$line['unit_price']],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();
    }

    private function orderCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    }
}
