<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — mirrors ../bootstrap.php's autoloading and env
 * loading, but deliberately skips session_start() and the request-lifecycle
 * error handlers (nothing under test needs a PHP session, and PHPUnit has
 * its own error/exception reporting). Runs once per PHPUnit process, so
 * the disposable test database is rebuilt from scratch exactly once per
 * test run here, the PHPUnit equivalent of Jest's globalSetup.
 *
 * DB credentials (host/user/password) are read from the real app/.env —
 * never duplicated into a second file that would either go uncommitted
 * (breaking `composer test` on a fresh clone) or bake a real credential
 * into git history. Only DB_DATABASE is overridden here, to the disposable
 * nexacrest_phpunit_test database — never nexacrest, the interactively-used
 * dev/demo DB with real sample data.
 */

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/Support/DbTestCase.php';

use App\Config\Env;

Env::load(__DIR__ . '/../.env');
putenv('DB_DATABASE=nexacrest_phpunit_test');
$_ENV['DB_DATABASE'] = 'nexacrest_phpunit_test';
$_SERVER['DB_DATABASE'] = 'nexacrest_phpunit_test';

\App\Tests\Support\DbTestCase::rebuildDatabase();

register_shutdown_function(function (): void {
    \App\Tests\Support\DbTestCase::dropDatabase();
});
