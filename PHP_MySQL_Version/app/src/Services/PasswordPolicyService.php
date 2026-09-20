<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CompanySettingsRepository;

/**
 * DB-driven password policy (company_settings, category 'security'), same
 * "everything from DB" rule as the rest of the schema — extracted here
 * because it was originally copy-pasted inline in
 * AuthController::forcePasswordChange() and would otherwise have become a
 * third copy in the forgot/reset-password flow, with three places to keep
 * in sync every time the policy changes.
 */
final class PasswordPolicyService
{
    /** @return string|null an error message, or null if the password passes every configured rule */
    public static function validate(string $password): ?string
    {
        $minLength = (int) (CompanySettingsRepository::get('password_min_length') ?? '10');
        $complexityJson = CompanySettingsRepository::get('password_complexity_json');
        $complexity = $complexityJson ? (json_decode($complexityJson, true) ?: []) : [];

        if (strlen($password) < $minLength) {
            return "Password must be at least {$minLength} characters.";
        }
        if (!empty($complexity['require_upper']) && !preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (!empty($complexity['require_number']) && !preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }
        if (!empty($complexity['require_symbol']) && !preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must contain at least one symbol.';
        }
        return null;
    }
}
