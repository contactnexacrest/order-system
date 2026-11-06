<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\RateLimiter;
use App\Helpers\View;
use App\Services\AuthService;
use App\Repositories\AssociateEventRepository;
use App\Repositories\AssociateRegistrationRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;
use App\Services\AssociatePortalService;
use App\Services\AssociateService;
use App\Services\EmailService;
use App\Config\Env;

/**
 * Owner decision D-253 (4 Nov 2026, A1): associate self-registration.
 * The MD (manage associates) creates a one-use link valid N days (setting,
 * default 7); the prospect fills a short form; staff accept (the associate
 * is created as Invited and gets the portal login link) or reject with a
 * reason. After the first login the associate completes the profile and
 * the KYC uploads. Expired or used links show a friendly page.
 * Node twin: associateRegistrationController.js.
 */
final class AssociateRegistrationController
{
    private static function user(): array
    {
        return AuthService::currentUser() ?? [];
    }

    public static function linkUrl(string $raw): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . '/associate-register/' . $raw;
    }

    // ------------------------------------------------------------ staff

    public function index(array $params): void
    {
        $status = (string) ($_GET['status'] ?? 'pending');
        View::render('associates/registrations', [
            'status' => $status,
            'registrations' => AssociateRegistrationRepository::all($status === 'all' ? null : $status),
            'links' => AssociateRegistrationRepository::links(),
            'linkDays' => AssociateRegistrationRepository::linkDays(),
            'newLink' => $_SESSION['_associate_registration_link'] ?? null,
        ], 'layout/base');
        unset($_SESSION['_associate_registration_link']);
    }

    public function createLink(array $params): void
    {
        $note = trim((string) ($_POST['note'] ?? ''));
        if (mb_strlen($note) > 200) {
            Flash::set('error', 'The note can be up to 200 characters.');
        } else {
            $raw = AssociateRegistrationRepository::createLink($note !== '' ? $note : null, (int) self::user()['id']);
            $_SESSION['_associate_registration_link'] = self::linkUrl($raw);
            AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_REGISTRATION_LINK_CREATED', 'associate_registration_links', 0, 'note', null, $note);
            Flash::set('success', 'Associate registration link created — valid ' . AssociateRegistrationRepository::linkDays() . ' days, for one registration. Copy it below.');
        }
        header('Location: /associates/registrations');
    }

    public function cancelLink(array $params): void
    {
        AssociateRegistrationRepository::cancelLink((int) $params['id']);
        AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_REGISTRATION_LINK_CANCELLED', 'associate_registration_links', (int) $params['id']);
        Flash::set('success', 'Link cancelled.');
        header('Location: /associates/registrations');
    }

    public function saveSettings(array $params): void
    {
        $days = (string) ($_POST['link_days'] ?? '');
        if (!ctype_digit($days) || (int) $days < 1 || (int) $days > 90) {
            Flash::set('error', 'Links can be valid for 1 to 90 days.');
        } else {
            $old = AssociateRegistrationRepository::linkDays();
            IncentiveRepository::setSetting('associate_registration_link_days', (string) (int) $days, (int) self::user()['id']);
            AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_REGISTRATION_SETTING_CHANGED', 'incentive_settings', 0, 'associate_registration_link_days', (string) $old, (string) (int) $days);
            Flash::set('success', "New links are valid for {$days} days.");
        }
        header('Location: /associates/registrations');
    }

    public function accept(array $params): void
    {
        $r = AssociateRegistrationRepository::find((int) $params['id']);
        $userId = (int) self::user()['id'];
        if ($r === null || $r['status'] !== 'pending') {
            Flash::set('error', 'This registration was already reviewed.');
            header('Location: /associates/registrations');
            return;
        }
        if (AssociateRepository::findByEmail((string) $r['email']) !== null) {
            Flash::set('error', 'An associate with the e-mail ' . $r['email'] . ' already exists — reject this registration or contact them.');
            header('Location: /associates/registrations');
            return;
        }
        $id = AssociateRepository::create([
            'name' => $r['name'], 'based_in' => $r['based_in'], 'country' => $r['country'], 'currency_code' => $r['based_in'] === 'india' ? 'INR' : 'USD',
            'email' => $r['email'], 'phone' => $r['phone'], 'status' => 'invited', 'notes' => 'Registered themselves through an associate registration link (D-253).',
        ], $userId);
        AssociateRepository::setFields($id, ['city' => $r['city'], 'access_level' => 'starter']); // D-261: a new associate starts on Starter
        if (!AssociateRegistrationRepository::decide((int) $r['id'], 'accepted', null, $userId, $id)) {
            Flash::set('error', 'This registration was already reviewed.');
            header('Location: /associates/registrations');
            return;
        }
        AuditLogRepository::log($userId, 'ASSOCIATE_REGISTRATION_ACCEPTED', 'associate_registrations', (int) $r['id'], 'associate_id', null, (string) $id);
        AssociateEventRepository::add($id, 'registered', 'Registered through an associate registration link; accepted by ' . (self::user()['name'] ?? 'NexaCrest') . '.', null, null, null, $userId, false);
        try {
            $link = AssociateService::invitePortal($id, $userId);
            Flash::set('success', "{$r['name']} is now an associate (Invited). The portal login link was e-mailed to {$r['email']}." . (AssociatePortalService::showCodeOnScreen() ? " Development only — link: {$link}" : ''));
        } catch (\RuntimeException $e) {
            Flash::set('error', 'Associate created, but the portal login link could not be sent: ' . $e->getMessage());
        }
        header('Location: /associates/' . $id);
    }

    public function reject(array $params): void
    {
        $r = AssociateRegistrationRepository::find((int) $params['id']);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($r === null || $r['status'] !== 'pending') {
            Flash::set('error', 'This registration was already reviewed.');
        } elseif (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            Flash::set('error', 'Give the reason (10 to 300 characters) — it is e-mailed to the person.');
        } elseif (AssociateRegistrationRepository::decide((int) $r['id'], 'rejected', $reason, (int) self::user()['id'], null)) {
            AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_REGISTRATION_REJECTED', 'associate_registrations', (int) $r['id'], 'reason', null, $reason);
            EmailService::sendPlainText((string) $r['email'], 'Your NexaCrest associate registration',
                "Hello {$r['name']},\n\nThank you for registering as a NexaCrest associate. We cannot accept the registration at this time:\n{$reason}\n\nNexaCrest International Private Limited");
            Flash::set('success', 'Registration rejected — the reason was e-mailed.');
        }
        header('Location: /associates/registrations');
    }

    // ------------------------------------------------------------ public (no login)

    public function show(array $params): void
    {
        $link = AssociateRegistrationRepository::usableLink((string) $params['token']);
        if ($link === null) {
            View::render('associate_register/unavailable', [], 'layout/bare');
            return;
        }
        View::render('associate_register/form', ['token' => (string) $params['token'], 'old' => [], 'errors' => []], 'layout/bare');
    }

    /** @return array{0: array<string,string>, 1: array<string,string>} [data, errors by field] */
    public static function validate(array $in): array
    {
        $d = [];
        foreach (['name', 'email', 'phone', 'country', 'based_in', 'city'] as $f) {
            $d[$f] = trim((string) ($in[$f] ?? ''));
        }
        $e = [];
        if (mb_strlen($d['name']) < 2 || mb_strlen($d['name']) > 150) {
            $e['name'] = 'Enter your full name (2 to 150 characters).';
        }
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 190) {
            $e['email'] = 'Enter a valid e-mail address — it becomes your portal login.';
        }
        if (preg_match('/^[0-9 +()\-]{6,40}$/', $d['phone']) !== 1) {
            $e['phone'] = 'Enter a phone number with the country code, e.g. +91 98765 43210.';
        }
        if (mb_strlen($d['country']) < 2 || mb_strlen($d['country']) > 80) {
            $e['country'] = 'Enter your country.';
        }
        if (!in_array($d['based_in'], ['india', 'abroad'], true)) {
            $e['based_in'] = 'Choose whether you are based in India or abroad.';
        }
        if (mb_strlen($d['city']) < 2 || mb_strlen($d['city']) > 100) {
            $e['city'] = 'Enter your city.';
        }
        if (($in['consent'] ?? '') !== '1') {
            $e['consent'] = 'Tick the box to agree that NexaCrest may use these details to review your registration.';
        }
        return [$d, $e];
    }

    public function submit(array $params): void
    {
        $token = (string) $params['token'];
        $link = AssociateRegistrationRepository::usableLink($token);
        if ($link === null) {
            View::render('associate_register/unavailable', [], 'layout/bare');
            return;
        }
        if (RateLimiter::tooManyRequests('associate_registration_submit', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 5, 15)) {
            http_response_code(429);
            View::render('associate_register/form', ['token' => $token, 'old' => $_POST, 'errors' => ['form' => 'Too many attempts from this connection — please try again in 15 minutes.']], 'layout/bare');
            return;
        }
        [$d, $errors] = self::validate($_POST);
        if ($errors === [] && AssociateRepository::findByEmail($d['email']) !== null) {
            $errors['email'] = 'This e-mail is already registered with NexaCrest — please contact your NexaCrest contact.';
        }
        if ($errors !== []) {
            http_response_code(422);
            View::render('associate_register/form', ['token' => $token, 'old' => $d + ['consent' => $_POST['consent'] ?? ''], 'errors' => $errors], 'layout/bare');
            return;
        }
        if (!AssociateRegistrationRepository::claimLink((int) $link['id'])) {
            View::render('associate_register/unavailable', [], 'layout/bare');
            return;
        }
        $id = AssociateRegistrationRepository::create((int) $link['id'], $d, $_SERVER['REMOTE_ADDR'] ?? null);
        AuditLogRepository::log(null, 'ASSOCIATE_REGISTRATION_SUBMITTED', 'associate_registrations', $id, 'email', null, $d['email']);
        foreach (PermissionRepository::usersWithPermission('manage_associates') as $userId) {
            NotificationRepository::createInAppOnly((int) $userId, 'associate_registration', null, mb_substr("New associate registration from {$d['name']} ({$d['city']}, {$d['country']}) — review it under Associates › Registrations.", 0, 500));
        }
        View::render('associate_register/done', ['name' => $d['name']], 'layout/bare');
    }
}
