<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderShippingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #82: the order page's Payment Status section already
 * labels its DISPLAY-only amounts with the order's currency code (e.g.
 * "5,000.00 USD"), but the actual "Amount Received *" INPUT fields on the
 * advance/freight/balance remittance-recording forms gave no indication of
 * which currency to type the figure in — a real risk on an order whose
 * currency isn't USD, since nothing on the form itself said otherwise.
 */
final class RemittanceInputCurrencyLabelTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testAdvanceRemittanceFormLabelsTheInputWithTheOrdersCurrency(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'GBP');
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="advance_amount"', $output);
        self::assertMatchesRegularExpression(
            '/Advance Amount Received \*\s*<small class="muted">\(in GBP\)<\/small>\s*<input type="text" name="advance_amount"/',
            $output,
            'the advance remittance input must be labelled with the order\'s own currency (GBP), not left ambiguous'
        );
    }

    public function testFreightRemittanceFormLabelsTheInputWithTheOrdersCurrency(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
        for ($stage = 1; $stage <= 5; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        OrderFreightRepository::upsert($orderId, [
            'confirmed_freight_rate'     => '500',
            'insurance_amount'           => '50',
            'freight_forwarder_name'     => 'Test Forwarder',
            'freight_forwarder_contact'  => 'test@forwarder.test',
        ]);
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="freight_amount"', $output);
        self::assertMatchesRegularExpression(
            '/Freight Amount Received \*\s*<small class="muted">\(in EUR\)<\/small>\s*<input type="text" name="freight_amount"/',
            $output,
            'the freight remittance input must be labelled with the order\'s own currency, not left ambiguous'
        );
    }

    public function testBalanceRemittanceFormLabelsTheInputWithTheOrdersCurrency(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'EUR');
        $userId = $this->createTestUser('Admin');
        for ($stage = 1; $stage <= 5; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        StageGateService::maybeAutoSkipFreightStage($orderId, $userId);
        OrderShippingRepository::upsert($orderId, ['etd' => null, 'eta' => null]);
        OrderShippingRepository::recordBl($orderId, 'BL-CURRLABEL-TEST', '2026-01-01');
        StageGateService::passAndUnlockNext($orderId, 7, null);
        $this->createTestDocument($orderId, 'CI', 'approved');
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="balance_amount"', $output);
        self::assertMatchesRegularExpression(
            '/Balance Amount Received \*\s*<small class="muted">\(in EUR\)<\/small>\s*<input type="text" name="balance_amount"/',
            $output,
            'the balance remittance input must be labelled with the order\'s own currency, not left ambiguous'
        );
    }
}
