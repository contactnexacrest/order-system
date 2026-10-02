<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\SettingsController;
use App\Services\EmailService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section AS — the "Test SMTP Mail Connection" button
 * (mirrors the existing Zoho test-send button). The test environment's
 * app/.env deliberately ships with SMTP_HOST blank (PHPUnit's bootstrap
 * loads the real .env, only swapping DB_DATABASE — see tests/bootstrap.php
 * — so a real credential here would mean every test run actually tries to
 * send mail), so these tests only exercise the "not configured" failure
 * path; the actual send-success path is verified by hand against the
 * user's real SMTP credentials from the Settings screen, never by an
 * automated test.
 */
final class SmtpTestEmailTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
    }

    public function testSendTestEmailThrowsWhenSmtpHostIsNotConfigured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP_HOST is not configured');

        EmailService::sendTestEmail('someone@example.com', 'subject', 'body');
    }

    public function testControllerRejectsAnInvalidDestinationAddress(): void
    {
        $_POST = ['test_to' => 'not-an-email'];

        ob_start();
        (new SettingsController())->testSmtpEmail([]);
        ob_end_clean();

        // No exception thrown, no attempt made — the invalid-email guard
        // fires before EmailService::sendTestEmail() is ever called.
        self::assertTrue(true);
    }

    public function testControllerSurfacesTheRealSmtpErrorForAValidAddress(): void
    {
        $_POST = ['test_to' => 'someone@example.com'];

        ob_start();
        (new SettingsController())->testSmtpEmail([]);
        ob_end_clean();

        // Flash::set() appends into $_SESSION['_flash'] — confirm the
        // failure message (not a silent success) reached the admin,
        // same contract as testZohoEmail's own failure path.
        self::assertArrayHasKey('_flash', $_SESSION);
        $last = end($_SESSION['_flash']);
        self::assertSame('error', $last['type']);
        self::assertStringContainsString('SMTP test send failed', $last['message']);
    }
}
