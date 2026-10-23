<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\CompanySettingsRepository;
use App\Services\EmailService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-43/L-46: customer emails (QT/PI/OC/etc.) went out
 * from a no-reply address with no Reply-To, so a buyer reply was lost; and
 * PHPMailer's Message-ID defaulted to the server's own machine hostname
 * (e.g. <...@vm>) instead of the company's own mail domain. Both are set
 * from EmailService::configureMailerIdentity() (called from every real
 * SMTP send) via the two pure helpers below, which are what's actually
 * under test here — a configured SMTP transport isn't available in this
 * environment, matching every other EmailService test in this suite.
 */
final class EmailDeliveryIdentityTest extends DbTestCase
{
    protected function tearDown(): void
    {
        CompanySettingsRepository::set('reply_to_email', '');
        parent::tearDown();
    }

    public function testResolveReplyToAddressUsesTheDedicatedSettingWhenConfigured(): void
    {
        CompanySettingsRepository::set('reply_to_email', 'sales@nexacrestinternational.test');

        self::assertSame('sales@nexacrestinternational.test', EmailService::resolveReplyToAddress());
    }

    public function testResolveReplyToAddressFallsBackToTheCompanyContactEmailWhenBlank(): void
    {
        CompanySettingsRepository::set('reply_to_email', '');
        $companyEmail = (string) CompanySettingsRepository::get('email');
        self::assertNotSame('', $companyEmail, 'sanity check: the company contact email must actually be configured for this test to mean anything');

        self::assertSame($companyEmail, EmailService::resolveReplyToAddress());
    }

    /**
     * External QA report TI-09: this test used to rely on whatever
     * SMTP_FROM_ADDRESS happened to be in the developer's own app/.env
     * (the comment claiming "tests/.env pins it" was simply wrong — no
     * such file exists), so it passed or failed by accident depending on
     * a value this test never actually controlled. Env::get() reads
     * $_ENV ahead of getenv(), so setting $_ENV directly here pins the
     * value regardless of .env, with no change needed to Env itself.
     */
    public function testMailHostnameIsDerivedFromTheFromAddressDomainNotTheServerMachineName(): void
    {
        $original = $_ENV['SMTP_FROM_ADDRESS'] ?? null;
        $_ENV['SMTP_FROM_ADDRESS'] = 'dev@nexacrest.test';
        try {
            self::assertSame('nexacrest.test', EmailService::mailHostname());
        } finally {
            if ($original === null) {
                unset($_ENV['SMTP_FROM_ADDRESS']);
            } else {
                $_ENV['SMTP_FROM_ADDRESS'] = $original;
            }
        }
    }
}
