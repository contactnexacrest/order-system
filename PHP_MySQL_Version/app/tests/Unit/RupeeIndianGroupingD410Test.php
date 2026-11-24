<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * D-410 screens show rupees in Indian grouping (₹1,85,477.76, ₹5,00,000) — MoneyFormat::indian, never
 * number_format, which groups the Western way (₹185,477.76). Exchange rates "₹84.00 per USD" are exempt. Older
 * screens (payout rows, TDS lines) are outside this check.
 * Node twin: tests/unit/rupeeIndianGroupingD410.test.js.
 */
final class RupeeIndianGroupingD410Test extends TestCase
{
    private const VIEWS = ['associates/_commission_rule.php', 'associates/show.php', 'orders/_associate_margin.php', 'associate_portal/commission.php', 'incentives/payouts.php'];

    public function testRupeeAmountsUseIndianGrouping(): void
    {
        $bad = [];
        foreach (self::VIEWS as $v) {
            foreach (file(dirname(__DIR__, 2) . '/views/' . $v) as $n => $line) {
                if (str_contains($line, 'per USD')) {
                    continue;
                }
                if (preg_match('/₹<\?= number_format\(|\'₹\' \. number_format\(|\'₹\' : [^;]*number_format\(\(float\) \$__[a-z]+\[\'total_paid\'\]/u', $line)) {
                    $bad[] = $v . ':' . ($n + 1);
                }
            }
        }
        self::assertSame([], $bad, 'rupees must use MoneyFormat::indian');
        self::assertSame('1,85,477.76', \App\Helpers\MoneyFormat::indian(185477.76));
    }
}
