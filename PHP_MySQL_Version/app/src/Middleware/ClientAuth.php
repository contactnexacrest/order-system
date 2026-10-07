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
            $timeoutMinutes = (int) (CompanySettingsRepository::get('session_timeout_minutes') ?? '30');
            $lastActivity = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;
            if ($lastActivity !== null && (time() - $lastActivity) > ($timeoutMinutes * 60)) {
                ClientPortalService::logout();
                header('Location: /client/login');
                return false;
            }
            $_SESSION[self::LAST_ACTIVITY_KEY] = time();

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
                return true;
            }

            // CP-06: a client (or their portal login specifically) deactivated
            // mid-session must lose access on their very next request — like
            // AUTH-10 for staff, is_active was otherwise only ever checked at
            // login time.
            $login = ClientLoginRepository::findById($clientId);
            if (!$login || !$login['is_active'] || !$login['client_is_active']) {
                ClientPortalService::logout();
                header('Location: /client/login');
                return false;
            }

            return true;
        };
    }
}
