<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\HsCodeProductGuideRepository;
use App\Repositories\HsCodeRepository;
use App\Tests\Support\DbTestCase;

/**
 * Point 4 — bulk-importing a real customs reference sheet (the business's
 * own granite/marble HS Code Quick Reference, seeded via docs/seed.sql)
 * instead of typing codes in one at a time. Covers the actual
 * pasted-from-Excel format (Tab separated), the hand-typed fallback
 * (comma separated), and every way a line can legitimately fail without
 * taking the whole import down with it.
 *
 * Fixture codes/product names here are deliberately fake and distinct
 * from anything in docs/seed.sql's real HS code import — the disposable
 * test DB is rebuilt from schema.sql + seed.sql once per PHPUnit run
 * (see DbTestCase), so the real 14 seeded codes and 10 product-guide rows
 * already exist by the time these tests run and must never collide with
 * a test's own fixtures.
 */
final class HsCodeBulkImportTest extends DbTestCase
{
    public function testImportsTabSeparatedLinesMatchingAnExcelPaste(): void
    {
        $userId = $this->createTestUser('Admin');
        $raw = "911001\tPHPUnit Test Code One\n911002\tPHPUnit Test Code Two";

        $result = HsCodeRepository::bulkImport($raw, $userId);

        self::assertSame(['911001', '911002'], $result['inserted']);
        self::assertSame([], $result['skipped']);
        self::assertNotNull(HsCodeRepository::findByCode('911001'));
        self::assertSame('PHPUnit Test Code Two', HsCodeRepository::findByCode('911002')['description']);
    }

    public function testAcceptsCommaSeparatedLinesForHandTyping(): void
    {
        $userId = $this->createTestUser('Admin');
        $result = HsCodeRepository::bulkImport('911003,PHPUnit test, comma, separated', $userId);

        self::assertSame(['911003'], $result['inserted']);
        self::assertSame([], $result['skipped']);
        self::assertSame('PHPUnit test, comma, separated', HsCodeRepository::findByCode('911003')['description']);
    }

    public function testSkipsAnInvalidCodeFormatButKeepsImportingOtherLines(): void
    {
        $userId = $this->createTestUser('Admin');
        $raw = "9110.04\tBad dotted format\n911005\tGood code";

        $result = HsCodeRepository::bulkImport($raw, $userId);

        self::assertSame(['911005'], $result['inserted']);
        self::assertCount(1, $result['skipped']);
        self::assertStringContainsString('9110.04', $result['skipped'][0]);
        self::assertStringContainsString('6 or 8 digits', $result['skipped'][0]);
    }

    public function testSkipsACodeThatAlreadyExists(): void
    {
        $userId = $this->createTestUser('Admin');
        HsCodeRepository::create('911006', 'Already here', $userId);

        $result = HsCodeRepository::bulkImport("911006\tDuplicate attempt", $userId);

        self::assertSame([], $result['inserted']);
        self::assertCount(1, $result['skipped']);
        self::assertStringContainsString('already exists', $result['skipped'][0]);
    }

    public function testSkipsALineWithNoSeparatorAtAll(): void
    {
        $userId = $this->createTestUser('Admin');
        $result = HsCodeRepository::bulkImport('JustOneWordWithNoDescription', $userId);

        self::assertSame([], $result['inserted']);
        self::assertCount(1, $result['skipped']);
        self::assertStringContainsString('Tab or a comma', $result['skipped'][0]);
    }

    public function testBlankLinesAreIgnoredNotCountedAsSkipped(): void
    {
        $userId = $this->createTestUser('Admin');
        $raw = "911007\tPHPUnit blank-line test A\n\n\n911008\tPHPUnit blank-line test B";

        $result = HsCodeRepository::bulkImport($raw, $userId);

        self::assertCount(2, $result['inserted']);
        self::assertSame([], $result['skipped']);
    }

    public function testUpdateDescriptionAlsoStoresAUsageNote(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = HsCodeRepository::create('911009', 'PHPUnit usage-note test', $userId);

        HsCodeRepository::updateDescription($id, 'PHPUnit usage-note test', 'Use this one when the SKU is X.');

        $updated = HsCodeRepository::findByCode('911009');
        self::assertSame('Use this one when the SKU is X.', $updated['usage_note']);
    }

    public function testProductGuideImportParsesTabSeparatedProductCodeAndNote(): void
    {
        $userId = $this->createTestUser('Admin');
        $raw = "PHPUnit Test Product Vase\t911010\tCarved 3D article\nPHPUnit Test Product Slab\t911011";

        $result = HsCodeProductGuideRepository::bulkImport($raw, $userId);

        self::assertSame(2, $result['inserted']);
        self::assertSame([], $result['skipped']);

        $vase = $this->findGuideRowByProduct('PHPUnit Test Product Vase');
        self::assertSame('911010', $vase['code_reference']);
        self::assertSame('Carved 3D article', $vase['note']);

        $slab = $this->findGuideRowByProduct('PHPUnit Test Product Slab');
        self::assertNull($slab['note']);
    }

    public function testProductGuideImportAcceptsPipeSeparatedForHandTyping(): void
    {
        $userId = $this->createTestUser('Admin');
        $result = HsCodeProductGuideRepository::bulkImport('PHPUnit Test Product Monument|911012 / 911013|Check per SKU', $userId);

        self::assertSame(1, $result['inserted']);
        $row = $this->findGuideRowByProduct('PHPUnit Test Product Monument');
        self::assertSame('911012 / 911013', $row['code_reference']);
        self::assertSame('Check per SKU', $row['note']);
    }

    public function testProductGuideDeleteRemovesTheEntry(): void
    {
        $userId = $this->createTestUser('Admin');
        HsCodeProductGuideRepository::bulkImport("PHPUnit Test Product Delete Me\t911014", $userId);
        $row = $this->findGuideRowByProduct('PHPUnit Test Product Delete Me');
        self::assertNotNull($row);

        HsCodeProductGuideRepository::delete((int) $row['id']);

        self::assertNull($this->findGuideRowByProduct('PHPUnit Test Product Delete Me'));
    }

    private function findGuideRowByProduct(string $productDescription): ?array
    {
        foreach (HsCodeProductGuideRepository::all() as $row) {
            if ($row['product_description'] === $productDescription) {
                return $row;
            }
        }
        return null;
    }
}
