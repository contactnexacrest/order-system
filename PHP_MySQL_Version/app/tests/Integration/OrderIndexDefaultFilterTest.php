<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * The Orders list used to default to showing every order (all statuses)
 * when no ?status= query param was given, with "All" and "no param at
 * all" sharing the same URL. Staff wanted the screen to open on Active by
 * default, since that's what's actually in play at any time — a
 * completed/lost order buried in the same list wasn't useful as a
 * default view. "All" is now its own explicit choice (?status=all).
 */
final class OrderIndexDefaultFilterTest extends DbTestCase
{
    private function renderIndex(array $get = []): string
    {
        $previousGet = $_GET;
        $_GET = $get;
        $controller = new OrderController();
        ob_start();
        $controller->index([]);
        $out = (string) ob_get_clean();
        $_GET = $previousGet;
        return $out;
    }

    public function testNoQueryParamShowsOnlyActiveOrders(): void
    {
        $user = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $user;

        $activeOrderId = $this->createTestOrder($this->createTestClient());
        $activeOrder = OrderRepository::find($activeOrderId);

        $lostOrderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::markLost($lostOrderId, 'Buyer went silent', $user);
        $lostOrder = OrderRepository::find($lostOrderId);

        $html = $this->renderIndex([]);

        self::assertStringContainsString($activeOrder['order_reference'], $html, 'the active order must show by default');
        self::assertStringNotContainsString($lostOrder['order_reference'], $html, 'the lost order must NOT show by default');
        self::assertStringContainsString('filter-chip active" href="/orders?status=active"', $html, 'the Active tab must be visually selected by default');
    }

    public function testExplicitStatusAllShowsEveryOrder(): void
    {
        $user = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $user;

        $activeOrderId = $this->createTestOrder($this->createTestClient());
        $activeOrder = OrderRepository::find($activeOrderId);

        $lostOrderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::markLost($lostOrderId, 'Buyer went silent', $user);
        $lostOrder = OrderRepository::find($lostOrderId);

        $html = $this->renderIndex(['status' => 'all']);

        self::assertStringContainsString($activeOrder['order_reference'], $html);
        self::assertStringContainsString($lostOrder['order_reference'], $html, 'explicit ?status=all must include the lost order too');
    }
}
