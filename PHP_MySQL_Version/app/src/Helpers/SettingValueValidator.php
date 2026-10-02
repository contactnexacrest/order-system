<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * QA-5 SET-02: company_settings.value_type (docs/schema.sql Section A) was
 * defined at the schema level but never actually enforced anywhere —
 * SettingsController::update() wrote whatever string a staff member typed
 * straight into setting_value with zero type or range checking. A
 * 'number' setting like session_timeout_minutes or
 * failed_login_lockout_count set to "abc", "-5", or blank would parse to
 * 0/garbage everywhere it's later read with (int) casts — breaking
 * session timeouts, lockout logic, or working-days/alert-day arithmetic
 * in ways that fail silently rather than refusing the edit up front.
 * Every currently-seeded 'number' setting is a day-count, percentage, or
 * threshold — none has a legitimate negative value — so a negative
 * number is rejected here on the same footing as outright non-numeric
 * garbage.
 */
final class SettingValueValidator
{
    /** @return string|null an error message if invalid, null if the value passes */
    public static function check(string $valueType, string $rawValue): ?string
    {
        $value = trim($rawValue);

        switch ($valueType) {
            case 'number':
                if ($value === '' || !is_numeric($value)) {
                    return "must be a number (got \"{$rawValue}\")";
                }
                if ((float) $value < 0) {
                    return "must not be negative (got \"{$rawValue}\")";
                }
                return null;

            case 'boolean':
                if ($value !== '0' && $value !== '1') {
                    return "must be 0 or 1 (got \"{$rawValue}\")";
                }
                return null;

            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    return "must be a date in YYYY-MM-DD format (got \"{$rawValue}\")";
                }
                [$y, $m, $d] = array_map('intval', explode('-', $value));
                if (!checkdate($m, $d, $y)) {
                    return "is not a real calendar date (got \"{$rawValue}\")";
                }
                return null;

            case 'json':
                json_decode($value);
                if ($value === '' || json_last_error() !== JSON_ERROR_NONE) {
                    return "must be valid JSON (got \"{$rawValue}\")";
                }
                return null;

            // docs/schema.sql Section AS: 'email'/'email_list' are blank-
            // allowed (an unconfigured redirect/CC address is a valid,
            // common state — mail_redirect_enabled or the CC list simply
            // has nothing to use yet) but reject a non-blank value that
            // isn't actually a deliverable address.
            case 'email':
                if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "must be a valid email address (got \"{$rawValue}\")";
                }
                return null;

            case 'email_list':
                foreach (explode(',', $value) as $addr) {
                    $addr = trim($addr);
                    if ($addr !== '' && !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        return "contains an invalid email address \"{$addr}\"";
                    }
                }
                return null;

            case 'string':
            default:
                return null;
        }
    }
}
