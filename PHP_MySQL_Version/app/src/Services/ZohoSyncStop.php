<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner decision 2 Nov 2026 (D-239, "Zoho limits yes"): a reason to stop a
 * Zoho Books sync run at once — the daily allowance of API calls for this app
 * is used up, or Zoho answered "too many requests". Every per-record catch in
 * the sync services passes it on (rethrow()), so the run stops instead of
 * spending more calls on the remaining records; they stay waiting for the
 * next sync.
 */
class ZohoSyncStop extends \RuntimeException
{
    /** In a per-record catch: let a stop reason end the whole run. */
    public static function rethrow(\Throwable $e): void
    {
        if ($e instanceof self) {
            throw $e;
        }
    }
}
