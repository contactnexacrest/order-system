<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Services\EmailService;
use App\Services\TestModeService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (TM-07/TM-08 — external QA report cross-verification):
 *
 * TM-07: when Test Mode is on but no test_email is configured yet,
 * EmailService::resolveRecipient() (now TestModeService::resolveEmailRecipient())
 * fell back to sending straight to the real address — defeating Test
 * Mode's whole point at exactly the moment it matters most (a fresh,
 * not-yet-fully-configured Test Mode session).
 *
 * TM-08: the Test Mode gate lived only inside EmailService's own SMTP
 * send methods. MailSenderService — the actual chokepoint every
 * order/document/comment email goes through — tried Zoho Mail FIRST,
 * with the untouched real address, and only fell back to EmailService (and
 * its gate) if Zoho failed. Turning Zoho on was a complete bypass of Test
 * Mode. Fixed by moving the resolve to the very top of
 * MailSenderService::send(), before either transport is chosen — this test
 * covers the shared resolver both fixes now go through.
 */
final class TestModeEmailGateTest extends DbTestCase
{
    private array $originalSettings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSettings = TestModeService::getSettings();
    }

    protected function tearDown(): void
    {
        // test_mode_settings is a single shared row (id = 1) — every test
        // here must leave it exactly as it found it for whatever test runs
        // next in this same PHPUnit process.
        Database::connection()->prepare(
            'UPDATE test_mode_settings SET is_enabled = :enabled, test_email = :email WHERE id = 1'
        )->execute([
            'enabled' => (int) $this->originalSettings['is_enabled'],
            'email' => $this->originalSettings['test_email'],
        ]);
        parent::tearDown();
    }

    public function testRealAddressPassesThroughWhenTestModeIsOff(): void
    {
        TestModeService::setTestEmail('');
        $this->setTestModeEnabled(false);

        $result = TestModeService::resolveEmailRecipient('buyer@real-company.example', false, 'Order Confirmation');

        self::assertSame('buyer@real-company.example', $result);
    }

    public function testSecurityEmailAlwaysBypassesTestModeRegardlessOfSettings(): void
    {
        $this->setTestModeEnabled(true);
        TestModeService::setTestEmail(''); // deliberately unconfigured — must not matter for a security email

        $result = TestModeService::resolveEmailRecipient('staff@nexacrest.example', true, '2FA code');

        self::assertSame('staff@nexacrest.example', $result, 'staff 2FA/password-reset emails must never be redirected or blocked');
    }

    public function testRedirectsToTheConfiguredTestEmailWhenTestModeIsOn(): void
    {
        $this->setTestModeEnabled(true);
        TestModeService::setTestEmail('qa-inbox@nexacrest.example');

        $result = TestModeService::resolveEmailRecipient('buyer@real-company.example', false, 'Order Confirmation');

        self::assertSame('qa-inbox@nexacrest.example', $result);
    }

    /** QA-5 TM-07: the actual regression this defect described. */
    public function testBlocksTheSendInsteadOfFallingBackToTheRealAddressWhenNoTestEmailIsConfigured(): void
    {
        $this->setTestModeEnabled(true);
        TestModeService::setTestEmail('');

        $result = TestModeService::resolveEmailRecipient('buyer@real-company.example', false, 'Order Confirmation');

        self::assertNull($result, 'a real buyer must never be emailed just because nobody has set a test address yet');
    }

    /**
     * QA-5 TM-07 at the public API level: both EmailService send methods —
     * the ones every other call site (2FA aside) actually calls — must
     * refuse the send outright rather than quietly delivering it to the
     * real address.
     */
    public function testEmailServiceRefusesToSendRatherThanFallBackToTheRealAddress(): void
    {
        $this->setTestModeEnabled(true);
        TestModeService::setTestEmail('');

        $plainTextResult = EmailService::sendPlainText('buyer@real-company.example', 'Order Confirmation', 'body');
        $attachmentResult = EmailService::sendWithAttachments('buyer@real-company.example', 'Order Confirmation', 'body', []);

        self::assertFalse($plainTextResult);
        self::assertFalse($attachmentResult);
    }

    private function setTestModeEnabled(bool $enabled): void
    {
        Database::connection()
            ->prepare('UPDATE test_mode_settings SET is_enabled = :enabled WHERE id = 1')
            ->execute(['enabled' => $enabled ? 1 : 0]);
    }
}
