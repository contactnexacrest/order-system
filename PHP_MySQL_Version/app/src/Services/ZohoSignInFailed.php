<?php

declare(strict_types=1);

namespace App\Services;

/**
 * QA ZOH-004: Zoho refused the Books refresh token (invalid_code, revoked,
 * mistyped). Stops the run at the first refusal — asking Zoho for a token
 * again for every waiting record only gets the token endpoint throttled.
 * Node twin: zohoApiBudget.ZohoSignInFailed.
 */
final class ZohoSignInFailed extends ZohoSyncStop
{
    public static function message(string $error): string
    {
        return 'Stopped: Zoho sign-in failed — Zoho refused the Zoho Books refresh token (' . $error . '). '
            . 'Reconnect: put a new refresh token from the Zoho API Console in Settings (Zoho Books refresh token) and run Test Zoho Books Connection; '
            . 'remaining records will be sent at the next sync.';
    }
}
