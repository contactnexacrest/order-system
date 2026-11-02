<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\RemittanceReasonController;
use App\Helpers\Flash;
use App\Repositories\OrderCostEntryRepository;
use App\Services\PartPaymentService;
use App\Services\RemittanceDifference;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner follow-up on point 18 (2 Nov 2026): "Why is it different?" is a
 * drop-down of the usual reasons, kept by Admin (Settings → Remittance
 * difference reasons), each treated as bank charges / buyer paid less /
 * buyer paid more, with "Other — type the reason" last. Only reasons that
 * fit the amount are accepted; a reason's treatment is fixed once used.
 * Node twin: remittanceDifferenceReasons.test.js.
 */
final class RemittanceDifferenceReasonsTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        \App\Services\PermissionService::resetCache();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
        parent::tearDown();
    }

    private function flash(): array
    {
        $f = Flash::pull();
        return end($f) ?: ['type' => '', 'message' => ''];
    }

    private function order(string $action, int $orderId, array $post): array
    {
        $_POST = $post;
        ob_start();
        (new OrderController())->{$action}(['id' => (string) $orderId]);
        ob_end_clean();
        return $this->flash();
    }

    private function admin(string $action, array $post, array $params = []): array
    {
        $_POST = $post;
        ob_start();
        (new RemittanceReasonController())->{$action}($params);
        ob_end_clean();
        return $this->flash();
    }

    private function orderAtAdvance(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        Database::connection()->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        Database::connection()->prepare('UPDATE orders SET advance_pct = 40 WHERE id = :o')->execute(['o' => $orderId]);
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        $this->createTestDocument($orderId, 'PI', 'approved');
        Database::connection()->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        return $orderId;
    }

    private function advance(string $amount, array $extra): array
    {
        return ['advance_amount' => $amount, 'advance_received_at' => '2026-10-20', 'advance_exchange_rate' => '84', 'advance_bank_reference' => 'UTR990011'] + $extra;
    }

    private function idOf(string $label): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM remittance_difference_reasons WHERE label = :l');
        $stmt->execute(['l' => $label]);
        return (int) $stmt->fetchColumn();
    }

    public function testTheSeededListAndHowAPostedChoiceIsRead(): void
    {
        $byTreatment = [];
        foreach (RemittanceDifference::reasons() as $r) {
            $byTreatment[$r['treatment']][] = $r['label'];
        }
        self::assertSame([4, 4, 3], [count($byTreatment['bank_charges']), count($byTreatment['short']), count($byTreatment['excess'])]);
        self::assertSame('Intermediary / correspondent bank charges deducted', $byTreatment['bank_charges'][0]);

        $none = RemittanceDifference::resolvePosted([]);
        self::assertNull($none['treatment']);
        $listed = RemittanceDifference::resolvePosted(['difference_reason_choice' => (string) $this->idOf('Buyer deducted an agreed discount')]);
        self::assertSame(['short', 'Buyer deducted an agreed discount', false], [$listed['treatment'], $listed['reason_text'], $listed['custom']]);

        self::assertSame('Type the reason the amount is different (at least 10 characters), or choose one from the list.',
            RemittanceDifference::resolvePosted(['difference_reason_choice' => 'other', 'difference_reason_other' => 'too short', 'difference_treatment' => 'short'])['error']);
        self::assertSame('Choose how to treat the difference: bank charges, buyer paid less, or buyer paid more.',
            RemittanceDifference::resolvePosted(['difference_reason_choice' => 'other', 'difference_reason_other' => 'Buyer sent part by cheque', 'difference_treatment' => ''])['error']);
        $other = RemittanceDifference::resolvePosted(['difference_reason_choice' => 'other', 'difference_reason_other' => '  Buyer sent   part by cheque ', 'difference_treatment' => 'short']);
        self::assertSame(['short', null, 'Buyer sent part by cheque', true], [$other['treatment'], $other['reason_id'], $other['reason_text'], $other['custom']]);

        self::assertSame('Choose a reason from the list, or "Other — type the reason".', RemittanceDifference::resolvePosted(['difference_reason_choice' => 'bank_charges'])['error']);
        Database::connection()->prepare('UPDATE remittance_difference_reasons SET is_active = 0 WHERE id = :id')->execute(['id' => $this->idOf('Buyer rounded up the amount')]);
        self::assertSame('That reason is no longer in the list. Choose another one.',
            RemittanceDifference::resolvePosted(['difference_reason_choice' => (string) $this->idOf('Buyer rounded up the amount')])['error']);
    }

    public function testTheServerRefusesAReasonThatDoesNotFitTheAmount(): void
    {
        $orderId = $this->orderAtAdvance();
        // Less than due arrived (1,700 of 1,800), but a "buyer paid more" reason was posted.
        $r = $this->order('recordAdvancePayment', $orderId, $this->advance('1700', ['difference_reason_choice' => (string) $this->idOf('Buyer paid extra — adjust against balance')]));
        self::assertSame('error', $r['type']);
        self::assertSame('USD 1,700.00 is not more than the advance still due (USD 1,800.00) — choose another reason, or "Amount as due — no difference".', $r['message']);
        // More than due arrived, but a bank-charges reason was posted.
        $r = $this->order('recordAdvancePayment', $orderId, $this->advance('1900', ['difference_reason_choice' => (string) $this->idOf('Our bank deducted its charges from the amount credited')]));
        self::assertSame('Bank charges apply only when less than the advance still due arrived (USD 1,800.00). Nothing is missing here — choose "Amount as due — no difference".', $r['message']);
        $saved = Database::connection()->prepare('SELECT COUNT(*) FROM order_payment_receipts WHERE order_id = :o');
        $saved->execute(['o' => $orderId]);
        self::assertSame(0, (int) $saved->fetchColumn(), 'nothing was saved');
    }

    public function testOtherSavesTheTypedReasonAndTheDescriptionUsesIt(): void
    {
        $orderId = $this->orderAtAdvance();
        $r = $this->order('recordAdvancePayment', $orderId, $this->advance('1785', [
            'difference_reason_choice' => 'other', 'difference_reason_other' => 'SWIFT fee and courier charge deducted', 'difference_treatment' => 'bank_charges', 'bank_charges' => '15']));
        self::assertSame('success', $r['type'], $r['message']);
        $receipt = PartPaymentService::summary($orderId, 'advance')['receipts'][0];
        self::assertSame(['bank_charges', null, 'SWIFT fee and courier charge deducted', 1],
            [$receipt['difference_reason'], $receipt['difference_reason_id'], $receipt['difference_reason_text'], (int) $receipt['difference_reason_custom']]);
        $costs = OrderCostEntryRepository::forOrder($orderId);
        self::assertStringEndsWith('· bank ref UTR990011 · Reason: SWIFT fee and courier charge deducted', $costs[0]['description']);

        $custom = RemittanceDifference::customReasonsUsed();
        self::assertSame(['SWIFT fee and courier charge deducted', 'bank_charges', 1], [$custom[0]['reason'], $custom[0]['treatment'], (int) $custom[0]['uses']]);

        $audit = Database::connection()->prepare("SELECT reason FROM audit_log WHERE action_type = 'REMITTANCE_BANK_CHARGES_RECORDED' AND entity_id = :r");
        $audit->execute(['r' => $receipt['id']]);
        self::assertStringContainsString('Reason: SWIFT fee and courier charge deducted', (string) $audit->fetchColumn());
    }

    public function testAdminAddsRenamesReordersAndSwitchesOffAndTheTreatmentLocksOnceUsed(): void
    {
        $r = $this->admin('create', ['label' => 'SWIFT charges deducted by the intermediary bank', 'treatment' => 'bank_charges', 'sort_order' => '']);
        self::assertSame('success', $r['type'], $r['message']);
        $id = $this->idOf('SWIFT charges deducted by the intermediary bank');
        self::assertGreaterThan(110, (int) Database::connection()->query("SELECT sort_order FROM remittance_difference_reasons WHERE id = {$id}")->fetchColumn(), 'added at the end');
        self::assertSame('A reason called "swift charges deducted by the intermediary bank" is already in the list.',
            $this->admin('create', ['label' => 'swift charges deducted by the intermediary bank', 'treatment' => 'short'])['message']);
        self::assertSame('Choose how the reason is treated: bank charges, buyer paid less, or buyer paid more.',
            $this->admin('create', ['label' => 'Something new here', 'treatment' => 'refund'])['message']);

        // Unused: rename, reorder and even change the treatment.
        self::assertSame('success', $this->admin('update', ['label' => 'SWIFT fee deducted', 'treatment' => 'short', 'sort_order' => '5'], ['id' => (string) $id])['type']);
        $row = Database::connection()->query("SELECT label, treatment, sort_order FROM remittance_difference_reasons WHERE id = {$id}")->fetch();
        self::assertSame(['SWIFT fee deducted', 'short', 5], [$row['label'], $row['treatment'], (int) $row['sort_order']]);

        // Used on a receipt: the treatment is fixed, the name can still change.
        $orderId = $this->orderAtAdvance();
        self::assertSame('success', $this->order('recordAdvancePayment', $orderId, $this->advance('1700', ['difference_reason_choice' => (string) $id]))['type']);
        $locked = $this->admin('update', ['label' => 'SWIFT fee deducted', 'treatment' => 'bank_charges', 'sort_order' => '5'], ['id' => (string) $id]);
        self::assertSame('"SWIFT fee deducted" is already on 1 receipt(s), so how it is treated cannot change. Switch it off and add a new reason instead.', $locked['message']);
        self::assertSame('success', $this->admin('update', ['label' => 'SWIFT fee deducted by a bank', 'treatment' => 'short', 'sort_order' => '5'], ['id' => (string) $id])['type']);

        // Switch off: gone from the forms, the receipt keeps its reason text.
        self::assertStringContainsString('is switched off', $this->admin('toggle', [], ['id' => (string) $id])['message']);
        self::assertNotContains($id, array_column(RemittanceDifference::reasons(), 'id'));
        self::assertSame('SWIFT fee deducted', PartPaymentService::summary($orderId, 'advance')['receipts'][0]['difference_reason_text'], 'snapshot kept');
        self::assertStringContainsString('is back in the list', $this->admin('toggle', [], ['id' => (string) $id])['message']);

        $codes = Database::connection()->query("SELECT action_type FROM audit_log WHERE entity_type = 'remittance_difference_reasons' AND entity_id = {$id} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['REMITTANCE_REASON_ADDED', 'REMITTANCE_REASON_UPDATED', 'REMITTANCE_REASON_UPDATED', 'REMITTANCE_REASON_UPDATED', 'REMITTANCE_REASON_UPDATED', 'REMITTANCE_REASON_DEACTIVATED', 'REMITTANCE_REASON_REACTIVATED'], $codes);
    }

    public function testTheFormAndTheAdminPageShowTheList(): void
    {
        $orderId = $this->orderAtAdvance();
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $page = (string) ob_get_clean();
        self::assertStringContainsString('<option value="" data-none>Amount as due — no difference</option>', $page);
        self::assertStringContainsString('data-treatment-group="bank_charges"', $page);
        self::assertStringContainsString('>Intermediary / correspondent bank charges deducted</option>', $page);
        self::assertStringContainsString('<option value="other" data-treatment="other">Other — type the reason</option>', $page);
        self::assertStringContainsString('name="difference_reason_other"', $page);
        self::assertStringContainsString('name="difference_treatment" required data-remit-treatment', $page);

        $this->order('recordAdvancePayment', $orderId, $this->advance('1700', ['difference_reason_choice' => 'other', 'difference_reason_other' => 'Buyer kept back a sample cost', 'difference_treatment' => 'short']));
        ob_start();
        (new RemittanceReasonController())->index([]);
        $admin = (string) ob_get_clean();
        self::assertStringContainsString('Remittance difference reasons', $admin);
        self::assertStringContainsString('value="Buyer deducted a quality / quantity claim"', $admin);
        self::assertStringContainsString('Reasons typed in by hand', $admin);
        self::assertStringContainsString('<td>Buyer kept back a sample cost</td><td>Buyer paid less (short payment)</td><td>1</td>', $admin);
    }
}
