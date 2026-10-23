<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * External QA report L-05: the buyer-facing T&C (QT/PI/OC) still said
 * "Grade A quality" in the Natural Stone Characteristics clause — the
 * owner asked for "quality is as agreed" instead. A content-only
 * regression guard: fails if "Grade A quality" is ever reintroduced
 * into the seeded clause text.
 */
final class NaturalStoneClauseWordingTest extends DbTestCase
{
    public function testNaturalStoneClauseNoLongerSaysGradeAQuality(): void
    {
        $stmt = Database::connection()->query(
            "SELECT clause_text FROM tc_clauses WHERE clause_title = 'Natural Stone Characteristics'"
        );
        $clauseText = (string) $stmt->fetchColumn();

        self::assertNotEmpty($clauseText, 'the Natural Stone Characteristics clause must exist');
        self::assertStringNotContainsString('Grade A quality', $clauseText);
        self::assertStringContainsString('quality', $clauseText);
        self::assertStringContainsString('as agreed', $clauseText);
    }

    /**
     * External QA report L-05 v4 retest: "automated PDF check could not
     * locate the paragraph - open one QT PDF to confirm it is printed."
     * The clause row itself was already confirmed correct above — this
     * confirms it actually reaches the rendered PDF for all three
     * buyer-facing document types it's seeded against, not just the DB.
     */
    #[DataProvider('documentTypeProvider')]
    public function testClauseIsActuallyPrintedOnTheRenderedPdf(string $documentTypeCode): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderId, 1, 'L-05 verification slab', '600x600x18mm', 'Polished', '100', false, 'sqm', '25.00');
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, $documentTypeCode, $userId, null, false, true);
        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        $text = @shell_exec('pdftotext ' . escapeshellarg($file['server_path']) . ' - 2>/dev/null');

        if ($text === null || $text === false || trim($text) === '') {
            self::markTestSkipped('pdftotext is not available in this environment.');
        }

        self::assertStringNotContainsString('Grade A', $text, "{$documentTypeCode} must not print 'Grade A' anywhere");
        self::assertStringContainsString('quality of granite supplied is as agreed', $text, "{$documentTypeCode} must print the corrected Natural Stone Characteristics wording");
    }

    /** @return array<string,array{0:string}> */
    public static function documentTypeProvider(): array
    {
        return ['QT' => ['QT'], 'PI' => ['PI'], 'OC' => ['OC']];
    }
}
