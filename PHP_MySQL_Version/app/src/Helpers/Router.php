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

                try {
                    foreach ($route['middleware'] as $mw) {
                        $result = $mw($params);
                        if ($result === false) {
                            return; // middleware already sent a response (redirect/403/etc.)
                        }
                    }

                    ($route['handler'])($params);
                } catch (\PDOException $e) {
                    // External QA report R-01/R-03/R-04/R-09/R-11/R-12: no
                    // form anywhere validated its fields against what the
                    // DB would actually accept — a too-long value, an
                    // impossible date (30-Feb), a non-numeric amount, or an
                    // id referencing a row that doesn't exist all crashed
                    // the request as an uncaught PDOException: a raw HTTP
                    // 500, no flash message, every field the user typed
                    // lost. Each SQLSTATE below is MySQL's own unambiguous
                    // signal for exactly one of those — safe to treat
                    // generically everywhere in this app, since every
                    // write here comes from a user-submitted form field.
                    // This is the single chokepoint every affected form's
                    // request already passes through, so one fix here
                    // closes the crash for all of them at once rather than
                    // requiring an individual check added to every form (a
                    // real, separate, much larger follow-up — see R-01's
                    // own fix direction for the ideal per-field/HTML
                    // maxlength UX this does not replace).
                    $message = self::MESSAGE_FOR_SQLSTATE[$e->getCode()] ?? null;
                    if ($message !== null) {
                        self::handleBadInput($message);
                        return;
                    }
                    throw $e;
                }
                return;
            }
        }

        http_response_code(404);
        echo '404 Not Found';
    }

    /** @var array<string,string> SQLSTATE => friendly message */
    private const MESSAGE_FOR_SQLSTATE = [
        // "String data, right truncated" — a field longer than its column (R-01/R-12).
        '22001' => 'One of the fields you entered is too long for this form — please shorten it and try again. Nothing was saved.',
        // "Invalid datetime format" — covers both an impossible date like
        // 30-Feb (R-03/R-09/R-11) and a non-numeric amount in a DECIMAL
        // column (R-09) — MySQL uses this same SQLSTATE for both.
        '22007' => "One of the dates or numbers you entered isn't valid — please check it and try again. Nothing was saved.",
        // "Integrity constraint violation" — either a duplicate value where
        // one must be unique, or an id referring to a row that doesn't
        // exist (R-04).
        '23000' => "That couldn't be saved — either this already exists, or it refers to something that no longer exists. Please check your input and try again.",
    ];

    private static function handleBadInput(string $message): void
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        if ($requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json')) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['error' => $message]);
            return;
        }

        Flash::set('error', $message);
        if (!empty($_POST)) {
            Flash::setOld($_POST);
        }
        header('Location: ' . self::safeRedirectBack());
    }

    /**
     * Only ever redirects back to a same-origin relative path — the
     * Referer header is client-supplied and must never be trusted for an
     * absolute/external redirect target (open-redirect risk).
     */
    private static function safeRedirectBack(): string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? null;
        if ($referer !== '' && $host !== null) {
            // A scheme-relative Referer ("//evil.example.com/phish") still
            // parses PHP_URL_PATH as "/phish" alone — only trust the path
            // when the Referer's own host actually matches this request's.
            if (parse_url($referer, PHP_URL_HOST) === $host) {
                $path = parse_url($referer, PHP_URL_PATH);
                if (is_string($path) && str_starts_with($path, '/')) {
                    $query = parse_url($referer, PHP_URL_QUERY);
                    return $path . ($query ? "?{$query}" : '');
                }
            }
        }
        return '/';
    }
}
