<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #11: the Buyer PO document (and, since this field is
 * read from the same shared `buyer` array by every DOCX builder branch —
 * QT, PI, OC, FDN, BUYERPO, BLI, CI — effectively every document type's
 * DOCX output) printed $order['consignee_name']/$order['consignee_address']
 * verbatim. Those two columns live on `clients` and are frequently NULL
 * whenever consignee_same_as_buyer is set (the default/common case,
 * meaning "the consignee IS the buyer's own current details") — a wiring
 * gap, not a missing feature, since DocumentDataAssembler::resolveConsignee()
 * already computes the correct value earlier in the very same assemble()
 * call and every OTHER consignee-aware field already defers to it. Fixed
 * by wiring buyer.consignee_name/buyer.consignee_address to the
 * already-resolved $resolvedConsignee instead of the raw columns.
 */
final class DocumentDataAssemblerBuyerConsigneeTest extends DbTestCase
{
    public function testBuyerConsigneeFieldsResolveToBuyersOwnDetailsWhenSameAsBuyer(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertTrue($data['consignee']['same_as_buyer']);
        self::assertSame('PHPUnit Test Buyer Ltd', $data['buyer']['consignee_name'], 'when same-as-buyer, consignee_name must be the buyer\'s own company name, not the raw (NULL) clients.consignee_name column');
        self::assertSame('1 Test Street, Test City', $data['buyer']['consignee_address']);
        self::assertSame($data['consignee']['company_legal_name'], $data['buyer']['consignee_name'], 'buyer.consignee_name must match the already-resolved consignee block exactly');
        self::assertSame($data['consignee']['address_line1'], $data['buyer']['consignee_address']);
    }

    public function testBuyerConsigneeFieldsUseIndependentConsigneeWhenNotSameAsBuyer(): void
    {
        $clientId = $this->createTestClient();
        Database::connection()->prepare(
            'UPDATE clients SET
                consignee_same_as_buyer = 0,
                consignee_name = :name, consignee_address_line1 = :addr1, consignee_city = :city
             WHERE id = :id'
        )->execute([
            'name'  => 'Independent Consignee Co',
            'addr1' => '5 Consignee Avenue',
            'city'  => 'Manchester',
            'id'    => $clientId,
        ]);
        $orderId = $this->createTestOrder($clientId);

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertFalse($data['consignee']['same_as_buyer']);
        self::assertSame('Independent Consignee Co', $data['buyer']['consignee_name']);
        self::assertSame('5 Consignee Avenue', $data['buyer']['consignee_address']);
        self::assertSame($data['consignee']['company_legal_name'], $data['buyer']['consignee_name']);
        self::assertSame($data['consignee']['address_line1'], $data['buyer']['consignee_address']);
    }
}
