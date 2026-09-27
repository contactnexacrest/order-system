<?php

declare(strict_types=1);

/**
 * Standalone worker script for CONC-04's concurrency regression test —
 * spawned as a real, separate OS process via proc_open() so several of
 * these genuinely generate a document for the same order at once, the
 * only way to exercise a true race from PHP's process-per-request model.
 *
 * Usage: php documentConcurrencyWorker.php <orderId>
 * Prints the resulting document_id on stdout, or "ERROR: <message>" and a
 * non-zero exit code on failure.
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
use App\Services\DocumentGenerationService;

Env::load(__DIR__ . '/../../.env');
putenv('DB_DATABASE=nexacrest_phpunit_test');
$_ENV['DB_DATABASE'] = 'nexacrest_phpunit_test';
$_SERVER['DB_DATABASE'] = 'nexacrest_phpunit_test';

$orderId = (int) ($argv[1] ?? 0);

try {
    $result = DocumentGenerationService::generate($orderId, 'QT', 1);
    echo $result['document_id'];
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
}
