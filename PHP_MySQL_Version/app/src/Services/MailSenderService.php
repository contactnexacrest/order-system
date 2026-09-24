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
        if (!$isSecurityEmail && ZohoMailService::isEnabled()) {
            try {
                if (ZohoMailService::send($to, $subject, $body, $attachments)) {
                    return true;
                }
                error_log('[ZOHO MAIL — send returned false, falling back to SMTP] To: ' . $to);
            } catch (\Throwable $e) {
                error_log('[ZOHO MAIL — exception, falling back to SMTP] ' . $e->getMessage());
            }
        }

        return EmailService::sendWithAttachments($to, $subject, $body, $attachments, $isSecurityEmail);
    }
}
