<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Flash;
use App\Services\TestModeService;

/**
 * Test Mode's two access gates (docs/schema.sql Section V). The Router has
 * no concept of global "before every route" middleware (App\Helpers\Router
 * — middleware is only ever attached per-route), so both gates are called
 * directly from public_html/index.php right before dispatch, by path
 * prefix, rather than threading a flag through every existing route
 * registration.
 */
final class TestModeGate
{
    /**
     * "Admin panel settings" — frozen while Test Mode is on, regardless of
     * role/permission (requirement: unable to update any setting,
     * immaterial of role). Deliberately does NOT include /test-mode itself
     * (must stay operable to turn Test Mode off) or /products (Product
     * Catalog — decided to stay editable: it's operational reference data
     * staff consult day to day, not site configuration).
     */
    private const FROZEN_PREFIXES = [
        '/settings',
        '/holidays',
        '/reference-docs',
        '/company-assets',
        '/signatories',
        '/super-admin',
        '/admin/permissions',
        '/admin/overrides',
        '/admin/field-protection',
        '/users',
    ];

    /**
     * Client-facing surfaces — blocked entirely (every method, not just
     * writes) while Test Mode is on: the public quotation-request intake
     * form and the whole client portal. "/client" as a prefix does not
     * collide with staff-side "/clients" or "/client-intake" —
     * matchesPrefix requires an exact match or a '/' boundary right after
     * the prefix, and both of those paths diverge from "/client" at the
     * very next character ('s', '-').
     */
    private const CLIENT_FACING_PREFIXES = ['/quotation-request', '/client'];

    public static function blockAdminWritesInTestMode(string $method, string $path): void
    {
        if ($method === 'GET' || !self::matchesPrefix($path, self::FROZEN_PREFIXES)) {
            return;
        }
        if (TestModeService::isEnabled()) {
            Flash::set('error', 'Test Mode is active — admin panel settings cannot be changed until it is disabled.');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));
            exit;
        }
    }

    public static function blockClientFacingInTestMode(string $path): void
    {
        if (!self::matchesPrefix($path, self::CLIENT_FACING_PREFIXES)) {
            return;
        }
        if (TestModeService::isEnabled()) {
            http_response_code(503);
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Temporarily Unavailable</title>'
                . '<style>body{font-family:sans-serif;max-width:640px;margin:80px auto;text-align:center;color:#17233A}'
                . 'h1{font-size:1.4rem}p{color:#5B6573}</style></head>'
                . '<body><h1>Temporarily Unavailable</h1>'
                . '<p>This site is undergoing internal testing and is not available right now. Please check back shortly.</p>'
                . '</body></html>';
            exit;
        }
    }

    /** @param string[] $prefixes */
    private static function matchesPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }
        return false;
    }
}
