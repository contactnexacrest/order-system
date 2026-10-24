<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "30/70 payment preset": only two seeded presets
 * existed (both 40% advance / 60% balance), and the full Payment
 * Presets CRUD screen (/payment-presets) already lets an admin create
 * a custom one — but nobody had actually added the 30/70 split staff
 * wanted as a standard, reusable option. Adds two seeded 30/70 presets
 * — "Extended Terms — Before Shipment" and "Extended Terms — Post-BL"
 * (the latter renamed from the original single "Extended Terms —
 * 30/70" once a Before-Shipment sibling existed, mirroring the
 * existing 40/60 bucket's own Before-Shipment/Post-BL pair) — and
 * proves the SOP's own claim (docs/SOP/15-admin-settings.md: "Creating
 * a new preset ... needs no further code change anywhere") actually
 * holds for them: an order created on either preset snapshots its own
 * advance_pct/balance_pct from it (90-item backlog "standalone order" —
 * OrderRepository::insertRow()), never a hardcoded 40/60 split.
 */
final class ExtendedTermsPaymentPresetTest extends DbTestCase
{
    public function testThePostBlPresetIsSeededWithTheRightSplit(): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM payment_presets WHERE preset_name = :name');
        $stmt->execute(['name' => 'Extended Terms — Post-BL']);
        $preset = $stmt->fetch();

        self::assertNotFalse($preset, 'the Extended Terms — Post-BL preset must be seeded');
        self::assertSame('30.00', $preset['advance_pct']);
        self::assertSame('70.00', $preset['balance_pct']);
        self::assertSame('B_AGAINST_BL', $preset['balance_trigger_option']);
        self::assertSame(1, (int) $preset['is_active']);
        self::assertSame(1, (int) $preset['is_protected'], 'all five presets ship protected — they drive where money is actually sent/received');
    }

    public function testTheBeforeShipmentPresetIsSeededWithTheRightSplit(): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM payment_presets WHERE preset_name = :name');
        $stmt->execute(['name' => 'Extended Terms — Before Shipment']);
        $preset = $stmt->fetch();

        self::assertNotFalse($preset, 'the Extended Terms — Before Shipment preset must be seeded');
        self::assertSame('30.00', $preset['advance_pct']);
        self::assertSame('70.00', $preset['balance_pct']);
        self::assertSame('A_BEFORE_SHIPMENT', $preset['balance_trigger_option']);
        self::assertSame(3, (int) $preset['balance_days']);
        self::assertSame(1, (int) $preset['is_active']);
        self::assertSame(1, (int) $preset['is_protected']);
    }

    /**
     * A sight LC has no separate cash advance leg — the full amount is
     * paid once shipping documents are presented to the bank, so this
     * preset's split is 0% advance / 100% balance, not 30/70.
     */
    public function testTheLcPresetHasNoAdvanceLeg(): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM payment_presets WHERE preset_name = :name');
        $stmt->execute(['name' => 'Irrevocable Letter of Credit at Sight']);
        $preset = $stmt->fetch();

        self::assertNotFalse($preset);
        self::assertSame('0.00', $preset['advance_pct']);
        self::assertSame('100.00', $preset['balance_pct']);
        self::assertSame('LC', $preset['payment_method']);
        self::assertSame(1, (int) $preset['is_protected']);
    }

    /**
     * 90-item backlog "standalone order": an order's advance_pct/balance_pct
     * are now snapshotted from whichever preset it's created with (never
     * a hardcoded 40/60), not re-read from a live join afterward — so
     * this proves the snapshot taken AT CREATION reflects the preset
     * actually picked, rather than switching the preset post-creation
     * (which, correctly, no longer has any effect — see
     * OrderRepository::insertRow()'s docblock).
     */
    public function testAnOrderOnThisPresetComputesItsOwnSplitWithNoHardcodedPercentage(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Extended Terms — Post-BL');

        $order = OrderRepository::find($orderId);

        self::assertSame(30.0, (float) $order['advance_pct'], 'must reflect this preset\'s own 30%, never a hardcoded 40%');
        self::assertSame(70.0, (float) $order['balance_pct']);
    }

    public function testAnOrderOnTheBeforeShipmentPresetComputesItsOwnSplit(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Extended Terms — Before Shipment');

        $order = OrderRepository::find($orderId);

        self::assertSame(30.0, (float) $order['advance_pct']);
        self::assertSame(70.0, (float) $order['balance_pct']);
    }
}
