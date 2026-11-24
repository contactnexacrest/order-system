<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * D-393 (owner, 19 Nov 2026), following D-292: the associate never sees levels, the ladder or counts.
 * The commission statement in the associate portal had kept a "Counts toward your next commission
 * rate: yes / no" row (D-263); it is removed there. Staff still see "Counts toward the ladder" with the
 * counting-order number and the threshold. Node twin: associateStatementNoCountsD393.test.js.
 */
final class AssociateStatementNoCountsD393Test extends TestCase
{
    /** @param array<string,mixed> $over */
    private static function render(array $over): string
    {
        $c = array_merge([
            'currency' => 'USD', 'lines' => [['line_no' => 1, 'description' => 'Granite slabs', 'fob' => 17000.0, 'in_range' => true, 'commission' => 340.0]],
            'goods_fc' => 17000.0, 'reduction_fc' => 0.0, 'basis_fc' => 17000.0, 'basis_inr' => 1428000.0, 'rate_inr' => 84.0,
            'rate_estimated' => false, 'fixed' => true, 'fixed_at' => '2026-11-19', 'fixed_rate' => 84.0, 'level_label' => 'Level 1 — 2%',
            'level_fixed' => true, 'amount_fc' => 340.0, 'amount_inr' => 28560.0, 'state' => 'paid', 'override' => false,
            'override_reason' => null, 'gstin' => '', 'gst_pct' => 18.0, 'fully_paid_on' => '2026-11-19', 'due_on' => '2026-12-19',
            'payout_run_on' => '2026-12-31', 'claim_hold' => false, 'counts' => true, 'counting_number' => 3, 'threshold_inr' => 500000.0, 'ladder_text' => 'Step 3',
            'show_fob' => true, 'show_pct' => true, 'portal' => false,
        ], $over);
        ob_start();
        require dirname(__DIR__, 2) . '/views/associate_portal/_statement.php';
        return (string) ob_get_clean();
    }

    public function testTheAssociateSeesNoCountsRow(): void
    {
        foreach (['paid', 'earned'] as $state) {
            $html = self::render(['portal' => true, 'state' => $state]);
            self::assertStringNotContainsString('Counts toward', $html, "portal, state {$state}");
            self::assertStringNotContainsString('next commission rate', $html);
            self::assertStringNotContainsString('counting order', $html);
        }
    }

    public function testStaffStillSeeTheLadderRowWithTheCountingNumber(): void
    {
        $html = self::render(['portal' => false]);
        self::assertStringContainsString('<th>Counts toward the ladder</th>', $html);
        // D-410 (24 Nov 2026): the row shows the ladder status line ("Step 3", "Same step as …", "Does not count — below ₹…").
        self::assertStringContainsString('Step 3', $html);
        self::assertStringContainsString('Does not count — below ₹500,000', self::render(['portal' => false, 'counts' => false, 'ladder_text' => 'Does not count — below ₹500,000']));
    }
}
