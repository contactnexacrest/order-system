<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, R5-20: the order page offered forms and links the user's
 * permissions did not allow — the user filled a form in, submitted it and
 * landed on a bare 403 with their input lost. This renders the order page
 * (every stage open, documents on file) for roles without the broad
 * permissions and checks every form action and order/document link
 * against the permission its route actually requires in
 * public_html/index.php.
 */
final class OrderPageActionsMatchPermissionsTest extends DbTestCase
{
    protected function tearDown(): void
    {
        \App\Helpers\Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    /** @return array<int, array{method: string, pattern: string, regex: string, permissions: ?array}> */
    public static function routes(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/index.php');
        preg_match_all("#\\\$router->(get|post)\\('([^']+)',\\s*\\[[^\\]]*\\],\\s*\\[(.*?)\\]\\);#", $source, $matches, PREG_SET_ORDER);
        $routes = [];
        foreach ($matches as [, $method, $pattern, $middleware]) {
            $permissions = null;
            if (preg_match("#PermissionCheck::requires\\('([^']+)'\\)#", $middleware, $m)) {
                $permissions = [$m[1]];
            } elseif (preg_match('#PermissionCheck::requiresAny\(\[([^\]]+)\]\)#', $middleware, $m)) {
                preg_match_all("#'([^']+)'#", $m[1], $keys);
                $permissions = $keys[1];
            }
            $regex = '#^' . preg_replace('#\\\\\{[^}]+\\\\\}#', '[^/]+', preg_quote($pattern, '#')) . '$#';
            $routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'regex' => $regex, 'permissions' => $permissions];
        }
        return $routes;
    }

    private function richOrder(int $assignee): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'USD', null, null, $assignee);
        $pdo = Database::connection();
        $pdo->prepare("UPDATE order_stages SET status = 'in_progress' WHERE order_id = :id")->execute(['id' => $orderId]);
        foreach (['QT', 'PI', 'OC', 'SUPPO', 'PL', 'BLI', 'CI', 'FDN', 'COOPREP'] as $code) {
            $this->createTestDocument($orderId, $code, 'approved', $this->createTestFile($orderId));
        }
        $this->createTestDocument($orderId, 'BUYERPO', 'draft', $this->createTestFile($orderId));
        return $orderId;
    }

    /** @return string[] "METHOD /route — needs x" for every action shown that the user may not use */
    private function deniedActionsShownTo(string $roleName): array
    {
        $userId = $this->createTestUser($roleName);
        $orderId = $this->richOrder($userId);
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
        $roleId = (int) Database::connection()->query('SELECT role_id FROM users WHERE id = ' . $userId)->fetchColumn();

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $targets = [];
        foreach ($dom->getElementsByTagName('form') as $form) {
            $targets[] = [strtoupper($form->getAttribute('method') ?: 'GET'), $form->getAttribute('action')];
        }
        foreach ($dom->getElementsByTagName('a') as $a) {
            $targets[] = ['GET', $a->getAttribute('href')];
        }

        $routes = self::routes();
        $denied = [];
        foreach ($targets as [$method, $url]) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '' || !str_starts_with($path, '/')) {
                continue;
            }
            foreach ($routes as $route) {
                if ($route['method'] !== $method || !preg_match($route['regex'], $path)) {
                    continue;
                }
                $allowed = $route['permissions'] === null;
                foreach ($route['permissions'] ?? [] as $key) {
                    $allowed = $allowed || PermissionService::can($userId, $roleId, $key);
                }
                if (!$allowed) {
                    $denied["{$method} {$route['pattern']}"] = "{$method} {$route['pattern']} — needs " . implode(' or ', $route['permissions']);
                }
                break;
            }
        }
        ksort($denied);
        return array_values($denied);
    }

    public function testAViewOnlyUserIsOfferedNoActionItMayNotUse(): void
    {
        self::assertSame([], $this->deniedActionsShownTo('Viewer / Auditor'));
    }

    public function testALogisticsExecutiveIsOfferedNoActionItMayNotUse(): void
    {
        self::assertSame([], $this->deniedActionsShownTo('Logistics Executive'));
    }

    public function testAnAccountsExecutiveIsOfferedNoActionItMayNotUse(): void
    {
        self::assertSame([], $this->deniedActionsShownTo('Accounts Executive'));
    }

    /** Owner decision (2026-10-28): the CA has view_orders (read-only) and view_cafin. */
    public function testACaIsOfferedNoActionItMayNotUse(): void
    {
        self::assertSame([], $this->deniedActionsShownTo('CA / Chartered Accountant'));
    }
}
