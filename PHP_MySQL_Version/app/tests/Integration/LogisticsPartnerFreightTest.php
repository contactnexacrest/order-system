<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderFreightRepository;
use App\Repositories\SupplierRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-27): the freight forwarder in Step 9a is a
 * logistics partner picked from the Suppliers directory (like the supplier
 * in 7a), not free text; Step 7a's supplier list leaves logistics-only
 * partners out; factory addresses are edited only in the directory.
 */
final class LogisticsPartnerFreightTest extends DbTestCase
{
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        for ($stage = 1; $stage <= 5; $stage++) {
            StageGateService::passAndUnlockNext($this->orderId, $stage, null);
        }
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        Flash::pull();
    }

    private function save(array $post): array
    {
        $_POST = $post + ['confirmed_freight_rate' => '950', 'insurance_amount' => '120'];
        ob_start();
        (new OrderController())->saveFreightTerms(['id' => (string) $this->orderId]);
        ob_end_clean();
        $flash = Flash::pull();
        return end($flash) ?: ['type' => '', 'message' => ''];
    }

    public function testTheForwarderMustBeALogisticsPartnerFromTheDirectory(): void
    {
        self::assertSame('error', $this->save([])['type']);
        $quarry = SupplierRepository::create(['supplier_legal_name' => 'Quarry Co']);
        SupplierRepository::setTypes($quarry, ['Quarry Owner']);
        self::assertSame('error', $this->save(['freight_forwarder_supplier_id' => (string) $quarry])['type']);
        self::assertNull(OrderFreightRepository::find($this->orderId));

        $partner = SupplierRepository::create(['supplier_legal_name' => 'Blue Wave Logistics', 'contact_person' => 'Ravi', 'phone' => '+91 90000 11111', 'email' => 'ops@bluewave.test']);
        SupplierRepository::setTypes($partner, [SupplierRepository::LOGISTICS_TYPE]);
        self::assertSame('success', $this->save(['freight_forwarder_supplier_id' => (string) $partner, 'freight_forwarder_name' => 'ignored free text'])['type']);
        $freight = OrderFreightRepository::find($this->orderId);
        self::assertSame('Blue Wave Logistics', $freight['freight_forwarder_name']);
        self::assertSame('Ravi · +91 90000 11111 · ops@bluewave.test', $freight['freight_forwarder_contact']);
        self::assertSame($partner, (int) $freight['freight_forwarder_supplier_id']);
    }

    public function testTheOrderPageListsPartnersIn9aAndSuppliersIn7a(): void
    {
        $partner = SupplierRepository::create(['supplier_legal_name' => 'Blue Wave Logistics']);
        SupplierRepository::setTypes($partner, [SupplierRepository::LOGISTICS_TYPE]);
        $quarry = SupplierRepository::create(['supplier_legal_name' => 'Quarry Co']);
        SupplierRepository::setTypes($quarry, ['Quarry Owner']);

        ob_start();
        (new OrderController())->show(['id' => (string) $this->orderId]);
        $html = (string) ob_get_clean();

        self::assertMatchesRegularExpression('/name="freight_forwarder_supplier_id".*?value="' . $partner . '"/s', $html);
        $spoSelect = substr($html, (int) strpos($html, 'id="spo-supplier-select"'), 3000);
        self::assertStringContainsString('Quarry Co', $spoSelect);
        self::assertStringNotContainsString('Blue Wave Logistics', $spoSelect);
        self::assertStringNotContainsString('Manage factory addresses', $html);
    }
}
