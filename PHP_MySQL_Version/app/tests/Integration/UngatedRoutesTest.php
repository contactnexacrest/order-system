<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\ClientPasswordResetTokenRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\UserRepository;
use App\Services\PermissionService;
use App\Services\SuperAdminService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, R5-14 (second half): RouteMatrixTest covers every route that
 * needs a permission. The 88 routes below need none — they are guarded by
 * sign-in (staff or client), by Super Admin, by a CSRF token, or are
 * public token links — and the QA "Missing Test Cases" sheet listed 30 of
 * them as tested on one stack only, or not at all. This drives every one
 * of them through the real Router and its real middleware:
 *  - the list itself is pinned, so a new route without a permission check
 *    fails here until it is added (and tested);
 *  - signed out, every staff route goes to /login and every client route
 *    to /client/login before its handler runs; a staff sign-in does not
 *    open the client portal and a client sign-in does not open staff pages;
 *  - every Super Admin route refuses an Admin (403);
 *  - every POST is refused without a CSRF token, or with a wrong one;
 *  - with the guards passed, no route gives a server error for an unknown
 *    id or token, and every page without a {param} opens;
 *  - the sign-in, password and Super Admin flows do what they say.
 * The Node suite has the same test (ungatedRoutes.test.js), with the same list.
 */
final class UngatedRoutesTest extends DbTestCase
{
    /** Every route without a permission check: method, path ({} = a parameter), guard, +csrf. */
    private const EXPECTED = [
        'GET / staff',
        'GET /2fa public',
        'GET /account staff',
        'GET /buyer-action/{} public',
        'GET /buyer-action/{}/buyer-po-document public',
        'GET /buyer-action/{}/order-confirmation public',
        'GET /buyer-action/{}/proforma-invoice public',
        'GET /client client',
        'GET /client/account client',
        'GET /client/documents/{}/download client',
        'GET /client/forgot-password public',
        'GET /client/login public',
        'GET /client/orders/{} client',
        'GET /client/orders/{}/comment-attachments/{}/download client',
        'GET /client/orders/{}/payment-reports/{}/screenshot client',
        'GET /client/orders/{}/reorder client',
        'GET /client/set-password/{} public',
        'GET /company-assets/preview staff',
        'GET /force-password-change staff',
        'GET /forgot-password public',
        'GET /gallery staff',
        'GET /gallery/file staff',
        'GET /getting-started staff',
        'GET /login public',
        'GET /notifications staff',
        'GET /payment-action/{} public',
        'GET /payment-action/{}/document public',
        'GET /pi-details/{} public',
        'GET /quotation-details public',
        'GET /quotation-details/edit/{} public',
        'GET /reference-docs staff',
        'GET /reference-docs/custom/{} staff',
        'GET /reference-docs/custom/{}/download staff',
        'GET /reference-docs/{} staff',
        'GET /reset-password/{} public',
        'GET /reviews staff',
        'GET /sop staff',
        'GET /sop-assets/{} staff',
        'GET /sop/{} staff',
        'GET /super-admin super-admin',
        'GET /supplier-action/{} public',
        'GET /supplier-action/{}/po-document public',
        'GET /test-mode super-admin',
        'GET /vendor-details/{} public',
        'POST /2fa public +csrf',
        'POST /account/notifications staff +csrf',
        'POST /account/password staff +csrf',
        'POST /account/signature staff +csrf',
        'POST /account/ui-template staff +csrf',
        'POST /buyer-action/{}/acknowledge-oc public +csrf',
        'POST /buyer-action/{}/buyer-po public +csrf',
        'POST /buyer-action/{}/report-payment public +csrf',
        'POST /client/account/password client +csrf',
        'POST /client/end-impersonation public +csrf',
        'POST /client/forgot-password public +csrf',
        'POST /client/login public +csrf',
        'POST /client/logout public +csrf',
        'POST /client/orders/{}/acknowledge-oc client +csrf',
        'POST /client/orders/{}/buyer-po client +csrf',
        'POST /client/orders/{}/comments client +csrf',
        'POST /client/orders/{}/complaints client +csrf',
        'POST /client/orders/{}/complaints/{}/replies client +csrf',
        'POST /client/orders/{}/disputes client +csrf',
        'POST /client/orders/{}/feedback client +csrf',
        'POST /client/orders/{}/reorder client +csrf',
        'POST /client/orders/{}/report-payment client +csrf',
        'POST /client/set-password/{} public +csrf',
        'POST /email-log/{}/cancel staff +csrf',
        'POST /force-password-change staff +csrf',
        'POST /forgot-password public +csrf',
        'POST /login public +csrf',
        'POST /logout public +csrf',
        'POST /payment-action/{}/report public +csrf',
        'POST /pi-details/{} public +csrf',
        'POST /quotation-details/edit/{} public +csrf',
        'POST /quotation-details/submit public +csrf',
        'POST /reset-password/{} public +csrf',
        'POST /reviews/{}/approve staff +csrf',
        'POST /reviews/{}/reject staff +csrf',
        'POST /super-admin/delegations super-admin +csrf',
        'POST /super-admin/delegations/{}/revoke super-admin +csrf',
        'POST /super-admin/set-permanent super-admin +csrf',
        'POST /supplier-action/{}/acknowledgment public +csrf',
        'POST /test-mode/delete-test-data super-admin +csrf',
        'POST /test-mode/disable super-admin +csrf',
        'POST /test-mode/enable super-admin +csrf',
        'POST /test-mode/test-email super-admin +csrf',
        'POST /vendor-details/{} public +csrf',
    ];

