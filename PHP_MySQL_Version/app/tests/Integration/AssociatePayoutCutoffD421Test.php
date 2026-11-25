<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Services\AssociateCommission;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-421 (25 Nov 2026): associate commission payout cut-off day. The commission is ready on the day
 * the client's last payment is cleared + the waiting days; ready on or before the cut-off day → paid in that
 * month's run, after it → next month's run. Start values: 0 waiting days, cut-off on the 20th, payment on the last
 * day of the month, Sunday/holiday → the working day before. Company-level settings ("Manage associate rules").
 * Node twin: associatePayoutCutoffD421.test.js.
 */
final class AssociatePayoutCutoffD421Test extends DbTestCase
{
    private function set(array $over = []): array
    {
        return $over + ['due_days' => 0, 'cutoff_day' => 20, 'run' => 'monthly', 'last_day' => true, 'day' => 0, 'holiday_rule' => 'before'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Database::connection()->exec("DELETE FROM company_holidays WHERE holiday_date BETWEEN '2026-12-01' AND '2027-02-28'");
    }

    public function testClearedByTheCutoffIsPaidThisMonth(): void
    {
        self::assertSame('2026-12-31', AssociateCommission::associateRunOn('2026-12-15', $this->set()));
        self::assertSame('2026-12-31', AssociateCommission::associateRunOn('2026-12-20', $this->set()), 'on the cut-off day itself');
    }

    public function testClearedAfterTheCutoffIsPaidNextMonthOnTheWorkingDayBefore(): void
    {
        // 31 Jan 2027 is a Sunday → Saturday 30 Jan.
        self::assertSame('2027-01-30', AssociateCommission::associateRunOn('2026-12-24', $this->set()));
        self::assertSame('2027-01-30', AssociateCommission::associateRunOn('2026-12-21', $this->set()));
    }

    public function testTheCutoffIsASettingAndWaitingDaysAddFirst(): void
    {
        self::assertSame('2026-12-31', AssociateCommission::associateRunOn('2026-12-24', $this->set(['cutoff_day' => 25])), 'cut-off on the 25th');
        $s = $this->set(['due_days' => 10]);
        $ready = AssociateCommission::dueOn('2026-12-15', $s);
        self::assertSame('2026-12-25', $ready);
        self::assertSame('2027-01-30', AssociateCommission::associateRunOn($ready, $s), 'ready after the cut-off → next month');
    }

    public function testWeeklyRunIgnoresTheCutoff(): void
    {
        // Weekly on Friday: ready Thu 24 Dec 2026 → Fri 25 Dec 2026.
        self::assertSame('2026-12-25', AssociateCommission::associateRunOn('2026-12-24', $this->set(['run' => 'weekly', 'last_day' => false, 'day' => 5])));
    }

    public function testAFixedPaymentDayAfterTheCutoff(): void
    {
        // Paid on the 28th, cut-off the 20th: 15 Dec → 28 Dec (Monday); 22 Dec → 28 Jan (Thursday).
        $s = $this->set(['last_day' => false, 'day' => 28]);
        self::assertSame('2026-12-28', AssociateCommission::associateRunOn('2026-12-15', $s));
        self::assertSame('2027-01-28', AssociateCommission::associateRunOn('2026-12-22', $s));
    }

    public function testTheSettingsPageExamplesUseTheSameRule(): void
    {
        $rows = AssociateCommission::payExamples($this->set(), '2026-12-05');
        self::assertSame(['2026-12-10', '2026-12-20', '2026-12-21', '2026-12-28'], array_column($rows, 'cleared'));
        self::assertSame(['2026-12-31', '2026-12-31', '2027-01-30', '2027-01-30'], array_column($rows, 'paid'));
        self::assertSame([true, true, false, false], array_column($rows, 'by_cutoff'));
    }

    public function testTheSettingIsReadFromIncentiveSettings(): void
    {
        $pdo = Database::connection();
        $pdo->exec("DELETE FROM incentive_settings WHERE setting_key = 'associate_payout_cutoff_day'");
        $pdo->exec("INSERT INTO incentive_settings (setting_key, setting_value) VALUES ('associate_payout_cutoff_day', '25')");
        self::assertSame(25, AssociateCommission::settings()['cutoff_day']);
        $pdo->exec("UPDATE incentive_settings SET setting_value = '20' WHERE setting_key = 'associate_payout_cutoff_day'");
    }

    public function testTheAssociateReadsWhenItIsPaidInPlainWords(): void
    {
        // Owner 25 Nov 2026: step 4 of "How it works" never says "0 days later" and names the cut-off day.
        self::assertSame("We pay it in our monthly payment on the last day of the month: if the buyer's last payment reached our bank by the 20th, in that month's payment; if later, in the next month's.",
            AssociateCommission::whenPaidText($this->set()));
        self::assertSame("We pay it 10 days after the buyer's last payment reached our bank, in our monthly payment on the last day of the month: if that day is on or before the 20th, in that month's payment; if later, in the next month's.",
            AssociateCommission::whenPaidText($this->set(['due_days' => 10])));
        self::assertSame("We pay it after the buyer's last payment has reached our bank, in our weekly payment every Friday.",
            AssociateCommission::whenPaidText($this->set(['run' => 'weekly', 'last_day' => false, 'day' => 5])));
    }
}

