<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AuthController;
use App\Controllers\ClientIntakeController;
use App\Controllers\ClientPortalController;
use App\Controllers\OrderController;
use App\Repositories\EmailTemplateRepository;
use App\Services\ClientPortalService;
use App\Services\TwoFactorService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #37: a completeness audit of the Email Templates module
 * found 7 system/transactional emails (2FA login code, both password
 * resets, the client-portal invite, and 3 staff-generated one-time links)
 * whose subject/body were hardcoded inline in PHP, bypassing the
 * admin-editable email_templates table entirely — unlike every
 * "Send to Buyer" document email, which already went through it.
 * EmailTemplateRepository::renderOrDefault() is the fix (see its own
 * docblock); this suite proves each of the 7 real call sites is now
 * genuinely wired to it, not just that the helper itself works in
 * isolation.
 */
final class EmailTemplateCompletenessTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        http_response_code(200);
    }

    /**
     * DbTestCase rebuilds the DB once per PHPUnit process, not per test —
     * every test method in this class overwrites one of the 7 shared
     * seeded system-template rows, which would otherwise leak into every
     * later test in this file AND into unrelated test classes elsewhere
     * in the suite that also exercise one of these same templates (e.g.
     * QuotationIntakeRateLimitTest's own quotation_correction_link send).
     * Restoring the exact seed.sql wording after every test keeps this
     * file's own mutations from ever being observable outside the one
     * test that made them.
     */
    protected function tearDown(): void
    {
        foreach (self::seededDefaults() as $key => [$subject, $body]) {
            $template = EmailTemplateRepository::find($key);
            if ($template) {
                EmailTemplateRepository::update((int) $template['id'], $subject, $body, null, 1);
                EmailTemplateRepository::setActive((int) $template['id'], true, 1);
            }
        }
        parent::tearDown();
    }

    /** @return array<string, array{0:string,1:string}> template_key => [subject, body], matching seed.sql exactly. */
    private static function seededDefaults(): array
    {
        return [
            'two_factor_code' => [
                'Your NexaCrest login verification code',
                "Your verification code is: {code}\nThis code expires in 5 minutes. If you did not request this, contact your administrator.",
            ],
            'client_portal_invite' => [
                'Your NexaCrest order portal access',
                "Hello,\n\nYour advance payment has been received and cleared — thank you.\n\nYou can now log in to track your order and download your documents (Quotation, Proforma Invoice, and everything issued from here onward) at any time.\n\nTo set up your login, open this link within 72 hours:\n{set_password_link}\n\nYour login email will be: {email}\n\nNexaCrest International Private Limited",
            ],
            'staff_password_reset' => [
                'Reset your NexaCrest password',
                "Hello {name},\n\nA password reset was requested for your NexaCrest Export Operations account ({email}).\n\nTo set a new password, open this link within 45 minutes:\n{reset_link}\n\nIf you didn't request this, you can ignore this email — your password will not be changed.",
            ],
            'client_portal_password_reset' => [
                'Reset your NexaCrest order portal password',
                "Hello,\n\nA password reset was requested for your NexaCrest order portal account ({email}).\n\nTo set a new password, open this link within 72 hours:\n{reset_link}\n\nIf you didn't request this, you can ignore this email — your password will not be changed.",
            ],
            'quotation_correction_link' => [
                'Your NexaCrest quotation request — correction link',
                "Hello,\n\nThank you for your quotation request. We'll review it and get back to you within 24 hours.\n\nSpotted a mistake in what you submitted? You can correct it yourself, as long as we haven't already processed it, at:\n{correction_link}\n\nThis link works for 7 days.\n\nNexaCrest International Private Limited",
            ],
            'pi_form_link' => [
                'NexaCrest — confirm your PI-stage details',
                "Hello,\n\nThank you for accepting our Quotation for order {order_reference}.\n\nTo issue your Proforma Invoice, please confirm your shipping/consignee details at:\n{pi_form_link}\n\nThis link works for 30 days.\n\nNexaCrest International Private Limited",
            ],
            'buyer_action_link' => [
                'NexaCrest — acknowledge your order / upload your Buyer PO',
                "Hello,\n\nTo acknowledge your Order Confirmation and/or upload your signed Buyer PO copy for order {order_reference}, please visit:\n{buyer_action_link}\n\nThis link works for 90 days.\n\nNexaCrest International Private Limited",
            ],
        ];
    }

    private function setClientEmail(int $clientId, string $email): void
    {
        Database::connection()->prepare('UPDATE clients SET email = :email WHERE id = :id')
            ->execute(['email' => $email, 'id' => $clientId]);
    }

    private function userEmail(int $userId): string
    {
        return (string) Database::connection()
            ->query('SELECT email FROM users WHERE id = ' . (int) $userId)
            ->fetchColumn();
    }

    private function overwriteTemplate(string $templateKey, string $subject, string $body): void
    {
        $template = EmailTemplateRepository::find($templateKey);
        self::assertNotNull($template, "seed.sql must already carry a '{$templateKey}' row");
        EmailTemplateRepository::update((int) $template['id'], $subject, $body, null, 1);
    }

    /** Same pattern as AmendmentDocumentEmailGatesTest — EmailService falls back to error_log() with no SMTP configured in this environment. */
    private function captureEmailLog(callable $action): string
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpunit_error_log_');
        $previous = ini_set('error_log', $logFile);
        try {
            $action();
        } finally {
            ini_set('error_log', $previous !== false ? $previous : '');
        }
        $contents = (string) file_get_contents($logFile);
        unlink($logFile);
        return $contents;
    }

    // ---------------------------------------------------------------
    // EmailTemplateRepository::renderOrDefault
    // ---------------------------------------------------------------

    public function testRenderOrDefaultUsesTheActiveTemplateWhenOneExists(): void
    {
        $this->overwriteTemplate('two_factor_code', 'Custom subject {code}', 'Custom body {code}');

        $rendered = EmailTemplateRepository::renderOrDefault('two_factor_code', ['{code}' => '123456'], 'Fallback subject', 'Fallback body');

        self::assertSame('Custom subject 123456', $rendered['subject']);
        self::assertSame('Custom body 123456', $rendered['body']);
    }

    public function testRenderOrDefaultFallsBackWhenTemplateIsMissing(): void
    {
        $rendered = EmailTemplateRepository::renderOrDefault('not_a_real_key', ['{code}' => '123456'], 'Fallback subject {code}', 'Fallback body {code}');

        self::assertSame('Fallback subject 123456', $rendered['subject']);
        self::assertSame('Fallback body 123456', $rendered['body']);
    }

    public function testRenderOrDefaultFallsBackWhenTemplateIsDeactivated(): void
    {
        // DbTestCase rebuilds the DB once per PHPUnit process, not per
        // test, so deactivating this shared seeded row must be undone
        // before this test ends — otherwise every later test in this
        // class (and this file's own call-site tests, which also key off
        // 'two_factor_code') would see it as still inactive.
        $template = EmailTemplateRepository::find('two_factor_code');
        EmailTemplateRepository::setActive((int) $template['id'], false, 1);
        try {
            $rendered = EmailTemplateRepository::renderOrDefault('two_factor_code', ['{code}' => '123456'], 'Fallback subject {code}', 'Fallback body {code}');
            self::assertSame('Fallback subject 123456', $rendered['subject']);
        } finally {
            EmailTemplateRepository::setActive((int) $template['id'], true, 1);
        }
    }

    public function testRenderOrDefaultAppendsTheFooterWhenOneIsSet(): void
    {
        $template = EmailTemplateRepository::find('two_factor_code');
        EmailTemplateRepository::update((int) $template['id'], 'Subject', 'Body', 'Footer line', 1);

        $rendered = EmailTemplateRepository::renderOrDefault('two_factor_code', [], 'Fallback', 'Fallback');

        self::assertSame("Body\n\nFooter line", $rendered['body']);
    }

    // ---------------------------------------------------------------
    // The 7 real call sites — each must genuinely read from the table.
    // ---------------------------------------------------------------

    public function testTwoFactorCodeEmailUsesTheAdminEditedTemplate(): void
    {
        $this->overwriteTemplate('two_factor_code', 'PHPUNIT-CUSTOM-2FA', 'Your one-time code is {code}.');
        $userId = $this->createTestUser('Admin');

        $logContents = $this->captureEmailLog(function () use ($userId) {
            TwoFactorService::issueCodeFor($userId, 'email', 'two-fa-test@nexacrest.test');
        });

        self::assertStringContainsString('PHPUNIT-CUSTOM-2FA', $logContents);
        self::assertMatchesRegularExpression('/Your one-time code is \d{6}\./', $logContents);
    }

    public function testClientPortalInviteEmailUsesTheAdminEditedTemplate(): void
    {
        $this->overwriteTemplate('client_portal_invite', 'PHPUNIT-CUSTOM-INVITE', 'Set your password at {set_password_link} ({email}).');
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId, 'portal-invite-test@nexacrest.test');
        $orderId = $this->createTestOrder($clientId);

        $logContents = $this->captureEmailLog(function () use ($clientId, $orderId) {
            ClientPortalService::provisionIfNeeded($clientId, $orderId);
        });

        self::assertStringContainsString('PHPUNIT-CUSTOM-INVITE', $logContents);
        self::assertStringContainsString('portal-invite-test@nexacrest.test', $logContents);
        self::assertStringContainsString('/client/set-password/', $logContents);
    }

    public function testStaffPasswordResetEmailUsesTheAdminEditedTemplate(): void
    {
        $this->overwriteTemplate('staff_password_reset', 'PHPUNIT-CUSTOM-STAFF-RESET', 'Hello {name}, reset at {reset_link} ({email}).');
        $userId = $this->createTestUser('Admin');
        $_POST['email'] = $this->userEmail($userId);

        $logContents = $this->captureEmailLog(function () {
            (new AuthController())->forgotPassword([]);
        });

        self::assertStringContainsString('PHPUNIT-CUSTOM-STAFF-RESET', $logContents);
        self::assertStringContainsString('PHPUnit Test User', $logContents);
        self::assertStringContainsString('/reset-password/', $logContents);
    }

    public function testClientPortalPasswordResetEmailUsesTheAdminEditedTemplate(): void
    {
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId, 'portal-reset-test@nexacrest.test');
        $orderId = $this->createTestOrder($clientId);
        // Provisions the real client_logins row this flow needs — ignore
        // the invite email this also sends.
        ClientPortalService::provisionIfNeeded($clientId, $orderId);

        $this->overwriteTemplate('client_portal_password_reset', 'PHPUNIT-CUSTOM-PORTAL-RESET', 'Reset at {reset_link} ({email}).');
        $_POST['email'] = 'portal-reset-test@nexacrest.test';

        $logContents = $this->captureEmailLog(function () {
            (new ClientPortalController())->forgotPassword([]);
        });

        self::assertStringContainsString('PHPUNIT-CUSTOM-PORTAL-RESET', $logContents);
        self::assertStringContainsString('portal-reset-test@nexacrest.test', $logContents);
        self::assertStringContainsString('/client/set-password/', $logContents);
    }

    public function testQuotationCorrectionLinkEmailUsesTheAdminEditedTemplate(): void
    {
        $this->overwriteTemplate('quotation_correction_link', 'PHPUNIT-CUSTOM-CORRECTION', 'Correct it at {correction_link}.');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        $_POST = [
            'company_legal_name'     => 'Email Template Completeness Test Co',
            'billing_address_line1'  => '1 Test Street',
            'billing_city'           => 'Testville',
            'billing_country'        => 'Testland',
            'vat_eori_tax_no'        => 'VAT123',
            'contact_person'         => 'Jane Test',
            'email'                  => 'correction-link-test@nexacrest.test',
            'country_of_destination' => 'Testland',
            'incoterm_preference'    => 'FOB',
        ];

        $logContents = $this->captureEmailLog(function () {
            ob_start();
            (new ClientIntakeController())->submit([]);
            ob_end_clean();
        });

        self::assertStringContainsString('PHPUNIT-CUSTOM-CORRECTION', $logContents);
        self::assertStringContainsString('/quotation-details/edit/', $logContents);
    }

    public function testPiFormLinkEmailUsesTheAdminEditedTemplate(): void
    {
        $this->overwriteTemplate('pi_form_link', 'PHPUNIT-CUSTOM-PI-LINK', 'Order {order_reference} — confirm at {pi_form_link}.');
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId, 'pi-form-link-test@nexacrest.test');
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        $logContents = $this->captureEmailLog(function () use ($orderId) {
            (new OrderController())->generatePiFormLink(['id' => (string) $orderId]);
        });

        self::assertStringContainsString('PHPUNIT-CUSTOM-PI-LINK', $logContents);
        self::assertStringContainsString('/pi-details/', $logContents);
    }

    public function testBuyerActionLinkEmailUsesTheAdminEditedTemplate(): void
    {
        $this->overwriteTemplate('buyer_action_link', 'PHPUNIT-CUSTOM-BUYER-ACTION', 'Order {order_reference} — visit {buyer_action_link}.');
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId, 'buyer-action-link-test@nexacrest.test');
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        $logContents = $this->captureEmailLog(function () use ($orderId) {
            (new OrderController())->generateBuyerActionLink(['id' => (string) $orderId]);
        });

        self::assertStringContainsString('PHPUNIT-CUSTOM-BUYER-ACTION', $logContents);
        self::assertStringContainsString('/buyer-action/', $logContents);
    }
}
