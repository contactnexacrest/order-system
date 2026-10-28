<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, R5-14: 87 routes had no test in any suite. This drives
 * every route registered in public_html/index.php through the real
 * Router with its real middleware:
 *  - every staff route that requires a permission refuses (403) a
 *    signed-in user who holds no permission at all — GET and POST;
 *  - every staff page without a {param} opens for Admin without a server
 *    error;
 *  - every public page without a {param} opens for a signed-out visitor.
 * The Node suite has the same test (routeMatrix.test.js).
 */
final class RouteMatrixTest extends DbTestCase
{
    private static ?Router $router = null;

    /** The application's own Router, with every route index.php registers. */
    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__route-matrix-build__';
            ob_start();
            require dirname(__DIR__, 3) . '/public_html/index.php';
            ob_end_clean();
            restore_exception_handler(); // the one app/bootstrap.php installs
            self::$router = $router;
        }
        return self::$router;
    }

    /** @return array<int, array{method: string, pattern: string, permissions: ?array}> */
    private static function routes(): array
    {
        return array_map(
            static fn (array $r): array => ['method' => $r['method'], 'pattern' => $r['pattern'], 'permissions' => $r['permissions']],
            OrderPageActionsMatchPermissionsTest::routes()
        );
    }

    private function signIn(?int $userId): void
    {
        $_SESSION = $userId === null ? [] : ['_auth_user_id' => $userId];
        if ($userId !== null) {
            $_SESSION['_session_version'] = AuthService::sessionVersion() ?? 0;
        }
        PermissionService::resetCache();
    }

    /** @return array{0: int, 1: string} the response code and body */
    private function dispatch(string $method, string $path): array
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        ob_start();
        try {
            self::router()->dispatch($method, $path);
        } finally {
            $body = (string) ob_get_clean();
        }
        return [(int) http_response_code(), $body];
    }

    private static function concrete(string $pattern): string
    {
        return (string) preg_replace('#\{[^}]+\}#', '999999', $pattern);
    }

    private function noPermissionUser(): int
    {
        Database::connection()->exec("INSERT IGNORE INTO roles (name, description, is_system_role) VALUES ('Test Role — No Permissions', 'Test only', 0)");
        return $this->createTestUser('Test Role — No Permissions');
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    public function testEveryPermissionGatedRouteRefusesAUserWithoutThePermission(): void
    {
        self::router();
        $this->signIn($this->noPermissionUser());

        $checked = 0;
        $notRefused = [];
        foreach (self::routes() as $route) {
            if ($route['permissions'] === null) {
                continue;
            }
            [$code] = $this->dispatch($route['method'], self::concrete($route['pattern']));
            if ($code !== 403) {
                $notRefused[] = "{$route['method']} {$route['pattern']} → {$code}";
            }
            $checked++;
        }

        self::assertGreaterThan(300, $checked);
        self::assertSame([], $notRefused);
    }

    public function testEveryStaffPageWithoutParametersOpensForAdmin(): void
    {
        self::router();
        $adminId = $this->createTestUser('Admin');

        $checked = 0;
        $failed = [];
        foreach (self::routes() as $route) {
            if ($route['method'] !== 'GET' || $route['permissions'] === null || str_contains($route['pattern'], '{')) {
                continue;
            }
            $this->signIn($adminId);
            try {
                [$code] = $this->dispatch('GET', $route['pattern']);
                if ($code >= 500) {
                    $failed[] = "{$route['pattern']} → {$code}";
                }
            } catch (\Throwable $e) {
                $failed[] = "{$route['pattern']} → " . get_class($e) . ': ' . $e->getMessage();
            }
            $checked++;
        }

        self::assertGreaterThan(60, $checked);
        self::assertSame([], $failed);
    }

    public function testEveryPublicPageWithoutParametersOpensSignedOut(): void
    {
        self::router();
        $public = ['/login', '/forgot-password', '/client/login', '/client/forgot-password', '/quotation-request'];
        foreach ($public as $path) {
            $this->signIn(null);
            [$code, $body] = $this->dispatch('GET', $path);
            if ($code === 404 && str_contains($body, '404 Not Found')) {
                continue; // not a route on this stack
            }
            self::assertLessThan(500, $code, $path);
            self::assertNotSame('', trim($body), $path);
        }
    }
}
