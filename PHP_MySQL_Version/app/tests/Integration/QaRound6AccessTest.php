<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Controllers\DisputeController;
use App\Controllers\DocumentController;
use App\Controllers\FileStoreController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\ReportRepository;
use App\Services\OrderAccessService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 6, Batch 1 (owner approved the plan 2026-10-30): the order
 * assignment rule on child-record routes (R6-N08), reports and the client
 * page scoped to the orders the user may see (R6-N01 / Q-R6-4), the
 * Disputes list open to respond_to_disputes and scoped (R6-N09 / Q-R6-3),
 * CAFIN download needing the order too (Q-R6-2), and an advance with an
 * oversized rate saving nothing (R6-N22). The Node suite has the same
 * test (qaRound6Access.test.js).
 */
final class QaRound6AccessTest extends DbTestCase
{
    private int $exporter;
    private int $mine;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = $this->createTestUser('Export Executive');
        $client = $this->createTestClient();
        $this->mine = $this->createTestOrder($client, 'CIF', 'Standard — New Buyer', 'USD', null, null, $this->exporter);
        $this->other = $this->createTestOrder($this->createTestClient());
        // Both orders under one client, as on the QA site (clients 9 and 10).
        Database::connection()->prepare('UPDATE orders SET client_id = :c, sequence_no = 2 WHERE id = :o')->execute(['c' => $client, 'o' => $this->other]);
        $this->actAs($this->exporter);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
        $reset = new \ReflectionProperty(OrderAccessService::class, 'openableCache');
        $reset->setValue(null, null);
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    private function reference(int $orderId): string
    {
        return (string) Database::connection()->query('SELECT order_reference FROM orders WHERE id = ' . $orderId)->fetchColumn();
    }

    /** R6-N08: a dispute, complaint or amendment of an order the user cannot open is refused like the order itself. */
    public function testChildRecordRoutesFollowTheOrdersAssignmentRule(): void
    {
        $pdo = Database::connection();
        $ids = [];
        foreach ([$this->mine, $this->other] as $orderId) {
            $pdo->prepare("INSERT INTO disputes (order_id, notice_date, description) VALUES (:o, CURDATE(), 'PHPUnit dispute')")->execute(['o' => $orderId]);
            $dispute = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO order_complaints (order_id, client_id, description) SELECT id, client_id, 'PHPUnit complaint' FROM orders WHERE id = :o")->execute(['o' => $orderId]);
            $complaint = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO amendments (amendment_reference, order_id, reason, requested_by, original_terms_snapshot) VALUES (:r, :o, 'PHPUnit amendment', 'importer', '{}')")
                ->execute(['r' => 'PHPUNIT-AMD-' . $orderId, 'o' => $orderId]);
            $ids[$orderId] = ["/disputes/{$dispute}/replies", "/complaints/{$complaint}/replies", '/amendments/' . $pdo->lastInsertId() . '/generate-document'];
        }
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'Export Executive'")->fetchColumn();
        foreach ($ids[$this->other] as $path) {
            self::assertSame((int) $this->other, OrderAccessService::orderIdForPath($path), $path);
            self::assertNotNull(OrderAccessService::denialReasonForPath($path, $this->exporter, $roleId), "{$path} is refused on an order not assigned to the user");
        }
        foreach ($ids[$this->mine] as $path) {
            self::assertNull(OrderAccessService::denialReasonForPath($path, $this->exporter, $roleId), "{$path} stays open on the user's own order");
        }
    }

    /** R6-N01 / Q-R6-4: reports and the client page list only the orders the user may see. */
    public function testReportsAndTheClientPageShowOnlyVisibleOrders(): void
    {
        $pdo = Database::connection();
        foreach ([$this->mine, $this->other] as $orderId) {
            $pdo->prepare('UPDATE order_payment_status SET advance_amount = 100, advance_remittance_received_at = NOW() WHERE order_id = :o')->execute(['o' => $orderId]);
        }
        $orderIds = array_map(static fn (array $r): int => (int) $r['id'], ReportRepository::paymentsReport(null, null)['rows']);
        self::assertContains($this->mine, $orderIds);
        self::assertNotContains($this->other, $orderIds, 'the payments report leaves out an order not assigned to the user');

        $clientId = (int) $pdo->query('SELECT client_id FROM orders WHERE id = ' . $this->mine)->fetchColumn();
        $perClient = array_map(static fn (array $r): int => (int) $r['id'], ReportRepository::perClient($clientId)['orders']);
        self::assertSame([$this->mine], $perClient);

        $page = $this->render(fn () => (new ClientController())->show(['id' => (string) $clientId]));
        self::assertStringContainsString($this->reference($this->mine), $page);
        self::assertStringNotContainsString($this->reference($this->other), $page);

        // Admin (view_all_orders) still sees both.
        $this->actAs($this->createTestUser('Admin'));
        $orderIds = array_map(static fn (array $r): int => (int) $r['id'], ReportRepository::paymentsReport(null, null)['rows']);
        self::assertContains($this->other, $orderIds);
    }

