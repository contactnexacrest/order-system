<?php
/**
 * Router script for PHP's built-in dev server ONLY (`php -S host:port _dev_router.php`).
 * Not used on Apache/Bluehost — there, .htaccess does this job. This file
 * exists purely so local testing matches Apache's actual behavior: serve a
 * real file/directory as-is, otherwise hand everything to index.php.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fullPath = __DIR__ . $path;

if ($path !== '/' && file_exists($fullPath) && !is_dir($fullPath)) {
    return false; // let the built-in server serve the real file
}

require __DIR__ . '/index.php';
