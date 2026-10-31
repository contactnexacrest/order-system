<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\DocumentController;
use App\Controllers\FileStoreController;
use App\Controllers\OrderController;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Services\MoneyVisibility;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-214 (2026-10-31), phase 2: who sees which money. Sales
 * sees the selling side, Procurement the buying side, Logistics only
 * logistics costs; Finance, management, the auditors and the CA see
 * everything — on the order page, and for every document and stored file.
 * The Node suite has the same test (moneySeparation.test.js).
 */
final class MoneySeparationTest extends DbTestCase
{
    /** role => [selling, buyer payments, purchase, logistics, Supplier PO, settle] */
    private const MATRIX = [
        'Export Executive' => [1, 1, 0, 0, 0, 0],
        'Sales/Export Manager' => [1, 1, 0, 0, 0, 0],
        'Procurement Executive' => [0, 0, 1, 0, 1, 0],
        'Procurement Manager' => [0, 0, 1, 0, 1, 0],
        'Logistics Executive' => [0, 0, 0, 1, 0, 0],
        'Logistics Manager' => [0, 0, 0, 1, 0, 0],
        'Accounts Executive' => [1, 1, 1, 1, 0, 1],
        'Finance Manager' => [1, 1, 1, 1, 0, 1],
        'Managing Director' => [1, 1, 1, 1, 1, 1],
        'Viewer / Auditor' => [1, 1, 1, 1, 0, 0],
        'CA / Chartered Accountant' => [1, 1, 1, 1, 0, 0],
        'System Administrator' => [0, 0, 0, 0, 0, 0],
    ];
    private const KEYS = ['view_selling_prices', 'view_buyer_payments', 'view_purchase_prices', 'view_logistics_costs', 'manage_supplier_po', 'settle_orders'];

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
        http_response_code(200);
        foreach ($this->written as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
        \App\Services\ActiveDepartmentService::resetCache();
    }

    /** @return array{0:int,1:string} status and body */
    private function call(object $controller, string $action, array $params): array
    {
        http_response_code(200);
        ob_start();
        $controller->{$action}($params);
        $body = (string) ob_get_clean();
        return [(int) http_response_code(), $body];
    }

    public function testEachRoleSeesExactlyItsOwnMoney(): void
    {
        foreach (self::MATRIX as $role => $expected) {
            $roleRow = RoleRepository::findByName($role);
            self::assertNotNull($roleRow, $role);
            $effective = PermissionRepository::effectivePermissions(0, (int) $roleRow['id']);
            $actual = array_map(static fn (string $k): int => empty($effective[$k]) ? 0 : 1, self::KEYS);
            self::assertSame($expected, $actual, "{$role}: " . implode(', ', self::KEYS));
        }
    }

    public function testDocumentsCarryTheirMoneyClass(): void
    {
        foreach (['QT', 'PI', 'OC', 'CI', 'AMD', 'FDN', 'BUYERPO', 'COOPREP'] as $code) {
            self::assertSame(MoneyVisibility::SELLING, MoneyVisibility::documentClass($code), $code);
        }
        self::assertSame(MoneyVisibility::PURCHASE, MoneyVisibility::documentClass('SUPPO'));
        self::assertSame(MoneyVisibility::LOGISTICS, MoneyVisibility::documentClass('BLI'));
        self::assertNull(MoneyVisibility::documentClass('PL'));
    }

    private array $written = [];

    private function writeTestFile(int $fileId): void
    {
        $path = (string) \App\Repositories\FileStoreRepository::find($fileId)['server_path'];
        file_put_contents($path, '%PDF-1.4 test');
        $this->written[] = $path;
    }

    /** A document or file whose money the person may not see answers 403; one they may see downloads (200). */
    public function testDownloadsFollowTheMoneyAndTheOrder(): void
    {
        $cases = [
            ['Export Executive', 'CI', 200], ['Export Executive', 'SUPPO', 403], ['Export Executive', 'BLI', 403],
            ['Logistics Executive', 'CI', 403], ['Logistics Executive', 'SUPPO', 403], ['Logistics Executive', 'BLI', 200], ['Logistics Executive', 'PL', 200],
            ['Accounts Executive', 'SUPPO', 200], ['Accounts Executive', 'CI', 200],
        ];
        foreach ($cases as [$role, $code, $expected]) {
            $user = $this->createTestUser($role);
            $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $user);
            $fileId = $this->createTestFile($orderId);
            $this->writeTestFile($fileId);
            $documentId = $this->createTestDocument($orderId, $code, 'approved', $fileId);
            $this->signIn($user);
            self::assertSame($expected, $this->call(new DocumentController(), 'download', ['documentId' => (string) $documentId])[0], "{$role} downloading {$code}");
            self::assertSame($expected, $this->call(new FileStoreController(), 'download', ['id' => (string) $fileId])[0], "{$role} downloading the {$code} file");
        }

        // Not on the order: refused even for a document type the person may see.
        $other = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $documentId = $this->createTestDocument($orderId, 'CI', 'approved', $this->createTestFile($orderId));
        $this->signIn($other);
        self::assertSame(403, $this->call(new DocumentController(), 'download', ['documentId' => (string) $documentId])[0]);
    }

    public function testTheOrderPageShowsEachDepartmentOnlyItsMoney(): void
    {
        $markers = ['selling' => '<th>Unit Price</th>', 'fob' => '>FOB Value<'];
        foreach (['Logistics Executive' => false, 'Viewer / Auditor' => true] as $role => $sees) {
            $user = $this->createTestUser($role);
            $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $user);
            \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($orderId);
            \App\Repositories\OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 6100.0, '2026-06-01');
            $this->signIn($user);
            [, $html] = $this->call(new OrderController(), 'show', ['id' => (string) $orderId]);
            foreach ($markers as $name => $marker) {
                self::assertSame($sees, str_contains($html, $marker), "{$role}: {$name}");
            }
            self::assertSame($sees, (bool) preg_match('/6,100\.00/', $html), "{$role}: buyer payment amount");
        }
    }
}
