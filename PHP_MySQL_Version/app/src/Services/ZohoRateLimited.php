<?php

declare(strict_types=1);

namespace App\Services;

/** D-239: Zoho answered "too many requests", or this app's per-minute speed limit was reached. */
final class ZohoRateLimited extends ZohoSyncStop
{
}
