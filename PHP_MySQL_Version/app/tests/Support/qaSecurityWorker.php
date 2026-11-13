<?php

declare(strict_types=1);

/**
 * Worker for QaSecurityBatchNov2026Test (QA round 13 Nov 2026): spawned as a
 * real, separate OS process (proc_open) so several run against the same
 * database at the same moment. The parent passes the test database name in
 * the DB_DATABASE environment variable and a start time (microtime) that every
 * worker waits for, so the workers really overlap.
 *
 * Usage: php qaSecurityWorker.php <startAt> assoc_code <associateId> <code>
 *        php qaSecurityWorker.php <startAt> payment <userId> <orderId> <leg> <amount> <bankRef> <inrCredited> [<milestoneId>]
 * Prints a short result on stdout.
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

date_default_timezone_set('Asia/Kolkata');
$database = (string) getenv('DB_DATABASE');
Env::load(__DIR__ . '/../../.env');
putenv('DB_DATABASE=' . $database);
$_ENV['DB_DATABASE'] = $database;
$_SERVER['DB_DATABASE'] = $database;
putenv('SMTP_HOST=');
$_ENV['SMTP_HOST'] = '';
$_SERVER['SMTP_HOST'] = '';

$startAt = (float) ($argv[1] ?? 0);
$mode = $argv[2] ?? '';
// Warm the connection first, then wait for the common start time.
\App\Config\Database::connection();
while (microtime(true) < $startAt) {
    usleep(500);
}

if ($mode === 'assoc_code') {
    $_SESSION = ['_associate_portal_pending_id' => (int) $argv[3]];
    echo \App\Services\AssociatePortalService::verifyCode((string) $argv[4]) ? 'ok' : 'no';
} elseif ($mode === 'payment') {
    // HNT-003: one remittance recording, as the POST handler runs it.
    $_SESSION = ['_auth_user_id' => (int) $argv[3]];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $orderId = (int) $argv[4];
    $leg = (string) $argv[5];
    $prefix = $leg === 'milestone' ? '' : $leg . '_';
    $_POST = [
        $prefix . 'amount' => (string) $argv[6],
        ($leg === 'milestone' ? 'received_on' : $prefix . 'received_at') => date('Y-m-d'),
        $prefix . 'inr_credited' => (string) $argv[8],
        $prefix . 'bank_reference' => (string) $argv[7],
    ];
    ob_start();
    if ($leg === 'milestone') {
        (new \App\Controllers\PaymentMilestoneController())->record(['id' => (string) $orderId, 'milestoneId' => (string) $argv[9]]);
    } else {
        (new \App\Controllers\OrderController())->{'record' . ucfirst($leg) . 'Payment'}(['id' => (string) $orderId]);
    }
    ob_end_clean();
    echo json_encode($_SESSION['_flash'] ?? $_SESSION['flash'] ?? null);
} else {
    fwrite(STDERR, "Unknown mode: {$mode}\n");
    exit(1);
}
