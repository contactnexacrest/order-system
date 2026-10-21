<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AdminOverrideController;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "per-doc-type Legal Terms toggle": tc_clause_documents
 * already let a Legal Terms clause be scoped to specific document types
 * (DocumentGenerationService::resolveClauseGroup() has always filtered on
 * it), but the admin screen itself never offered a way to see or change
 * which document types a given clause reached — only its title/text were
 * editable, with the view's own copy just describing a fixed, hardcoded
 * list of document types. This adds that toggle, restricted server-side
 * to customer-facing document types (the only kind the Legal Terms box
 * ever renders on) regardless of what a tampered POST tries to submit.
 */
final class LegalTermsDocumentTypeToggleTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    private function createTestLegalTermsClause(): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "INSERT INTO tc_clauses (clause_title, clause_text, clause_group, status)
             VALUES ('PHPUnit Test Clause', 'Test clause text.', 'legal_terms', 'active')"
        );
        $stmt->execute();
        return (int) $pdo->lastInsertId();
    }

    private function documentTypeId(string $code): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM document_types WHERE code = :code');
        $stmt->execute(['code' => $code]);
        return (int) $stmt->fetchColumn();
    }

    private function linkedDocumentTypeIds(int $clauseId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT document_type_id FROM tc_clause_documents WHERE clause_id = :id ORDER BY document_type_id');
        $stmt->execute(['id' => $clauseId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testIndexRendersACheckboxPerCustomerFacingDocumentTypeReflectingCurrentLinks(): void
    {
        $clauseId = $this->createTestLegalTermsClause();
        $qtId = $this->documentTypeId('QT');
        Database::connection()
            ->prepare('INSERT INTO tc_clause_documents (clause_id, document_type_id) VALUES (:clause_id, :dt_id)')
            ->execute(['clause_id' => $clauseId, 'dt_id' => $qtId]);

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new AdminOverrideController())->index([]);
        $output = ob_get_clean();

        self::assertStringContainsString("document_types[{$clauseId}][]", $output);
        self::assertMatchesRegularExpression(
            '/name="document_types\[' . $clauseId . '\]\[\]" value="' . $qtId . '" checked/',
            $output,
            'QT must be pre-checked since the clause is already linked to it'
        );
        // SUPPO (procurement) and STAGEGATE (internal) must never appear as
        // offerable options — Legal Terms is a buyer-facing section only.
        self::assertStringNotContainsString('value="' . $this->documentTypeId('SUPPO') . '"', $output);
    }

    public function testUpdateAddsAndRemovesDocumentTypeLinks(): void
    {
        $clauseId = $this->createTestLegalTermsClause();
        $qtId = $this->documentTypeId('QT');
        $piId = $this->documentTypeId('PI');
        Database::connection()
            ->prepare('INSERT INTO tc_clause_documents (clause_id, document_type_id) VALUES (:clause_id, :dt_id)')
            ->execute(['clause_id' => $clauseId, 'dt_id' => $qtId]);

        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = [
            'reason' => 'PHPUnit test — switching this clause from QT to PI.',
            'document_types' => [$clauseId => [(string) $piId]],
        ];
        (new AdminOverrideController())->updateTcClauses([]);

        self::assertSame([$piId], $this->linkedDocumentTypeIds($clauseId));

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT old_value, new_value FROM audit_log
             WHERE entity_type = 'tc_clause_documents' AND entity_id = :id
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['id' => $clauseId]);
        $log = $stmt->fetch();
        self::assertNotFalse($log, 'the document-type change must be audit-logged');
        self::assertSame('QT', $log['old_value']);
        self::assertSame('PI', $log['new_value']);
    }

    public function testATamperedPostCannotLinkAClauseToANonCustomerFacingDocumentType(): void
    {
        $clauseId = $this->createTestLegalTermsClause();
        $supPoId = $this->documentTypeId('SUPPO'); // category = 'procurement'

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = [
            'reason' => 'PHPUnit test — attempting to link to a non-customer-facing type.',
            'document_types' => [$clauseId => [(string) $supPoId]],
        ];
        (new AdminOverrideController())->updateTcClauses([]);

        self::assertSame([], $this->linkedDocumentTypeIds($clauseId), 'SUPPO must be silently dropped, never linked');
    }

    public function testADocumentTypeChangeOnAProtectedClauseIsBlockedWithoutUnlock(): void
    {
        $clauseId = $this->createTestLegalTermsClause();
        Database::connection()->prepare('UPDATE tc_clauses SET is_protected = 1 WHERE id = :id')->execute(['id' => $clauseId]);
        $qtId = $this->documentTypeId('QT');

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = [
            'reason' => 'PHPUnit test — trying to bypass the unlock gesture.',
            'document_types' => [$clauseId => [(string) $qtId]],
        ];
        (new AdminOverrideController())->updateTcClauses([]);

        self::assertSame([], $this->linkedDocumentTypeIds($clauseId), 'a protected clause must reject the change without the unlock flag');
    }
}
