<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Phase 2 item 9 of the approved remediation plan: "RBI Purpose Codes
 * incomplete in Admin Settings vs PI document." Root cause: the PI's own
 * PDF template hardcoded a static 7-code reference cheat-sheet (most of
 * them irrelevant to a PI, which only ever requests the advance payment),
 * while CI and FDN each correctly print their one admin-configured,
 * relevant code (rbi_purpose_code_balance / rbi_purpose_code_freight) —
 * and the PI's own DOCX builder already did the same correct thing via
 * rbi_purpose_code_advance. The admin Settings screen was never
 * "incomplete" — the PI PDF template just wasn't reading it. Made the PI
 * PDF match the DOCX builder and the other two document types.
 */
final class PiRbiPurposeCodeTest extends DbTestCase
{
    private function extractPdfText(int $pdfFileId): string
    {
        $file = FileStoreRepository::find($pdfFileId);
        self::assertNotNull($file, 'generated PDF file_store row must exist');
        $process = proc_open(
            ['pdftotext', $file['server_path'], '-'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $text = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return (string) $text;
    }

    public function testPiPdfShowsTheSingleAdminConfiguredAdvanceCodeNotTheStaticSevenCodeList(): void
    {
        Database::connection()->prepare(
            "UPDATE company_settings SET setting_value = 'P0103-TEST' WHERE setting_key = 'rbi_purpose_code_advance'"
        )->execute();

        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'PI', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('P0103-TEST', $text, 'PI must show its own admin-configured advance-payment RBI code');
        self::assertStringNotContainsString('Inspection/Certificate Fees', $text, 'the static, mostly-irrelevant 7-code cheat-sheet must be gone');
        self::assertStringNotContainsString('Freight/Insurance Recovery', $text, 'a PI never requests freight — that code belongs on the FDN, not here');
    }
}
