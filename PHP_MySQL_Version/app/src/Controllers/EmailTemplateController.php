<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\EmailTemplateRepository;
use App\Services\AuthService;

/** docs/schema.sql Section AI — email template CRUD, gated by manage_email_templates. Never delete. */
final class EmailTemplateController
{
    public function index(array $params): void
    {
        View::render('email_templates/index', [
            'templates' => EmailTemplateRepository::all(),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        View::render('email_templates/form', [
            'template' => null,
            'availableTokens' => self::availableTokens(),
        ], 'layout/base');
    }

    public function store(array $params): void
    {
        $templateKey = strtolower(trim((string) ($_POST['template_key'] ?? '')));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        $footer = trim((string) ($_POST['footer'] ?? ''));

        if ($templateKey === '' || !preg_match('/^[a-z0-9_]+$/', $templateKey)) {
            Flash::set('error', 'Template key must be lowercase letters, numbers, and underscores only.');
            header('Location: /email-templates/create');
            return;
        }
        if ($subject === '' || trim($body) === '') {
            Flash::set('error', 'Subject and body are required.');
            header('Location: /email-templates/create');
            return;
        }
        if (EmailTemplateRepository::keyExists($templateKey)) {
            Flash::set('error', "Template key \"{$templateKey}\" already exists — edit it instead of creating a duplicate.");
            header('Location: /email-templates/create');
            return;
        }

        $user = AuthService::currentUser();
        EmailTemplateRepository::create($templateKey, $subject, $body, $footer !== '' ? $footer : null, (int) $user['id']);
        Flash::set('success', 'Template created.');
        header('Location: /email-templates');
    }

    public function edit(array $params): void
    {
        $template = EmailTemplateRepository::findById((int) $params['id']);
        if (!$template) {
            http_response_code(404);
            echo 'Template not found.';
            return;
        }
        View::render('email_templates/form', [
            'template' => $template,
            'availableTokens' => self::availableTokens(),
        ], 'layout/base');
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $template = EmailTemplateRepository::findById($id);
        if (!$template) {
            http_response_code(404);
            echo 'Template not found.';
            return;
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        $footer = trim((string) ($_POST['footer'] ?? ''));
        if ($subject === '' || trim($body) === '') {
            Flash::set('error', 'Subject and body are required.');
            header("Location: /email-templates/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        EmailTemplateRepository::update($id, $subject, $body, $footer !== '' ? $footer : null, (int) $user['id']);
        Flash::set('success', 'Template updated. Every email already sent from it keeps exactly what it said at send time — this only affects new sends.');
        header('Location: /email-templates');
    }

    public function toggleActive(array $params): void
    {
        $id = (int) $params['id'];
        $template = EmailTemplateRepository::findById($id);
        if (!$template) {
            http_response_code(404);
            echo 'Template not found.';
            return;
        }
        $user = AuthService::currentUser();
        EmailTemplateRepository::setActive($id, !$template['is_active'], (int) $user['id']);
        Flash::set('success', $template['is_active'] ? 'Template deactivated — no longer offered for new sends.' : 'Template reactivated.');
        header('Location: /email-templates');
    }

    /** @return string[] */
    private static function availableTokens(): array
    {
        return [
            '{buyer_contact_person}', '{buyer_company_name}', '{document_reference}', '{generated_date}',
            '{order_reference}', '{buyer_inquiry_ref}', '{quotation_ref}', '{quotation_valid_until}',
            '{pi_ref}', '{pi_valid_until}', '{oc_ref}', '{company_name}', '{company_email}', '{company_phone}',
            '{sender_name}', '{sender_title}', '{sender_signature}',
        ];
    }
}
