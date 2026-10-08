<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\PaymentPresetRepository;
use App\Tests\Support\DbTestCase;

/**
 * Payment Preset CRUD (docs/schema.sql — payment presets) — covers the
 * two real business rules beyond plain CRUD: exactly one preset may be
 * is_default=1 (clearOtherDefaults), and is_protected blocks
 * toggleActive until unlocked via Field Protection.
 */
final class PaymentPresetRepositoryTest extends DbTestCase
{
    private function defaultCurrencyId(): int
    {
        $stmt = Database::connection()->query("SELECT id FROM currencies WHERE code = 'USD'");
        return (int) $stmt->fetchColumn();
    }

    private function minimalData(int $currencyId, array $overrides = []): array
    {
        return array_merge([
            'preset_name'             => 'PHPUnit Test Preset ' . bin2hex(random_bytes(4)),
            'is_default'              => false,
            'advance_pct'             => 40,
            'advance_trigger_text'    => 'against Proforma Invoice before production commences',
            'balance_pct'             => 60,
            'balance_trigger_option'  => 'A_BEFORE_SHIPMENT',
            'balance_days'            => 3,
            'balance_trigger_wording' => null,
            'currency_id'             => $currencyId,
            'requires_md_approval'    => false,
        ], $overrides);
    }

    public function testCreateThenFindReturnsAllFieldsIncludingCurrencyCode(): void
    {
        $userId = $this->createTestUser('Admin');
        $currencyId = $this->defaultCurrencyId();
        $id = PaymentPresetRepository::create($this->minimalData($currencyId), $userId);

        $row = PaymentPresetRepository::find($id);

        self::assertNotNull($row);
        self::assertSame('40.00', $row['advance_pct']);
        self::assertSame('60.00', $row['balance_pct']);
        self::assertSame('A_BEFORE_SHIPMENT', $row['balance_trigger_option']);
        self::assertSame('USD', $row['currency_code']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame(0, (int) $row['is_protected']);
    }

    public function testUpdateOverwritesFieldsIncludingBalanceTriggerWording(): void
    {
        $userId = $this->createTestUser('Admin');
        $currencyId = $this->defaultCurrencyId();
        $id = PaymentPresetRepository::create($this->minimalData($currencyId), $userId);

        PaymentPresetRepository::update($id, $this->minimalData($currencyId, [
            'preset_name'             => 'PHPUnit Renamed Preset',
            'advance_pct'             => 20,
            'balance_pct'             => 80,
            'balance_trigger_wording' => 'Within {days} Calendar Days of custom wording.',
        ]));

        $row = PaymentPresetRepository::find($id);
        self::assertSame('PHPUnit Renamed Preset', $row['preset_name']);
        self::assertSame('20.00', $row['advance_pct']);
        self::assertSame('80.00', $row['balance_pct']);
        self::assertSame('Within {days} Calendar Days of custom wording.', $row['balance_trigger_wording']);
    }

    public function testIsDefaultOnCreateClearsEveryOtherPresetDefault(): void
    {
        $userId = $this->createTestUser('Admin');
        $currencyId = $this->defaultCurrencyId();
        $firstId = PaymentPresetRepository::create($this->minimalData($currencyId, ['is_default' => true]), $userId);
        $secondId = PaymentPresetRepository::create($this->minimalData($currencyId, ['is_default' => true]), $userId);

        self::assertSame(0, (int) PaymentPresetRepository::find($firstId)['is_default']);
        self::assertSame(1, (int) PaymentPresetRepository::find($secondId)['is_default']);
    }

    public function testIsDefaultOnUpdateAlsoClearsEveryOtherPresetDefault(): void
    {
        $userId = $this->createTestUser('Admin');
        $currencyId = $this->defaultCurrencyId();
        $firstId = PaymentPresetRepository::create($this->minimalData($currencyId, ['is_default' => true]), $userId);
        $secondId = PaymentPresetRepository::create($this->minimalData($currencyId), $userId);

        PaymentPresetRepository::update($secondId, $this->minimalData($currencyId, ['is_default' => true]));

        self::assertSame(0, (int) PaymentPresetRepository::find($firstId)['is_default']);
        self::assertSame(1, (int) PaymentPresetRepository::find($secondId)['is_default']);
    }

    public function testToggleActiveFlipsBothWaysForAnUnprotectedPreset(): void
    {
        $userId = $this->createTestUser('Admin');
        $currencyId = $this->defaultCurrencyId();
        $id = PaymentPresetRepository::create($this->minimalData($currencyId), $userId);
        self::assertSame(1, (int) PaymentPresetRepository::find($id)['is_active']);

        PaymentPresetRepository::toggleActive($id);
        self::assertSame(0, (int) PaymentPresetRepository::find($id)['is_active']);

        PaymentPresetRepository::toggleActive($id);
        self::assertSame(1, (int) PaymentPresetRepository::find($id)['is_active']);
    }

    public function testToggleActiveThrowsForAProtectedPresetAndLeavesIsActiveUnchanged(): void
    {
        $userId = $this->createTestUser('Admin');
        $currencyId = $this->defaultCurrencyId();
        $id = PaymentPresetRepository::create($this->minimalData($currencyId), $userId);
        Database::connection()->prepare('UPDATE payment_presets SET is_protected = 1 WHERE id = :id')->execute(['id' => $id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/protected/i');
        try {
            PaymentPresetRepository::toggleActive($id);
        } finally {
            self::assertSame(1, (int) PaymentPresetRepository::find($id)['is_active']);
        }
    }

    public function testIsInUseReflectsWhetherAnyOrderReferencesThePreset(): void
    {
        $userId = $this->createTestUser('Admin');
        $currencyId = $this->defaultCurrencyId();
        $id = PaymentPresetRepository::create($this->minimalData($currencyId), $userId);

        self::assertFalse(PaymentPresetRepository::isInUse($id));
    }

    public function testAllExcludesInactiveByDefaultButIncludesOnRequest(): void
    {
        $userId = $this->createTestUser('Admin');
        $currencyId = $this->defaultCurrencyId();
        $activeId = PaymentPresetRepository::create($this->minimalData($currencyId), $userId);
        $inactiveId = PaymentPresetRepository::create($this->minimalData($currencyId), $userId);
        PaymentPresetRepository::toggleActive($inactiveId);

        $activeOnlyIds = array_column(PaymentPresetRepository::all(false), 'id');
        self::assertContains($activeId, $activeOnlyIds);
        self::assertNotContains($inactiveId, $activeOnlyIds);

        $withInactiveIds = array_column(PaymentPresetRepository::all(true), 'id');
        self::assertContains($activeId, $withInactiveIds);
        self::assertContains($inactiveId, $withInactiveIds);
    }
}
