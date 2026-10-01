<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderProductRepository;
use App\Tests\Support\DbTestCase;

/**
 * Order Details redesign — a quick-glance summary row (current stage,
 * product count, payment legs resolved, compliance checklist resolved,
 * document count) added above the existing commercial-terms kv-grid, so
 * staff don't have to scroll the whole page to see where an order stands.
 * The Compliance Checklist line reuses the close_orders gate (no amounts
 * or sensitive detail here, just a count, but still hidden from staff who
 * can't act on it, to avoid leaking who-can-see-what through a side door).
 */
final class OrderDetailsAtAGlanceTest extends DbTestCase
{
    public function testShowsProductCountAndCurrentStage(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderProductRepository::add($orderId, 1, 'Granite Slab', null, null, '10', false, 'sqm', '50', '680222');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Current Stage', $output);
        self::assertStringContainsString('1 line', $output);
        self::assertStringContainsString('Stage 1', $output);
    }

    public function testComplianceChecklistLineHiddenWithoutCloseOrders(): void
    {
        $userId = $this->createTestUser('Accounts Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('Compliance Checklist', $output);
    }

    public function testComplianceChecklistLineShownWithCloseOrders(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Compliance Checklist', $output);
        self::assertMatchesRegularExpression('/\d+ of \d+ resolved/', $output);
    }
}
