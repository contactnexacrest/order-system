<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderShippingRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2026-10-29 (CA advice): the shipping bill's number, date
 * and customs exchange rate are recorded on the order — the CI and Debit
 * Note go to Zoho Books at that rate.
 */
final class ShippingBillTest extends DbTestCase
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

    private function post(int $orderId, array $post): array
    {
        $_POST = $post;
        ob_start();
        (new OrderController())->recordShippingBill(['id' => (string) $orderId]);
        ob_end_clean();
        $messages = Flash::pull();
        return end($messages) ?: [];
    }

    public function testTheShippingBillIsRecordedAndCanBeCorrected(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $flash = $this->post($orderId, ['shipping_bill_no' => '1234567', 'shipping_bill_date' => '2026-10-27', 'customs_exchange_rate' => '84.35']);
        self::assertSame(['type' => 'success', 'message' => 'Shipping bill 1234567 recorded (customs rate 84.35).'], $flash);
        $row = OrderShippingRepository::find($orderId);
        self::assertSame(['1234567', '2026-10-27', '84.3500'], [$row['shipping_bill_no'], $row['shipping_bill_date'], (string) $row['customs_exchange_rate']]);

        $this->post($orderId, ['shipping_bill_no' => '1234567', 'shipping_bill_date' => '2026-10-27', 'customs_exchange_rate' => '84.4']);
        self::assertSame('84.4000', (string) OrderShippingRepository::find($orderId)['customs_exchange_rate']);
        $audit = Database::connection()->query("SELECT old_value, new_value FROM audit_log WHERE action_type = 'ORDER_SHIPPING_BILL_RECORDED' AND entity_id = {$orderId} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame(['84.3500', '84.4'], [$audit['old_value'], $audit['new_value']]);
    }

    public function testBadInputIsRefusedWithAMessage(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $good = ['shipping_bill_no' => '1234567', 'shipping_bill_date' => '2026-10-27', 'customs_exchange_rate' => '84.35'];

        self::assertSame('Enter the shipping bill number (up to 30 characters).', $this->post($orderId, ['shipping_bill_no' => ''] + $good)['message']);
        self::assertSame('Enter the shipping bill number (up to 30 characters).', $this->post($orderId, ['shipping_bill_no' => str_repeat('9', 31)] + $good)['message']);
        self::assertSame('Enter the shipping bill date (not in the future).', $this->post($orderId, ['shipping_bill_date' => date('Y-m-d', strtotime('+1 day'))] + $good)['message']);
        self::assertSame('Enter the shipping bill date (not in the future).', $this->post($orderId, ['shipping_bill_date' => '2026-02-30'] + $good)['message']);
        self::assertStringStartsWith('Enter the customs exchange rate printed on the shipping bill', $this->post($orderId, ['customs_exchange_rate' => '0'] + $good)['message']);
        self::assertStringStartsWith('Enter the customs exchange rate printed on the shipping bill', $this->post($orderId, ['customs_exchange_rate' => 'abc'] + $good)['message']);
        self::assertNull(OrderShippingRepository::find($orderId)['shipping_bill_no'] ?? null);
    }
}
