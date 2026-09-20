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

date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    $secure = !Env::isLocal(); // HTTPS cookie flag off only for local http:// testing
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('nexacrest_session');
    session_start();
}