    private const PASSWORD = 'UngatedRoutes123!';
    private const CSRF = 'ungated-routes-csrf-token';

    private static ?Router $router = null;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__ungated-routes-build__';
            ob_start();
            require dirname(__DIR__, 3) . '/public_html/index.php';
            ob_end_clean();
            restore_exception_handler(); // the one app/bootstrap.php installs
            self::$router = $router;
        }
        return self::$router;
    }

    /**
     * Every route in index.php without a PermissionCheck, with the class
     * name of each of its middleware in order.
     *
     * @return array<int, array{method: string, pattern: string, guard: string, csrf: bool, middleware: array<int, string>}>
     */
    private static function routes(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/index.php');
        preg_match_all("#\\\$router->(get|post)\\('([^']+)',\\s*\\[[^\\]]*\\](?:,\\s*\\[(.*?)\\])?\\);#", $source, $matches, PREG_SET_ORDER);
        $routes = [];
        foreach ($matches as $m) {
            $middleware = $m[3] ?? '';
            if (str_contains($middleware, 'PermissionCheck')) {
                continue;
            }
            preg_match_all('#(\w+)::\w+\(#', $middleware, $names);
            $guard = match (true) {
                in_array('SuperAdminOnly', $names[1], true) => 'super-admin',
                in_array('ClientAuth', $names[1], true) => 'client',
                in_array('SessionAuth', $names[1], true) => 'staff',
                default => 'public',
            };
            $routes[] = [
                'method' => strtoupper($m[1]),
                'pattern' => $m[2],
                'guard' => $guard,
                'csrf' => in_array('CsrfCheck', $names[1], true),
                'middleware' => $names[1],
            ];
        }
        return $routes;
    }

    private static function concrete(string $pattern): string
    {
        return (string) preg_replace('#\{[^}]+\}#', '999999', $pattern);
    }

    private function signInStaff(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => (int) UserRepository::findById($userId)['session_version']];
        PermissionService::resetCache();
    }

    private function signInClient(int $clientId): void
    {
        $_SESSION = ['_client_portal_client_id' => $clientId];
    }

    private function signOut(): void
    {
        $_SESSION = [];
        PermissionService::resetCache();
    }

    /** Signs in whoever the route's guard lets through; signed out for a public route. */
    private function signInFor(string $guard): void
    {
        match ($guard) {
            'staff' => $this->signInStaff($this->adminId()),
            'super-admin' => $this->signInStaff($this->superAdminId()),
            'client' => $this->signInClient($this->clientWithLogin()),
            default => $this->signOut(),
        };
    }

    private ?int $admin = null;
    private ?int $superAdmin = null;
    private ?int $client = null;

    private function adminId(): int
    {
        return $this->admin ??= $this->userWithPassword('Admin');
    }

    private function superAdminId(): int
    {
        if ($this->superAdmin === null) {
            $this->superAdmin = $this->userWithPassword('Admin');
            Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $this->superAdmin]);
        }
        return $this->superAdmin;
    }

    private function clientWithLogin(): int
    {
        if ($this->client === null) {
            $this->client = $this->createTestClient('ungated-' . bin2hex(random_bytes(4)) . '@nexacrest.test');
            Database::connection()->prepare('INSERT INTO client_logins (client_id, password_hash, is_active, force_password_change) VALUES (:cid, :hash, 1, 0)')
                ->execute(['cid' => $this->client, 'hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        }
        return $this->client;
    }

    private function userWithPassword(string $role): int
    {
        $userId = $this->createTestUser($role);
        UserRepository::updatePassword($userId, password_hash(self::PASSWORD, PASSWORD_DEFAULT), false);
        return $userId;
    }

    private function prepareRequest(string $method, string $path, array $post): void
    {
        $_POST = $post;
        $_GET = [];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        unset($_SERVER['CONTENT_LENGTH']);
        http_response_code(200);
    }

    /** A POST body carrying the session's CSRF token. */
    private function withCsrf(array $post = []): array
    {
        $_SESSION['_csrf_token'] = self::CSRF;
        return ['_csrf' => self::CSRF] + $post;
    }

    /** @return array{0: int, 1: string} the response code and body, through the whole route */
    private function dispatch(string $method, string $path, array $post = []): array
    {
        $this->prepareRequest($method, $path, $post);
        ob_start();
        try {
            self::router()->dispatch($method, $path);
        } finally {
            $body = (string) ob_get_clean();
        }
        return [(int) http_response_code(), $body];
    }

    /**
     * Runs only the route's middleware, in order, and returns the class
     * name of the one that refused the request — or null when every one
     * let it through. The handler itself never runs.
     */
    private function refusedBy(array $route, array $post = []): ?string
    {
        $path = self::concrete($route['pattern']);
        $this->prepareRequest($route['method'], $path, $post);
        foreach ((new \ReflectionProperty(Router::class, 'routes'))->getValue(self::router()) as $registered) {
            if ($registered['method'] !== $route['method'] || $registered['pattern'] !== $route['pattern']) {
                continue;
            }
            self::assertCount(count($route['middleware']), $registered['middleware'], "{$route['method']} {$route['pattern']}");
            preg_match($registered['regex'], $path, $values);
            $params = array_combine($registered['paramNames'], array_slice($values, 1)) ?: [];
            foreach ($registered['middleware'] as $i => $middleware) {
                ob_start();
                try {
                    $passed = $middleware($params);
                } finally {
                    ob_end_clean();
                }
                if ($passed === false) {
                    return $route['middleware'][$i];
                }
            }
            return null;
        }
        self::fail("{$route['method']} {$route['pattern']} is not registered");
    }

    private static function label(array $route): string
    {
        return "{$route['method']} {$route['pattern']}";
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    public function testTheRoutesWithoutAPermissionCheckAreExactlyTheOnesTestedHere(): void
    {
        $actual = array_map(
            static fn (array $r): string => $r['method'] . ' ' . preg_replace('#\{[^}]+\}#', '{}', $r['pattern']) . ' ' . $r['guard'] . ($r['csrf'] ? ' +csrf' : ''),
            self::routes()
        );
        sort($actual);
        $expected = self::EXPECTED;
        sort($expected);
        self::assertSame($expected, $actual, 'A route without a permission check was added, removed or re-guarded — update EXPECTED here and in ungatedRoutes.test.js, and test it.');
    }

    public function testEveryPostIsCsrfChecked(): void
    {
        $unchecked = array_filter(self::routes(), static fn (array $r): bool => $r['method'] === 'POST' && !$r['csrf']);
        self::assertSame([], array_values(array_map([self::class, 'label'], $unchecked)));
    }

    public function testSignedOutEveryStaffRouteGoesToLoginBeforeItsHandlerRuns(): void
    {
        $wrong = [];
        $checked = 0;
        foreach (self::routes() as $route) {
            if (!in_array($route['guard'], ['staff', 'super-admin'], true)) {
                continue;
            }
            $this->signOut();
            $refusedBy = $this->refusedBy($route);
            $this->signOut();
            [$code, $body] = $this->dispatch($route['method'], self::concrete($route['pattern']));
            if ($refusedBy !== 'SessionAuth' || $code !== 302 || trim($body) !== '') {
                $wrong[] = self::label($route) . " → refused by {$refusedBy}, {$code}";
            }
            $checked++;
        }
        self::assertSame(33, $checked);
        self::assertSame([], $wrong);
    }

    public function testSignedOutEveryClientRouteGoesToTheClientLogin(): void
    {
        $wrong = [];
        $checked = 0;
        foreach (self::routes() as $route) {
            if ($route['guard'] !== 'client') {
                continue;
            }
            $this->signOut();
            $refusedBy = $this->refusedBy($route);
            $this->signOut();
            [$code, $body] = $this->dispatch($route['method'], self::concrete($route['pattern']));
            if ($refusedBy !== 'ClientAuth' || $code !== 302 || trim($body) !== '') {
                $wrong[] = self::label($route) . " → refused by {$refusedBy}, {$code}";
            }
            $checked++;
        }
        self::assertSame(17, $checked);
        self::assertSame([], $wrong);
    }

    public function testAStaffSignInDoesNotOpenTheClientPortalAndAClientSignInDoesNotOpenStaffPages(): void
    {
        $wrong = [];
        foreach (self::routes() as $route) {
            if ($route['guard'] === 'client') {
                $this->signInStaff($this->adminId());
                $expected = 'ClientAuth';
            } elseif ($route['guard'] === 'staff') {
                $this->signInClient($this->clientWithLogin());
                $expected = 'SessionAuth';
            } else {
                continue;
            }
            $refusedBy = $this->refusedBy($route);
            if ($refusedBy !== $expected) {
                $wrong[] = self::label($route) . " → refused by {$refusedBy}";
            }
        }
        self::assertSame([], $wrong);
    }

    public function testEverySuperAdminRouteRefusesAnAdmin(): void
    {
        $wrong = [];
        $checked = 0;
        foreach (self::routes() as $route) {
            if ($route['guard'] !== 'super-admin') {
                continue;
            }
            $this->signInStaff($this->adminId());
            $refusedBy = $this->refusedBy($route, $this->withCsrf());
            [$code] = $this->dispatch($route['method'], self::concrete($route['pattern']), $this->withCsrf());
            if ($refusedBy !== 'SuperAdminOnly' || $code !== 403) {
                $wrong[] = self::label($route) . " → refused by {$refusedBy}, {$code}";
            }
            $checked++;
        }
        self::assertSame(9, $checked);
        self::assertSame([], $wrong);
    }

    public function testEveryPostIsRefusedWithoutACsrfTokenOrWithAWrongOne(): void
    {
        $wrong = [];
        $checked = 0;
        foreach (self::routes() as $route) {
            if ($route['method'] !== 'POST') {
                continue;
            }
            foreach ([[], ['_csrf' => 'not-the-session-token']] as $post) {
                $this->signInFor($route['guard']);
                $_SESSION['_csrf_token'] = self::CSRF;
                $refusedBy = $this->refusedBy($route, $post);
                if ($refusedBy !== 'CsrfCheck') {
                    $wrong[] = self::label($route) . ' ' . ($post === [] ? 'no token' : 'wrong token') . " → refused by {$refusedBy}";
                }
            }
            // and with the right token the same guards let it through
            $this->signInFor($route['guard']);
            if (($refusedBy = $this->refusedBy($route, $this->withCsrf())) !== null) {
                $wrong[] = self::label($route) . " right token → refused by {$refusedBy}";
            }
            $checked++;
        }
        self::assertSame(44, $checked);
        self::assertSame([], $wrong);
    }

    public function testNoRouteGivesAServerErrorForAnUnknownIdOrToken(): void
    {
        $failed = [];
        $checked = 0;
        foreach (self::routes() as $route) {
            if (!str_contains($route['pattern'], '{')) {
                continue;
            }
            $this->signInFor($route['guard']);
            try {
                [$code] = $this->dispatch($route['method'], self::concrete($route['pattern']), $route['method'] === 'POST' ? $this->withCsrf() : []);
                if ($code >= 500) {
                    $failed[] = self::label($route) . " → {$code}";
                }
            } catch (\Throwable $e) {
                $failed[] = self::label($route) . ' → ' . get_class($e) . ': ' . $e->getMessage();
            }
            Flash::pull();
            $checked++;
        }
        self::assertSame(46, $checked);
        self::assertSame([], $failed);
    }

    public function testEveryPageWithoutParametersOpens(): void
    {
        $failed = [];
        $checked = 0;
        foreach (self::routes() as $route) {
            if ($route['method'] !== 'GET' || str_contains($route['pattern'], '{')) {
                continue;
            }
            $this->signInFor($route['guard']);
            try {
                [$code, $body] = $this->dispatch('GET', $route['pattern']);
                $redirectsOnPurpose = in_array($route['pattern'], ['/2fa', '/gallery/file', '/company-assets/preview'], true);
                if ($code >= 500 || ($code !== 200 && !$redirectsOnPurpose) || ($code === 200 && trim($body) === '')) {
                    $failed[] = self::label($route) . " → {$code}";
                }
            } catch (\Throwable $e) {
                $failed[] = self::label($route) . ' → ' . get_class($e) . ': ' . $e->getMessage();
            }
            Flash::pull();
            $checked++;
        }
        self::assertSame(21, $checked);
        self::assertSame([], $failed);
    }

    public function testLoginRefusesAWrongPasswordAndSignsInWithTheRightOne(): void
    {
        $userId = $this->adminId();
        $email = (string) UserRepository::findById($userId)['email'];

        $this->signOut();
        $this->dispatch('POST', '/login', $this->withCsrf(['email' => $email, 'password' => 'not-the-password']));
        self::assertArrayNotHasKey('_auth_user_id', $_SESSION);
        self::assertSame('Invalid email or password.', Flash::pull()[0]['message'] ?? null);

        $this->dispatch('POST', '/login', $this->withCsrf(['email' => $email, 'password' => self::PASSWORD]));
        self::assertSame($userId, $_SESSION['_auth_user_id'] ?? null);
    }

    public function testLogoutEndsTheStaffSession(): void
    {
        $this->signInStaff($this->adminId());
        [$code] = $this->dispatch('POST', '/logout', $this->withCsrf());
        self::assertSame(302, $code);
        self::assertArrayNotHasKey('_auth_user_id', $_SESSION);
    }

    public function testTheTwoFactorPageNeedsAPendingSignInAndRefusesAWrongCode(): void
    {
        $this->signOut();
        [$code, $body] = $this->dispatch('GET', '/2fa');
        self::assertSame([302, ''], [$code, trim($body)], 'no sign-in in progress → back to /login, nothing shown');

        $userId = $this->adminId();
        $_SESSION['_2fa_pending'] = ['user_id' => $userId, 'method' => 'email', 'code_hash' => password_hash('123456', PASSWORD_DEFAULT), 'expires_at' => time() + 300, 'attempts' => 0];
        [$code] = $this->dispatch('GET', '/2fa');
        self::assertSame(200, $code);

        $this->dispatch('POST', '/2fa', $this->withCsrf(['code' => '654321']));
        self::assertArrayNotHasKey('_auth_user_id', $_SESSION);
        self::assertSame('Invalid or expired verification code.', Flash::pull()[0]['message'] ?? null);

        $this->dispatch('POST', '/2fa', $this->withCsrf(['code' => '123456']));
        self::assertSame($userId, $_SESSION['_auth_user_id'] ?? null);
    }

    public function testForcePasswordChangeRefusesAMismatchAndSavesAMatch(): void
    {
        $userId = $this->adminId();
        $before = (string) UserRepository::findById($userId)['password_hash'];

        $this->signInStaff($userId);
        $this->dispatch('POST', '/force-password-change', $this->withCsrf(['new_password' => 'BrandNewPass123!', 'confirm_password' => 'BrandNewPass124!']));
        self::assertSame('Passwords do not match.', Flash::pull()[0]['message'] ?? null);
        self::assertSame($before, (string) UserRepository::findById($userId)['password_hash']);

        $this->signInStaff($userId);
        $this->dispatch('POST', '/force-password-change', $this->withCsrf(['new_password' => 'BrandNewPass123!', 'confirm_password' => 'BrandNewPass123!']));
        self::assertTrue(password_verify('BrandNewPass123!', (string) UserRepository::findById($userId)['password_hash']));
    }

    public function testAPasswordResetLinkWorksOnceAndAnUnknownOneIsRefused(): void
    {
        $this->signOut();
        $this->dispatch('POST', '/reset-password/not-a-real-token', $this->withCsrf(['new_password' => 'ResetPass12345!', 'confirm_password' => 'ResetPass12345!']));
        self::assertStringContainsString('invalid or has expired', Flash::pull()[0]['message'] ?? '');

        $userId = $this->adminId();
        $token = bin2hex(random_bytes(32));
        PasswordResetTokenRepository::create($userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + 600), null);

        [$code] = $this->dispatch('GET', "/reset-password/{$token}");
        self::assertSame(200, $code);
        $this->dispatch('POST', "/reset-password/{$token}", $this->withCsrf(['new_password' => 'ResetPass12345!', 'confirm_password' => 'ResetPass12345?']));
        self::assertSame('Passwords do not match.', Flash::pull()[0]['message'] ?? null);

        $this->dispatch('POST', "/reset-password/{$token}", $this->withCsrf(['new_password' => 'ResetPass12345!', 'confirm_password' => 'ResetPass12345!']));
        self::assertTrue(password_verify('ResetPass12345!', (string) UserRepository::findById($userId)['password_hash']));
        Flash::pull();

        $this->dispatch('POST', "/reset-password/{$token}", $this->withCsrf(['new_password' => 'SecondTry12345!', 'confirm_password' => 'SecondTry12345!']));
        self::assertStringContainsString('invalid or has expired', Flash::pull()[0]['message'] ?? '', 'a used link must not work twice');
        self::assertTrue(password_verify('ResetPass12345!', (string) UserRepository::findById($userId)['password_hash']));
    }

    public function testClientLoginLogoutAndSetPasswordLink(): void
    {
        $clientId = $this->clientWithLogin();
        $email = (string) Database::connection()->query('SELECT email FROM clients WHERE id = ' . $clientId)->fetchColumn();

        $this->signOut();
        $this->dispatch('POST', '/client/login', $this->withCsrf(['email' => $email, 'password' => 'not-the-password']));
        self::assertArrayNotHasKey('_client_portal_client_id', $_SESSION);
        self::assertSame('Incorrect email or password.', Flash::pull()[0]['message'] ?? null);

        $this->dispatch('POST', '/client/login', $this->withCsrf(['email' => $email, 'password' => self::PASSWORD]));
        self::assertSame($clientId, $_SESSION['_client_portal_client_id'] ?? null);

        $this->dispatch('POST', '/client/logout', $this->withCsrf());
        self::assertArrayNotHasKey('_client_portal_client_id', $_SESSION);

        $this->dispatch('GET', '/client/set-password/not-a-real-token');
        self::assertStringContainsString('invalid or has expired', Flash::pull()[0]['message'] ?? '');

        $token = bin2hex(random_bytes(32));
        ClientPasswordResetTokenRepository::create($clientId, hash('sha256', $token), date('Y-m-d H:i:s', time() + 600), null);
        $this->dispatch('POST', "/client/set-password/{$token}", $this->withCsrf(['new_password' => 'ClientNew12345!', 'confirm_password' => 'ClientNew12345!']));
        $this->dispatch('POST', '/client/login', $this->withCsrf(['email' => $email, 'password' => 'ClientNew12345!']));
        self::assertSame($clientId, $_SESSION['_client_portal_client_id'] ?? null);
    }

    public function testClientPasswordChangeNeedsTheCurrentPassword(): void
    {
        $clientId = $this->clientWithLogin();
        $hash = static fn (): string => (string) Database::connection()->query('SELECT password_hash FROM client_logins WHERE client_id = ' . $clientId)->fetchColumn();

        $this->signInClient($clientId);
        $this->dispatch('POST', '/client/account/password', $this->withCsrf(['current_password' => 'wrong', 'new_password' => 'ClientNew12345!', 'confirm_password' => 'ClientNew12345!']));
        self::assertSame('Current password is incorrect.', Flash::pull()[0]['message'] ?? null);
        self::assertTrue(password_verify(self::PASSWORD, $hash()));

        $this->signInClient($clientId);
        $this->dispatch('POST', '/client/account/password', $this->withCsrf(['current_password' => self::PASSWORD, 'new_password' => 'ClientNew12345!', 'confirm_password' => 'ClientNew12345!']));
        self::assertTrue(password_verify('ClientNew12345!', $hash()));
    }

    public function testASuperAdminCanGrantAndRevokeADelegation(): void
    {
        $delegate = $this->createTestUser('Admin');
        $this->signInStaff($this->superAdminId());
        $this->dispatch('POST', '/super-admin/delegations', $this->withCsrf(['delegate_user_id' => (string) $delegate, 'reason' => 'Cover while the owner travels']));
        self::assertTrue(SuperAdminService::isEffective($delegate));

        $this->signInStaff($delegate);
        [$code] = $this->dispatch('GET', '/super-admin');
        self::assertSame(200, $code, 'the delegate now opens the Super Admin screen');

        $delegationId = (int) Database::connection()->query("SELECT id FROM super_admin_delegations WHERE delegate_user_id = {$delegate} AND revoked_at IS NULL")->fetchColumn();
        $this->signInStaff($this->superAdminId());
        $this->dispatch('POST', "/super-admin/delegations/{$delegationId}/revoke", $this->withCsrf(['reason' => 'Owner is back now']));
        self::assertFalse(SuperAdminService::isEffective($delegate));

        $this->signInStaff($delegate);
        [$code] = $this->dispatch('GET', '/super-admin');
        self::assertSame(403, $code);
    }

    public function testSopImagesCannotReachFilesOutsideTheImagesFolder(): void
    {
        foreach (['..%2F..%2F.env', '..%2F01-overview.md', '.env', 'no-such-image.png'] as $file) {
            $this->signInStaff($this->adminId());
            [$code, $body] = $this->dispatch('GET', "/sop-assets/{$file}");
            self::assertSame([404, 'Image not found.'], [$code, trim($body)], $file);
        }
    }
}
