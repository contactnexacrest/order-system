<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\OrderLineValidator;
use App\Helpers\SettingValueValidator;
use App\Repositories\OrderProductRepository;
use App\Services\QuantityLimits;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision item 10a (13 Nov 2026, "go for the two-level version"), replacing the
 * HNT-011 single cap of 10,000,000: an order line's quantity is (1) refused above
 * 1,000,000 in any unit, and (2) above about 20 containers of its unit (SQM 30,000 ·
 * SQFT 300,000 · CBM 500 · TON 600 · PCS 600,000 · RMT 60,000 — settings) saved only with
 * "I confirm this quantity" ticked, which is audited. Node: quantityLimitsItem10a.test.js.
 * Domestic Trade: DomesticTradeTest::testItem10aQuantityLimits.
 */
final class QuantityLimitsItem10aTest extends DbTestCase
{
    private int $auditFrom = 0;

    private const MESSAGE_SQM = "This is unusually large for one line (more than about 20 containers of SQM). Check the quantity, or tick 'I confirm this quantity' to save it.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowHandTypedGoodsLines(); // D-245 P2: these lines are typed by hand; the test is about the quantity
        $_POST = [];
        $_SESSION = [];
        $this->auditFrom = (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
    }

    public function testItem10aHardBlockIsOneMillionInAnyUnit(): void
    {
        self::assertNull(OrderLineValidator::checkLine('1000000', false, '1'));
        self::assertSame('quantity can be at most 1,000,000 (got "1000000.001")', OrderLineValidator::checkLine('1000000.001', false, '1'));
        self::assertStringContainsString('quantity can be at most 1,000,000', (string) OrderLineValidator::checkLine('99999999999', false, '10'));
        self::assertStringContainsString('quantity can be at most 1,000,000', (string) OrderLineValidator::checkLine('10000000', false, '1'));
        self::assertNull(OrderLineValidator::checkLine('', true, '10'));
    }

    public function testItem10aPerUnitLevelsNeedTheTick(): void
    {
        $cases = [['SQM', 30000], ['M2', 30000], ['SQFT', 300000], ['CBM', 500], ['TON', 600], ['MT', 600], ['PCS', 600000], ['NOS', 600000], ['RMT', 60000]];
        foreach ($cases as [$unit, $level]) {
            self::assertNull(QuantityLimits::check((string) $level, $unit, false, false), "{$unit}: at the level, no tick needed");
            self::assertSame(QuantityLimits::message($unit), QuantityLimits::check((string) ($level + 0.001), $unit, false, false), "{$unit}: above the level, refused without the tick");
            self::assertNull(QuantityLimits::check((string) ($level + 1), $unit, false, true), "{$unit}: above the level, saved with the tick");
        }
        self::assertSame(self::MESSAGE_SQM, QuantityLimits::check('40000', 'SQM', false, false));
        // Other units: only the hard block. A TBC or blank quantity: nothing to confirm.
        self::assertNull(QuantityLimits::check('999999', 'BOX', false, false));
        self::assertNull(QuantityLimits::check('999999', 'SET', false, false));
        self::assertNull(QuantityLimits::check('40000', 'SQM', true, false));
        self::assertNull(QuantityLimits::check('', 'SQM', false, false));
    }

