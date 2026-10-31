<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-215 (confirmed twice, 1 Nov 2026): an order line is
 * Goods, Service or Charge. A service has a SAC code instead of an HS code,
 * is shown on the QT/PI/OC with "Invoiced separately on our Export Service
 * Invoice", stays out of the goods FOB value (advance, balance, CI) and off
 * the goods documents. Goods-only orders print exactly as before. The Node
 * suite has the same test (lineTypes.test.js).
 */
final class LineTypesTest extends DbTestCase
{
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function addLine(array $body): array
    {
        $_POST = $body + ['description' => 'Line', 'quantity' => '5', 'unit' => 'PCS', 'unit_price' => '180'];
        (new OrderController())->addProduct(['id' => (string) $this->orderId]);
        $_POST = [];
        $all = Flash::pull();
        return end($all) ?: ['type' => null, 'message' => ''];
    }

    private function lastLine(): array
    {
        return Database::connection()->query("SELECT * FROM order_products WHERE order_id = {$this->orderId} ORDER BY id DESC LIMIT 1")->fetch();
    }

    public function testEachLineTypeIsCheckedAndSaved(): void
    {
        self::assertSame('A service line needs its SAC code: 6 digits starting 99 (e.g. 998719).',
            $this->addLine(['line_type' => 'service', 'description' => 'Installation supervision', 'sac_code' => '12345'])['message']);
        self::assertSame('Product line added.', $this->addLine(['line_type' => 'service', 'description' => 'Installation supervision', 'sac_code' => '998719'])['message']);
        $line = $this->lastLine();
        self::assertSame(['service', '998719'], [$line['line_type'], $line['sac_code']]);

        self::assertSame('Product line added.', $this->addLine(['line_type' => 'charge', 'description' => 'Testing fee', 'quantity' => '1', 'unit_price' => '50'])['message']);
        self::assertSame(['charge', null], [$this->lastLine()['line_type'], $this->lastLine()['sac_code']]);

        self::assertStringContainsString('is not on the HS Code master list', $this->addLine(['line_type' => 'goods', 'hs_code' => ''])['message']);
        self::assertSame('Choose whether the line is goods, a service or a charge.', $this->addLine(['line_type' => 'bundle'])['message']);
    }

    public function testServicesStayOutOfTheGoodsValueAndTheGoodsDocuments(): void
    {
        $goodsFob = OrderProductRepository::totalFobValue($this->orderId);
        $this->addLine(['line_type' => 'service', 'description' => 'Installation supervision', 'sac_code' => '998719']);
        self::assertSame($goodsFob, OrderProductRepository::totalFobValue($this->orderId), 'the FOB value (advance, balance, CI) counts goods only');
        self::assertSame(900.0, OrderProductRepository::totalServiceValue($this->orderId));

        $pi = DocumentDataAssembler::assemble($this->orderId, 'PI');
        self::assertContains('service', array_column($pi['products'], 'line_type'));
        self::assertTrue($pi['financial']['has_services']);
        self::assertSame(number_format($goodsFob + 900, 2), $pi['financial']['total_with_services']);
        self::assertSame(number_format($goodsFob, 2), $pi['financial']['fob_value']);

        foreach (['CI', 'PL', 'COOPREP', 'SUPPO', 'BLI'] as $code) {
            $doc = DocumentDataAssembler::assemble($this->orderId, $code);
            self::assertNotContains('service', array_column($doc['products'], 'line_type'), "{$code} lists goods only");
        }
    }

    public function testAGoodsOnlyOrderPrintsAsBefore(): void
    {
        $pi = DocumentDataAssembler::assemble($this->orderId, 'PI');
        self::assertFalse($pi['financial']['has_services']);
        foreach (['QT/quotation.html.twig', 'PI/proforma_invoice.html.twig'] as $template) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
            self::assertStringContainsString("{% if p.line_type == 'service' %}", $src, $template);
            self::assertStringContainsString('Invoiced separately on our Export Service Invoice', $src, $template);
            self::assertStringContainsString('{% elseif p.dimensions or p.finish %}', $src, 'goods lines keep their own spec line');
        }
        $piSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/PI/proforma_invoice.html.twig');
        self::assertStringContainsString('{{ financial.has_services ? financial.total_with_services : financial.fob_value }}', $piSrc);
    }

    public function testTheDocxShowsTheServiceLineTheSameWay(): void
    {
        $m = new \ReflectionMethod(\App\Services\Docx\DocxDocumentBuilder::class, 'buildProductRows');
        $rows = $m->invoke(null, [
            ['description' => 'Headstone', 'dimensions' => '60x30', 'finish' => 'Polished', 'hs_code' => '68022310', 'quantity' => '100', 'unit' => 'PCS', 'unit_price' => '45.00', 'amount' => '4,500.00', 'line_type' => 'goods'],
            ['description' => 'Installation supervision', 'dimensions' => null, 'finish' => null, 'hs_code' => '', 'sac_code' => '998719', 'quantity' => '5', 'unit' => 'DAY', 'unit_price' => '180.00', 'amount' => '900.00', 'line_type' => 'service'],
        ]);
        $text = static fn (array $spec): string => implode('', array_map(static fn (array $part): string => $part[0], $spec));
        self::assertSame('1. Dimensions: 60x30   ·   Finish: Polished   ·   HS Code: 68022310   ·   Country of Origin: India', $text($rows[0]['specText']));
        self::assertSame('2. Service   ·   SAC Code: 998719   ·   Invoiced separately on our Export Service Invoice', $text($rows[1]['specText']));
    }
}
