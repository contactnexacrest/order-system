<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CompanySettingsRepository;
use App\Repositories\UiTemplateRepository;

/**
 * docs/schema.sql Section BQ — resolves which UI template a user actually
 * sees, in this order: the user's own preference, if that template is
 * still active -> the company-wide default, if IT is still active ->
 * hard-coded 'standard', which can never itself be deactivated (see
 * UiTemplateController::toggleActive()), so this method always returns a
 * real, usable template key — never null, never a deactivated one.
 */
final class UiTemplateService
{
    public const FALLBACK_TEMPLATE_KEY = 'standard';

    public static function resolve(?int $userPreferenceId): string
    {
        if ($userPreferenceId !== null) {
            $preferred = UiTemplateRepository::findById($userPreferenceId);
            if ($preferred && (int) $preferred['is_active'] === 1) {
                return $preferred['template_key'];
            }
        }

        $defaultKey = CompanySettingsRepository::get('default_ui_template');
        if ($defaultKey !== null) {
            $default = UiTemplateRepository::findByKey($defaultKey);
            if ($default && (int) $default['is_active'] === 1) {
                return $default['template_key'];
            }
        }

        return self::FALLBACK_TEMPLATE_KEY;
    }
}
