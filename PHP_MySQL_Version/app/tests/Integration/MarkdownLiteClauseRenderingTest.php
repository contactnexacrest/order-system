<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AdminOverrideController;
use App\Repositories\FileStoreRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog Phase 5: editor-level bold/emphasis control for the
 * T&C Clause library. Covers the markdown_lite Twig filter actually
 * wired into a real generated PDF (end-to-end through
 * DocumentGenerationService), and that the admin screen shows the raw
 * markdown source (never pre-rendered) so staff edit the markers directly.
 */
final class MarkdownLiteClauseRenderingTest extends DbTestCase
{
    /** @var int[] */
    private array $createdClauseIds = [];

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach ($this->createdClauseIds as $id) {
            $pdo->prepare('DELETE FROM tc_clause_documents WHERE clause_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM tc_clauses WHERE id = :id')->execute(['id' => $id]);
        }
        $this->createdClauseIds = [];
        parent::tearDown();
    }

    private function seedClause(string $title, string $text, string $clauseGroup, int $order): int
    {
        $pdo = Database::connection();
        $qtId = (int) $pdo->query("SELECT id FROM document_types WHERE code = 'QT'")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO tc_clauses (clause_title, clause_text, clause_order, status, clause_group, is_locked)
             VALUES (:title, :text, :clause_order, \'active\', :clause_group, 0)'
        )->execute(['title' => $title, 'text' => $text, 'clause_order' => $order, 'clause_group' => $clauseGroup]);
        $clauseId = (int) $pdo->lastInsertId();
        $this->createdClauseIds[] = $clauseId;
        $pdo->prepare('INSERT INTO tc_clause_documents (clause_id, document_type_id) VALUES (:clause_id, :document_type_id)')
            ->execute(['clause_id' => $clauseId, 'document_type_id' => $qtId]);
        return $clauseId;
    }

    /** @return string|null extracted PDF text, or null if pdftotext isn't available in this environment */
    private function pdfText(int $fileId): ?string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertTrue(is_file($file['server_path']), 'Generated PDF must actually exist on disk.');
        $text = @shell_exec('pdftotext ' . escapeshellarg($file['server_path']) . ' - 2>/dev/null');
        if ($text === null || $text === false || trim($text) === '') {
            return null;
        }
        return $text;
    }

    public function testGeneratedPdfStripsMarkersFromAStandardClause(): void
    {
        $this->seedClause('PHPUnit Markdown Clause', 'Delivery must happen within the **agreed window**, no exceptions.', 'standard', 950);
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'QT', $userId);
        $text = $this->pdfText((int) $result['pdf_file_id']);

        if ($text !== null) {
            self::assertStringContainsString('agreed window', $text);
            self::assertStringNotContainsString('**agreed window**', $text, 'the ** markers must be parsed into formatting, never printed literally on a buyer-facing document');
        }
    }

    public function testGeneratedPdfStripsMarkersFromALegalTermsClause(): void
    {
        $this->seedClause('PHPUnit Markdown Legal Term', 'This clause is *strictly* binding on both parties.', 'legal_terms', 951);
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'QT', $userId);
        $text = $this->pdfText((int) $result['pdf_file_id']);

        if ($text !== null) {
            self::assertStringContainsString('strictly', $text);
            self::assertStringNotContainsString('*strictly*', $text, 'the * markers must be parsed into formatting, never printed literally');
        }
    }

    public function testAdminScreenShowsTheRawMarkersUnparsedForEditing(): void
    {
        $this->seedClause('PHPUnit Markdown Admin Clause', 'Keep this **exact** wording.', 'standard', 952);

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new AdminOverrideController())->index([]);
        $output = ob_get_clean();

        self::assertStringContainsString('Keep this **exact** wording.', $output, 'the admin textarea must show the raw markdown source so staff can edit the markers, not a pre-rendered version');
        self::assertStringContainsString('data-wrap="**"', $output, 'the Bold toolbar button must be present');
    }
}
