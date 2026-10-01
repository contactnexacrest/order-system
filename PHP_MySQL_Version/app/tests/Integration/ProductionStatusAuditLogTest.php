<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\AuditLogRepository;
use App\Tests\Support\DbTestCase;

/**
 * Users could update "Production Status" / "Estimated Shipment" on an order
 * with no record of who changed it, when, or what it said before — asked
 * directly: "when we update, where it gets logged and where can we see
 * it???". updateProductionStatus() now writes an audit log row per changed
 * field, visible on the order's own Audit Log page.
 */
final class ProductionStatusAuditLogTest extends DbTestCase
{
    private int $userId;
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $this->userId;
        $this->orderId = $this->createTestOrder($this->createTestClient());
    }

    public function testFirstUpdateIsLoggedWithNullOldValue(): void
    {
        $this->postUpdate(['production_status_text' => 'Cutting in progress — 20% complete']);

        $rows = $this->rowsFor('PRODUCTION_STATUS_UPDATED');
        self::assertCount(1, $rows);
        self::assertSame('production_status_text', $rows[0]['field_name']);
        self::assertSame('Not yet commenced', $rows[0]['old_value'], 'the schema default, since no staff update had happened yet');
        self::assertSame('Cutting in progress — 20% complete', $rows[0]['new_value']);
        self::assertSame($this->userId, (int) $rows[0]['user_id']);
    }

    public function testSubsequentUpdateCapturesPreviousValueAsOld(): void
    {
        $this->postUpdate(['production_status_text' => 'Cutting in progress — 20% complete']);
        $this->postUpdate(['production_status_text' => 'Polishing — 60% complete']);

        $rows = $this->rowsFor('PRODUCTION_STATUS_UPDATED');
        self::assertCount(2, $rows);
        self::assertSame('Cutting in progress — 20% complete', $rows[1]['old_value']);
        self::assertSame('Polishing — 60% complete', $rows[1]['new_value']);
    }

    public function testResubmittingTheSameTextDoesNotCreateADuplicateLogRow(): void
    {
        $this->postUpdate(['production_status_text' => 'Cutting in progress — 20% complete']);
        $this->postUpdate(['production_status_text' => 'Cutting in progress — 20% complete']);

        self::assertCount(1, $this->rowsFor('PRODUCTION_STATUS_UPDATED'));
    }

    public function testEstimatedShipmentChangeIsLoggedUnderItsOwnActionType(): void
    {
        $this->postUpdate(['est_shipment_date_text' => 'Week of 15 October 2026']);

        $rows = $this->rowsFor('EST_SHIPMENT_DATE_UPDATED');
        self::assertCount(1, $rows);
        self::assertSame('est_shipment_date_text', $rows[0]['field_name']);
        self::assertNull($rows[0]['old_value']);
        self::assertSame('Week of 15 October 2026', $rows[0]['new_value']);
    }

    public function testBothFieldsCanBeLoggedFromOneSubmission(): void
    {
        $this->postUpdate([
            'production_status_text' => 'Cutting in progress — 20% complete',
            'est_shipment_date_text' => 'Week of 15 October 2026',
        ]);

        self::assertCount(1, $this->rowsFor('PRODUCTION_STATUS_UPDATED'));
        self::assertCount(1, $this->rowsFor('EST_SHIPMENT_DATE_UPDATED'));
    }

    /** @param array<string, string> $fields */
    private function postUpdate(array $fields): void
    {
        $_POST = $fields;
        ob_start();
        (new OrderController())->updateProductionStatus(['id' => (string) $this->orderId]);
        ob_end_clean();
    }

    /** @return list<array<string, mixed>> */
    private function rowsFor(string $actionType): array
    {
        $rows = array_values(array_filter(
            AuditLogRepository::forOrder($this->orderId),
            static fn (array $r): bool => $r['action_type'] === $actionType
        ));
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $rows;
    }
}
