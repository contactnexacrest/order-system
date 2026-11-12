<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderAnnexureTermsRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Services\AnnexureChoice;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-336 (12 Nov 2026, owner confirmed twice): an order line "As per
 * annexure", Annexure B (as shipped) and the annexure printed with the Commercial
 * Invoice and Packing List. Twin of Node tests/integration/annexureBAsPerAnnexure.test.js.
 */
final class AnnexureBAndAsPerAnnexureTest extends DbTestCase
{
    /** An order with one goods line of 8.801 CBM ticked "As per annexure". */
    private function orderWithTickedLine(): array
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $lineId = OrderProductRepository::add($orderId, 1, 'Absolute black granite', '180 x 120 x 15 cm', 'Polished', '8.801', false, 'CBM', '2834.54', '68029300');
        OrderProductRepository::setAsPerAnnexure($lineId, true);
        return [$orderId, $lineId];
    }

    private static function annexureProduct(int $orderId, string $which, string $dimensions): int
    {
        return OrderAnnexureRepository::createProduct($orderId, [
            'name' => 'Slab', 'description' => 'Slab', 'dimensions' => $dimensions, 'finish' => 'Polished', 'components' => '', 'technical_notes' => '',
        ], $which);
    }

    public function testQuotationAndPiSayAsPerAnnexureA(): void
    {
        [$orderId] = $this->orderWithTickedLine();
        foreach (['QT', 'PI', 'OC', 'BUYERPO'] as $code) {
            $line = DocumentDataAssembler::assemble($orderId, $code)['products'][0];
            self::assertSame('8.801 (as per Annexure A)', $line['quantity'], $code);
            self::assertSame('As per Annexure A', $line['dimensions'], $code);
        }
    }

    public function testCommercialInvoiceWithAnnexureBChosenSaysAnnexureBAndPrintsB(): void
    {
        [$orderId] = $this->orderWithTickedLine();
        OrderRepository::setIncludeAnnexureA($orderId, true);
        self::annexureProduct($orderId, 'A', 'QUOTED 180 x 120 x 15 cm');
        self::annexureProduct($orderId, 'B', 'SHIPPED 181 x 121 x 15 cm');
        OrderRepository::setCiAnnexure($orderId, 'B');

        foreach (['CI', 'PL'] as $code) {
            $data = DocumentDataAssembler::assemble($orderId, $code);
            self::assertSame('B', $data['annexure_letter'], $code);
            self::assertTrue($data['order']['include_annexure_a'], $code);
            self::assertTrue($data['annexure_has_content'], $code);
            self::assertSame('SHIPPED 181 x 121 x 15 cm', $data['annexure_products'][0]['dimensions'], $code);
        }
        $ci = DocumentDataAssembler::assemble($orderId, 'CI')['products'][0];
        self::assertSame('8.801 (as per Annexure B)', $ci['quantity']);
        self::assertSame('As per Annexure B', $ci['dimensions']);
        // The Quotation keeps Annexure A.
        $qt = DocumentDataAssembler::assemble($orderId, 'QT');
        self::assertSame('A', $qt['annexure_letter']);
        self::assertSame('QUOTED 180 x 120 x 15 cm', $qt['annexure_products'][0]['dimensions']);
    }

    public function testLineWithoutTheTickIsUnchanged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderId, 1, 'Granite', '180 x 80 x 13 cm', 'Polished', '40', false, 'PCS', '100', '68029300');
        $line = DocumentDataAssembler::assemble($orderId, 'CI')['products'][0];
        self::assertSame('40', $line['quantity']);
        self::assertSame('180 x 80 x 13 cm', $line['dimensions']);
        self::assertSame('A', DocumentDataAssembler::assemble($orderId, 'CI')['annexure_letter']);
    }

    public function testCiDefaultIsBWhenIncludedAndFilledElseAElseNone(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertSame('NONE', AnnexureChoice::ciDefault(OrderRepository::find($orderId)));
        OrderRepository::setIncludeAnnexureA($orderId, true);
        self::annexureProduct($orderId, 'A', '1 x 1 x 1 cm');
        self::assertSame('A', AnnexureChoice::ciDefault(OrderRepository::find($orderId)));
        self::annexureProduct($orderId, 'B', '2 x 2 x 2 cm');
        self::assertSame('A', AnnexureChoice::ciDefault(OrderRepository::find($orderId)), 'B not included yet');
        OrderRepository::setIncludeAnnexureB($orderId, true);
        self::assertSame('B', AnnexureChoice::ciDefault(OrderRepository::find($orderId)));
        OrderRepository::setCiAnnexure($orderId, 'A');
        self::assertSame('A', AnnexureChoice::ciChoice(OrderRepository::find($orderId)), 'a saved choice wins');
    }

    public function testSafetyChecksRefuseWithTheExactWording(): void
    {
        [$orderId] = $this->orderWithTickedLine();
        self::assertSame('Line 1 says "As per annexure" but Annexure A has no product yet — add it in Annexure A (order page › Annexure A), then generate again.', AnnexureChoice::blockReason($orderId, 'QT'));
        self::assertSame('Line 1 says "As per annexure" but "No annexure" is chosen for the Commercial Invoice and Packing List — choose Annexure B (as shipped) or Annexure A (as quoted), then generate again.', AnnexureChoice::blockReason($orderId, 'CI'));
        OrderRepository::setIncludeAnnexureA($orderId, true);
        self::annexureProduct($orderId, 'A', '1 x 1 x 1 cm');
        self::assertNull(AnnexureChoice::blockReason($orderId, 'PI'));
        self::assertSame('Line 1 says "As per annexure" but Annexure B (as shipped) has no product yet — add it, or choose Annexure A, then generate again.', AnnexureChoice::blockReason($orderId, 'PL', 'B'));
        self::assertNull(AnnexureChoice::blockReason($orderId, 'CI'), 'default is Annexure A, which has a product');
        self::assertNull(AnnexureChoice::blockReason($orderId, 'FDN'), 'other documents are not checked');
    }

    public function testCopyFromAReplacesBWithProductsImagesTermsAndMode(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $a = self::annexureProduct($orderId, 'A', '1 x 1 x 1 cm');
        $fileId = $this->createTestFile($orderId);
        OrderAnnexureRepository::addImage($a, $fileId);
        self::annexureProduct($orderId, 'B', 'OLD B');
        OrderAnnexureTermsRepository::upsert($orderId, '<p>Terms A</p>', $this->createTestUser('Admin'), 'A');
        OrderRepository::setAnnexureMode($orderId, 'BOTH');

        self::assertSame(1, AnnexureChoice::copyAToB(OrderRepository::find($orderId), $this->createTestUser('Admin')));
        $b = OrderAnnexureRepository::forOrder($orderId, 'B');
        self::assertCount(1, $b);
        self::assertSame('1 x 1 x 1 cm', $b[0]['dimensions']);
        self::assertSame($fileId, (int) $b[0]['images'][0]['file_id'], 'the same stored file');
        self::assertCount(1, OrderAnnexureRepository::forOrder($orderId, 'A'), 'Annexure A untouched');
        self::assertSame('<p>Terms A</p>', OrderAnnexureTermsRepository::find($orderId, 'B')['content_html'] ?? null);
        self::assertSame('BOTH', OrderRepository::find($orderId)['annexure_b_mode']);
        self::assertSame('<p>Terms A</p>', OrderAnnexureTermsRepository::find($orderId, 'A')['content_html']);
    }

    public function testTickBoxAndQtyToBeConfirmedAreMutuallyExclusive(): void
    {
        $method = new \ReflectionMethod(OrderController::class, 'annexureLineInput');
        $call = static function (array $post, mixed $current = null) use ($method): array {
            $args = [&$post, $current];
            $result = $method->invokeArgs(null, $args);
            return [$result, $post];
        };

        [[$ticked, $error]] = $call(['as_per_annexure_field' => '1', 'as_per_annexure' => '1', 'quantity_is_tbc' => '1', 'quantity' => '5']);
        self::assertTrue($ticked);
        self::assertSame('tick either "Qty To Be Confirmed" or "As per annexure", not both.', $error);

        [[, $error]] = $call(['as_per_annexure_field' => '1', 'as_per_annexure' => '1', 'quantity' => '']);
        self::assertSame('a line "As per annexure" still needs its quantity — enter the Qty.', $error);

        [[$ticked, $error], $post] = $call(['as_per_annexure_field' => '1', 'as_per_annexure' => '1', 'quantity' => '8.801', 'piece_length_cm' => '180', 'dimensions' => '180 x 1']);
        self::assertTrue($ticked);
        self::assertNull($error);
        self::assertSame('', $post['piece_length_cm']);
        self::assertSame('', $post['dimensions']);

        [[$ticked]] = $call(['quantity' => '1'], 1);
        self::assertTrue($ticked, 'a form without the tick box keeps the line as it is');
        [[$ticked]] = $call(['as_per_annexure_field' => '1', 'quantity' => '1'], 1);
        self::assertFalse($ticked, 'unticked in the line form');
    }

    public function testDuplicateKeepsTheTick(): void
    {
        [, $lineId] = $this->orderWithTickedLine();
        $copy = OrderProductRepository::duplicate($lineId);
        self::assertSame(1, (int) OrderProductRepository::find($copy)['as_per_annexure']);
    }
}
