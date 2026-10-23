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

// User-reported batch item 6: this bootstrap never set the default
// timezone, so every test ran under PHP's php.ini default (UTC) while
// the real app (via ../bootstrap.php) has run under Asia/Kolkata all
// along — a gap that let a real IST-vs-UTC mismatch between PHP and the
// MySQL session (see Database::connection()'s own fix) go completely
// uncovered by the test suite. Matching the real app's timezone here is
// what makes a test of that fix actually exercise production behaviour.
date_default_timezone_set('Asia/Kolkata');

Env::load(__DIR__ . '/../.env');
putenv('DB_DATABASE=nexacrest_phpunit_test');
$_ENV['DB_DATABASE'] = 'nexacrest_phpunit_test';
$_SERVER['DB_DATABASE'] = 'nexacrest_phpunit_test';

// External QA report TI-05: the suite was never isolated from a
// developer's own .env — with real SMTP credentials configured there,
// EmailService::sendPlainText() would actually attempt to send mail
// during a test run (and several tests failed outright because they
// assert the "no SMTP configured" dev-fallback path). Env::load() above
// already read whatever the real .env has, so this has to run after it
// to actually win; forcing it blank here is what makes every test run
// deterministic regardless of the machine it runs on.
putenv('SMTP_HOST=');
$_ENV['SMTP_HOST'] = '';
$_SERVER['SMTP_HOST'] = '';

\App\Tests\Support\DbTestCase::rebuildDatabase();

register_shutdown_function(function (): void {
    \App\Tests\Support\DbTestCase::dropDatabase();
});
