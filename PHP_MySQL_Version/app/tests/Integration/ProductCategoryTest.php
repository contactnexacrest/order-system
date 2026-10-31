<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\ProductCategoryController;
use App\Helpers\Flash;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\ProductCategoryRepository;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-215 (5a, confirmed twice): product categories with their
 * own attributes (Products → Categories). Natural Stone is today's products
 * — Dimensions and Finish, printed word for word as before. A line of
 * another category prints its own attributes in the same place, followed by
 * the HS code. The Node suite has the same test (productCategories.test.js).
 */
final class ProductCategoryTest extends DbTestCase
{
    private int $orderId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function post(object $controller, string $method, array $post, array $params = []): string
    {
        $_POST = $post;
        ob_start();
        $controller->{$method}($params);
        ob_end_clean();
        $_POST = [];
        $all = Flash::pull();
        return (string) ((end($all) ?: [])['message'] ?? '');
    }

    private function tiles(): int
    {
        $name = 'Ceramic Tiles ' . bin2hex(random_bytes(3));
        self::assertSame("Category \"{$name}\" added with Size, Grade, Colour.",
            $this->post(new ProductCategoryController(), 'store', ['name' => $name, 'attributes' => ['Size', 'Grade', 'Colour', '', '', '']]));
        return (int) Database::connection()->query('SELECT id FROM product_categories WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
    }

    public function testNaturalStoneIsTodaysProducts(): void
    {
        $stone = array_values(array_filter(ProductCategoryRepository::all(), static fn (array $c): bool => (int) $c['is_system'] === 1));
        self::assertSame(['Natural Stone', ['Dimensions', 'Finish']], [$stone[0]['name'], $stone[0]['attributes']]);
        self::assertSame("Natural Stone is today's products — its Dimensions and Finish stay as they are.",
            $this->post(new ProductCategoryController(), 'update', ['name' => 'Stone', 'attributes' => ['Size']], ['id' => (string) $stone[0]['id']]));

        OrderProductRepository::add($this->orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
        $pi = DocumentDataAssembler::assemble($this->orderId, 'PI');
        self::assertSame([], $pi['products'][0]['attributes'], 'a stone line prints Dimensions and Finish as today');
    }

    public function testACategoryIsCheckedWhenSaved(): void
    {
        $c = new ProductCategoryController();
        self::assertSame('Give the category at least one attribute (e.g. Size, Grade, Colour).', $this->post($c, 'store', ['name' => 'Tiles X', 'attributes' => ['', '']]));
        self::assertSame('"size" is listed twice — each attribute once.', $this->post($c, 'store', ['name' => 'Tiles X', 'attributes' => ['Size', 'size']]));
        self::assertSame('There is already a category called "Natural Stone".', $this->post($c, 'store', ['name' => 'Natural Stone', 'attributes' => ['Size']]));
        $id = $this->tiles();
        self::assertSame(['Size', 'Grade', 'Colour'], ProductCategoryRepository::find($id)['attributes']);
    }

    public function testALineOfAnotherCategoryPrintsItsOwnAttributes(): void
    {
        $tiles = $this->tiles();
        self::assertSame('Product line added.', $this->post(new OrderController(), 'addProduct', [
            'description' => 'Porcelain floor tile', 'quantity' => '200', 'unit' => 'PCS', 'unit_price' => '12.50', 'line_type' => 'goods', 'hs_code' => '680293',
            'category_id' => (string) $tiles, "attr_{$tiles}" => ['600x600 mm', 'Premium', ''],
        ], ['id' => (string) $this->orderId]));
        $line = Database::connection()->query("SELECT * FROM order_products WHERE order_id = {$this->orderId} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame((string) $tiles, (string) $line['category_id']);

        $pi = DocumentDataAssembler::assemble($this->orderId, 'PI');
        self::assertSame([['label' => 'Size', 'value' => '600x600 mm'], ['label' => 'Grade', 'value' => 'Premium'], ['label' => 'Colour', 'value' => 'To Be Confirmed']], $pi['products'][0]['attributes']);

        $result = DocumentGenerationService::generate($this->orderId, 'PI', $this->userId, null, false, true);
        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        $text = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) @shell_exec('pdftotext ' . escapeshellarg($file['server_path']) . ' - 2>/dev/null')));
        if (trim((string) $text) === '') {
            self::fail('Could not read the generated PDF with pdftotext — install poppler-utils.');
        }
        self::assertStringContainsString('1. Size: 600x600 mm · Grade: Premium · Colour: To Be Confirmed · HS Code: 680293 · Country of Origin: India', $text);

        $m = new \ReflectionMethod(\App\Services\Docx\DocxDocumentBuilder::class, 'buildProductRows');
        $rows = $m->invoke(null, $pi['products']);
        self::assertSame('1. Size: 600x600 mm   ·   Grade: Premium   ·   Colour: To Be Confirmed   ·   HS Code: 680293   ·   Country of Origin: India',
            implode('', array_map(static fn (array $part): string => $part[0], $rows[0]['specText'])));

        // A copy of the line keeps its category and values.
        $this->post(new OrderController(), 'duplicateProduct', [], ['id' => (string) $this->orderId, 'productId' => (string) $line['id']]);
        $copy = Database::connection()->query("SELECT * FROM order_products WHERE order_id = {$this->orderId} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame([(string) $tiles, $line['attribute_values']], [(string) $copy['category_id'], $copy['attribute_values']]);
    }
}
