<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\BuyerActionController;
use App\Controllers\OrderController;
use App\Controllers\SupplierActionController;
use App\Helpers\Flash;
use App\Repositories\OrderRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * Owner rule (2026-10-27, non-negotiable): the supplier and the buyer are
 * two different parties and never cross. A supplier link opens only the
 * supplier's page, a buyer link only the buyer's; Step 7c only ever offers
 * the Supplier Action Link; and the Supplier PO document carries nothing
 * about the client or the client's order.
 */
final class SupplierBuyerSeparationTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        Flash::pull();
    }

    private function orderWithSupplierPo(): array
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        \App\Config\Database::connection()
            ->prepare("UPDATE orders SET buyer_inquiry_ref = 'BUYER-INQ-7781' WHERE id = :id")
            ->execute(['id' => $orderId]);
        // Step 7 (stage 5) open, so the 7c card shows its contents.
        \App\Config\Database::connection()
            ->prepare("UPDATE order_stages SET status = 'in_progress' WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = 5)")
            ->execute(['id' => $orderId]);
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Separation Quarry Co']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SEP-SPO-' . $orderId, [
            'material_stone_type' => 'Kota Blue', 'grade' => null, 'surface_finish' => null,
            'dimensions' => null, 'dimensional_tolerance' => null, 'quantity' => '100',
            'unit' => 'sqm', 'colour_reference' => null, 'special_requirements' => null,
            'unit_price_inr' => '450', 'basic_value_inr' => '45000', 'gst_rate_pct' => '5',
            'gst_amount_inr' => '2250', 'total_payable_inr' => '47250', 'advance_pct' => '30',
            'advance_amount_inr' => '14175', 'balance_amount_inr' => '33075',
            'delivery_location' => 'Kandla CFS', 'required_delivery_date' => '2026-12-01', 'packing_requirement' => null,
        ], 'issued');
        return [$orderId, OrderSupplierPoRepository::findLatestForOrder($orderId)];
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    public function testStep7cOffersOnlyTheSupplierLinkAndNoneBeforeItIsGenerated(): void
    {
        [$orderId] = $this->orderWithSupplierPo();
        // A live buyer link on the same order must never leak into 7c.
        $buyerToken = OrderRepository::generateBuyerActionToken($orderId);

        $page = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        $box = $this->supplierLinkBox($page);
        self::assertStringContainsString('Generate Supplier Link', $box);
        self::assertStringNotContainsString('aria-label="Supplier Action Link"', $box, 'no link is shown before one is generated');
        self::assertStringNotContainsString('buyer-action', $box);
        self::assertStringNotContainsString($buyerToken, $box);

        (new OrderController())->generateSupplierActionLink(['id' => (string) $orderId]);
        Flash::pull();
        $box = $this->supplierLinkBox($this->render(fn () => (new OrderController())->show(['id' => (string) $orderId])));
        self::assertMatchesRegularExpression('#/supplier-action/[0-9a-f]{64}"#', $box);
        self::assertStringNotContainsString('buyer-action', $box);
        self::assertStringNotContainsString($buyerToken, $box);
    }

    private function supplierLinkBox(string $page): string
    {
        $start = strpos($page, 'class="supplier-action-link"');
        self::assertNotFalse($start, 'the 7c Supplier Action Link box is on the order page');
        return substr($page, $start, (int) strpos($page, '</form>', $start) - $start);
    }

    public function testASupplierLinkNeverOpensTheBuyerPageAndABuyerLinkNeverOpensTheSupplierPage(): void
    {
        [$orderId, $supplierPo] = $this->orderWithSupplierPo();
        $buyerToken = OrderRepository::generateBuyerActionToken($orderId);
        $supplierToken = OrderSupplierPoRepository::generateActionToken((int) $supplierPo['id']);

        self::assertStringContainsString('Link No Longer Valid', $this->render(fn () => (new BuyerActionController())->show(['token' => $supplierToken])));
        self::assertStringContainsString('Link No Longer Valid', $this->render(fn () => (new SupplierActionController())->show(['token' => $buyerToken])));

        $supplierPage = $this->render(fn () => (new SupplierActionController())->show(['token' => $supplierToken]));
        $order = OrderRepository::find($orderId);
        self::assertStringContainsString('SEP-SPO-' . $orderId, $supplierPage);
        foreach ([(string) $order['company_legal_name'], 'BUYER-INQ-7781', (string) $order['order_reference']] as $clientDetail) {
            self::assertStringNotContainsString($clientDetail, $supplierPage);
        }
    }

    public function testTheSupplierPoDocumentCarriesNothingAboutTheClient(): void
    {
        [$orderId] = $this->orderWithSupplierPo();
        $order = OrderRepository::find($orderId);
        $data = DocumentDataAssembler::assemble($orderId, 'SUPPO');
        $json = (string) json_encode($data);

        self::assertSame('SEP-SPO-' . $orderId, $data['supplier_po']['supplier_po_reference']);
        self::assertStringContainsString('Kandla CFS', $json);
        foreach (array_filter([(string) $order['company_legal_name'], 'BUYER-INQ-7781', (string) $order['order_reference'], (string) $order['client_email']]) as $clientDetail) {
            self::assertStringNotContainsString($clientDetail, $json, "the Supplier PO must not carry the client's {$clientDetail}");
        }
        self::assertSame([], $data['buyer']);
        self::assertSame([], $data['products']);
        self::assertNull($data['payment_status']);
        self::assertFalse($data['order']['has_active_amendment']);
        self::assertSame('30', $data['financial']['advance_pct'], "clause placeholders use the supplier's own advance, never the client's");

        // A buyer-facing document still gets the full client context.
        self::assertSame($order['company_legal_name'], DocumentDataAssembler::assemble($orderId, 'PI')['buyer']['company_legal_name']);
    }

    public function testTheSupplierPoTemplatesReferenceNoClientData(): void
    {
        $root = __DIR__ . '/../../..';
        foreach ([
            $root . '/app/templates/SUPPO/supplier_po.html.twig',
            $root . '/../NODE_MySQL_Version/app/templates/SUPPO/supplier_po.njk',
        ] as $path) {
            $template = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression('/\b(buyer|consignee|notify_party_block|products|financial|payment_status)\./', $template, basename($path));
            self::assertDoesNotMatchRegularExpression('/order\.(buyer_inquiry_ref|pi_ref|quotation_ref|buyers_po_ref|order_reference)/', $template, basename($path));
        }
    }
}
