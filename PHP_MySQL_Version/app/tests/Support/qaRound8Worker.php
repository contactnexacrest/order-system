<?php

declare(strict_types=1);

/**
 * QA Round 8 (R8-N11, R8-N12): a separate OS process that saves one GST
 * refund credit or one associate claw-back, so several really run at once
 * against the same database (see concurrencyWorker.php for why a process).
 *
 * Usage: php qaRound8Worker.php gst_credit <claimId> <amount> <userId>
 *        php qaRound8Worker.php clawback <payoutId> <amount>
 * Prints "saved" or "refused".
 */

require __DIR__ . '/../../vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Config\Env;
use App\Repositories\GstRefundRepository;
use App\Repositories\IncentiveRepository;

Env::load(__DIR__ . '/../../.env');
putenv('DB_DATABASE=nexacrest_phpunit_test');
$_ENV['DB_DATABASE'] = 'nexacrest_phpunit_test';
$_SERVER['DB_DATABASE'] = 'nexacrest_phpunit_test';

$mode = $argv[1] ?? '';
if ($mode === 'gst_credit') {
    $r = GstRefundRepository::addCreditWithinClaim((int) $argv[2], (float) $argv[3], 0.0, '', date('Y-m-d'), 'UTR' . getmypid(), 'provisional', (int) $argv[4]);
    echo $r['id'] === null ? 'refused' : 'saved';
} elseif ($mode === 'clawback') {
    $r = IncentiveRepository::addClawbackWithinPaid((int) $argv[2], (float) $argv[3], date('Y-m'), 'QA R8 concurrency test');
    echo $r['id'] === null ? 'refused' : 'saved';
} else {
    fwrite(STDERR, "Unknown mode: {$mode}\n");
    exit(1);
}
