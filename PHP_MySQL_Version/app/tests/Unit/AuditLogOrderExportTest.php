<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controllers\AuditLogController;
use PHPUnit\Framework\TestCase;

/**
 * External QA report M-03: "One-click export of an order's audit log
 * (privileged only)." Drives the extracted orderAuditLogCsvRows() pure
 * function directly, rather than the forOrder() controller method itself,
 * since Csv::stream() calls exit() on its success path — same reasoning
 * as ReportCsvExportTest.
 */
final class AuditLogOrderExportTest extends TestCase
{
    public function testRowsMapToTheExpectedCsvColumns(): void
    {
        $rows = [
            ['created_at' => '2026-10-01 09:00:00', 'user_name' => 'Jane Staff', 'action_type' => 'FIELD_EDIT', 'entity_type' => 'orders', 'entity_id' => 5, 'field_name' => 'incoterm_id', 'old_value' => '1', 'new_value' => '2', 'reason' => 'Buyer changed terms'],
            ['created_at' => '2026-10-01 09:05:00', 'user_name' => null, 'action_type' => 'DOCUMENT_GENERATED', 'entity_type' => 'documents', 'entity_id' => 9, 'field_name' => null, 'old_value' => null, 'new_value' => null, 'reason' => null],
        ];

        $result = AuditLogController::orderAuditLogCsvRows($rows);

        self::assertSame('Jane Staff', $result[0]['User']);
        self::assertSame('orders #5', $result[0]['Entity']);
        self::assertSame('system', $result[1]['User'], 'a system-generated row with no user must fall back to "system", same as the on-page table');
        self::assertSame('documents #9', $result[1]['Entity']);
        self::assertSame('', $result[1]['Field'], 'a null field/old/new/reason must render as an empty cell, not the literal word null');
    }
}
