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
}
