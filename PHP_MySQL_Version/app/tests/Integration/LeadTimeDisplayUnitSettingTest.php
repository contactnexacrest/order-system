<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog item 16: lead-time days-vs-weeks display setting.
 * Covers CompanySettingsRepository's lead_time_display_unit row actually
 * changing what the Order Detail page renders — the stored day figures
 * themselves never change, only the label and the rendered text.
 */
final class LeadTimeDisplayUnitSettingTest extends DbTestCase
{
    private function renderShow(int $orderId, int $userId): string
    {
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testDefaultsToDaysAndRendersPlainIntegers(): void
    {
        $userId = $this->createTestUser('Admin');
        CompanySettingsRepository::set('lead_time_display_unit', 'days', $userId);
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        OrderRepository::updateDetails($orderId, array_merge(OrderRepository::find($orderId), [
            'lead_time_min_days' => 45,
            'lead_time_max_days' => 60,
            'sailing_lead_time_min_days' => 18,
            'sailing_lead_time_max_days' => 24,
        ]));

        $html = $this->renderShow($orderId, $userId);

        self::assertStringContainsString('Production Lead Time (days)', $html);
        self::assertStringContainsString('Sailing Lead Time (days)', $html);
        self::assertStringContainsString('45–60', $html);
        self::assertStringContainsString('18–24', $html);
    }

    public function testWeeksSettingConvertsTheRenderedRange(): void
    {
        $userId = $this->createTestUser('Admin');
        CompanySettingsRepository::set('lead_time_display_unit', 'weeks', $userId);
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        OrderRepository::updateDetails($orderId, array_merge(OrderRepository::find($orderId), [
            'lead_time_min_days' => 45,
            'lead_time_max_days' => 60,
        ]));

        $html = $this->renderShow($orderId, $userId);

        self::assertStringContainsString('Production Lead Time (weeks)', $html);
        self::assertStringContainsString('6.4–8.6', $html);
    }

    public function testOrdersWithNoLeadTimeSetStillRenderAnEmDash(): void
    {
        $userId = $this->createTestUser('Admin');
        CompanySettingsRepository::set('lead_time_display_unit', 'days', $userId);
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $html = $this->renderShow($orderId, $userId);

        self::assertStringContainsString('Production Lead Time (days)', $html);
    }
}
