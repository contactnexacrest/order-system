<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ProductController;
use App\Repositories\ProductRepository;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner, 12 Nov 2026 (D-334, "fix this" — a deactivated product disappeared):
 *  - Products › All products filters Active · Inactive · All (with counts), on the list and on a search;
 *  - an inactive product says where it is used (ProductRepository::usage());
 *  - Reactivate / Deactivate (audited);
 *  - POST /products/{id}/delete refuses an active or used product, server-side, and deletes an
 *    inactive unused one.
 * Node twin: tests/integration/inactiveProducts.test.js.
 */
final class InactiveProductsTest extends DbTestCase
{
    private int $admin;
    private string $tag;
    /** @var list<int> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        PermissionService::resetCache();
        $this->tag = 'D334 ' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach ($this->made as $id) {
            $pdo->prepare('DELETE FROM price_requests WHERE product_id = :id')->execute(['id' => $id]);
            $pdo->prepare('UPDATE order_products SET catalog_product_id = NULL WHERE catalog_product_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM catalog_products WHERE id = :id')->execute(['id' => $id]);
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function product(string $name, bool $active): int
    {
        $id = ProductRepository::create(['name' => "{$this->tag} {$name}", 'specifications' => 'Granite slab', 'hs_code' => '68022310', 'is_active' => $active ? 1 : 0], $this->admin);
        $this->made[] = $id;
        return $id;
    }

    private function render(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function flash(): string
    {
        $m = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return $m === [] ? '' : (string) end($m)['message'];
    }

    private function audits(string $action, int $id): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM audit_log WHERE action_type = :a AND entity_id = :id');
        $stmt->execute(['a' => $action, 'id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    private function exists(int $id): bool
    {
        return ProductRepository::find($id) !== null;
    }

    /** One order line picked from the product (and one procurement line for it). */
    private function useOnOrder(int $productId, bool $withProcurement = false): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, catalog_product_id) VALUES (:o, 1, 'D334 line', 10, 'SQM', 50, :p)")
            ->execute(['o' => $order, 'p' => $productId]);
        $line = (int) $pdo->lastInsertId();
        if ($withProcurement) {
            $pdo->prepare("INSERT INTO purchase_requirements (order_id, order_product_id, description, quantity, needed_by, raised_by) VALUES (:o, :l, 'D334 buy', 10, CURDATE(), :u)")
                ->execute(['o' => $order, 'l' => $line, 'u' => $this->admin]);
        }
    }

    public function testTheListAndTheSearchFilterByStatusWithCounts(): void
    {
        $this->product('Active slab', true);
        $this->product('Old slab', false);

        self::assertSame(['active' => 1, 'inactive' => 1, 'all' => 2], ProductRepository::statusCounts($this->tag));
        self::assertSame(["{$this->tag} Active slab"], array_column(ProductRepository::search($this->tag), 'name'), 'a search shows active products by default');
        self::assertSame(["{$this->tag} Old slab"], array_column(ProductRepository::search($this->tag, 'inactive'), 'name'));
        self::assertCount(2, ProductRepository::search($this->tag, 'all'));
        self::assertSame('active', ProductRepository::statusFilter('rubbish'));

        $_GET = ['q' => $this->tag];
        $page = $this->render(fn () => (new ProductController())->index([]));
        self::assertStringContainsString('Active (1)', $page);
        self::assertStringContainsString('Inactive (1)', $page);
        self::assertStringContainsString('All (2)', $page);
        self::assertStringNotContainsString('Old slab', $page);
        self::assertStringContainsString('Products are picked on order lines and used for price checks.', $page);
        self::assertStringNotContainsString('completely independent of the order pipeline', $page);

        $_GET = ['q' => $this->tag, 'status' => 'inactive'];
        $page = $this->render(fn () => (new ProductController())->index([]));
        self::assertStringContainsString('Old slab', $page);
        self::assertStringNotContainsString('Active slab', $page);
        self::assertStringContainsString('>Inactive</span>', $page, 'the Status column shows Inactive');
        self::assertStringContainsString('not used anywhere', $page);
        self::assertStringContainsString('Reactivate', $page);
        self::assertStringContainsString('It is not used anywhere. Its supplier prices, images and price history are deleted too. This cannot be undone.', $page);

        $_GET = ['status' => 'inactive'];
        $page = $this->render(fn () => (new ProductController())->index([]));
        self::assertStringContainsString('Old slab', $page, 'the full list filters too');
    }

    public function testUsageCountsOrdersPriceRequestsAndProcurement(): void
    {
        $id = $this->product('Used slab', false);
        self::assertSame(['orders' => 0, 'order_lines' => 0, 'price_requests' => 0, 'procurement' => 0, 'total' => 0], ProductRepository::usage($id));
        self::assertSame('not used anywhere', ProductRepository::usageText(ProductRepository::usage($id)));

        $this->useOnOrder($id, true);
        $this->useOnOrder($id);
        $this->useOnOrder($id);
        $u = ProductRepository::usage($id);
        self::assertSame(3, $u['orders']);
        self::assertSame(1, $u['procurement']);
        self::assertSame('3 orders · 1 procurement line', ProductRepository::usageText($u));
        self::assertSame('Used on 3 orders · 1 procurement line — kept so those orders stay complete.', ProductRepository::deleteRefusal(ProductRepository::find($id), $u));
        self::assertSame('1 order · 2 price requests', ProductRepository::usageText(['orders' => 1, 'price_requests' => 2, 'procurement' => 0]));
        self::assertSame('Used on 1 price request — kept so those price requests stay complete.',
            ProductRepository::deleteRefusal(['is_active' => 0], ['orders' => 0, 'price_requests' => 1, 'procurement' => 0, 'total' => 1]));

        $_GET = ['q' => $this->tag, 'status' => 'inactive'];
        $page = $this->render(fn () => (new ProductController())->index([]));
        self::assertStringContainsString('3 orders · 1 procurement line', $page);
        self::assertStringContainsString('Used on 3 orders · 1 procurement line — kept so those orders stay complete.', $page);
        self::assertStringNotContainsString('Delete…', $page, 'a used product has no working Delete');

        $show = $this->render(fn () => (new ProductController())->show(['id' => (string) $id]));
        self::assertStringContainsString('Used on: <strong>3 orders · 1 procurement line</strong>', $show);
    }

    public function testDeleteIsRefusedForAnActiveOrAUsedProduct(): void
    {
        $active = $this->product('Active slab', true);
        (new ProductController())->delete(['id' => (string) $active]);
        self::assertTrue($this->exists($active));
        self::assertSame('Not deleted: This product is active. Deactivate it first — only an inactive product that is not used anywhere can be deleted.', $this->flash());
        self::assertSame(0, $this->audits('PRODUCT_DELETED', $active));

        $used = $this->product('Used slab', false);
        $this->useOnOrder($used);
        (new ProductController())->delete(['id' => (string) $used]);
        self::assertTrue($this->exists($used));
        self::assertSame('Not deleted: Used on 1 order — kept so those orders stay complete.', $this->flash());
        self::assertSame(0, $this->audits('PRODUCT_DELETED', $used));

        $show = $this->render(fn () => (new ProductController())->show(['id' => (string) $active]));
        self::assertStringNotContainsString('/products/' . $active . '/delete', $show, 'no Delete on an active product');
        self::assertStringContainsString('/products/' . $active . '/deactivate', $show, 'an active product offers Deactivate, not Delete');
    }

    public function testAnInactiveUnusedProductIsDeleted(): void
    {
        $id = $this->product('Spare slab', false);
        (new ProductController())->delete(['id' => (string) $id]);
        self::assertFalse($this->exists($id));
        self::assertSame(1, $this->audits('PRODUCT_DELETED', $id));
        self::assertStringContainsString('deleted', $this->flash());
    }

    public function testDeactivateAndReactivateAreAudited(): void
    {
        $id = $this->product('Toggle slab', true);
        (new ProductController())->deactivate(['id' => (string) $id]);
        self::assertSame(0, (int) ProductRepository::find($id)['is_active']);
        self::assertSame(1, $this->audits('PRODUCT_DEACTIVATED', $id));

        $_POST = ['return_to' => 'list'];
        (new ProductController())->reactivate(['id' => (string) $id]);
        self::assertSame(1, (int) ProductRepository::find($id)['is_active']);
        self::assertSame(1, $this->audits('PRODUCT_REACTIVATED', $id));
        self::assertStringContainsString('is active again', $this->flash());
    }
}
