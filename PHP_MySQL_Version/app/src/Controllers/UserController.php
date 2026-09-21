<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/**
 * Admin user-management screen (Section 14 gap found in Phase E's own
 * follow-up review — there was previously no way, not even for Admin, to
 * create a login, deactivate one, or reset someone else's password short
 * of a direct SQL statement). Gated entirely on `manage_users`, a
 * permission that was already seeded in Phase A and granted to
 * Admin/Managing Director, but never had a screen behind it until now.
 *
 * Deliberately NOT a generic "edit any user field" screen, matching the
 * same bounded-scope judgment call Phase E's admin-override system made —
 * this covers exactly the actions the gap analysis named: create, list,
 * deactivate/reactivate, and force a password reset. Editing an existing
 * user's name/email/role isn't included; add it the same targeted way if
 * it turns out to be needed later.
 */
final class UserController
{
    public function index(array $params): void
    {
        View::render('users/index', [
            'users' => UserRepository::listAllForAdmin(),
            'roles' => LookupRepository::roles(),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? '')) ?: null;
        $roleId = ($_POST['role_id'] ?? '') !== '' ? (int) $_POST['role_id'] : null;

        if ($name === '' || $email === '') {
            Flash::set('error', 'Name and email are required.');
            header('Location: /users');
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', "\"{$email}\" doesn't look like a valid email address.");
            header('Location: /users');
            return;
        }

        if (UserRepository::findByEmail($email)) {
            Flash::set('error', "A user with the email {$email} already exists.");
            header('Location: /users');
            return;
        }

        $tempPassword = self::generateTempPassword();
        $userId = UserRepository::create($name, $email, $phone, $roleId, password_hash($tempPassword, PASSWORD_DEFAULT));

        $actor = AuthService::currentUser();
        AuditLogRepository::log((int) $actor['id'], 'USER_CREATED', 'users', $userId, null, null, null, "Created user {$email}");

        // Shown once, here, in a flash message — never emailed, never
        // logged, never stored anywhere but the (already hashed) DB row.
        // Hand it to the person out-of-band; they're forced to change it
        // on first login regardless (force_password_change = 1).
        Flash::set('success', "User created: {$name} ({$email}). One-time temporary password: {$tempPassword} — give this to them directly; it will not be shown again, and they must change it on first login.");
        header('Location: /users');
    }

    public function toggleActive(array $params): void
    {
        $userId = (int) $params['id'];
        $target = UserRepository::findById($userId);
        if (!$target) {
            Flash::set('error', 'User not found.');
            header('Location: /users');
            return;
        }

        $actor = AuthService::currentUser();
        if ($userId === (int) $actor['id']) {
            Flash::set('error', "You can't deactivate your own account.");
            header('Location: /users');
            return;
        }
        if ((int) $target['is_super_admin'] === 1) {
            Flash::set('error', 'A Super Admin account can never be deactivated.');
            header('Location: /users');
            return;
        }

        $newState = !((bool) $target['is_active']);
        UserRepository::setActive($userId, $newState);
        AuditLogRepository::log(
            (int) $actor['id'],
            $newState ? 'USER_REACTIVATED' : 'USER_DEACTIVATED',
            'users',
            $userId,
            'is_active',
            $target['is_active'] ? '1' : '0',
            $newState ? '1' : '0'
        );

        Flash::set('success', $newState ? "{$target['name']} reactivated." : "{$target['name']} deactivated — they can no longer log in.");
        header('Location: /users');
    }

    public function forceResetPassword(array $params): void
    {
        $userId = (int) $params['id'];
        $target = UserRepository::findById($userId);
        if (!$target) {
            Flash::set('error', 'User not found.');
            header('Location: /users');
            return;
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: /users');
            return;
        }

        $tempPassword = self::generateTempPassword();
        UserRepository::adminForceResetPassword($userId, password_hash($tempPassword, PASSWORD_DEFAULT));

        $actor = AuthService::currentUser();
        AuditLogRepository::log((int) $actor['id'], 'PASSWORD_ADMIN_RESET', 'users', $userId, null, null, null, $reason);

        Flash::set('success', "Password reset for {$target['name']} ({$target['email']}). One-time temporary password: {$tempPassword} — give this to them directly; they must change it on first login.");
        header('Location: /users');
    }

    private static function generateTempPassword(): string
    {
        // 12 random bytes -> 16-char base64url, always contains mixed
        // case + digits (no dependency on the character set including a
        // symbol, since force_password_change means it only has to
        // survive one login, not become anyone's long-term password).
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    }
}