    /** R6-N09 / Q-R6-3: the Export Executive opens the Disputes list, which shows their own orders' disputes only. */
    public function testTheDisputesListOpensToRespondersAndIsScoped(): void
    {
        $route = array_values(array_filter(OrderPageActionsMatchPermissionsTest::routes(), static fn (array $r): bool => $r['method'] === 'GET' && $r['pattern'] === '/disputes'))[0];
        self::assertContains('respond_to_disputes', $route['permissions']);
        $route = array_values(array_filter(OrderPageActionsMatchPermissionsTest::routes(), static fn (array $r): bool => $r['method'] === 'GET' && $r['pattern'] === '/complaints'))[0];
        self::assertContains('respond_to_complaints', $route['permissions']);

        $pdo = Database::connection();
        foreach ([$this->mine => 'PHPUnit mine dispute', $this->other => 'PHPUnit other dispute'] as $orderId => $text) {
            $pdo->prepare('INSERT INTO disputes (order_id, notice_date, description) VALUES (:o, CURDATE(), :d)')->execute(['o' => $orderId, 'd' => $text]);
        }
        $page = $this->render(fn () => (new DisputeController())->index([]));
        self::assertStringContainsString($this->reference($this->mine), $page);
        self::assertStringNotContainsString($this->reference($this->other), $page);
    }

    /** Q-R6-2 (D-118): CAFIN needs view_cafin and the order itself. */
    public function testCafinDownloadAlsoNeedsTheOrder(): void
    {
        $fileId = $this->createTestFile($this->other);
        $path = (string) Database::connection()->query('SELECT server_path FROM file_store WHERE id = ' . $fileId)->fetchColumn();
        file_put_contents($path, '%PDF-1.4 CAFIN');
        $documentId = $this->createTestDocument($this->other, 'CAFIN', 'approved', $fileId);
        try {
            // Holds view_cafin but neither manage_orders nor view_orders: the order page answers 403.
            $userId = $this->createTestUser('Viewer / Auditor');
            $pdo = Database::connection();
            foreach (['view_cafin' => 1, 'view_orders' => 0] as $key => $enabled) {
                $permissionId = (int) $pdo->query("SELECT id FROM permissions WHERE permission_key = '{$key}'")->fetchColumn();
                $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:u, :p, :e)')->execute(['u' => $userId, 'p' => $permissionId, 'e' => $enabled]);
            }
            $this->actAs($userId);
            self::assertFalse(OrderAccessService::currentUserMayOpenOrderId($this->other));
            self::assertSame('You do not have permission to view this document.', $this->render(fn () => (new DocumentController())->download(['documentId' => (string) $documentId])));
            self::assertSame('You do not have permission to view this document.', $this->render(fn () => (new FileStoreController())->download(['id' => (string) $fileId])));

            $this->actAs($this->createTestUser('Accounts Executive'));
            self::assertSame('%PDF-1.4 CAFIN', $this->render(fn () => (new FileStoreController())->download(['id' => (string) $fileId])), 'Accounts (view_cafin, manage_orders, every order) still downloads it');
        } finally {
            @unlink($path);
        }
    }

    /** R6-N22: an exchange rate too large to store is refused before anything is saved. */
    public function testAnOversizedAdvanceRateSavesNothing(): void
    {
        $this->actAs($this->createTestUser('Admin'));
        $_POST = ['advance_amount' => '1020', 'advance_exchange_rate' => '100000000000000000000', 'advance_received_at' => date('Y-m-d')];
        (new OrderController())->recordAdvancePayment(['id' => (string) $this->mine]);
        self::assertSame('error', Flash::pull()[0]['type']);
        $row = Database::connection()->query('SELECT advance_amount, advance_remittance_received_at, advance_exchange_rate FROM order_payment_status WHERE order_id = ' . $this->mine)->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([null, null, null], [$row['advance_amount'] ?? null, $row['advance_remittance_received_at'] ?? null, $row['advance_exchange_rate'] ?? null]);
    }

    /** R6-N22: what Database::transaction() wraps is all saved or none of it. */
    public function testATransactionLeavesNothingBehindWhenItFails(): void
    {
        try {
            Database::transaction(function (): void {
                Database::connection()->prepare('UPDATE orders SET buyers_po_ref = :r WHERE id = :o')->execute(['r' => 'PHPUNIT-TX', 'o' => $this->mine]);
                throw new \RuntimeException('second write failed');
            });
            self::fail('the failure is passed on');
        } catch (\RuntimeException $e) {
            self::assertSame('second write failed', $e->getMessage());
        }
        self::assertNotSame('PHPUNIT-TX', Database::connection()->query('SELECT buyers_po_ref FROM orders WHERE id = ' . $this->mine)->fetchColumn());
    }
}
