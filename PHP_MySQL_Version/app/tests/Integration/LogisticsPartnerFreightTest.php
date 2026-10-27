<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderFreightRepository;
use App\Repositories\LogisticsPartnerRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-27): the freight forwarder in Step 9a is picked
 * from the Logistics Partners directory (like the supplier in 7a from the
 * Suppliers directory), not free text; factory addresses are edited only in
 * the Suppliers directory.
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
        $inactive = LogisticsPartnerRepository::create(['partner_name' => 'Old Forwarder', 'service_type' => 'freight_forwarder'], (int) $_SESSION['_auth_user_id']);
        LogisticsPartnerRepository::toggleActive($inactive);
        self::assertSame('error', $this->save(['freight_forwarder_partner_id' => (string) $inactive])['type']);
        self::assertNull(OrderFreightRepository::find($this->orderId));

        $partner = LogisticsPartnerRepository::create(['partner_name' => 'Blue Wave Logistics', 'service_type' => 'freight_forwarder', 'contact_person_name' => 'Ravi', 'phone' => '+91 90000 11111', 'email' => 'ops@bluewave.test'], (int) $_SESSION['_auth_user_id']);
        self::assertSame('success', $this->save(['freight_forwarder_partner_id' => (string) $partner, 'freight_forwarder_name' => 'ignored free text'])['type']);
        $freight = OrderFreightRepository::find($this->orderId);
        self::assertSame('Blue Wave Logistics', $freight['freight_forwarder_name']);
        self::assertSame('Ravi · +91 90000 11111 · ops@bluewave.test', $freight['freight_forwarder_contact']);
        self::assertSame($partner, (int) $freight['freight_forwarder_partner_id']);
    }

    public function testThe9aListComesFromTheLogisticsPartnersDirectory(): void
    {
        $partner = LogisticsPartnerRepository::create(['partner_name' => 'Blue Wave Logistics', 'service_type' => 'freight_forwarder'], (int) $_SESSION['_auth_user_id']);

        ob_start();
        (new OrderController())->show(['id' => (string) $this->orderId]);
        $html = (string) ob_get_clean();

        self::assertMatchesRegularExpression('/name="freight_forwarder_partner_id".*?value="' . $partner . '">Blue Wave Logistics \\(Freight Forwarder\\)/s', $html);
        self::assertStringNotContainsString('Manage factory addresses', $html);
    }
}
