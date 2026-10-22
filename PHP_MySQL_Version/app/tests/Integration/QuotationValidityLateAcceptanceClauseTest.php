<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\TermsClauseRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-09: "QT lacks the agreed clause: advance received
 * after 'Valid Until' is not acceptance / NexaCrest issues a revised QT
 * at same/updated prices. App says only 'subject to revision'." Extends
 * the existing 'Quotation Validity' clause (tc_clauses, linked to QT via
 * tc_clause_documents) with that missing sentence.
 */
final class QuotationValidityLateAcceptanceClauseTest extends DbTestCase
{
    public function testTheQuotationValidityClauseWarnsThatLateAcceptanceIsNotAcceptance(): void
    {
        $clauses = TermsClauseRepository::forDocumentTypeCodeAndGroup('QT', 'standard', null);
        $validityClause = null;
        foreach ($clauses as $clause) {
            if ($clause['title'] === 'Quotation Validity') {
                $validityClause = $clause;
                break;
            }
        }

        self::assertNotNull($validityClause, 'sanity check: the Quotation Validity clause must actually be linked to QT');
        self::assertStringContainsString('does not constitute acceptance', $validityClause['text']);
        self::assertStringContainsString('revised Quotation', $validityClause['text']);
    }
}
