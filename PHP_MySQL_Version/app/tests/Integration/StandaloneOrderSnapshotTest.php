<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\OrderRepository;
use App\Repositories\PortRepository;
use App\Services\OrderDuplicationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "standalone order": an order's incoterm/currency/port/
 * payment-preset terms are resolved ONCE at creation
 * (OrderRepository::insertRow()) and snapshotted onto the order's own
 * columns — never re-read from incoterms/currencies/ports/payment_presets
 * again. Before this, every one of these was a live JOIN
 * (OrderRepository::find()), so editing or retiring a lookup row later
 * silently rewrote what every past order referencing it displayed and
 * printed, even an order closed years ago. This proves the isolation
 * actually holds: editing each of the four lookup tables after an order
 * is created must never change that order's own resolved values.
 * Duplicating an order is the one deliberate exception — it re-resolves
 * TODAY's current lookup-table values (a duplicate is a new commercial
 * decision made today, not a continuation of the source's possibly
 * years-old terms — see OrderDuplicationService / insertRow()'s own
 * docblock).
 */
final class StandaloneOrderSnapshotTest extends DbTestCase
{
    public function testOrderSnapshotsIncotermCurrencyPresetAtCreation(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
        $order = OrderRepository::find($orderId);

        self::assertSame('CIF', $order['incoterm_code']);
        self::assertSame('EUR', $order['currency_code']);
        self::assertSame('Standard — New Buyer', $order['preset_name']);
        self::assertSame('40.00', $order['advance_pct']);
        self::assertSame('60.00', $order['balance_pct']);
    }

    public function testOrderSnapshotsPortNameAndCityAtCreation(): void
    {
        $portId = PortRepository::create('Nhava Sheva (JNPT)', 'Mumbai', 'India', 'loading', 97);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', $portId);
        $order = OrderRepository::find($orderId);

        self::assertSame('Nhava Sheva (JNPT)', $order['port_of_loading_name']);
        self::assertSame('Mumbai', $order['port_of_loading_city']);
    }

    public function testEditingTheIncotermsTableDoesNotChangeAnExistingOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        self::assertSame('FOB', OrderRepository::find($orderId)['incoterm_code']);

        Database::connection()->prepare("UPDATE incoterms SET code = 'ZZZ' WHERE code = 'FOB'")->execute();

        self::assertSame('FOB', OrderRepository::find($orderId)['incoterm_code'], 'an existing order must keep its own snapshot even after the incoterms table changes');

        // restore for other tests in this process
        Database::connection()->prepare("UPDATE incoterms SET code = 'FOB' WHERE code = 'ZZZ'")->execute();
    }

    public function testEditingTheCurrenciesTableDoesNotChangeAnExistingOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        self::assertSame('USD', OrderRepository::find($orderId)['currency_code']);

        Database::connection()->prepare("UPDATE currencies SET code = 'ZZZ' WHERE code = 'USD'")->execute();

        self::assertSame('USD', OrderRepository::find($orderId)['currency_code'], 'an existing order must keep its own snapshot even after the currencies table changes');

        Database::connection()->prepare("UPDATE currencies SET code = 'USD' WHERE code = 'ZZZ'")->execute();
    }

    public function testEditingAPortsNameAndCityDoesNotChangeAnExistingOrder(): void
    {
        $portId = PortRepository::create('Original Terminal Name', 'Original City', 'India', 'loading', 96);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', $portId);
        self::assertSame('Original Terminal Name', OrderRepository::find($orderId)['port_of_loading_name']);

        Database::connection()->prepare('UPDATE ports SET name = :name, city = :city WHERE id = :id')
            ->execute(['name' => 'Renamed Terminal', 'city' => 'Renamed City', 'id' => $portId]);

        $order = OrderRepository::find($orderId);
        self::assertSame('Original Terminal Name', $order['port_of_loading_name'], 'an existing order must keep its own snapshot even after the port is renamed');
        self::assertSame('Original City', $order['port_of_loading_city']);
    }

    public function testEditingAPaymentPresetsTermsDoesNotChangeAnExistingOrder(): void
    {
        $pdo = Database::connection();
        $presetId = (int) $pdo->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")->fetchColumn();

        $orderId = $this->createTestOrder($this->createTestClient());
        $before = OrderRepository::find($orderId);
        self::assertSame('40.00', $before['advance_pct']);
        self::assertSame('60.00', $before['balance_pct']);

        $pdo->prepare('UPDATE payment_presets SET advance_pct = 55.00, balance_pct = 45.00, show_bl_instructions = 0 WHERE id = :id')
            ->execute(['id' => $presetId]);

        $after = OrderRepository::find($orderId);
        self::assertSame('40.00', $after['advance_pct'], 'an existing order must keep its own snapshot even after its preset changes');
        self::assertSame('60.00', $after['balance_pct']);
        self::assertSame(1, (int) $after['show_bl_instructions']);

        // restore for other tests in this process
        $pdo->prepare('UPDATE payment_presets SET advance_pct = 40.00, balance_pct = 60.00, show_bl_instructions = 1 WHERE id = :id')
            ->execute(['id' => $presetId]);
    }

    public function testDuplicatingAnOrderReResolvesTodaysCurrentPresetValues(): void
    {
        $pdo = Database::connection();
        $presetId = (int) $pdo->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")->fetchColumn();

        $clientId = $this->createTestClient();
        $sourceOrderId = $this->createTestOrder($clientId);
        self::assertSame('40.00', OrderRepository::find($sourceOrderId)['advance_pct']);

        // "6 months later" the preset's own terms change.
        $pdo->prepare('UPDATE payment_presets SET advance_pct = 25.00, balance_pct = 75.00 WHERE id = :id')
            ->execute(['id' => $presetId]);

        $duplicateOrderId = OrderDuplicationService::duplicate($sourceOrderId, $this->createTestUser('Admin'));

        $source = OrderRepository::find($sourceOrderId);
        $duplicate = OrderRepository::find($duplicateOrderId);

        self::assertSame('40.00', $source['advance_pct'], 'the original order must still reflect what was true when IT was created');
        self::assertSame('25.00', $duplicate['advance_pct'], 'a duplicate is a new commercial decision made today — it must reflect the preset\'s CURRENT terms, not freeze-copy the source\'s old snapshot');
        self::assertSame('75.00', $duplicate['balance_pct']);

        // restore for other tests in this process
        $pdo->prepare('UPDATE payment_presets SET advance_pct = 40.00, balance_pct = 60.00 WHERE id = :id')
            ->execute(['id' => $presetId]);
    }
}
