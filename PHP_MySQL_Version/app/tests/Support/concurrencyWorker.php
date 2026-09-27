<?php

declare(strict_types=1);

/**
 * Standalone worker script for concurrency regression tests (QA-5
 * CONC-01/02/03) — spawned as a real, separate OS process via proc_open()
 * so several of these genuinely run at once against the same database,
 * exercising true concurrent MySQL connections. A callback running inside
 * the single PHPUnit process (even async/interleaved) can't reproduce
 * that; this can.
 *
 * Usage: php concurrencyWorker.php client_unique
 *        php concurrencyWorker.php order_seq <clientId>
 * Prints the resulting number on stdout.
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
use App\Services\ReferenceNumberService;

Env::load(__DIR__ . '/../../.env');
putenv('DB_DATABASE=nexacrest_phpunit_test');
$_ENV['DB_DATABASE'] = 'nexacrest_phpunit_test';
$_SERVER['DB_DATABASE'] = 'nexacrest_phpunit_test';

$mode = $argv[1] ?? '';
if ($mode === 'client_unique') {
    echo ReferenceNumberService::generateClientUniqueNumber();
} elseif ($mode === 'order_seq') {
    $clientId = (int) ($argv[2] ?? 0);
    echo ReferenceNumberService::nextOrderSequenceForClient($clientId);
} else {
    fwrite(STDERR, "Unknown mode: {$mode}\n");
    exit(1);
}
