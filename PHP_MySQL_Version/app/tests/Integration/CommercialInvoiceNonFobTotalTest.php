<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog item #18 (BLOCKER): for a non-FOB order, the
 * Commercial Invoice's TOTAL COMMERCIAL INVOICE VALUE must include
 * Freight & Insurance — the buyer-country customs/import-duty authority
 * assesses duty against the full CFR/CIF value, and a Commercial
 * Invoice that understates it (goods value only) risks customs delay
 * or a penalty for the buyer. FOB Value itself stays completely
 * unchanged everywhere (QT/PI/OC's own "Total ... Value" fields,
 * Section A's own FOB Value row, Section B's advance/balance payment
 * math) — this only adds a new, additional "total commercial invoice
 * value" figure used solely by the CI document.
 */
final class CommercialInvoiceNonFobTotalTest extends DbTestCase
{
    private function addTestProduct(int $orderId): void
    {
        OrderProductRepository::add($orderId, 1, 'Test granite slab', null, null, '10', false, 'PCS', '1825.00');
    }

    public function testNonFobTotalIncludesConfirmedStage6FreightWhenNoFdnPaymentRecordedYet(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        $this->addTestProduct($orderId);
        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => '1200.00', 'insurance_amount' => '150.00']);

        $data = DocumentDataAssembler::assemble($orderId, 'CI');

        // FOB value = 10 x 1825.00 = 18250.00; freight+insurance = 1350.00
        self::assertSame('18,250.00', $data['financial']['fob_value'], 'FOB Value itself must stay exactly as before');
        self::assertSame('1,350.00', $data['financial']['freight_insurance_amount']);
        self::assertSame('19,600.00', $data['financial']['total_commercial_invoice_value']);
    }

    public function testNonFobTotalPrefersTheActuallyClearedFdnPaymentAmountOverStage6Confirmed(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CFR');
        $this->addTestProduct($orderId);
        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => '1200.00', 'insurance_amount' => '150.00']);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordFreightReceived($orderId, 1500.00, date('Y-m-d'));

        $data = DocumentDataAssembler::assemble($orderId, 'CI');

        self::assertSame('1,500.00', $data['financial']['freight_insurance_amount'], 'the actually-recorded FDN payment amount must win over the Stage 6 confirmed figure');
        self::assertSame('19,750.00', $data['financial']['total_commercial_invoice_value']);
    }

    public function testFobOrderTotalEqualsFobValueOnly(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $this->addTestProduct($orderId);

        $data = DocumentDataAssembler::assemble($orderId, 'CI');

        self::assertSame('18,250.00', $data['financial']['total_commercial_invoice_value']);
        self::assertNull($data['financial']['freight_insurance_amount']);
    }

    public function testNonFobOrderWithNoFreightInfoYetFallsBackToFobValueOnly(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        $this->addTestProduct($orderId);

        $data = DocumentDataAssembler::assemble($orderId, 'CI');

        self::assertSame('18,250.00', $data['financial']['total_commercial_invoice_value'], 'with no freight figure recorded yet, the total must not silently drop to something wrong — it falls back to FOB value only');
        self::assertNull($data['financial']['freight_insurance_amount']);
    }

    public function testQtPiOcOwnTotalValueFieldIsCompletelyUnaffected(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        $this->addTestProduct($orderId);
        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => '1200.00', 'insurance_amount' => '150.00']);

        $data = DocumentDataAssembler::assemble($orderId, 'QT');

        self::assertSame('18,250.00', $data['financial']['total_value'], 'QT/PI/OC own "Total ... Value" field must never include freight — only the new CI-specific field does');
    }
}
