<?php

declare(strict_types=1);

/**
 * Owner decision D-301 (point 4, 11 Nov 2026): the daily USD → INR exchange rate.
 * Fetches today's market rate from Frankfurter (European Central Bank rates), or
 * from open.er-api.com when that fails, and keeps it in the history (fx_rates).
 * A fetch that fails keeps the rate in use (Pricing settings shows a warning); a
 * change of more than 2 % waits for the MD's OK. Prices on issued Quotations and
 * Proforma Invoices never change.
 *
 * The server must be able to reach api.frankfurter.app and open.er-api.com (HTTPS).
 *
 * Suggested crontab (once a day, 07:05 IST — the European Central Bank rate of the
 * previous working day, before the working day starts):
 *   CRON_TZ=Asia/Kolkata
 *   5 7 * * * php /path/to/app/cron/fetch_fx_rate.php
 */

require __DIR__ . '/../bootstrap.php';

use App\Services\FxRateService;

$result = FxRateService::fetchDaily();
echo $result['message'] . "\n";
exit($result['status'] === 'failed' ? 1 : 0);
