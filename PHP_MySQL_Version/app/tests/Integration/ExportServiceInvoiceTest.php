<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DocumentController;
use App\Helpers\Flash;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Services\MoneyVisibility;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-215 (5a-2, confirmed twice): an order's service lines are
 * invoiced on their own Export Service Invoice (ESI) — under LUT, with SAC
 * codes — so the Commercial Invoice stays goods-only. An order without
 * service lines cannot have one. The Node suite has the same test
 * (exportServiceInvoice.test.js).
 */
final class ExportServiceInvoiceTest extends DbTestCase
{
    private int $orderId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderProductRepository::add($this->orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function addService(): void
    {
        $id = OrderProductRepository::add($this->orderId, 2, 'Installation supervision', null, null, '5', false, 'DAY', '180.00');
        OrderProductRepository::setLineType($id, 'service', '998719');
    }

    public function testAnOrderWithoutServicesCannotHaveOne(): void
    {
        $_POST = ['document_type' => 'ESI', 'generate_pdf' => '1'];
        (new DocumentController())->generate(['id' => (string) $this->orderId]);
        $all = Flash::pull();
        self::assertSame('This order has no service lines — the Export Service Invoice is only for services.', (end($all) ?: [])['message'] ?? '');
        $count = (int) Database::connection()->query("SELECT COUNT(*) FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.order_id = {$this->orderId} AND t.code = 'ESI'")->fetchColumn();
        self::assertSame(0, $count);
    }

    public function testItCarriesTheServiceLinesOnlyAndIsSellingMoney(): void
    {
        $this->addService();
        $esi = DocumentDataAssembler::assemble($this->orderId, 'ESI');
        self::assertSame(['Installation supervision'], array_column($esi['products'], 'description'));
        self::assertSame('900.00', $esi['financial']['service_value']);
        self::assertSame(MoneyVisibility::SELLING, MoneyVisibility::documentClass('ESI'));
    }

    public function testItIsGeneratedAsPdfAndDocx(): void
    {
        $this->addService();
        $result = DocumentGenerationService::generate($this->orderId, 'ESI', $this->userId, null, true);
        $doc = DocumentRepository::find((int) $result['document_id']);
        self::assertMatchesRegularExpression('#^SC/ESI/\d{2}/\d{4}\d{3}$#', (string) $doc['document_reference']);
        self::assertNotEmpty($result['docx_file_id'] ?? null, 'the DOCX is made too');

        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        $text = (string) @shell_exec('pdftotext ' . escapeshellarg($file['server_path']) . ' - 2>/dev/null');
        if ($text === '') {
            self::fail('Could not read the generated PDF with pdftotext — install poppler-utils.');
        }
        self::assertStringContainsString('EXPORT SERVICE INVOICE', $text);
        self::assertStringContainsString('Installation supervision', $text);
        self::assertStringContainsString('998719', $text);
        self::assertStringContainsString('export of services without payment of IGST', $text);
        self::assertStringNotContainsString('Black granite headstone', $text, 'the goods stay on the Commercial Invoice');
    }

    public function testTheOrderPageOffersItOnlyWhenThereAreServiceLines(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/views/orders/show.php');
        self::assertStringContainsString('<input type="hidden" name="document_type" value="ESI">', $src);
        self::assertStringContainsString("<?php if (\$__serviceLines && \$canGenerateDocuments && \$sees['selling']): ?>", $src);
    }
}
