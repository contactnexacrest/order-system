<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\CompanySettingsRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "default weight/volume values": the New Order form's
 * Total CBM/Gross Weight/Net Weight fields used to always start blank.
 * They now pre-fill from configurable company_settings — staff still
 * edit per order as always, and a validation-retry's own typed value (or
 * the #41/#46 intake prefill) always wins over the configured default.
 */
final class OrderDefaultWeightVolumeTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_GET = [];
        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');
    }

    public function testCreateFormPrefillsConfiguredWeightVolumeDefaults(): void
    {
        ob_start();
        (new OrderController())->create([]);
        $html = (string) ob_get_clean();

        $totalCbm = CompanySettingsRepository::get('default_estimated_total_cbm');
        $grossWeight = CompanySettingsRepository::get('default_estimated_gross_weight_kg');
        $netWeight = CompanySettingsRepository::get('default_estimated_net_weight_kg');

        self::assertStringContainsString("name=\"estimated_total_cbm\" value=\"{$totalCbm}\"", $html);
        self::assertStringContainsString("name=\"estimated_gross_weight_kg\" value=\"{$grossWeight}\"", $html);
        self::assertStringContainsString("name=\"estimated_net_weight_kg\" value=\"{$netWeight}\"", $html);
    }

    public function testValidationRetryValueWinsOverTheConfiguredDefault(): void
    {
        \App\Helpers\Flash::setOld(['estimated_total_cbm' => '99.9']);

        ob_start();
        (new OrderController())->create([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="estimated_total_cbm" value="99.9"', $html, 'a value the user already typed (surviving a validation failure) must never be clobbered by the configured default');
    }

    /** Owner request (2026-10-25): a typical full container for this business. */
    public function testSeededDefaultsAreAFullContainer(): void
    {
        self::assertSame('8.2', CompanySettingsRepository::get('default_estimated_total_cbm'));
        self::assertSame('27000', CompanySettingsRepository::get('default_estimated_gross_weight_kg'));
        self::assertSame('26000', CompanySettingsRepository::get('default_estimated_net_weight_kg'));
    }

    /**
     * Owner request (2026-10-25): the configured default instead of an
     * "e.g." placeholder — also on Edit Order, for an order that has no
     * estimate saved; a saved value always wins.
     */
    public function testEditFormShowsTheDefaultsForAnOrderWithNoEstimateSaved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        \App\Config\Database::connection()->exec(
            "UPDATE orders SET estimated_total_cbm = NULL, estimated_gross_weight_kg = NULL, estimated_net_weight_kg = 25500 WHERE id = {$orderId}"
        );

        ob_start();
        (new OrderController())->editDetails(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="estimated_total_cbm" value="8.2"', $html);
        self::assertStringContainsString('name="estimated_gross_weight_kg" value="27000"', $html);
        self::assertMatchesRegularExpression('/name="estimated_net_weight_kg" value="25500(\.0+)?"/', $html, 'a saved value always wins over the default');
        self::assertDoesNotMatchRegularExpression('/name="estimated_(total_cbm|gross_weight_kg|net_weight_kg)" value="[^"]*" placeholder=/', $html);
    }

    public function testCreateFormHasNoExamplePlaceholdersOnTheseFields(): void
    {
        ob_start();
        (new OrderController())->create([]);
        $html = (string) ob_get_clean();

        self::assertDoesNotMatchRegularExpression('/name="estimated_(total_cbm|gross_weight_kg|net_weight_kg)" value="[^"]*" placeholder=/', $html);
    }
}
