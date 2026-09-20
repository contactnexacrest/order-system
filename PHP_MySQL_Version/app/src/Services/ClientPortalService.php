<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientLoginRepository;
use App\Repositories\ClientPasswordResetTokenRepository;
use App\Repositories\ClientRepository;
use App\Repositories\LoginAttemptRepository;

/**
 * Client portal auth — a structurally separate surface from staff/`users`
 * sessions (its own session key, its own login table). No client can log
 * in until ClientLoginRepository has a row for them, and that row is only
 * ever created here, called from the Stage 3 advance-cleared gate. A
 * client with more than one order does not get provisioned twice —
 * whichever order clears its advance first triggers it, and every
 * subsequent order's documents are simply visible under the same login.
 */
final class ClientPortalService
{
    private const SESSION_CLIENT_ID = '_client_portal_client_id';

    /**
     * Called from OrderController::clearAdvancePayment().
     * @return string 'provisioned' | 'already_provisioned' | 'no_email_on_file'
     *         — the caller surfaces 'no_email_on_file' to staff, since a
     *         client with no email can never receive their login otherwise,
     *         and that must not fail silently.
     */
    public static function provisionIfNeeded(int $clientId, int $orderId): string
    {
        if (ClientLoginRepository::findByClientId($clientId)) {
            return 'already_provisioned';
        }

        $client = ClientRepository::find($clientId);
        if (!$client || empty($client['email'])) {
            return 'no_email_on_file';
        }

        // Random password the client will never actually see or use —
        // force_password_change (default 1) means the very first thing
        // they do is set their own via the emailed link below, mirroring
        // the staff admin-created-account pattern.
        $randomPassword = bin2hex(random_bytes(16));
        ClientLoginRepository::create($clientId, password_hash($randomPassword, PASSWORD_DEFAULT), $orderId);
        AuditLogRepository::log(null, 'CLIENT_PORTAL_PROVISIONED', 'clients', $clientId, null, null, null, "Triggered by order #{$orderId} advance cleared");

        self::sendSetPasswordEmail($clientId, $client['email'], $client['company_legal_name']);

        return 'provisioned';
    }

    private static function sendSetPasswordEmail(int $clientId, string $email, string $companyName): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + (72 * 3600)); // longer than the staff 45-minute window — a client may not check email immediately
        ClientPasswordResetTokenRepository::create($clientId, $tokenHash, $expiresAt, null);

        $setUrl = rtrim(Env::get('APP_URL', ''), '/') . '/client/set-password/' . $rawToken;
        $body = "Hello,\n\n"
            . "Your advance payment has been received and cleared — thank you.\n\n"
            . "You can now log in to track your order and download your documents (Quotation, Proforma Invoice, and everything issued from here onward) at any time.\n\n"
            . "To set up your login, open this link within 72 hours:\n{$setUrl}\n\n"
            . "Your login email will be: {$email}\n\n"
            . "NexaCrest International Private Limited";

        EmailService::sendPlainText($email, 'Your NexaCrest order portal access', $body);
    }

    public static function attemptLogin(string $email, string $password): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $login = ClientLoginRepository::findByEmail($email);

        if (!$login) {
            LoginAttemptRepository::record(null, $email, $ip, false);
            return ['status' => 'invalid_credentials'];
        }
        if (!$login['client_is_active'] || !$login['is_active']) {
            LoginAttemptRepository::record(null, $email, $ip, false);
            return ['status' => 'account_disabled'];
        }
        if ($login['locked_until'] && strtotime($login['locked_until']) > time()) {
            return ['status' => 'locked_out', 'locked_until' => $login['locked_until']];
        }
        if (!password_verify($password, $login['password_hash'])) {
            ClientLoginRepository::incrementFailedLogins((int) $login['client_id']);
            if ((int) $login['failed_login_count'] + 1 >= 5) {
                $until = date('Y-m-d H:i:s', time() + (15 * 60));
                ClientLoginRepository::lockUntil((int) $login['client_id'], $until);
                return ['status' => 'locked_out', 'locked_until' => $until];
            }
            return ['status' => 'invalid_credentials'];
        }

        ClientLoginRepository::resetFailedLogins((int) $login['client_id']);
        session_regenerate_id(true);
        $_SESSION[self::SESSION_CLIENT_ID] = (int) $login['client_id'];
        ClientLoginRepository::updateLastLogin((int) $login['client_id']);
        AuditLogRepository::log(null, 'CLIENT_LOGIN_SUCCESS', 'clients', (int) $login['client_id']);

        return ['status' => 'ok', 'force_password_change' => (bool) $login['force_password_change']];
    }

    public static function currentClientId(): ?int
    {
        return $_SESSION[self::SESSION_CLIENT_ID] ?? null;
    }

    public static function currentClient(): ?array
    {
        $id = self::currentClientId();
        return $id ? ClientRepository::find($id) : null;
    }

    public static function logout(): void
    {
        $clientId = self::currentClientId();
        if ($clientId) {
            AuditLogRepository::log(null, 'CLIENT_LOGOUT', 'clients', $clientId);
        }
        unset($_SESSION[self::SESSION_CLIENT_ID]);
        session_regenerate_id(true);
    }

    public static function changePassword(int $clientId, string $newPassword): void
    {
        ClientLoginRepository::updatePassword($clientId, password_hash($newPassword, PASSWORD_DEFAULT), false);
        AuditLogRepository::log(null, 'CLIENT_PASSWORD_CHANGED', 'clients', $clientId);
    }
}
