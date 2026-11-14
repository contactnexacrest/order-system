<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderProductRepository;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner, 14 Nov 2026 (D-373): an order's lines are always numbered 1, 2, 3 … with no gap, however often
 * lines are removed and added; every removal is written to the Audit Log. Node twin:
 * tests/integration/orderLineNumbering.test.js.
 */
final class OrderLineNumberingTest extends DbTestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function addLine(int $orderId, string $name): int
    {
        return OrderProductRepository::add($orderId, OrderProductRepository::nextLineNo($orderId), $name, null, null, '4.680', false, 'CBM', '2865.84', '680293');
    }

    private function remove(int $orderId, int $lineId): void
    {
        $_POST = ['reason' => ''];
        ob_start();
        (new OrderController())->deleteProduct(['id' => (string) $orderId, 'productId' => (string) $lineId]);
        ob_end_clean();
    }

    /** @return list<array{0:int,1:string}> [line_no, description] of the visible lines */
    private function lines(int $orderId): array
    {
        return array_map(static fn (array $r): array => [(int) $r['line_no'], (string) $r['description']], OrderProductRepository::forOrder($orderId));
    }

    public function testLinesStaySequentialAfterRemovingAndAddingAgain(): void
    {
        $admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $admin];
        PermissionService::resetCache();
        $orderId = $this->createTestOrder($this->createTestClient());

        $first = [$this->addLine($orderId, 'A'), $this->addLine($orderId, 'B'), $this->addLine($orderId, 'C')];
        foreach ($first as $id) {
            $this->remove($orderId, $id);
        }
        self::assertSame([], $this->lines($orderId));

        $x = $this->addLine($orderId, 'X');
        $y = $this->addLine($orderId, 'Y');
        $this->addLine($orderId, 'Z');
        self::assertSame([[1, 'X'], [2, 'Y'], [3, 'Z']], $this->lines($orderId), 'new lines start again at 1 (the owner\'s 4, 5, 6 case)');

        $this->remove($orderId, $y);
        self::assertSame([[1, 'X'], [2, 'Z']], $this->lines($orderId), 'removing the middle line closes the gap');

        OrderProductRepository::duplicate($x);
        self::assertSame([[1, 'X'], [2, 'X'], [3, 'Z']], $this->lines($orderId), 'a copy goes right below the original');

        $this->addLine($orderId, 'W');
        self::assertSame([1, 2, 3, 4], array_column($this->lines($orderId), 0));

        $log = Database::connection()->prepare("SELECT old_value FROM audit_log WHERE action_type = 'ORDER_LINE_REMOVED' AND entity_type = 'order_products' AND entity_id = :id");
        $log->execute(['id' => $y]);
        self::assertSame('Line 2: Y — 4.68 CBM', (string) $log->fetchColumn(), 'the removal is in the Audit Log, without the price');
    }
}
