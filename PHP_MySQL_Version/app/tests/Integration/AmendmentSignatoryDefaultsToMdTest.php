<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\SignatoryRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * User's Q2 clarification: "Payment Terms Amendment document require that
 * the signatory specifically be the Managing Director - can we have
 * configuration on this - by default it will be MD, but we need to have an
 * option to choose other designation and signature and seal for that
 * designation."
 *
 * The generic three-layer resolution in
 * DocumentDataAssembler::signatoryBlock() (per-document-type default ->
 * global default -> legacy fallback) already covers this; this test locks
 * in (a) that the seeded default for the Amendment (AMD) document type
 * really does resolve to the Managing Director, and (b) that an admin can
 * repoint it at a user with any other designation, whose own
 * name/designation/seal then print instead.
 */
final class AmendmentSignatoryDefaultsToMdTest extends DbTestCase
{
    private function amdDocumentTypeId(): int
    {
        $pdo = Database::connection();
        return (int) $pdo->query("SELECT id FROM document_types WHERE code = 'AMD'")->fetchColumn();
    }

    public function testAmendmentDocumentTypeDefaultsToTheManagingDirectorSignatory(): void
    {
        $block = DocumentDataAssembler::signatoryBlock($this->amdDocumentTypeId());

        self::assertSame('Gulmohar Sontakke', $block['name']);
        self::assertSame('Founder & Managing Director', $block['designation']);
        self::assertTrue($block['used_designation_seal'], 'AMD is seeded to show the resolved signatory\'s own designation seal, not just the company seal.');
    }

    public function testAdminCanOverrideTheAmendmentSignatoryToAUserWithADifferentDesignation(): void
    {
        $amdTypeId = $this->amdDocumentTypeId();
        $pdo = Database::connection();

        // Baseline: capture the real seeded default so this test can put it
        // back exactly as it found it (the schema/seed rebuild happens once
        // per PHPUnit process, not per test, so state here is visible to
        // every other test that runs afterward).
        $original = $pdo->prepare('SELECT user_id, use_designation_seal FROM document_type_signatories WHERE document_type_id = :dt');
        $original->execute(['dt' => $amdTypeId]);
        $originalRow = $original->fetch();

        $superAdminId = (int) $pdo->query("SELECT id FROM users WHERE email = 'gulmohar.sontakke@nexacrestinternational.com'")->fetchColumn();

        $financeHeadDesignationId = SignatoryRepository::createDesignation('Finance Head', $superAdminId);
        $financeHeadUserId = $this->createTestUser('Export Executive');
        $pdo->prepare('UPDATE users SET name = :name WHERE id = :id')
            ->execute(['name' => 'Priya Deshmukh', 'id' => $financeHeadUserId]);
        SignatoryRepository::setEligibility($financeHeadUserId, true, $financeHeadDesignationId, $superAdminId);

        try {
            SignatoryRepository::setDocumentTypeSignatory($amdTypeId, $financeHeadUserId, false, $superAdminId);

            $block = DocumentDataAssembler::signatoryBlock($amdTypeId);

            self::assertSame('Priya Deshmukh', $block['name']);
            self::assertSame('Finance Head', $block['designation']);
            self::assertNotSame('Founder & Managing Director', $block['designation'], 'overriding the per-document-type signatory must actually change the printed designation, not silently keep showing the MD.');
        } finally {
            // Restore the real seeded default exactly.
            if ($originalRow) {
                SignatoryRepository::setDocumentTypeSignatory(
                    $amdTypeId,
                    (int) $originalRow['user_id'],
                    (bool) $originalRow['use_designation_seal'],
                    $superAdminId
                );
            }
        }
    }
}
