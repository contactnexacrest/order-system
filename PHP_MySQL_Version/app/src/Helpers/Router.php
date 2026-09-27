<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Env;

/**
 * Minimal hand-rolled router. No framework lock-in (see ARCHITECTURE.md
 * section 2 — avoiding Laravel/Symfony assumptions that don't fit shared
 * hosting cleanly). A route is: method, path (exact match or {param}
 * segments), and a callable. Middleware is just callables run in order
 * before the handler; any of them may call Router::abort() to short-circuit.
 */
final class Router
{
    /**
     * QA-5 UP-02/AUTH-14: sitewide response headers, sent unconditionally
     * from dispatch() — the single chokepoint every response passes through
     * — rather than duplicated in every controller. Kept as a named
     * constant (rather than inlined in the header() calls) so the value is
     * directly assertable from a test — header()'s own effect isn't
     * observable under the CLI SAPI PHPUnit runs under.
     *
     * - X-Content-Type-Options: nosniff (UP-02) — an uploaded file
     *   (dispute/amendment/BL/PO evidence, chat attachments, client-portal
     *   payment screenshots) is served back with a Content-Type read from
     *   whatever the browser declared at upload time, not sniffed
     *   server-side. Without this, a file uploaded as "invoice.pdf" that's
     *   actually HTML/JS can be MIME-sniffed by the browser and
     *   rendered/executed instead of downloaded — stored XSS via upload.
     * - X-Frame-Options: DENY (AUTH-14) — nothing in this app is meant to
     *   be framed by another site. Without it, a disgruntled insider (or
     *   an outside attacker) can iframe a real authenticated page — e.g.
     *   an order's approve/close action — under invisible bait on another
     *   page and trick a logged-in staff member's click into landing on
     *   the real button underneath (clickjacking).
     * - Referrer-Policy: strict-origin-when-cross-origin (AUTH-14) — the
     *   default browser policy leaks the full request URL (path + query
     *   string) to any third-party resource a page loads, including a
     *   quotation/PI intake link's bearer token or a password-reset token
     *   if a page ever included an external image/link. This trims what's
     *   sent cross-origin to just the origin.
     */
    public const SECURITY_HEADERS = [
        'X-Content-Type-Options: nosniff',
        'X-Frame-Options: DENY',
        'Referrer-Policy: strict-origin-when-cross-origin',
    ];

    /**
     * QA-5 AUTH-14: HSTS tells the browser to only ever reach this host
     * over HTTPS, even if a bookmark/typed URL or an attacker-controlled
     * link points it at plain http:// — closing the window for a
     * man-in-the-middle to downgrade the connection and read/tamper with a
     * session cookie in transit. Kept out of SECURITY_HEADERS and
     * conditional on Env::isLocal() because it would otherwise break local
     * http:// development (a browser that's cached the header will refuse
     * to load http://localhost at all until it expires).
     */
    public static function hstsHeader(): ?string
    {
        if (Env::isLocal()) {
            return null;
        }
        return 'Strict-Transport-Security: max-age=31536000; includeSubDomains';
    }

    /** @var array<int, array{method:string, pattern:string, paramNames:array<int,string>, regex:string, handler:callable, middleware:array<int,callable>}> */
    private array $routes = [];

    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $path);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $path,
            'paramNames' => $paramNames,
            'regex'      => $regex,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        foreach (self::SECURITY_HEADERS as $h) {
            header($h);
        }
        if ($hsts = self::hstsHeader()) {
            header($hsts);
        }

        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $params = array_combine($route['paramNames'], $matches) ?: [];

                foreach ($route['middleware'] as $mw) {
                    $result = $mw($params);
                    if ($result === false) {
                        return; // middleware already sent a response (redirect/403/etc.)
                    }
                }

                ($route['handler'])($params);
                return;
            }
        }

        http_response_code(404);
        echo '404 Not Found';
    }
}
