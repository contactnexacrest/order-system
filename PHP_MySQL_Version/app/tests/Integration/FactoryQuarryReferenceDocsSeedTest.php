<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Env;
use App\Repositories\ReferenceLibraryRepository;
use App\Tests\Support\DbTestCase;

/**
 * These 7 client-verification/legal-template documents (Quarry SOP,
 * Factory SOP, Factory Processing Agreement template, Quarry Block
 * Supply Agreement template, plus — added alongside Section BB/BC —
 * the International Sales & Supply Agreement template and the two NDA
 * templates) used to exist only as rows hand-inserted into the two live
 * dev databases, pointing at dev-machine-specific absolute paths, with
 * the actual files sitting only in storage/internal/reference_library/ —
 * which is gitignored. A fresh clone or a production deploy from git
 * alone would have neither the rows nor the files. Pins both: seed.sql
 * must create exactly these 7 rows, and the file each one's file_path
 * resolves to (once __STORAGE_BASE_PATH__ is substituted, same as
 * production's post-import step) must actually exist in the repo.
 */
final class FactoryQuarryReferenceDocsSeedTest extends DbTestCase
{
    private const EXPECTED_TITLES = [
        'Quarry SOP – Block Selection & Reservation',
        'Factory SOP – Processing, QC & Packing',
        'Factory Processing Agreement (Template)',
        'Quarry Block Supply Agreement (Template)',
        'International Sales & Supply Agreement (Template)',
        'Mutual NDA — Commercial Counterparty (Template)',
        'Staff Confidentiality & NDA (Template)',
    ];

    public function testSeedCreatesAllSevenDocumentsWithFilesAttached(): void
    {
        $all = ReferenceLibraryRepository::all();
        $byTitle = [];
        foreach ($all as $row) {
            $byTitle[$row['title']] = $row;
        }

        foreach (self::EXPECTED_TITLES as $title) {
            self::assertArrayHasKey($title, $byTitle, "seed.sql must create a reference_library_documents row titled \"$title\"");
            self::assertNotEmpty($byTitle[$title]['file_path'], "\"$title\" must have a file attached, not text-only");
            self::assertStringContainsString('__STORAGE_BASE_PATH__/assets/reference_library/', $byTitle[$title]['file_path']);
        }
    }

    public function testEachSeededFilePathResolvesToARealCommittedFile(): void
    {
        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $all = ReferenceLibraryRepository::all();

        $checked = 0;
        foreach ($all as $row) {
            if (!str_contains((string) $row['file_path'], '__STORAGE_BASE_PATH__/assets/reference_library/')) {
                continue;
            }
            $resolved = str_replace('__STORAGE_BASE_PATH__', $storageBase, $row['file_path']);
            self::assertFileExists($resolved, "\"{$row['title']}\" points at a file that isn't committed to the repo: $resolved");
            $checked++;
        }

        self::assertSame(7, $checked, 'expected exactly 7 seeded reference-library documents backed by a committed file');
    }
}
