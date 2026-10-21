<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Repositories\CompanySettingsRepository;

/**
 * docs/schema.sql Section AS — independent of Test Mode (TestModeService):
 * a separate on/off switch so staff can rehearse real outgoing mail (CC
 * lists, templates, attachments) without turning on the whole Test Mode
 * sandbox. Every transport chains this immediately after
 * TestModeService::resolveEmailRecipient() so neither switch can be
 * bypassed by picking a different transport. $isSecurityEmail is the same
 * carve-out as Test Mode, for the same reason: staff's own 2FA codes and
 * password-reset links must always reach the real address, never be
 * redirected or CC'd to a third party.
 */
final class MailRedirectService
{
    public static function resolveRecipient(string $toEmail, bool $isSecurityEmail): string
    {
        if ($isSecurityEmail) {
            return $toEmail;
        }
        if (CompanySettingsRepository::get('mail_redirect_enabled') !== '1') {
            return $toEmail;
        }
        $redirectTo = trim((string) CompanySettingsRepository::get('mail_redirect_address'));
        if ($redirectTo === '' || !filter_var($redirectTo, FILTER_VALIDATE_EMAIL)) {
            error_log("[MAIL REDIRECT — enabled but mail_redirect_address is blank/invalid, sending to original recipient] To: {$toEmail}");
            return $toEmail;
        }
        error_log("[MAIL REDIRECT — email redirected] Original To: {$toEmail} -> {$redirectTo}");
        return $redirectTo;
    }

    /**
     * @return string[] deduplicated, validated CC addresses — mail_cc_emails
     *         (comma-separated) plus mail_default_cc_email, always applied
     *         together. Empty for a security email.
     */
    public static function ccList(bool $isSecurityEmail): array
    {
        if ($isSecurityEmail) {
            return [];
        }

        $addresses = [];
        foreach (explode(',', (string) CompanySettingsRepository::get('mail_cc_emails')) as $raw) {
            $addr = trim($raw);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $addresses[] = $addr;
            }
        }

        // 90-item backlog item #12: a fresh install with company_settings
        // not yet configured previously had no default CC at all — falls
        // back to MAIL_DEFAULT_CC_EMAIL in .env (same "DB wins if set,
        // else .env" precedence as every other optional company setting
        // with an .env counterpart) rather than silently doing nothing
        // until someone remembers to set it in Company Settings.
        $default = trim((string) CompanySettingsRepository::get('mail_default_cc_email')) ?: trim((string) Env::get('MAIL_DEFAULT_CC_EMAIL', ''));
        if ($default !== '' && filter_var($default, FILTER_VALIDATE_EMAIL)) {
            $addresses[] = $default;
        }

        return array_values(array_unique($addresses));
    }
}
