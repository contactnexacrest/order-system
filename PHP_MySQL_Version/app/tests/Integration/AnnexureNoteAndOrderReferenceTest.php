<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "Annexure note configurability + order_reference
 * display": the Annexure's Product Specification "Note:" banner was
 * hardcoded straight into the template (unlike bl_type_instruction/
 * bl_consignee_instruction's own admin-editable pattern), and the
 * assembler never exposed the order's own order_reference to any
 * document template at all — the Annexure's own meta table specifically
 * needs it.
 */
final class AnnexureNoteAndOrderReferenceTest extends DbTestCase
{
    public function testOrderReferenceIsExposedToEveryDocumentTemplate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $order = OrderRepository::find($orderId);

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertSame($order['order_reference'], $data['order']['order_reference']);
    }

    public function testAnnexureNoteFallsBackToTheOriginalWordingWhenUnconfigured(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertSame(
            'Product images and drawings are for reference only and may show optional accessories or decorative elements not included in the quoted price. All binding specifications are as stated in each product section below. Actual product appearance may vary slightly due to the natural characteristics of granite.',
            $data['annexure_product_note']
        );
    }

    public function testAnnexureNoteReadsTheConfiguredOverride(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        CompanySettingsRepository::set('annexure_product_note', 'Custom note: colours may vary by batch.');

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertSame('Custom note: colours may vary by batch.', $data['annexure_product_note']);
    }
}
