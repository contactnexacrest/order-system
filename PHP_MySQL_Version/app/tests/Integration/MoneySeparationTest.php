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

    /**
     * QA Round 7, R7-N02 — D-214: "Logistics sees the client name, Procurement
     * only the order number", on the order page header, its summary and the
     * order cards of both templates.
     */
    public function testProcurementSeesTheOrderNumberButNotTheClientName(): void
    {
        foreach (['Procurement Executive' => false, 'Procurement Manager' => false, 'Procurement Director' => false, 'Logistics Executive' => true, 'Sales Executive' => true] as $role => $seesClient) {
            if (\App\Repositories\RoleRepository::findByName($role) === null) {
                continue;
            }
            $user = $this->createTestUser($role);
            $clientId = $this->createTestClient();
            $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $user);
            $order = \App\Repositories\OrderRepository::find($orderId);
            $name = htmlspecialchars((string) $order['company_legal_name']);
            $this->signIn($user);
            [, $html] = $this->call(new OrderController(), 'show', ['id' => (string) $orderId]);
            self::assertStringContainsString(htmlspecialchars((string) $order['order_reference']), $html, "{$role}: the order number");
            self::assertSame($seesClient, str_contains($html, $name), "{$role}: the client name on the order page");
            foreach ([null, (int) \App\Repositories\UiTemplateRepository::findByKey('kanban')['id']] as $template) {
                \App\Repositories\UserRepository::updateUiTemplatePreference($user, $template);
                $this->signIn($user);
                $_GET = ['status' => 'all'];
                [, $list] = $this->call(new OrderController(), 'index', []);
                $_GET = [];
                self::assertSame($seesClient, str_contains($list, $name), "{$role}: the client name on the orders " . ($template === null ? 'list' : 'board'));
            }
        }
    }

    /**
     * QA Round 7, R7-N06: the order page links a received file (Buyer PO copy,
     * client payment screenshot, Supplier PO acknowledgment, …) only to
     * someone who may download it; otherwise it shows as text, never a link
     * that answers 403.
     */
    public function testTheOrderPageLinksOnlyFilesThePersonMayDownload(): void
    {
        $pdo = \App\Config\Database::connection();
        foreach (['Logistics Executive' => false, 'Viewer / Auditor' => true] as $role => $seesSelling) {
            $user = $this->createTestUser($role);
            $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $user);
            $buyerPoFile = $this->createTestFile($orderId);
            $pdo->prepare('INSERT INTO order_buyer_po_documents (order_id, file_id) VALUES (:o, :f)')->execute(['o' => $orderId, 'f' => $buyerPoFile]);
            \App\Services\StageGateService::passAndUnlockNext($orderId, 1, null);
            $this->signIn($user);
            [, $html] = $this->call(new OrderController(), 'show', ['id' => (string) $orderId]);
            preg_match_all('#href="/file-store/(\d+)/download"#', $html, $m);
            foreach (array_unique($m[1]) as $fileId) {
                self::assertTrue(\App\Services\MoneyVisibility::canSeeFile((int) $fileId), "{$role}: file {$fileId} is linked but would answer 403");
            }
            self::assertSame($seesSelling, in_array((string) $buyerPoFile, $m[1], true), "{$role}: the Buyer PO copy link");
        }
    }
}
