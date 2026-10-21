<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\DashboardController;
use App\Repositories\CompanySettingsRepository;
use App\Tests\Support\DbTestCase;

/**
 * Phase 3 item 11 of the approved remediation plan: "Sidebar alert
 * count badge for settings nearing expiry (e.g. LUT)." The underlying
 * calculation (DashboardRepository::complianceExpiryWarnings()) already
 * existed and already drove a warning banner on the Dashboard page
 * body — but nothing surfaced it from every other page, the way the
 * other standing-request sidebar badges (Email Approvals, Quotation
 * Intake Review, ...) already do.
 */
final class SettingsExpiryWarningSidebarBadgeTest extends DbTestCase
{
    private function render(): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new DashboardController())->index([]);
        return ob_get_clean();
    }

    public function testSidebarShowsNoBadgeWhenNothingIsNearExpiry(): void
    {
        CompanySettingsRepository::set('lut_expiry_date', (new \DateTimeImmutable('+2 years'))->format('Y-m-d'));
        CompanySettingsRepository::set('rcmc_valid_until', (new \DateTimeImmutable('+2 years'))->format('Y-m-d'));

        $output = $this->render();
        $settingsLinkPos = strpos($output, 'Company Settings');
        self::assertNotFalse($settingsLinkPos);
        $nearbyMarkup = substr($output, $settingsLinkPos, 120);

        self::assertStringNotContainsString('nav-badge', $nearbyMarkup);
    }

    public function testSidebarShowsABadgeOfOneWhenOnlyLutIsNearExpiry(): void
    {
        CompanySettingsRepository::set('lut_expiry_date', (new \DateTimeImmutable('+10 days'))->format('Y-m-d'));
        CompanySettingsRepository::set('lut_alert_days_x', '30');
        CompanySettingsRepository::set('rcmc_valid_until', (new \DateTimeImmutable('+2 years'))->format('Y-m-d'));

        $output = $this->render();
        $settingsLinkPos = strpos($output, 'Company Settings');
        self::assertNotFalse($settingsLinkPos);
        $nearbyMarkup = substr($output, $settingsLinkPos, 120);

        self::assertStringContainsString('nav-badge">1<', $nearbyMarkup);
    }

    public function testSidebarShowsABadgeOfTwoWhenBothLutAndRcmcAreNearExpiry(): void
    {
        CompanySettingsRepository::set('lut_expiry_date', (new \DateTimeImmutable('+10 days'))->format('Y-m-d'));
        CompanySettingsRepository::set('lut_alert_days_x', '30');
        CompanySettingsRepository::set('rcmc_valid_until', (new \DateTimeImmutable('+10 days'))->format('Y-m-d'));
        CompanySettingsRepository::set('rcmc_alert_days_a', '60');

        $output = $this->render();
        $settingsLinkPos = strpos($output, 'Company Settings');
        self::assertNotFalse($settingsLinkPos);
        $nearbyMarkup = substr($output, $settingsLinkPos, 120);

        self::assertStringContainsString('nav-badge">2<', $nearbyMarkup);
    }
}
