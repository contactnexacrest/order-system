<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\PricingController;
use App\Repositories\PricingRepository;
use App\Services\FxRateService;
use App\Services\PermissionService;
use App\Services\PricingService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-340 (12 Nov 2026, "go with recommendation"):
 *  - Pricing settings: a "Rate used for prices right now" box at the top of the
 *    Exchange Rate fieldset — green (Automatic, fetched rate, no warning), orange
 *    (Automatic falling back to the manual rate, or any warning), blue (Manual) —
 *    with the facts, the warnings, the MD's OK for a big move, "Fetch today's rate
 *    now" and the history; badges on the cushion and manual-rate fields.
 *  - The order page: one line with the rate the prices were worked out at (live
 *    before the PI, fixed on the PI after), for price build-up viewers only.
 * Node twin: tests/integration/fxRateBoxD340.test.js (same wording).
 */
final class FxRateBoxD340Test extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        Database::connection()->exec('DELETE FROM fx_rates');
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        $pdo->exec('DELETE FROM fx_rates');
        foreach (PricingService::DEFAULTS as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $_SESSION = [];
        parent::tearDown();
    }

    private function rate(string $date, ?float $market, string $status, ?string $note = null): void
    {
        Database::connection()->prepare('INSERT INTO fx_rates (rate_date, market_rate, source, status, change_pct, note, fetched_at) VALUES (:d, :r, :s, :st, :c, :n, :t)')
            ->execute(['d' => $date, 'r' => $market, 's' => $market !== null ? 'Frankfurter (European Central Bank)' : null, 'st' => $status,
                'c' => $status === 'waiting_md' ? '2.941' : null, 'n' => $note, 't' => $date . ' 07:00:00']);
    }

    private function setting(string $key, string $value): void
    {
        Database::connection()->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $key, 'v' => $value]);
    }

    private function page(string $role = 'Managing Director'): string
    {
        $_SESSION = ['_auth_user_id' => $this->createTestUser($role)];
        PermissionService::resetCache();
        ob_start();
        (new PricingController())->settings([]);
        return (string) ob_get_clean();
    }

    private static function day(string $ymd): string
    {
        return date('j M Y', (int) strtotime($ymd));
    }

    /** The badge with this data-fx-when and text is shown (no hidden attribute). */
    private static function shown(string $page, string $when, string $text): bool
    {
        return (bool) preg_match('/data-fx-when="' . preg_quote($when, '/') . '">' . preg_quote($text, '/') . '</u', $page);
    }

    public function testAutomaticWithTodaysRateIsGreen(): void
    {
        $today = date('Y-m-d');
        $this->rate($today, 88.40, 'in_use');
        $fx = FxRateService::status();
        self::assertSame(['auto', 'green', 'Automatic · fetched today', self::day($today) . ', 07:00', '2.0% → ₹88.40 × 0.98'],
            [$fx['state'], $fx['tone'], $fx['badge'], $fx['fetched'], $fx['cushion_line']]);
        self::assertSame('Manual rate ₹82.00 is not used — it is only the fall-back if no rate has ever been fetched.', $fx['manual_note']);
        $page = $this->page();
        foreach (['RATE USED FOR PRICES RIGHT NOW', 'fx-now fx-now-green', '₹86.63 per USD <span class="fx-now-badge">Automatic · fetched today</span>',
            '<span>Market rate</span><b>₹88.40</b>', '<span>Source</span><b>Frankfurter (European Central Bank)</b>', 'Why?',
            '<button type="submit" form="fx-fetch-form" class="btn-sm">Fetch today\'s rate now</button>', 'id="fx-fetch-form"',
            '<a class="btn-sm" href="#fx-history">History (last 30 fetches)</a>', '<details id="fx-history">', 'data-fx-has-market="1"'] as $s) {
            self::assertStringContainsString($s, $page);
        }
        self::assertTrue(self::shown($page, 'auto-market', 'not used now — fall-back only'));
        self::assertFalse(self::shown($page, 'manual', 'not used in Manual'));
        self::assertMatchesRegularExpression('/name="pricing_fx_rate"[^>]*data-fx-muted-when="auto-market" class="fx-unused"/', $page);
        // The separate "Exchange Rate" block above the form is gone (its content is in the box).
        self::assertStringNotContainsString('<h2>Exchange Rate</h2>', $page);
    }

    public function testAutomaticNeverFetchedFallsBackToTheManualRateInOrange(): void
    {
        $fx = FxRateService::status();
        self::assertSame(['fallback', 'orange', 'Manual fall-back', false], [$fx['state'], $fx['tone'], $fx['badge'], $fx['has_market']]);
        $page = $this->page();
        foreach (['fx-now fx-now-orange', '₹82.00 per USD <span class="fx-now-badge">Manual fall-back</span>',
            '⚠ No market rate has been fetched yet — prices use the manual rate ₹82.00 until the first daily fetch.',
            'Check that the daily job runs (README › Scheduled jobs), or press &quot;Fetch today&#039;s rate now&quot;.', 'data-fx-has-market="0"'] as $s) {
            self::assertStringContainsString($s, $page);
        }
        self::assertTrue(self::shown($page, 'auto-none', 'in use now'));
        self::assertFalse(self::shown($page, 'auto-market', 'not used now — fall-back only'));
    }

    public function testAutomaticWithTodaysFetchFailedKeepsTheLastRateInOrange(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->rate($yesterday, 88.16, 'in_use');
        $this->rate(date('Y-m-d'), null, 'failed', 'Frankfurter (European Central Bank) did not answer');
        $fx = FxRateService::status();
        self::assertSame(['auto', 'orange', 'Automatic · rate of ' . self::day($yesterday)], [$fx['state'], $fx['tone'], $fx['badge']]);
        $page = $this->page();
        self::assertStringContainsString('⚠ Today&#039;s rate could not be fetched (Frankfurter (European Central Bank) did not answer) — prices keep the rate of ' . $yesterday . '.', $page);
        self::assertStringContainsString('₹86.40 per USD', $page);
        self::assertTrue(self::shown($page, 'auto-market', 'not used now — fall-back only'));
    }

    public function testManualAboveTheMarketIsBlueWithTheComparison(): void
    {
        $today = date('Y-m-d');
        $this->rate($today, 88.40, 'in_use');
        $this->setting('fx_mode', 'manual');
        $this->setting('pricing_fx_rate', '93.0000');
        $fx = FxRateService::status();
        self::assertSame(['manual', 'blue', 'Manual'], [$fx['state'], $fx['tone'], $fx['badge']]);
        self::assertSame('For comparison, last market rate fetched: ₹88.40 (' . self::day($today) . ') — your rate is 5.2% above the market.', $fx['compare']);
        self::assertSame('Above the market means prices in USD come out lower than intended.', $fx['above_note']);
        $page = $this->page();
        self::assertStringContainsString('fx-now fx-now-blue', $page);
        self::assertStringContainsString('₹93.00 per USD <span class="fx-now-badge">Manual</span>', $page);
        self::assertTrue(self::shown($page, 'manual', 'not used in Manual'));
        self::assertTrue(self::shown($page, 'manual', 'in use now'));
        self::assertMatchesRegularExpression('/name="fx_cushion_pct"[^>]*data-fx-muted-when="manual" class="fx-unused"/', $page);
        // Below the market: no warning.
        $this->setting('pricing_fx_rate', '86.0000');
        $fx = FxRateService::status();
        self::assertSame('For comparison, last market rate fetched: ₹88.40 (' . self::day($today) . ') — your rate is 2.7% below the market.', $fx['compare']);
        self::assertNull($fx['above_note']);
    }

    public function testTheMdsDecisionOnABigMoveSitsInTheBoxAndEdOnlyLooks(): void
    {
        $this->rate(date('Y-m-d', strtotime('-1 day')), 88.40, 'in_use');
        $this->rate(date('Y-m-d'), 91.00, 'waiting_md');
        $page = $this->page();
        self::assertStringContainsString('fx-now fx-now-orange', $page);
        self::assertMatchesRegularExpression('#<form method="post" action="/pricing/fx-rates/\d+/decide" id="fx-decide-form">#', $page);
        self::assertStringContainsString('<input type="text" name="reason" form="fx-decide-form" required', $page);
        self::assertStringContainsString('<button type="submit" form="fx-decide-form" name="decision" value="approve"', $page);
        $ed = $this->page('Executive Director');
        self::assertStringContainsString('RATE USED FOR PRICES RIGHT NOW', $ed);
        self::assertStringNotContainsString('fx-fetch-form', $ed);
        self::assertStringNotContainsString('fx-decide-form', $ed);
    }

    public function testTheOrderLineBeforeAndAfterTheProformaInvoice(): void
    {
        $today = date('Y-m-d');
        $this->rate($today, 88.40, 'in_use');
        $orderId = $this->createTestOrder($this->createTestClient());
        $order = \App\Repositories\OrderRepository::find($orderId);
        $live = [1 => ['status' => 'ok', 'line' => ['price_snapshot' => null]]];
        self::assertNull(FxRateService::orderLine($order, [1 => ['status' => 'unchecked', 'line' => ['price_snapshot' => null]]]), 'no checked line, no line');
        self::assertSame('Prices worked out at ₹86.63 per USD (Automatic, ' . self::day($today) . ') — live: follows today\'s rate.', FxRateService::orderLine($order, $live));
        $this->setting('fx_mode', 'manual');
        self::assertSame('Prices worked out at ₹82.00 per USD (Manual) — live: follows today\'s rate.', FxRateService::orderLine($order, $live));
        $this->setting('fx_mode', 'auto');
        // After the PI: the rate fixed on the lines (price_snapshot), with the PI's date.
        $this->createTestDocument($orderId, 'PI');
        $fixed = [1 => ['status' => 'ok', 'line' => ['price_snapshot' => json_encode(['fx_rate' => 85.5, 'floor' => 1])]]];
        self::assertSame('Prices worked out at ₹85.50 per USD — fixed on the Proforma Invoice of ' . self::day($today) . ' at ₹85.50 per USD; today\'s rate ₹86.63 does not change them.',
            FxRateService::orderLine($order, $fixed));
        $same = [1 => ['status' => 'ok', 'line' => ['price_snapshot' => json_encode(['fx_rate' => 86.632])]]];
        self::assertSame('Prices worked out at ₹86.63 per USD — fixed on the Proforma Invoice of ' . self::day($today) . ' at ₹86.63 per USD.', FxRateService::orderLine($order, $same));
        // Figures without a rate: the order's rate fixed with the PI.
        $order['pricing_fx_rate_at_pi'] = '84.0000';
        self::assertStringStartsWith('Prices worked out at ₹84.00 per USD — fixed on the Proforma Invoice of ', FxRateService::orderLine($order, [1 => ['status' => 'approved', 'line' => ['price_snapshot' => '{"floor":1}']]]));
    }

    public function testOnlyPriceBuildupViewersSeeTheOrderLine(): void
    {
        $can = static function (string $role): bool {
            $roleId = (int) Database::connection()->query('SELECT id FROM roles WHERE name = ' . Database::connection()->quote($role))->fetchColumn();
            return PermissionService::can(0, $roleId, 'view_price_buildup');
        };
        self::assertTrue($can('Managing Director'));
        self::assertTrue($can('Executive Director'));
        self::assertFalse($can('Export Executive'));
        $view = (string) file_get_contents(__DIR__ . '/../../views/orders/show.php');
        self::assertStringContainsString("\$__fxOrderLine = \$sees['selling'] && !empty(\$seesPriceBuildup) ? \\App\\Services\\FxRateService::orderLine(", $view);
        self::assertStringContainsString('id="price-rate"', $view);
        // Never on documents (D-304).
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../templates', \FilesystemIterator::SKIP_DOTS)) as $tpl) {
            self::assertStringNotContainsString('Prices worked out at', (string) file_get_contents((string) $tpl));
        }
        self::assertStringNotContainsString('FxRateService', (string) file_get_contents(__DIR__ . '/../../src/Services/DocumentDataAssembler.php'));
    }

    public function testTheBadgesFollowTheModeDropDownInTheSitewideScript(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/js/app.js');
        self::assertStringContainsString("matches('select[data-fx-mode]')", $js);
        self::assertStringContainsString("'auto-market' : 'auto-none'", $js);
        self::assertStringContainsString("classList.toggle('fx-unused'", $js);
    }
}
