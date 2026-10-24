<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\PaymentPresetRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql payment_presets.show_bl_instructions (90-item backlog
 * #32) — an admin-UI-editable per-preset flag deciding whether the
 * generated BL Instruction Sheet includes the BL Type / BL Consignee
 * Instruction rows (company_settings bl_type_instruction /
 * bl_consignee_instruction). Gated in exactly one place,
 * DocumentDataAssembler::blBlock(), reached through
 * OrderRepository::find()'s pp.show_bl_instructions column.
 */
final class PaymentPresetBlInstructionsTest extends DbTestCase
{
    private function setPresetShowBlInstructions(int $presetId, bool $value): void
    {
        Database::connection()
            ->prepare('UPDATE payment_presets SET show_bl_instructions = :v WHERE id = :id')
            ->execute(['v' => $value ? 1 : 0, 'id' => $presetId]);
    }

    private function standardPresetId(): int
    {
        return (int) Database::connection()
            ->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")
            ->fetchColumn();
    }

    public function testAssembleIncludesBlInstructionsWhenPresetFlagIsOn(): void
    {
        $presetId = $this->standardPresetId();
        $this->setPresetShowBlInstructions($presetId, true);
        $orderId = $this->createTestOrder($this->createTestClient());

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertNotNull($data['bl']['type_instruction']);
        self::assertNotNull($data['bl']['consignee_instruction']);
        self::assertStringContainsString('TO ORDER OF', $data['bl']['consignee_instruction']);
    }

    public function testAssembleOmitsBlInstructionsWhenPresetFlagIsOff(): void
    {
        $presetId = $this->standardPresetId();
        $this->setPresetShowBlInstructions($presetId, false);
        $orderId = $this->createTestOrder($this->createTestClient());

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertNull($data['bl']['type_instruction']);
        self::assertNull($data['bl']['consignee_instruction']);

        // Restore the shared seeded preset so other tests aren't affected.
        $this->setPresetShowBlInstructions($presetId, true);
    }

    public function testPaymentPresetRepositoryRoundTripsShowBlInstructionsFlag(): void
    {
        $userId = $this->createTestUser('Admin');
        $currencyId = (int) Database::connection()->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();

        $baseData = [
            'preset_name'             => 'PHPUnit Test Preset ' . bin2hex(random_bytes(3)),
            'is_default'              => false,
            'advance_pct'             => 40.00,
            'advance_trigger_text'    => 'against Proforma Invoice',
            'payment_method'          => 'CASH_ADVANCE',
            'balance_pct'             => 60.00,
            'balance_trigger_option'  => 'A_BEFORE_SHIPMENT',
            'balance_days'            => 3,
            'balance_trigger_wording' => null,
            'currency_id'             => $currencyId,
            'requires_md_approval'    => false,
            'show_bl_instructions'    => false,
        ];

        $presetId = PaymentPresetRepository::create($baseData, $userId);
        $created = PaymentPresetRepository::find($presetId);
        self::assertSame(0, (int) $created['show_bl_instructions'], 'create() must persist an explicit false as 0');

        PaymentPresetRepository::update($presetId, array_merge($baseData, ['show_bl_instructions' => true]));
        $updated = PaymentPresetRepository::find($presetId);
        self::assertSame(1, (int) $updated['show_bl_instructions'], 'update() must persist the flipped flag');
    }
}
