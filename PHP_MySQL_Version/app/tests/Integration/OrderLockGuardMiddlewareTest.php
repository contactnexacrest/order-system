<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Helpers\Flash;
use App\Helpers\Router;
use App\Middleware\OrderLockGuard;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-28: "Closed (complete + locked) order: production
 * status can still be edited — the new 'lock closed orders' does not
 * cover every edit route." OrderEditGuard (covered by
 * OrderLockedEditGuardTest) only ever protected the 5 routes that called
 * it by hand; this middleware is the central guard registered on every
 * other order-mutating POST /orders/{id}/* route in public_html/index.php
 * (see OrderLockGuard's own docblock for the exact exclusion list). Drives
 * it through a real Router::dispatch() — the same pattern
 * RouterDataTooLongTest uses — since this IS middleware, not a plain
 * service method.
 */
final class OrderLockGuardMiddlewareTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
    }

    public function testBlocksTheActionOnALockedOrderWithoutTheOverridePermission(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::markComplete($orderId);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');
        $reached = false;
        $router = new Router();
        $router->post('/order-lock-guard-test/{id}', function (array $params) use (&$reached): void {
            $reached = true;
        }, [OrderLockGuard::check()]);

        $router->dispatch('POST', "/order-lock-guard-test/{$orderId}");

        self::assertFalse($reached, 'the guarded handler must never run on a locked order without edit_locked_data');
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type'] ?? null);
        self::assertStringContainsString('locked', $flash[0]['message'] ?? '');
    }

    public function testAllowsTheActionOnALockedOrderWithTheOverridePermission(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::markComplete($orderId);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $reached = false;
        $router = new Router();
        $router->post('/order-lock-guard-test/{id}', function (array $params) use (&$reached): void {
            $reached = true;
        }, [OrderLockGuard::check()]);

        $router->dispatch('POST', "/order-lock-guard-test/{$orderId}");

        self::assertTrue($reached, 'a user holding edit_locked_data must still be able to act on a locked order');
    }

    public function testAllowsTheActionOnAnActiveOrderRegardless(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');
        $reached = false;
        $router = new Router();
        $router->post('/order-lock-guard-test/{id}', function (array $params) use (&$reached): void {
            $reached = true;
        }, [OrderLockGuard::check()]);

        $router->dispatch('POST', "/order-lock-guard-test/{$orderId}");

        self::assertTrue($reached, 'an order that is not locked must never be blocked by this guard');
    }

    public function testEveryNonExcludedOrderMutationRouteIsRegisteredWithTheGuard(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/index.php');
        $excluded = [
            '/orders/{id}/override-status-lock', '/orders/{id}/close', '/orders/{id}/mark-fully-settled', '/orders/{id}/mark-lost',
            '/orders/{id}/archive', '/orders/{id}/unarchive', '/orders/{id}/duplicate', '/orders/{id}/comments',
            '/orders/{id}/documents/generate', '/orders/{id}/documents/{documentId}/delete',
            '/orders/{id}/documents/{documentId}/send', '/orders/{id}/email/send', '/orders/{id}/disputes',
            // docs/schema.sql Section BX — a Complaint, like a Dispute, is
            // never a routine edit gated behind the order's own lock state;
            // it must stay reachable on any order, locked or not.
            '/orders/{id}/complaints',
            // Step 16 (Order Feedback): only reachable once the order is
            // shipped_docs_sent/complete — by the time it's 'complete' the
            // order is already locked, so guarding it would make it
            // permanently unreachable, same reasoning as the cancellation
            // routes below.
            '/orders/{id}/feedback',
            // External QA report M-09: the cancellation workflow is
            // mark-lost's sibling (a terminal action ending the order's
            // active life, not a routine edit), excluded for the same
            // reason mark-lost is above. completeCancellationRefund in
            // particular MUST stay excluded: by the time it runs, cancel()
            // has already set is_locked=1, so guarding it would make it
            // permanently unreachable for the close_orders-only tier it's
            // deliberately gated on (one step below edit_locked_data).
            '/orders/{id}/cancellation/request', '/orders/{id}/cancellation/{cancellationId}/approve',
            '/orders/{id}/cancellation/{cancellationId}/reject', '/orders/{id}/cancellation/{cancellationId}/complete-refund',
            // Order ownership / assignment visibility (see
            // docs/migrations/2026-10-24c-order-multi-assignee.sql) —
            // visibility-only, never touches the order's own substantive
            // data, same tier as disputes/complaints above: adding or
            // removing an assignee on a completed order (e.g. a staff
            // member leaves) must stay reachable regardless of lock
            // state. Already narrowly gated to assign_order_owner
            // (Admin/MD/ED), who hold edit_locked_data too in practice.
            '/orders/{id}/assignees', '/orders/{id}/assignees/{userId}/remove',
            // Owner request (2026-10-27): CAFIN is internal and can be
            // generated whenever it is wanted — often after the order has
            // closed (benefits are credited months later) — so it must
            // stay reachable on a locked order. It writes no order data.
            '/orders/{id}/ca-internal-doc/generate',
        ];
        $lines = array_filter(
            explode("\n", $source),
            static fn (string $line): bool => (bool) preg_match('#^\$router->post\(\'/orders/\{id\}#', trim($line))
        );
        self::assertNotEmpty($lines, 'sanity check: the route file must actually contain order mutation routes, or this test proves nothing');

        foreach ($lines as $line) {
            preg_match("#^\\\$router->post\\('([^']+)'#", trim($line), $m);
            $path = $m[1];
            if (in_array($path, $excluded, true)) {
                continue;
            }
            self::assertStringContainsString('OrderLockGuard::check()', $line, "route {$path} must be guarded by OrderLockGuard — this test would have caught L-28's production-status gap");
        }
    }
}
