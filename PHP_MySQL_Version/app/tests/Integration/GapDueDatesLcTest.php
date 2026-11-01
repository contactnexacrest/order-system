<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderLcAmendmentRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Services\BalanceDueService;
use App\Tests\Support\DbTestCase;

require_once __DIR__ . '/../../cron/check_alerts.php';

/**
 * Gap list answers (owner, 1 Nov 2026, register D-218), part B:
 * G-2 — a balance paid after the BL (option "against BL", and LC orders)
 * has no due date until the scanned BL is sent to the buyer, then the
 * order's balance days; an overdue balance is reminded daily.
 * G-4 — the LC's dates (issue, expiry, last shipment, presentation) with
 * reminders 7 days before each, and the LC amendments.
 * The Node suite has the same test (gapDueDatesLc.test.js).
 */
final class GapDueDatesLcTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
        parent::tearDown();
    }

    private function order(string $preset, array $assignees = []): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', $preset, 'USD', null, null, $assignees);
        Database::connection()->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        Database::connection()->prepare('UPDATE order_payment_status SET balance_amount = 2700, balance_due_date = NULL WHERE order_id = :o')->execute(['o' => $orderId]);
        Database::connection()->prepare('INSERT IGNORE INTO order_shipping (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        return $orderId;
    }

    private function due(int $orderId): ?string
    {
        $v = Database::connection()->query("SELECT balance_due_date FROM order_payment_status WHERE order_id = {$orderId}")->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    private function flash(): array
    {
        return Flash::pull()[0] ?? ['type' => '', 'message' => ''];
    }

    public function testABalanceAgainstTheBlIsDueFromTheDayTheScannedBlIsSent(): void
    {
        $orderId = $this->order('Established Buyer — Post-BL');
        self::assertNull($this->due($orderId), 'no due date before the BL is sent');
        (new OrderController())->recordScannedBlSent(['id' => (string) $orderId]);
        $due = date('Y-m-d', strtotime('+7 days'));
        self::assertSame($due, $this->due($orderId), 'the order\'s 7 balance days from the day the scanned BL is sent');
        self::assertSame('Scanned BL copy marked as sent to buyer. Balance payment due on ' . \App\Helpers\Dates::short($due) . '.', $this->flash()['message']);
    }

    public function testAnLcBalanceToo_ButABeforeShipmentBalanceGetsNoFixedDate(): void
    {
        $lc = $this->order('Irrevocable Letter of Credit at Sight');
        self::assertTrue(BalanceDueService::dueAfterBl(\App\Repositories\OrderRepository::find($lc)));
        self::assertSame(date('Y-m-d', strtotime('2026-11-10 +7 days')), BalanceDueService::startFromScannedBl($lc, '2026-11-10'));

        $before = $this->order('Standard — New Buyer');
        (new OrderController())->recordScannedBlSent(['id' => (string) $before]);
        self::assertNull($this->due($before));
        self::assertSame('Scanned BL copy marked as sent to buyer.', $this->flash()['message']);

        $cleared = $this->order('Established Buyer — Post-BL');
        Database::connection()->exec("UPDATE order_payment_status SET balance_cleared_at = NOW() WHERE order_id = {$cleared}");
        self::assertNull(BalanceDueService::startFromScannedBl($cleared, '2026-11-10'), 'a cleared balance is left alone');
    }

    public function testAnOverdueBalanceIsRemindedDailyToAdminAndTheAssignees(): void
    {
        $assignee = $this->createTestUser('Export Executive');
        $orderId = $this->order('Established Buyer — Post-BL', [$assignee]);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        Database::connection()->exec("UPDATE order_payment_status SET balance_due_date = '{$yesterday}' WHERE order_id = {$orderId}");
        $ref = (string) Database::connection()->query("SELECT order_reference FROM orders WHERE id = {$orderId}")->fetchColumn();

        \run();
        foreach ([$this->admin, $assignee] as $u) {
            self::assertTrue(NotificationRepository::existsToday($u, 'balance_overdue', $orderId));
        }
        $msg = (string) Database::connection()->query("SELECT message FROM notifications WHERE user_id = {$assignee} AND type = 'balance_overdue' AND related_order_id = {$orderId}")->fetchColumn();
        self::assertSame("Balance payment overdue on order {$ref} — was due {$yesterday}, still not cleared.", $msg);
        \run();
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$assignee} AND type = 'balance_overdue' AND related_order_id = {$orderId}")->fetchColumn(), 'once a day');

        $today = $this->order('Established Buyer — Post-BL', [$assignee]);
        Database::connection()->exec("UPDATE order_payment_status SET balance_due_date = CURDATE() WHERE order_id = {$today}");
        \run();
        self::assertFalse(NotificationRepository::existsToday($assignee, 'balance_overdue', $today), 'due today is not overdue yet');
    }

    public function testTheLcDatesAreCheckedAndSaved(): void
    {
        $orderId = $this->order('Irrevocable Letter of Credit at Sight');
        $target = $orderId;
        $save = function (array $post) use (&$target): array {
            $_POST = $post;
            (new OrderController())->saveLcDates(['id' => (string) $target]);
            return $this->flash();
        };
        self::assertSame('Enter each LC date as a date, or leave it empty.', $save(['lc_expiry_date' => '2026-02-30'])['message']);
        self::assertSame('The LC issue date cannot be in the future.', $save(['lc_issue_date' => date('Y-m-d', strtotime('+1 day'))])['message']);
        self::assertSame('The last shipment date cannot be after the LC expiry date.', $save(['lc_expiry_date' => '2026-12-31', 'lc_last_shipment_date' => '2027-01-05'])['message']);
        self::assertSame('The deadline to present documents cannot be after the LC expiry date.', $save(['lc_expiry_date' => '2026-12-31', 'lc_presentation_deadline' => '2027-01-05'])['message']);
        self::assertSame('The expiry, last shipment and presentation dates must be after the LC issue date (01 Oct 2026).',
            $save(['lc_issue_date' => '2026-10-01', 'lc_expiry_date' => '2026-12-31', 'lc_last_shipment_date' => '2026-09-30'])['message']);
        $f = $save(['lc_issue_date' => '2026-10-01', 'lc_expiry_date' => '2026-12-31', 'lc_last_shipment_date' => '2026-12-10', 'lc_presentation_deadline' => '2026-12-21']);
        self::assertSame('LC dates saved. Reminders go out 7 days before the last shipment date, the deadline to present documents and the expiry.', $f['message']);
        $lc = OrderLcDetailRepository::find($orderId);
        self::assertSame(['2026-10-01', '2026-12-31', '2026-12-10', '2026-12-21'], [$lc['lc_issue_date'], $lc['lc_expiry_date'], $lc['lc_last_shipment_date'], $lc['lc_presentation_deadline']]);
        self::assertSame(4, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'LC_DATE_SAVED' AND entity_id = {$orderId}")->fetchColumn());

        $cash = $this->order('Standard — New Buyer');
        $target = $cash;
        self::assertSame('This order is not paid by Letter of Credit.', $save(['lc_expiry_date' => '2026-12-31'])['message']);
    }

    public function testLcDateWarningsAndRemindersSevenDaysBeforeOnceEach(): void
    {
        $assignee = $this->createTestUser('Export Executive');
        $orderId = $this->order('Irrevocable Letter of Credit at Sight', [$assignee]);
        $in5 = date('Y-m-d', strtotime('+5 days'));
        $in20 = date('Y-m-d', strtotime('+20 days'));
        $past = date('Y-m-d', strtotime('-2 days'));
        OrderLcDetailRepository::saveDates($orderId, null, $in20, $in5, $past, $this->admin);
        Database::connection()->exec("UPDATE order_lc_details SET lc_number = 'LC-777' WHERE order_id = {$orderId}");
        self::assertSame([['label' => 'Last shipment date', 'date' => $in5, 'days' => 5], ['label' => 'Deadline to present documents', 'date' => $past, 'days' => -2]],
            OrderLcDetailRepository::dateWarnings(OrderLcDetailRepository::find($orderId)));

        $ref = (string) Database::connection()->query("SELECT order_reference FROM orders WHERE id = {$orderId}")->fetchColumn();
        \run();
        $msgs = Database::connection()->query("SELECT type, message FROM notifications WHERE user_id = {$assignee} AND related_order_id = {$orderId} AND type LIKE 'lc_%'")->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame(['lc_last_shipment_soon' => "Order {$ref}: LC LC-777 — last shipment date is {$in5} (5 day(s) away)."], $msgs, 'only the date within 7 days; not the past one, not the one 20 days away');
        self::assertTrue(NotificationRepository::existsToday($this->admin, 'lc_last_shipment_soon', $orderId));

        Database::connection()->exec("UPDATE notifications SET created_at = DATE_SUB(created_at, INTERVAL 1 DAY) WHERE related_order_id = {$orderId}");
        \run();
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$assignee} AND related_order_id = {$orderId} AND type = 'lc_last_shipment_soon'")->fetchColumn(), 'once, not every day');

        Database::connection()->exec("UPDATE order_lc_details SET lc_received_at = CURDATE() WHERE order_id = {$orderId}");
        self::assertSame([], OrderLcDetailRepository::dateWarnings(OrderLcDetailRepository::find($orderId)), 'no warning once the bank has paid');
    }

    public function testLcAmendmentsAreRecorded(): void
    {
        $orderId = $this->order('Irrevocable Letter of Credit at Sight');
        $add = function (array $post) use ($orderId): array {
            $_POST = $post;
            (new OrderController())->addLcAmendment(['id' => (string) $orderId]);
            return $this->flash();
        };
        self::assertSame('Enter the date of the amendment (today or earlier).', $add(['amended_on' => date('Y-m-d', strtotime('+1 day')), 'reference' => 'AMD-1', 'change_text' => 'Expiry extended'])['message']);
        self::assertSame('Enter the amendment number or reference (up to 100 characters).', $add(['amended_on' => '2026-10-20', 'reference' => '', 'change_text' => 'Expiry extended'])['message']);
        self::assertSame('Say what the amendment changed (5 to 500 characters).', $add(['amended_on' => '2026-10-20', 'reference' => 'AMD-1', 'change_text' => 'abc'])['message']);
        self::assertSame('LC amendment AMD-1 recorded. If it changed a date, update the LC dates too.',
            $add(['amended_on' => '2026-10-20', 'reference' => 'AMD-1', 'change_text' => 'Last shipment date extended to 30 Nov 2026'])['message']);
        $rows = OrderLcAmendmentRepository::forOrder($orderId);
        self::assertSame(['2026-10-20', 'AMD-1', 'Last shipment date extended to 30 Nov 2026'], [$rows[0]['amended_on'], $rows[0]['reference'], $rows[0]['change_text']]);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'LC_AMENDMENT_RECORDED' AND entity_id = " . (int) $rows[0]['id'])->fetchColumn());

        $cash = $this->order('Standard — New Buyer');
        $_POST = ['amended_on' => '2026-10-20', 'reference' => 'AMD-2', 'change_text' => 'Expiry extended'];
        (new OrderController())->addLcAmendment(['id' => (string) $cash]);
        self::assertSame('This order is not paid by Letter of Credit.', $this->flash()['message']);
    }

    public function testTheOrderPageShowsTheLcDatesAmendmentsAndWarning(): void
    {
        $orderId = $this->order('Irrevocable Letter of Credit at Sight');
        OrderLcDetailRepository::saveDates($orderId, '2026-10-01', date('Y-m-d', strtotime('+30 days')), date('Y-m-d', strtotime('+3 days')), null, $this->admin);
        OrderLcAmendmentRepository::add($orderId, '2026-10-20', 'AMD-9', 'Partial shipment allowed', null, $this->admin);
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('<span class="badge badge-lost">LC date within 7 days</span>', $html);
        self::assertStringContainsString('Last shipment date: ' . \App\Helpers\Dates::short(date('Y-m-d', strtotime('+3 days'))) . ' — in 3 day(s)', $html);
        self::assertStringContainsString('<td>AMD-9</td><td>Partial shipment allowed</td>', $html);
        self::assertStringContainsString('action="/orders/' . $orderId . '/lc-dates"', $html);
        self::assertStringContainsString('set when the scanned BL is sent to the buyer (Step 11b)', $html);
    }
}
