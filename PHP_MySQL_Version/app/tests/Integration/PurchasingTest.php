<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\PurchasingController;
use App\Helpers\Flash;
use App\Repositories\DepartmentRepository;
use App\Repositories\PurchasingRepository;
use App\Services\ActiveDepartmentService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-214 (2026-10-31), phase 3: Procurement. Sales raises a
 * requirement (no prices), Procurement records quotes, compares, awards (an
 * award above the designation's approval limit waits for an approver who is
 * not the awarder), and records receipt with the quality check. The Node
 * suite has the same test (purchasing.test.js).
 */
final class PurchasingTest extends DbTestCase
{
    private int $orderId;
    private int $supplierA;
    private int $supplierB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $this->supplierA = $this->createSupplier('Quarry A');
        $this->supplierB = $this->createSupplier('Quarry B');
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function createSupplier(string $name): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO suppliers (supplier_legal_name, is_active) VALUES (:n, 1)')->execute(['n' => $name . ' ' . bin2hex(random_bytes(3))]);
        return (int) $pdo->lastInsertId();
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
        ActiveDepartmentService::resetCache();
    }

    /** A user working as a designation (its role's rights and approval limit). */
    private function member(string $designation, ?float $limit = null): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT dd.id, r.name FROM department_designations dd JOIN roles r ON r.id = dd.role_id WHERE dd.title = :t');
        $stmt->execute(['t' => $designation]);
        $d = $stmt->fetch();
        $pdo->prepare('UPDATE department_designations SET approval_limit_inr = :l WHERE id = :id')->execute(['l' => $limit, 'id' => $d['id']]);
        $userId = $this->createTestUser($d['name']);
        $pdo->prepare('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)')->execute(['u' => $userId, 'o' => $this->orderId]);
        $m = DepartmentRepository::addMembership($userId, (int) $d['id'], date('Y-m-d'), $userId);
        $this->signIn($userId);
        ActiveDepartmentService::activate($userId, $m, false);
        return $userId;
    }

    /** @return array{type:?string,message:string} */
    private function post(string $action, array $body, array $params): array
    {
        $_POST = $body;
        (new PurchasingController())->{$action}($params);
        $_POST = [];
        $all = Flash::pull();
        return end($all) ?: ['type' => null, 'message' => ''];
    }

    private function raise(): int
    {
        $this->signIn($this->createTestUser('Admin'));
        $this->post('raise', ['description' => 'Black granite slabs 2cm', 'quantity' => '120', 'unit' => 'sqm', 'needed_by' => date('Y-m-d', strtotime('+20 days'))], ['id' => $this->orderId]);
        return (int) Database::connection()->query("SELECT MAX(id) FROM purchase_requirements WHERE order_id = {$this->orderId}")->fetchColumn();
    }

    public function testSalesRaisesARequirementWithChecks(): void
    {
        $this->signIn($this->createTestUser('Export Executive'));
        $body = ['description' => 'Black granite slabs 2cm', 'quantity' => '120', 'unit' => 'sqm', 'needed_by' => date('Y-m-d', strtotime('+20 days'))];
        self::assertSame('Enter the quantity needed (more than 0).', $this->post('raise', ['quantity' => '0'] + $body, ['id' => $this->orderId])['message']);
        self::assertSame('Enter the date it is needed by (today or later).', $this->post('raise', ['needed_by' => '2020-01-01'] + $body, ['id' => $this->orderId])['message']);
        $result = $this->post('raise', $body, ['id' => $this->orderId]);
        self::assertSame('success', $result['type']);
        self::assertMatchesRegularExpression('/^Purchase requirement PR-\d+ raised for Procurement: Black granite slabs 2cm, needed by \d{4}-\d{2}-\d{2}\.$/', $result['message']);
        $r = PurchasingRepository::forOrder($this->orderId)[0];
        self::assertSame('open', $r['status']);
        self::assertSame('120.000', (string) $r['quantity']);
    }

    public function testQuotesAreComparedCheapestWithGstFirstAndAnAwardWithinTheLimitIsApproved(): void
    {
        $id = $this->raise();
        $this->member('Procurement Manager', 500000.0);
        self::assertSame('Enter the GST % (0 to 28).', $this->post('addQuote', ['supplier_id' => $this->supplierA, 'unit_price_inr' => '1500', 'gst_percent' => '40', 'lead_time_days' => '14'], ['id' => $id])['message']);
        $this->post('addQuote', ['supplier_id' => $this->supplierA, 'unit_price_inr' => '1500', 'gst_percent' => '18', 'lead_time_days' => '14'], ['id' => $id]);
        $this->post('addQuote', ['supplier_id' => $this->supplierB, 'unit_price_inr' => '1450', 'gst_percent' => '18', 'lead_time_days' => '21'], ['id' => $id]);
        $quotes = PurchasingRepository::quotes($id);
        self::assertSame([$this->supplierB, $this->supplierA], array_map(static fn (array $q): int => (int) $q['supplier_id'], $quotes));
        self::assertSame('quoted', PurchasingRepository::find($id)['status']);

        $result = $this->post('award', ['quote_id' => $quotes[0]['id']], ['id' => $id]);
        self::assertStringContainsString('for ₹205,320.00. Approved — within your approval limit.', $result['message']);
        self::assertSame('approved', PurchasingRepository::find($id)['status']);
    }

    public function testAnAwardAboveTheLimitWaitsForAnotherApprover(): void
    {
        $id = $this->raise();
        $exec = $this->member('Procurement Executive', 0.0);
        $this->post('addQuote', ['supplier_id' => $this->supplierA, 'unit_price_inr' => '1500', 'gst_percent' => '18', 'lead_time_days' => '14'], ['id' => $id]);
        $quote = PurchasingRepository::quotes($id)[0];
        self::assertStringContainsString('It is above your approval limit and waits for an approver.', $this->post('award', ['quote_id' => $quote['id']], ['id' => $id])['message']);
        self::assertSame('awarded', PurchasingRepository::find($id)['status']);
        self::assertSame('Approving a purchase needs the "Approve purchases" permission.', $this->post('approve', [], ['id' => $id])['message']);

        $this->member('Procurement Manager', 100000.0);
        self::assertSame('This award (₹212,400.00) is above your approval limit of ₹100,000.00 — a higher approver must approve it.', $this->post('approve', [], ['id' => $id])['message']);

        $this->member('Procurement Director', null);
        self::assertSame('Purchase PR-' . $id . ' approved: ' . $quote['supplier_legal_name'] . ', ₹212,400.00.', $this->post('approve', [], ['id' => $id])['message']);
        self::assertSame('approved', PurchasingRepository::find($id)['status']);
        self::assertNotSame($exec, (int) PurchasingRepository::find($id)['approved_by']);
    }

    public function testReceiptRecordsTheQualityCheckAndFeedsSupplierPerformance(): void
    {
        $id = $this->raise();
        $this->member('Procurement Manager', null);
        $this->post('addQuote', ['supplier_id' => $this->supplierA, 'unit_price_inr' => '1500', 'gst_percent' => '18', 'lead_time_days' => '14'], ['id' => $id]);
        $this->post('award', ['quote_id' => PurchasingRepository::quotes($id)[0]['id']], ['id' => $id]);
        $body = ['received_quantity' => '118', 'received_on' => date('Y-m-d'), 'qc_result' => 'passed_with_remarks'];
        self::assertSame('Say what the quality check found (at least 5 characters).', $this->post('receive', $body, ['id' => $id])['message']);
        self::assertSame("Receipt recorded for PR-{$id}: quality check passed with remarks.", $this->post('receive', $body + ['qc_note' => '2 slabs chipped at edge'], ['id' => $id])['message']);
        $perf = array_values(array_filter(PurchasingRepository::supplierPerformance(), fn (array $s): bool => (int) $s['id'] === $this->supplierA))[0];
        self::assertSame([1, 1, 1, 1, 0], [(int) $perf['quotes_given'], (int) $perf['awards_won'], (int) $perf['received'], (int) $perf['on_time'], (int) $perf['qc_failed']]);
    }

    public function testARequirementOnAnOrderThePersonCannotSeeIsNotFound(): void
    {
        $id = $this->raise();
        $this->signIn($this->createTestUser('Procurement Executive'));
        http_response_code(200);
        ob_start();
        (new PurchasingController())->show(['id' => (string) $id]);
        ob_end_clean();
        self::assertSame(404, http_response_code());
        http_response_code(200);
    }
}