    public function testItem10aASettingChangeMovesTheLevel(): void
    {
        $pdo = Database::connection();
        $set = $pdo->prepare("UPDATE company_settings SET setting_value = :v WHERE setting_key = 'quantity_warning_level_cbm'");
        try {
            self::assertNull(QuantityLimits::check('150', 'CBM', false, false));
            $set->execute(['v' => '100']);
            self::assertSame(QuantityLimits::message('CBM'), QuantityLimits::check('150', 'CBM', false, false));
            self::assertNull(QuantityLimits::check('100', 'CBM', false, false));
            self::assertSame(100, QuantityLimits::browserLevels()['CBM']);
        } finally {
            $set->execute(['v' => '500']);
        }
        // The six settings exist (seed), take 1 to 1,000,000, and show a label and placeholder.
        $keys = $pdo->query("SELECT setting_key, setting_value, category, value_type FROM company_settings WHERE setting_key LIKE 'quantity_warning_level_%' ORDER BY setting_key")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['quantity_warning_level_cbm', 'quantity_warning_level_pcs', 'quantity_warning_level_rmt', 'quantity_warning_level_sqft', 'quantity_warning_level_sqm', 'quantity_warning_level_ton'], array_column($keys, 'setting_key'));
        self::assertSame(['500', '600000', '60000', '300000', '30000', '600'], array_column($keys, 'setting_value'));
        foreach ($keys as $k) {
            self::assertSame(['orders', 'number'], [$k['category'], $k['value_type']]);
            self::assertNull(SettingValueValidator::check('number', '25000', $k['setting_key']));
            self::assertNotNull(SettingValueValidator::check('number', '0', $k['setting_key']));
            self::assertNotNull(SettingValueValidator::check('number', '1000001', $k['setting_key']));
            self::assertStringStartsWith('Large quantity: confirm a line above', \App\Helpers\SettingLabels::label($k['setting_key']));
            self::assertMatchesRegularExpression('/^e\.g\. \d+$/', \App\Helpers\SettingLabels::placeholder($k['setting_key'], 'number'));
        }
    }

    public function testItem10aAddAndEditLineNeedTheTickAndAreAudited(): void
    {
        [$orderId, $userId] = $this->order();
        $before = count(OrderProductRepository::forOrder($orderId));

        $this->post(['quantity' => '40000', 'unit' => 'SQM']);
        (new OrderController())->addProduct(['id' => (string) $orderId]);
        self::assertCount($before, OrderProductRepository::forOrder($orderId), 'refused without the tick');
        self::assertSame('Product line: ' . self::MESSAGE_SQM, $this->flashError());

        $this->post(['quantity' => '1000001', 'unit' => 'BOX', 'confirm_large_quantity' => '1']);
        (new OrderController())->addProduct(['id' => (string) $orderId]);
        self::assertCount($before, OrderProductRepository::forOrder($orderId), 'above 1,000,000: refused even with the tick');

        $this->post(['quantity' => '29999', 'unit' => 'SQM']);
        (new OrderController())->addProduct(['id' => (string) $orderId]);
        self::assertCount($before + 1, OrderProductRepository::forOrder($orderId), 'below the level: no tick needed');
        self::assertSame(0, $this->auditCount());

        $this->post(['quantity' => '40000', 'unit' => 'SQM', 'confirm_large_quantity' => '1']);
        (new OrderController())->addProduct(['id' => (string) $orderId]);
        $lines = OrderProductRepository::forOrder($orderId);
        self::assertCount($before + 2, $lines, 'with the tick: saved');
        $big = end($lines);
        $audit = Database::connection()->query("SELECT user_id, entity_type, entity_id, new_value, reason FROM audit_log WHERE action_type = 'LARGE_QUANTITY_CONFIRMED' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([$userId, 'order_products', (int) $big['id'], '40000 SQM'], [(int) $audit['user_id'], $audit['entity_type'], (int) $audit['entity_id'], $audit['new_value']]);
        self::assertSame('Large quantity confirmed ("I confirm this quantity"): 40000 SQM on line "Granite slab item 10a" — above the 30,000 SQM level (about 20 containers).', $audit['reason']);

        // Edit: the line above its level comes ticked; unticked it is refused; the edit with the
        // same quantity is not audited again, a changed large quantity is.
        $page = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertMatchesRegularExpression('/name="confirm_large_quantity" value="1" data-confirm-tick="" checked>/', $page);
        $this->post(['quantity' => '45000', 'unit' => 'SQM']);
        (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $big['id']]);
        self::assertSame('40000.000', (string) OrderProductRepository::find((int) $big['id'])['quantity']);
        $this->post(['quantity' => '40000', 'unit' => 'SQM', 'confirm_large_quantity' => '1']);
        (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $big['id']]);
        self::assertSame(1, $this->auditCount());
        $this->post(['quantity' => '45000', 'unit' => 'SQM', 'confirm_large_quantity' => '1']);
        (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $big['id']]);
        self::assertSame('45000.000', (string) OrderProductRepository::find((int) $big['id'])['quantity']);
        self::assertSame(2, $this->auditCount());
    }

    public function testItem10aCreateOrderNeedsTheTickPerLine(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $clientId = $this->createTestClient();
        $pdo = Database::connection();
        $count = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $post = fn (array $confirm): array => [
            'client_id' => (string) $clientId,
            'incoterm_id' => (string) $pdo->query("SELECT id FROM incoterms WHERE code = 'FOB'")->fetchColumn(),
            'currency_id' => (string) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn(),
            'payment_preset_id' => (string) $pdo->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")->fetchColumn(),
            'product_description' => ['Small line item 10a', 'Large line item 10a'],
            'product_hs_code' => ['680293', '680293'],
            'product_quantity' => ['10', '600'],
            'product_unit' => ['SQM', 'CBM'],
            'product_unit_price' => ['5.00', '5.00'],
            'product_confirm_large_quantity' => $confirm,
        ];
        $before = $count();
        $_POST = $post(['0', '0']);
        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();
        self::assertSame($before, $count(), 'line 2 above 500 CBM without the tick: no order');
        self::assertSame('Product line 2: ' . QuantityLimits::message('CBM'), $this->flashError());

        $_POST = $post(['0', '1']);
        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();
        self::assertSame($before + 1, $count(), 'with the tick on line 2: created');
        self::assertSame(1, $this->auditCount());
    }

    public function testItem10aBrowserAttributesOnEveryLineForm(): void
    {
        [$orderId] = $this->order();
        $create = $this->render(fn () => (new OrderController())->create([]));
        $show = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        foreach (['create' => [$create, 'product_quantity\[\]', 'product_unit[]'], 'show' => [$show, 'quantity', 'unit']] as $form => [$html, $name, $unit]) {
            self::assertMatchesRegularExpression('/name="' . $name . '"[^>]*data-max-value="1000000"[^>]*data-confirm-above="\{[^"]*&quot;SQM&quot;:30000[^"]*&quot;CBM&quot;:500[^"]*\}" data-confirm-unit="' . preg_quote($unit, '/') . '"/', $html, $form);
            self::assertStringContainsString('data-confirm-message="This is unusually large for one line (more than about 20 containers of {unit}).', $html, $form);
            self::assertStringContainsString('I confirm this quantity</label>', $html, $form);
            self::assertStringNotContainsString('data-max-value="10000000"', $html, $form);
        }
        // Settings page: the levels' inputs carry min 1 / max 1,000,000.
        $settings = $this->render(fn () => (new \App\Controllers\SettingsController())->index([]));
        self::assertMatchesRegularExpression('/min="1" max="1000000" step="1"\s+name="settings\[quantity_warning_level_sqm\]"/', $settings);
        self::assertStringContainsString('<input type="hidden" name="product_confirm_large_quantity[]" value="0">', $create);
        self::assertStringContainsString('<input type="checkbox" data-confirm-tick="product_confirm_large_quantity[]" >', $create);
        // app.js (both stacks): one generic rule in fieldError(), the same file on PHP and Node.
        foreach ([__DIR__ . '/../../../public_html/assets/js/app.js', __DIR__ . '/../../../../NODE_MySQL_Version/app/public/js/app.js'] as $js) {
            $src = (string) file_get_contents($js);
            self::assertStringContainsString('var confirmState = confirmAboveState(el);', $src);
            self::assertStringContainsString('initConfirmAbove(); // item 10a', $src);
        }
    }

    /** @return array{0: int, 1: int} [order id, user id] */
    private function order(): array
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $orderId = $this->createTestOrder($this->createTestClient());
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        return [$orderId, $userId];
    }

    private function post(array $line): void
    {
        $_POST = array_merge(['description' => 'Granite slab item 10a', 'hs_code' => '68022310', 'unit_price' => '5.00'], $line);
        unset($_SESSION['_flash']);
    }

    private function flashError(): ?string
    {
        foreach (array_reverse($_SESSION['_flash'] ?? []) as $f) {
            if ($f['type'] === 'error') {
                return $f['message'];
            }
        }
        return null;
    }

    private function auditCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'LARGE_QUANTITY_CONFIRMED' AND id > " . $this->auditFrom)->fetchColumn();
    }

    private function render(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }
}
