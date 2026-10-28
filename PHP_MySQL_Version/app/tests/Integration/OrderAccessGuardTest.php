<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Middleware\SessionAuth;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, R5-24: the archived-order and assignment rules used to be
 * checked only on the order's main page — its other pages and actions
 * (edit, annexure, amendments, saving terms, the Full Report, document
 * downloads) stayed open. SessionAuth now applies the same rule to every
 * staff URL that belongs to an order, GET and POST.
 */
final class OrderAccessGuardTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        \App\Helpers\Flash::pull();
        $_SESSION = [];
        parent::tearDown();
    }

    /** @return array{0: bool, 1: string} whether the request may continue, and what was printed */
    private function request(int $userId, string $path): array
    {
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_session_version'] = AuthService::sessionVersion() ?? 0;
        $_SERVER['REQUEST_URI'] = $path;
        PermissionService::resetCache();
        http_response_code(200);
        ob_start();
        $passed = (SessionAuth::required())([]);
        return [$passed, (string) ob_get_clean()];
    }

    private function assign(int $orderId, int $userId): void
    {
        Database::connection()->prepare('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)')->execute(['o' => $orderId, 'u' => $userId]);
    }

    public function testEveryPageAndActionOfAnUnassignedOrderIsRefused(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT');

        foreach (["/orders/{$orderId}", "/orders/{$orderId}/edit", "/orders/{$orderId}/annexure", "/orders/{$orderId}/annexure/terms", "/orders/{$orderId}/amendments", "/reports/order/{$orderId}", "/documents/{$documentId}/download"] as $path) {
            [$passed, $output] = $this->request($userId, $path);
            self::assertFalse($passed, $path);
            self::assertSame(403, http_response_code(), $path);
            self::assertStringContainsString('assigned to a different user', $output, $path);
        }

        $this->assign($orderId, $userId);
        self::assertTrue($this->request($userId, "/orders/{$orderId}/annexure")[0]);
        self::assertTrue($this->request($userId, "/documents/{$documentId}/download")[0]);
    }

    public function testAnArchivedOrderIsRefusedEverywhereWithoutViewArchivedOrders(): void
    {
        // Viewer / Auditor sees every order (view_all_orders) but not archived ones.
        $viewerId = $this->createTestUser('Viewer / Auditor');
        $adminId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare('UPDATE orders SET is_archived = 1 WHERE id = :id')->execute(['id' => $orderId]);

        foreach (["/orders/{$orderId}/edit", "/orders/{$orderId}/annexure", "/orders/{$orderId}/annexure/terms", "/orders/{$orderId}/amendments"] as $path) {
            [$passed, $output] = $this->request($viewerId, $path);
            self::assertFalse($passed, $path);
            self::assertStringContainsString('has been archived', $output, $path);
        }
        self::assertTrue($this->request($adminId, "/orders/{$orderId}/annexure")[0]);
    }

    public function testPathsThatDoNotBelongToAnOrderAreUnaffected(): void
    {
        $userId = $this->createTestUser('Export Executive');
        foreach (['/orders', '/orders/create', '/orders/kanban', '/clients', '/orders/999999999/annexure'] as $path) {
            self::assertTrue($this->request($userId, $path)[0], $path);
        }
    }
}
