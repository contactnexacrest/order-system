<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Tests\Support\DbTestCase;

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
}
