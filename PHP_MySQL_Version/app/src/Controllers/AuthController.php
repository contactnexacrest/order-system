<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\EmailService;
use App\Services\PasswordPolicyService;

final class AuthController
{
    public function showLogin(array $params): void
    {
        if (AuthService::currentUserId()) {
            header('Location: /');
            return;
        }
        View::render('auth/login', [], 'layout/bare');
    }

    public function login(array $params): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            Flash::set('error', 'Email and password are both required.');
            header('Location: /login');
            return;
        }

        $result = AuthService::attemptLogin($email, $password);

        switch ($result['status']) {
            case 'ok':
                header('Location: ' . ($result['force_password_change'] ? '/force-password-change' : '/'));
                return;

            case 'requires_2fa':
                $_SESSION['_2fa_dev_code'] = $result['dev_code'] ?? '';
                header('Location: /2fa');
                return;

            case 'locked_out':
                Flash::set('error', 'Account locked until ' . $result['locked_until'] . ' after repeated failed attempts.');
                header('Location: /login');
                return;

            case 'account_disabled':
                Flash::set('error', 'This account has been disabled. Contact your administrator.');
                header('Location: /login');
                return;

            default:
                Flash::set('error', 'Invalid email or password.');
                header('Location: /login');
                return;
        }
    }

    public function show2fa(array $params): void
    {
        if (!\App\Services\TwoFactorService::pendingUserId()) {
            header('Location: /login');
            return;
        }
        View::render('auth/two_factor', [
            'devCode' => $_SESSION['_2fa_dev_code'] ?? '',
        ], 'layout/bare');
    }

    public function verify2fa(array $params): void
    {
        $code = trim((string) ($_POST['code'] ?? ''));
        $result = AuthService::completeTwoFactor($code);

        if ($result['status'] === 'ok') {
            unset($_SESSION['_2fa_dev_code']);
            header('Location: ' . ($result['force_password_change'] ? '/force-password-change' : '/'));
            return;
        }

        Flash::set('error', 'Invalid or expired verification code.');
        header('Location: /2fa');
    }

    public function showForcePasswordChange(array $params): void
    {
        View::render('auth/force_password_change', [], 'layout/bare');
    }

    public function forcePasswordChange(array $params): void
    {
        $user = AuthService::currentUser();
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $policyError = PasswordPolicyService::validate($new);
        if ($policyError !== null) {
            Flash::set('error', $policyError);
            header('Location: /force-password-change');
            return;
        }
        if ($new !== $confirm) {
            Flash::set('error', 'Passwords do not match.');
            header('Location: /force-password-change');
            return;
        }

        AuthService::changePassword((int) $user['id'], $new);
        Flash::set('success', 'Password updated.');
        header('Location: /');
    }

    public function showForgotPassword(array $params): void
    {
        View::render('auth/forgot_password', [], 'layout/bare');
    }

    /**
     * Always redirects to the same "if that address exists, we've sent a
     * link" flash regardless of whether the email matched an account —
     * an attacker sending this form a real vs. fake address must not be
     * able to tell the difference (user enumeration).
     */
    public function forgotPassword(array $params): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $genericMessage = "If {$email} matches an account, a password reset link has been sent to it. The link expires in 45 minutes.";

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('success', $genericMessage);
            header('Location: /login');
            return;
        }

        $user = UserRepository::findByEmail($email);

        if ($user && $user['is_active']) {
            // Cap at 5 requests per hour per account — a public,
            // unauthenticated form that triggers an outbound email is a
            // mail-bombing vector against anyone whose address you know.
            if (PasswordResetTokenRepository::countRecentForUser((int) $user['id'], 60) < 5) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + (45 * 60));
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;

                PasswordResetTokenRepository::create((int) $user['id'], $tokenHash, $expiresAt, $ip);

                $resetUrl = rtrim(Env::get('APP_URL', ''), '/') . '/reset-password/' . $rawToken;
                $body = "Hello {$user['name']},\n\n"
                    . "A password reset was requested for your NexaCrest Export Operations account ({$email}).\n\n"
                    . "To set a new password, open this link within 45 minutes:\n{$resetUrl}\n\n"
                    . "If you didn't request this, you can ignore this email — your password will not be changed.";

                EmailService::sendPlainText($email, 'Reset your NexaCrest password', $body, true);
                AuditLogRepository::log((int) $user['id'], 'PASSWORD_RESET_REQUESTED', 'users', (int) $user['id'], null, null, null, "Requested from IP {$ip}");
            }
        }

        Flash::set('success', $genericMessage);
        header('Location: /login');
    }

    public function showResetPassword(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $row = PasswordResetTokenRepository::findValidByHash(hash('sha256', $token));

        if (!$row) {
            Flash::set('error', 'This password reset link is invalid or has expired. Request a new one below.');
            header('Location: /forgot-password');
            return;
        }

        View::render('auth/reset_password', ['token' => $token], 'layout/bare');
    }

    public function resetPassword(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $row = PasswordResetTokenRepository::findValidByHash(hash('sha256', $token));

        if (!$row) {
            Flash::set('error', 'This password reset link is invalid or has expired. Request a new one below.');
            header('Location: /forgot-password');
            return;
        }

        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $policyError = PasswordPolicyService::validate($new);
        if ($policyError !== null) {
            Flash::set('error', $policyError);
            header('Location: /reset-password/' . $token);
            return;
        }
        if ($new !== $confirm) {
            Flash::set('error', 'Passwords do not match.');
            header('Location: /reset-password/' . $token);
            return;
        }

        AuthService::changePassword((int) $row['user_id'], $new);
        PasswordResetTokenRepository::markUsed((int) $row['id']);
        PasswordResetTokenRepository::invalidateAllForUser((int) $row['user_id']);
        AuditLogRepository::log((int) $row['user_id'], 'PASSWORD_RESET_VIA_EMAIL', 'users', (int) $row['user_id']);

        Flash::set('success', 'Password updated. Sign in with your new password.');
        header('Location: /login');
    }

    public function logout(array $params): void
    {
        AuthService::logout();
        header('Location: /login');
    }
}
