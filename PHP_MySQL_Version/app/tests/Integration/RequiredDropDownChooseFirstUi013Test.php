<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ComplianceTaskTypeController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Tests\Support\DbTestCase;

/**
 * QA UI-013 — owner decision 13 Nov 2026 (item 6, Option A): on every create /
 * add form a required drop-down starts at an empty "Choose…", so the user picks
 * consciously; the browser asks for it beside the field and the server refuses
 * an empty choice (no silent default). The views are checked as a whole by
 * FormLabelConventionsTest::testUi013…; this test checks the server side and
 * the New Order page. Node twin: requiredDropDownChooseFirstUi013.test.js.
 */
final class RequiredDropDownChooseFirstUi013Test extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        Flash::pull();
    }

    private function lastFlash(): string
    {
        $messages = Flash::pull();
        return (string) ((end($messages) ?: ['message' => ''])['message']);
    }

    public function testUi013ANewComplianceTaskWithoutWhenItIsDoneIsRefused(): void
    {
        $name = 'UI013 Task ' . bin2hex(random_bytes(3));
        $_POST = ['name' => $name, 'phase' => ''];
        (new ComplianceTaskTypeController())->create([]);
        self::assertSame('Choose when the task is done.', $this->lastFlash());
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM compliance_task_types WHERE name = :n');
        $count->execute(['n' => $name]);
        self::assertSame(0, (int) $count->fetchColumn(), 'never saved with a default phase');

        $_POST = ['name' => $name, 'phase' => 'shipment'];
        (new ComplianceTaskTypeController())->create([]);
        self::assertStringContainsString('added to the compliance checklist', $this->lastFlash());
    }

    public function testUi013ANewOrderWithoutItsCurrencyIsRefusedAndTheTypedValuesAreKept(): void
    {
        $clientId = $this->createTestClient();
        $pdo = Database::connection();
        $incoterm = (int) $pdo->query("SELECT id FROM incoterms ORDER BY id LIMIT 1")->fetchColumn();
        $preset = (int) $pdo->query("SELECT id FROM payment_presets ORDER BY id LIMIT 1")->fetchColumn();
        $_POST = ['client_id' => (string) $clientId, 'container_type' => '20ft Standard', 'incoterm_id' => (string) $incoterm, 'payment_preset_id' => (string) $preset, 'currency_id' => ''];
        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();
        self::assertSame('Choose the order currency.', $this->lastFlash());
        self::assertSame((string) $incoterm, (string) (Flash::pullOld()['incoterm_id'] ?? ''), 'the typed values come back');
    }

    public function testD376ANewOrderWithoutAContainerTypeIsRefusedAndTheFieldIsRequired(): void
    {
        $clientId = $this->createTestClient();
        $pdo = Database::connection();
        $incoterm = (int) $pdo->query("SELECT id FROM incoterms ORDER BY id LIMIT 1")->fetchColumn();
        $preset = (int) $pdo->query("SELECT id FROM payment_presets ORDER BY id LIMIT 1")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
        $_POST = ['client_id' => (string) $clientId, 'incoterm_id' => (string) $incoterm, 'payment_preset_id' => (string) $preset, 'currency_id' => (string) $currency, 'container_type' => ''];
        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();
        self::assertSame('Choose the container type.', $this->lastFlash());
        Flash::pullOld();

        ob_start();
        (new OrderController())->create([]);
        $page = (string) ob_get_clean();
        self::assertStringContainsString('Container Type *', $page);
        self::assertSame(1, preg_match('#<select name="container_type" required>\s*<option value="">Choose…</option>#', $page));
    }

    public function testUi013TheNewOrderCurrencyStartsAtChooseWithNothingPreselected(): void
    {
        ob_start();
        (new OrderController())->create([]);
        $page = (string) ob_get_clean();
        self::assertSame(1, preg_match('#<select name="currency_id" id="order-currency-select"[^>]*>(.*?)</select>#s', $page, $m));
        self::assertStringStartsWith('<option value="">Choose…</option>', trim($m[1]));
        self::assertStringNotContainsString('selected', $m[1], 'no default currency');
    }
}
