<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\UiTemplateRepository;
use App\Services\UiTemplateService;

/**
 * docs/schema.sql Section BQ — admin on/off switch per UI template,
 * gated by manage_company_settings (same tier as the rest of Settings).
 * The company-wide default itself is set from the Settings page (see
 * SettingsController), not here — this screen only controls which
 * templates exist to choose from at all.
 */
final class UiTemplateController
{
    public function index(array $params): void
    {
        View::render('ui_templates/index', [
            'templates' => UiTemplateRepository::all(),
        ], 'layout/base');
    }

    public function toggleActive(array $params): void
    {
        $id = (int) $params['id'];
        $template = UiTemplateRepository::findById($id);
        if (!$template) {
            http_response_code(404);
            echo 'Template not found.';
            return;
        }
        if ($template['template_key'] === UiTemplateService::FALLBACK_TEMPLATE_KEY && (int) $template['is_active'] === 1) {
            Flash::set('error', '"Standard" can never be deactivated — it is the fallback every user and the company default ultimately fall back to.');
            header('Location: /ui-templates');
            return;
        }
        UiTemplateRepository::setActive($id, !$template['is_active']);
        Flash::set('success', $template['is_active'] ? 'Template deactivated — anyone using it (by preference or as the company default) now falls back automatically.' : 'Template reactivated.');
        header('Location: /ui-templates');
    }
}
