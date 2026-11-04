<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderComplianceTaskRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Services\EmailDispatchService;
use App\Services\ShipmentGateService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Gap list answers (owner, 1 Nov 2026), part A:
 *  - G-1 a "before shipment" balance is recorded and cleared before the Bill
 *    of Lading (against the PI, from the freight stage on); the BL waits for it;
 *  - G-3 the pre-shipment inspection is recorded Passed / Failed with its
 *    report; the BL waits for a Passed one; a Failed one tells the managers;
 *  - a manager (override_shipment_gates) releases the BL step with a reason;
 *  - G-6 with e-mail off, the buyer's 48 hours start when staff mark the
 *    Order Confirmation sent, not on approval.
 * The Node suite has the same test (gapShipmentGates.test.js).
 */
final class GapShipmentGatesTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
        $this->admin = $this->createTestUser('Admin');
        $this->as($this->admin);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
        Flash::pull();
        CompanySettingsRepository::set('mail_sending_enabled', '0');
        parent::tearDown();
    }

    private function as(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
    }

    private function flash(): string
    {
        return (string) (Flash::pull()[0]['message'] ?? '');
    }

    private function call(string $action, int $orderId, array $post = []): string
    {
        $_POST = $post;
        ob_start();
        (new OrderController())->{$action}(['id' => (string) $orderId]);
        ob_end_clean();
        return $this->flash();
    }

    /** An order at Stage 7 (packing & BL) with its PL and BLI approved and a 2,700 balance expected. */
    private function orderAtBl(string $preset = 'Standard — New Buyer', bool $inspection = false): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', $preset);
        for ($stage = 1; $stage <= 6; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        $this->createTestDocument($orderId, 'PL', 'approved');
        $this->createTestDocument($orderId, 'BLI', 'approved');
        Database::connection()->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        Database::connection()->prepare('UPDATE order_payment_status SET balance_amount = 2700 WHERE order_id = :o')->execute(['o' => $orderId]);
        if (!$inspection) {
            $this->setInspection($orderId, 'skipped');
        }
        return $orderId;
    }

    private function setInspection(int $orderId, string $status): void
    {
        foreach (OrderComplianceTaskRepository::forOrder($orderId) as $t) {
            if ($t['task_type_name'] === ShipmentGateService::INSPECTION_TASK) {
                OrderComplianceTaskRepository::setStatus($orderId, (int) $t['task_type_id'], $status, $status === 'skipped' ? 'Not applicable — test.' : null, $this->admin);
            }
        }
    }

    private function stageStatus(int $orderId, int $stage): string
    {
        return (string) Database::connection()->query("SELECT os.status FROM order_stages os JOIN stages_master sm ON sm.id = os.stage_id WHERE os.order_id = {$orderId} AND sm.stage_number = {$stage}")->fetchColumn();
    }

    private const BL = ['bl_number' => 'MSCU7654321', 'bl_date' => '2026-11-01'];
    private const BALANCE = ['balance_amount' => '2700', 'balance_received_at' => '2026-10-30', 'balance_inr_unknown' => '1', 'balance_exchange_rate' => '91.5', 'balance_bank_reference' => 'UTRGAP000001'];

    public function testABeforeShipmentBalanceIsClearedBeforeTheBillOfLadingWhichWaitsForIt(): void
    {
        $orderId = $this->orderAtBl();
        self::assertSame(['balance' => ShipmentGateService::MESSAGES['balance']], ShipmentGateService::blockers($orderId));
        self::assertSame(ShipmentGateService::MESSAGES['balance'] . ' Or release the step with a written reason.', $this->call('recordBlIssued', $orderId, self::BL));
        self::assertNull(Database::connection()->query("SELECT bl_number FROM order_shipping WHERE order_id = {$orderId}")->fetchColumn() ?: null);

        // Recorded against the PI: no Commercial Invoice yet, Stage 8 still locked.
        self::assertStringContainsString('Balance remittance recorded.', $this->call('recordBalancePayment', $orderId, self::BALANCE));
        self::assertSame('Balance payment cleared before shipment. The Bill of Lading (Step 10f) can now be recorded; Step 11 completes once the Commercial Invoice is approved.', $this->call('clearBalancePayment', $orderId, ['balance_cleared_at' => '2026-10-31']));
        self::assertSame('locked', $this->stageStatus($orderId, 8));
        self::assertSame('The balance is already cleared.', $this->call('clearBalancePayment', $orderId));

        self::assertSame([], ShipmentGateService::blockers($orderId));
        self::assertSame('Bill of Lading recorded (MSCU7654321). Stage 8 (Commercial Invoice) unlocked.', $this->call('recordBlIssued', $orderId, self::BL));

        // Step 11 completes once the CI is approved — the balance stays as cleared before shipment.
        self::assertStringContainsString('Commercial Invoice', $this->call('clearBalancePayment', $orderId));
        self::assertSame('in_progress', $this->stageStatus($orderId, 8));
        $this->createTestDocument($orderId, 'CI', 'approved');
        self::assertSame('Step 11 complete — the balance was cleared before shipment on 31 Oct 2026. Stage 9 (Document Despatch & Closure) unlocked.', $this->call('clearBalancePayment', $orderId));
        self::assertSame('gate_passed', $this->stageStatus($orderId, 8));
        self::assertSame('2026-10-31', substr((string) Database::connection()->query("SELECT balance_cleared_at FROM order_payment_status WHERE order_id = {$orderId}")->fetchColumn(), 0, 10));
    }

    public function testTheBalanceIsNotOpenBeforeTheFreightStage(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 4; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        self::assertFalse(ShipmentGateService::balanceOpenEarly($orderId), 'Stage 5 (supplier PO) is too early');
        self::assertSame('Stage 8 has not been unlocked for this order yet.', $this->call('recordBalancePayment', $orderId, self::BALANCE));
    }

    public function testPostBlAndLetterOfCreditOrdersAreUnchanged(): void
    {
        foreach (['Established Buyer — Post-BL', 'Irrevocable Letter of Credit at Sight'] as $preset) {
            $orderId = $this->orderAtBl($preset);
            self::assertFalse(ShipmentGateService::balanceOpenEarly($orderId), $preset);
            self::assertSame([], ShipmentGateService::blockers($orderId), $preset);
            self::assertSame('Stage 8 has not been unlocked for this order yet.', $this->call('recordBalancePayment', $orderId, self::BALANCE), $preset);
            self::assertStringStartsWith('Bill of Lading recorded', $this->call('recordBlIssued', $orderId, self::BL), $preset);
        }
    }

    public function testTheInspectionMustPassBeforeTheBillOfLading(): void
    {
        $orderId = $this->orderAtBl('Established Buyer — Post-BL', true);
        self::assertTrue(ShipmentGateService::inspectionApplies($orderId), 'every order has the checklist line until it is marked not applicable');
        self::assertSame(ShipmentGateService::MESSAGES['inspection'] . ' Or release the step with a written reason.', $this->call('recordBlIssued', $orderId, self::BL));

        self::assertSame('Choose whether the inspection passed or failed.', $this->call('recordInspectionResult', $orderId, ['inspection_result' => '', 'inspected_on' => '2026-10-30', 'agency_name' => 'SGS India']));
        self::assertSame('Enter the date of the inspection (today or earlier).', $this->call('recordInspectionResult', $orderId, ['inspection_result' => 'passed', 'inspected_on' => '2099-01-01', 'agency_name' => 'SGS India']));
        self::assertSame('Say what failed (at least 10 characters).', $this->call('recordInspectionResult', $orderId, ['inspection_result' => 'failed', 'inspected_on' => '2026-10-30', 'agency_name' => 'SGS India', 'inspection_note' => 'bad']));
        self::assertSame('Attach the inspection report (PDF or image).', $this->call('recordInspectionResult', $orderId, ['inspection_result' => 'passed', 'inspected_on' => '2026-10-30', 'agency_name' => 'SGS India']));
        $tmp = tempnam(sys_get_temp_dir(), 'insp');
        file_put_contents($tmp, '%PDF-1.4 inspection report');
        $_FILES = ['inspection_report' => ['name' => 'report.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
        try {
            // move_uploaded_file() cannot succeed outside a real upload: reaching it proves the upload context resolves.
            self::assertStringContainsString('Failed to move the uploaded file', $this->call('recordInspectionResult', $orderId, ['inspection_result' => 'passed', 'inspected_on' => '2026-10-30', 'agency_name' => 'SGS India']));
        } finally {
            @unlink($tmp);
            $_FILES = [];
        }

        // As stored by a real upload: Failed — the managers are told, the BL still waits.
        $file = $this->createTestFile($orderId);
        $insert = Database::connection()->prepare('INSERT INTO order_inspections (order_id, result, inspected_on, agency_name, report_file_id, note, recorded_by) VALUES (:o, :r, :d, :a, :f, :n, :u)
            ON DUPLICATE KEY UPDATE result = VALUES(result), note = VALUES(note)');
        $insert->execute(['o' => $orderId, 'r' => 'failed', 'd' => '2026-10-30', 'a' => 'SGS India', 'f' => $file, 'n' => 'Two crates of slabs cracked.', 'u' => $this->admin]);
        self::assertArrayHasKey('inspection', ShipmentGateService::blockers($orderId));
        $insert->execute(['o' => $orderId, 'r' => 'passed', 'd' => '2026-10-31', 'a' => 'SGS India', 'f' => $file, 'n' => null, 'u' => $this->admin]);
        self::assertSame([], ShipmentGateService::blockers($orderId));
        self::assertStringStartsWith('Bill of Lading recorded', $this->call('recordBlIssued', $orderId, self::BL));
    }

    public function testAFailedInspectionNotifiesTheManagersAndAPassedOneResolvesTheChecklistLine(): void
    {
        $orderId = $this->orderAtBl('Established Buyer — Post-BL', true);
        $md = $this->createTestUser('Managing Director');
        $file = $this->createTestFile($orderId);
        $order = \App\Repositories\OrderRepository::find($orderId);
        ShipmentGateService::saveInspection($order, 'failed', '2026-10-30', 'SGS India', $file, 'Two crates cracked badly.', $this->admin);
        $note = (string) Database::connection()->query("SELECT message FROM notifications WHERE user_id = {$md} AND related_order_id = {$orderId} ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertSame("Pre-shipment inspection FAILED on order {$order['order_reference']} (SGS India, 30 Oct 2026): Two crates cracked badly. The Bill of Lading waits for a Passed inspection.", $note);
        self::assertSame('pending_approval', $this->taskStatus($orderId));
        $html = $this->render($orderId);
        self::assertStringContainsString('<span class="badge badge-lost">Failed</span>', $html);
        self::assertStringContainsString(htmlspecialchars(ShipmentGateService::MESSAGES['inspection']), $html);
        self::assertStringContainsString('name="gate_override_reason"', $html, 'an Admin may release the step');

        ShipmentGateService::saveInspection($order, 'passed', '2026-10-31', 'SGS India', $file, null, $this->admin);
        self::assertSame('approved', $this->taskStatus($orderId), 'Passed resolves the checklist line');
        self::assertStringContainsString('<span class="badge badge-active">Passed</span>', $this->render($orderId));
        self::assertSame(2, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'INSPECTION_RESULT_RECORDED' AND entity_id = {$orderId}")->fetchColumn());
    }

    private function taskStatus(int $orderId): string
    {
        foreach (OrderComplianceTaskRepository::forOrder($orderId) as $t) {
            if ($t['task_type_name'] === ShipmentGateService::INSPECTION_TASK) {
                return (string) $t['status'];
            }
        }
        return '';
    }

    private function render(int $orderId): string
    {
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    public function testAManagerReleasesTheBillOfLadingStepWithAReason(): void
    {
        $orderId = $this->orderAtBl('Standard — New Buyer', true);
        $exec = $this->createTestUser('Export Executive');
        Database::connection()->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'manage_shipping' WHERE r.name = 'Export Executive'")->execute();
        $this->as($exec);
        self::assertSame(ShipmentGateService::MESSAGES['balance'] . ' ' . ShipmentGateService::MESSAGES['inspection'] . ' A manager can release the step with a written reason.', $this->call('recordBlIssued', $orderId, self::BL));
        self::assertSame('Only a manager with "Override shipment gates" can release the Bill of Lading step.', $this->call('recordBlIssued', $orderId, self::BL + ['gate_override_reason' => 'The buyer asked us to ship now.']));
        self::assertStringNotContainsString('name="gate_override_reason"', $this->render($orderId));

        $this->as($this->admin);
        self::assertSame('Give the reason for releasing the Bill of Lading step (10 to 500 characters).', $this->call('recordBlIssued', $orderId, self::BL + ['gate_override_reason' => 'short']));
        self::assertStringStartsWith('Bill of Lading recorded', $this->call('recordBlIssued', $orderId, self::BL + ['gate_override_reason' => 'MD agreed by phone: long-standing buyer, balance arrives Monday.']));
        $row = Database::connection()->query("SELECT gates, reason, user_id FROM order_shipment_gate_overrides WHERE order_id = {$orderId}")->fetch();
        self::assertSame(['balance,inspection', 'MD agreed by phone: long-standing buyer, balance arrives Monday.', (string) $this->admin], [$row['gates'], $row['reason'], (string) $row['user_id']]);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'SHIPMENT_GATE_OVERRIDDEN' AND entity_id = {$orderId}")->fetchColumn());
        self::assertStringContainsString('before the pre-shipment balance and the inspection was done. Reason: MD agreed by phone', $this->render($orderId));

        $role = static fn (string $n): int => (int) Database::connection()->query('SELECT id FROM roles WHERE name = ' . Database::connection()->quote($n))->fetchColumn();
        foreach (['Managing Director' => true, 'Executive Director' => true, 'Admin' => true, 'Export Executive' => false, 'Accounts Executive' => false] as $name => $expected) {
            self::assertSame($expected, \App\Services\PermissionService::can($this->createTestUser($name), $role($name), 'override_shipment_gates'), $name);
        }
    }

    public function testTheOrderPageShowsTheEarlyBalanceSteps(): void
    {
        $orderId = $this->orderAtBl();
        $html = $this->render($orderId);
        self::assertStringContainsString('This order\'s balance is due <strong>before shipment</strong>', $html);
        self::assertStringContainsString('action="/orders/' . $orderId . '/payment/balance"', $html, 'recorded before the CI exists');
        $this->call('recordBalancePayment', $orderId, self::BALANCE);
        self::assertStringContainsString('Mark Balance Cleared (before shipment)', $this->render($orderId));
        $this->call('clearBalancePayment', $orderId);
        $this->call('recordBlIssued', $orderId, self::BL);
        $html = $this->render($orderId);
        self::assertStringContainsString('Complete Step 11 (unlocks Stage 9)', $html);
    }

    public function testWithEmailOffTheBuyersFortyEightHoursStartWhenTheOcIsMarkedSent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 3; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        $docId = $this->createTestDocument($orderId, 'OC', 'approved');
        EmailDispatchService::markSentIfMailDisabled($docId);
        self::assertSame('sent', Database::connection()->query("SELECT status FROM documents WHERE id = {$docId}")->fetchColumn());
        self::assertNull(OrderOcAcknowledgmentRepository::find($orderId), 'approval alone does not start the clock');
        self::assertStringContainsString('Mark Order Confirmation Sent to Buyer', $this->render($orderId));

        $message = $this->call('markOcSentToBuyer', $orderId);
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertNotNull($ack);
        self::assertSame(48 * 3600, strtotime((string) $ack['due_at']) - strtotime((string) $ack['sent_at']));
        self::assertStringStartsWith('Order Confirmation marked as sent to the buyer. If the buyer does not respond by ', $message);
        self::assertSame('This Order Confirmation is already marked as sent to the buyer.', $this->call('markOcSentToBuyer', $orderId));

        CompanySettingsRepository::set('mail_sending_enabled', '1');
        self::assertSame('E-mail sending is on: the buyer\'s 48 hours start when the Order Confirmation is e-mailed.', $this->call('markOcSentToBuyer', $orderId));
    }
}
