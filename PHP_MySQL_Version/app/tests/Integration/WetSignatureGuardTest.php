<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderSupplierPoDocumentRepository;
use App\Services\WetSignatureGuardService;
use App\Tests\Support\DbTestCase;

/**
 * Regression coverage for the wet-signature-required flag concept (task
 * tracker item #106): recordBuyerPo() (Stage 2) and confirmSupplierSigned()
 * (Stage 5) used to pass their gate on a button click alone, with no
 * upload of the counterparty's actual signed copy ever required. Pins the
 * fix — WetSignatureGuardService — in both its default-on (blocking) and
 * satisfied (unblocked) states, plus the Admin-toggle-off escape hatch.
 */
final class WetSignatureGuardTest extends DbTestCase
{
    public function testBuyerPoBlockedByDefaultWithNoUploadedCopy(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        self::assertSame('1', CompanySettingsRepository::get('wet_signature_required_buyer_po'), 'flag must default on');
        self::assertTrue(WetSignatureGuardService::buyerPoBlocked($orderId));
    }

    public function testBuyerPoUnblockedOnceACopyIsAttached(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $fileId = $this->createTestFile($orderId);

        OrderBuyerPoDocumentRepository::attach($orderId, $fileId);

        self::assertFalse(WetSignatureGuardService::buyerPoBlocked($orderId));
    }

    public function testBuyerPoNeverBlockedWhenFlagIsOff(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        CompanySettingsRepository::set('wet_signature_required_buyer_po', '0');
        try {
            self::assertFalse(WetSignatureGuardService::buyerPoBlocked($orderId));
        } finally {
            CompanySettingsRepository::set('wet_signature_required_buyer_po', '1');
        }
    }

    public function testSupplierPoBlockedByDefaultWithNoUploadedAcknowledgment(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $supplierPoId = $this->createTestSupplierPo($orderId);

        self::assertSame('1', CompanySettingsRepository::get('wet_signature_required_supplier_po'), 'flag must default on');
        self::assertTrue(WetSignatureGuardService::supplierPoBlocked($supplierPoId));
    }

    public function testSupplierPoUnblockedOnceAnAcknowledgmentIsAttached(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $supplierPoId = $this->createTestSupplierPo($orderId);
        $fileId = $this->createTestFile($orderId);

        OrderSupplierPoDocumentRepository::attach($supplierPoId, $fileId);

        self::assertFalse(WetSignatureGuardService::supplierPoBlocked($supplierPoId));
    }

    private function createTestFile(int $orderId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
             VALUES (:order_id, 'RECEIVED', '/tmp/phpunit-test-file.pdf', :uuid, 'signed-copy.pdf', 1024, 'application/pdf')"
        );
        $stmt->execute(['order_id' => $orderId, 'uuid' => bin2hex(random_bytes(16)) . '.pdf']);
        return (int) $pdo->lastInsertId();
    }

    private function createTestSupplierPo(int $orderId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO suppliers (supplier_legal_name) VALUES (:name)');
        $stmt->execute(['name' => 'WetSig Test Supplier Co']);
        $supplierId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO order_supplier_po (order_id, supplier_id, supplier_po_reference)
             VALUES (:order_id, :supplier_id, 'WETSIG-SPO-TEST')"
        );
        $stmt->execute(['order_id' => $orderId, 'supplier_id' => $supplierId]);
        return (int) $pdo->lastInsertId();
    }
}
