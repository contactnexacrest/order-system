<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AmendmentRepository;
use App\Repositories\OrderRepository;
use App\Services\AmendmentService;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * Found while verifying that an activated amendment's payment terms
 * correctly flow into the "standalone order" snapshot: orders.
 * balance_trigger_wording is custom free text snapshotted from whichever
 * preset the order started with (90-item backlog "standalone order") —
 * written for THAT preset's own balance_trigger_option. An amendment can
 * change the option (e.g. before-shipment -> against-BL) without
 * touching the wording, which would otherwise leave stale text printed
 * alongside the new option. AmendmentService::attachSignedCopyAndActivate()
 * now clears the wording whenever the option actually changes, falling
 * back to DocumentDataAssembler::balanceTriggerSentence()'s generic
 * built-in sentence for the new option — and leaves it untouched when
 * the option is unchanged.
 */
final class AmendmentBalanceTriggerWordingTest extends DbTestCase
{
    private function activateAmendment(int $orderId, ?string $balanceTriggerOption, ?int $balanceDays): int
    {
        $amendmentId = AmendmentRepository::create(
            'AMD-WORDING-TEST-' . bin2hex(random_bytes(4)),
            $orderId,
            'PHPUnit amendment wording test',
            'importer',
            ['note' => 'snapshot'],
            null, null, null,
            $balanceTriggerOption, $balanceDays, null, null
        );
        AmendmentService::approveByMd($amendmentId, 1);
        $documentId = $this->createTestDocument($orderId, 'QT');
        AmendmentRepository::attachDocument($amendmentId, $documentId);
        $fileId = $this->createTestFile($orderId);
        AmendmentService::attachSignedCopyAndActivate($amendmentId, $fileId, 1);
        return $amendmentId;
    }

    public function testAmendmentChangingTheBalanceTriggerOptionClearsTheStaleWording(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer');
        $before = OrderRepository::find($orderId);
        self::assertSame('A_BEFORE_SHIPMENT', $before['balance_trigger_option']);
        self::assertStringContainsString('before shipment', $before['balance_trigger_wording']);

        $this->activateAmendment($orderId, 'B_AGAINST_BL', 14);

        $after = OrderRepository::find($orderId);
        self::assertSame('B_AGAINST_BL', $after['balance_trigger_option']);
        self::assertNull($after['balance_trigger_wording'], 'wording written for the OLD option must not survive an amendment that changes the option');

        $sentence = DocumentDataAssembler::balanceTriggerSentence($after['balance_trigger_option'], (int) $after['balance_days'], $after['balance_trigger_wording']);
        self::assertStringContainsString('Bill of Lading', $sentence, 'future documents must print the generic sentence for the NEW option, never stale text about shipment');
        self::assertStringNotContainsString('before shipment', $sentence);
    }

    public function testAmendmentNotChangingTheBalanceTriggerOptionKeepsTheExistingWording(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer');
        $originalWording = OrderRepository::find($orderId)['balance_trigger_wording'];

        // Only the advance split changes — balance_trigger_option/days are untouched.
        $amendmentId = AmendmentRepository::create(
            'AMD-WORDING-TEST-' . bin2hex(random_bytes(4)),
            $orderId,
            'PHPUnit amendment wording test',
            'importer',
            ['note' => 'snapshot'],
            25.0, null, null, null, null, null, null
        );
        AmendmentService::approveByMd($amendmentId, 1);
        $documentId = $this->createTestDocument($orderId, 'QT');
        AmendmentRepository::attachDocument($amendmentId, $documentId);
        $fileId = $this->createTestFile($orderId);
        AmendmentService::attachSignedCopyAndActivate($amendmentId, $fileId, 1);

        $after = OrderRepository::find($orderId);
        self::assertSame('A_BEFORE_SHIPMENT', $after['balance_trigger_option'], 'the option itself must stay unchanged');
        self::assertSame($originalWording, $after['balance_trigger_wording'], 'wording still matches the unchanged option, so it must survive');
        self::assertSame(25.0, (float) $after['advance_pct']);
    }
}
