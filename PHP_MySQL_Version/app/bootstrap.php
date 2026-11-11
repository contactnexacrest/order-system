<?php

declare(strict_types=1);

/**
 * Application bootstrap.
 *
 * Deliberately does NOT hard-require vendor/autoload.php. Phase A (auth,
 * RBAC, company settings, asset management) uses nothing outside this
 * app's own App\ namespace, so it runs before you've ever touched Composer.
 * Phase B introduces Twig/DOMPDF/PHPWord/PHPMailer for document generation —
 * at that point, run `composer install` in this app/ folder once, and the
 * vendor autoloader below picks those classes up automatically. Nothing
 * needs to change in this file when that happens.
 */

error_reporting(E_ALL);

// PSR-4-ish autoloader for App\* — always available, no dependency on Composer.
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// Pick up Composer-installed packages once they exist (Phase B onward).
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

use App\Config\Env;

$envFile = __DIR__ . '/.env';
Env::load($envFile);

// Report errors to the log always; only render them to the screen in
// APP_ENV=local (never on Bluehost or any internet-facing environment).
ini_set('display_errors', Env::isLocal() ? '1' : '0');
ini_set('log_errors', '1');

// Give errors a fixed, discoverable home instead of whatever the host's
// default error_log happens to be (on shared hosting this is often outside
// the account's own writable space, or split across several log files
// depending on which handler caught it). Everything — PHP-level warnings/
// notices via log_errors above, and uncaught exceptions/fatal errors via
// the handlers below — lands in this one file.
$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$errorLogPath = $logDir . '/error.log';
ini_set('error_log', $errorLogPath);

set_exception_handler(function (\Throwable $e) use ($errorLogPath): void {
    error_log('[UNCAUGHT EXCEPTION] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    // QA Round 7, R7-N04: a briefly unreachable database answers 503 (try again), not 500.
    $response = \App\Helpers\DbResilience::errorResponse($e, Env::isLocal());
    if (!headers_sent()) {
        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
    }
    echo $response['body'];
});

// Fatal errors (parse errors, out-of-memory, ...) never reach
// set_exception_handler — this is the only hook that still fires for them,
// registered here so it's active for every request from the first line on.
register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[FATAL ERROR] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            http_response_code(500);
            echo Env::isLocal()
                ? '<pre>' . htmlspecialchars($error['message'] . ' in ' . $error['file'] . ':' . $error['line']) . '</pre>'
                : '<h1>500 — Something went wrong</h1><p>The error has been logged.</p>';
        }
    }
});

date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    $secure = !Env::isLocal(); // HTTPS cookie flag off only for local http:// testing
    // Owner decision D-283 (11 Nov 2026): PHP's own session life (garbage
    // collection, often 24 minutes on a host) and the cookie follow the
    // longest idle timeout set in Admin; session files live in the app's own
    // storage folder. Each area's own idle limit is then checked by its
    // middleware (SessionAuth / ClientAuth / AssociateAuth).
    try {
        $sessionLifetime = \App\Services\SessionTimeouts::configurePhpSession();
    } catch (\Throwable $e) {
        $sessionLifetime = 4 * 3600;
        ini_set('session.gc_maxlifetime', (string) ($sessionLifetime + 300));
    }
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('nexacrest_session');
    session_start();
    \App\Services\SessionTimeouts::refreshCookie($sessionLifetime, $secure); // rolling: an active person keeps the cookie
}
