<?php

declare(strict_types=1);

namespace App\Services;

/** D-239: the daily allowance of Zoho API calls for this app is used up — no call was made. */
final class ZohoBudgetExhausted extends ZohoSyncStop
{
}
