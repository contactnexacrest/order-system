<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\ClientPortalService;

/**
 * Gates every /client/* portal route. Structurally separate from
 * SessionAuth (staff): reads a different session key, and never grants
 * access based on a staff login or vice versa — a staff member browsing
 * /client/* while logged in as staff is NOT authenticated here at all.
 */
final class ClientAuth
{
    public static function required(): callable
    {
        return function (array $params): bool {
            if (!ClientPortalService::currentClientId()) {
                header('Location: /client/login');
                return false;
            }
            return true;
        };
    }
}
