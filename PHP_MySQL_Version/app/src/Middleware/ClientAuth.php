<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\ClientLoginRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Services\ClientPortalService;

/**
 * Gates every /client/* portal route. Structurally separate from
 * SessionAuth (staff): reads a different session key, and never grants
 * access based on a staff login or vice versa — a staff member browsing
 * /client/* while logged in as staff is NOT authenticated here at all.
 */
final class ClientAuth
{
    private const LAST_ACTIVITY_KEY = '_client_last_activity_ts';

    public static function required(): callable
    {
        return function (array $params): bool {
            $clientId = ClientPortalService::currentClientId();
            if (!$clientId) {
                header('Location: /client/login');
                return false;
            }

            // CP-12: the client portal never had an idle timeout at all —
            // an unattended browser (a shared office computer, a public
            // kiosk) stayed logged in to a buyer's own order/document
            // history forever. Reuses the same DB-driven setting
            // SessionAuth enforces for staff.
            // D-283: the buyers' own idle timeout (Admin › Settings › Security, default 2 hours).
            $lastActivity = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;
            if (\App\Services\SessionTimeouts::idleExpired('client', $lastActivity !== null ? (int) $lastActivity : null)) {
                ClientPortalService::logout();
                unset($_SESSION[self::LAST_ACTIVITY_KEY]);
                \App\Helpers\Flash::set('info', 'You were signed out after ' . \App\Services\SessionTimeouts::describeMinutes(\App\Services\SessionTimeouts::idleMinutes('client')) . ' without activity. Please log in again.');
                header('Location: /client/login');
                return false;
            }
            $_SESSION[self::LAST_ACTIVITY_KEY] = time();
            \App\Services\SessionTimeouts::markPage('client');

            // docs/schema.sql Section AV: a staff-impersonated session has
            // no client_logins row to check at all — it's a separate
            // channel, not a stand-in for the client's own password login
            // (that row may not even exist yet, e.g. before Stage 3). Only
            // the client record itself needs to still be active.
            if (ClientPortalService::isImpersonating()) {
                $client = ClientRepository::find($clientId);
                if (!$client || !$client['is_active']) {
                    ClientPortalService::endImpersonation();
                    header('Location: /client/login');
                    return false;
                }
                // QA ACC-9: the staff member behind it must still be an active user.
                $staff = \App\Repositories\UserRepository::findById((int) ClientPortalService::impersonatedByStaffId());
                if ($staff === null || !(int) $staff['is_active']) {
                    ClientPortalService::endImpersonation();
                    header('Location: /client/login');
                    return false;
                }
                // QA ACC-9: logging in as a client is for looking only — staff do not
                // acknowledge, upload, report payments, raise disputes or reorder as the buyer.
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                    $postPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
                    if (!in_array($postPath, ['/client/end-impersonation', '/client/logout', '/client/keep-alive'], true)) {
                        \App\Helpers\Flash::set('error', 'You are logged in as this client to look only: nothing can be saved for the buyer. Use "End impersonation" first.');
                        header('Location: ' . \App\Helpers\Router::safeRedirectBack());
                        return false;
                    }
                }
                return true;
            }

            // CP-06: a client (or their portal login specifically) deactivated
            // mid-session must lose access on their very next request — like
            // AUTH-10 for staff, is_active was otherwise only ever checked at
            // login time.
            $login = ClientLoginRepository::findById($clientId);
            // QA ACC-10: a password changed or reset since this session logged in ends it.
            if (!$login || !$login['is_active'] || !$login['client_is_active'] || !ClientPortalService::sessionPasswordStillCurrent((string) $login['password_hash'])) {
                ClientPortalService::logout();
                header('Location: /client/login');
                return false;
            }

            return true;
        };
    }
}
