<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\PasswordPolicyService;

/** docs/schema.sql Section AI — self-service staff account page (email signature, self-service password change — a natural home for other self-service settings later). */
final class AccountController
{
    public function edit(array $params): void
    {
        $user = UserRepository::findById((int) AuthService::currentUser()['id']);
        View::render('account/edit', ['user' => $user], 'layout/base');
    }

    public function updateSignature(array $params): void
    {
        $userId = (int) AuthService::currentUser()['id'];
        $signature = trim((string) ($_POST['email_signature'] ?? ''));
        UserRepository::updateSignature($userId, $signature !== '' ? $signature : null);
        Flash::set('success', 'Signature saved — it will be used on every email you send through the system from now on.');
        header('Location: /account');
    }

    /** User-reported batch item 20 — the per-user opt-in/out for action-notification emails. */
    public function updateNotificationPreference(array $params): void
    {
        $userId = (int) AuthService::currentUser()['id'];
        $enabled = !empty($_POST['email_action_notifications_enabled']);
        UserRepository::updateNotificationPreference($userId, $enabled);
        Flash::set('success', 'Notification preference saved.');
        header('Location: /account');
    }

    /**
     * 90-item backlog #26: a voluntary, logged-in self-service password
     * change — previously the only ways a staff user's password could
     * change were the forced-at-first-login flow and the logged-out
     * forgot-password email flow, with no option for an already-logged-in
     * user who simply wants to change it. Mirrors
     * ClientPortalController::changePassword()'s exact pattern (current
     * password re-verified, then PasswordPolicyService, then match check).
     */
    public function updatePassword(array $params): void
    {
        $userId = (int) AuthService::currentUser()['id'];
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $user = UserRepository::findById($userId);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            Flash::set('error', 'Current password is incorrect.');
            header('Location: /account');
            return;
        }
        $policyError = PasswordPolicyService::validate($new);
        if ($policyError !== null) {
            Flash::set('error', $policyError);
            header('Location: /account');
            return;
        }
        if ($new !== $confirm) {
            Flash::set('error', 'New passwords do not match.');
            header('Location: /account');
            return;
        }

        AuthService::changePassword($userId, $new);
        Flash::set('success', 'Password updated.');
        header('Location: /account');
    }
}
