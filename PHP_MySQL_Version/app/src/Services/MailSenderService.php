<?php

declare(strict_types=1);

namespace App\Services;

/**
 * docs/schema.sql Section AI — the one chokepoint every outbound email in
 * the app should go through from here on (order-comment notifications now;
 * document sends and 2FA/reset emails can be migrated onto it the same
 * way). When company_settings.zoho_mail_enabled is on, this tries the
 * Zoho Mail API first; on ANY failure at all — bad/missing credentials, a
 * network error, a malformed response, anything — it falls straight
 * through to the existing SMTP path (EmailService) with no error ever
 * surfaced to the caller. Zoho is strictly an optional enhancement layer,
 * never something the app can be blocked by.
 */
final class MailSenderService
{
    /**
     * @param array<int, array{path:string, name:string}> $attachments
     * @return bool true if actually handed to a transport, false if only logged (dev fallback)
     */
    public static function send(string $to, string $subject, string $body, array $attachments = [], bool $isSecurityEmail = false): bool
    {
        // QA-5 TM-08: resolved ONCE, here, before either transport is
        // chosen. Test Mode's redirect used to live only inside
        // EmailService's own send methods — the SMTP fallback path — so
        // turning Zoho on gave every outbound email a way around Test Mode
        // entirely, straight to the real buyer's inbox. Both transports
        // below now see the same, already-resolved address.
        $resolved = TestModeService::resolveEmailRecipient($to, $isSecurityEmail, $subject);
        if ($resolved === null) {
            // QA-5 TM-07: Test Mode is on and no test_email is configured —
            // never fall back to sending this to the real address, via
            // Zoho or SMTP.
            return false;
        }

        if (!$isSecurityEmail && ZohoMailService::isEnabled()) {
            try {
                if (ZohoMailService::send($resolved, $subject, $body, $attachments)) {
                    return true;
                }
                error_log('[ZOHO MAIL — send returned false, falling back to SMTP] To: ' . $resolved);
            } catch (\Throwable $e) {
                error_log('[ZOHO MAIL — exception, falling back to SMTP] ' . $e->getMessage());
            }
        }

        return EmailService::deliverWithAttachments($resolved, $subject, $body, $attachments);
    }
}
