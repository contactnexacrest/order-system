<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\TermsClauseRepository;
use App\Tests\Support\DbTestCase;

/**
 * Legal Terms & Definitions — covers the two new behaviors beyond the
 * existing 'standard' clause_group query: filtering by clause_group
 * (legal_terms/definitions, never leaking into the plain T&C list) and
 * the visibility_rule gate on a clause scoped to one balance-trigger
 * option.
 */
final class TermsClauseRepositoryGroupsTest extends DbTestCase
{
    private int $qtDocTypeId;
    /** @var int[] */
    private array $createdClauseIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $stmt = Database::connection()->query("SELECT id FROM document_types WHERE code = 'QT'");
        $this->qtDocTypeId = (int) $stmt->fetchColumn();
    }

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

    private function seedClause(string $title, string $text, string $clauseGroup, string $visibilityRule, int $order): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO tc_clauses (clause_title, clause_text, clause_order, status, clause_group, visibility_rule, is_locked)
             VALUES (:title, :text, :clause_order, \'active\', :clause_group, :visibility_rule, 0)'
        )->execute([
            'title' => $title, 'text' => $text, 'clause_order' => $order,
            'clause_group' => $clauseGroup, 'visibility_rule' => $visibilityRule,
        ]);
        $clauseId = (int) $pdo->lastInsertId();
        $this->createdClauseIds[] = $clauseId;
        $pdo->prepare('INSERT INTO tc_clause_documents (clause_id, document_type_id) VALUES (:clause_id, :document_type_id)')
            ->execute(['clause_id' => $clauseId, 'document_type_id' => $this->qtDocTypeId]);
        return $clauseId;
    }

    public function testForDocumentTypeCodeStandardNeverReturnsLegalTermsOrDefinitionsRows(): void
    {
        $this->seedClause('PHPUnit Legal Term', 'x', 'legal_terms', 'always', 900);
        $this->seedClause('PHPUnit Definition', 'y', 'definitions', 'always', 901);

        $standard = TermsClauseRepository::forDocumentTypeCode('QT');
        $titles = array_column($standard, 'title');
        self::assertNotContains('PHPUnit Legal Term', $titles);
        self::assertNotContains('PHPUnit Definition', $titles);
    }

    public function testForDocumentTypeCodeAndGroupReturnsOnlyTheRequestedGroup(): void
    {
        $this->seedClause('PHPUnit Legal Term 2', 'x', 'legal_terms', 'always', 902);
        $this->seedClause('PHPUnit Definition 2', 'y', 'definitions', 'always', 903);

        $legalTerms = array_column(TermsClauseRepository::forDocumentTypeCodeAndGroup('QT', 'legal_terms', null), 'title');
        self::assertContains('PHPUnit Legal Term 2', $legalTerms);
        self::assertNotContains('PHPUnit Definition 2', $legalTerms);

        $definitions = array_column(TermsClauseRepository::forDocumentTypeCodeAndGroup('QT', 'definitions', null), 'title');
        self::assertContains('PHPUnit Definition 2', $definitions);
        self::assertNotContains('PHPUnit Legal Term 2', $definitions);
    }

    public function testVisibilityRuleBeforeShipmentOnlyShowsForMatchingOption(): void
    {
        $this->seedClause('PHPUnit Before-Shipment Only', 'x', 'legal_terms', 'balance_trigger_before_shipment', 904);

        $matching = array_column(TermsClauseRepository::forDocumentTypeCodeAndGroup('QT', 'legal_terms', 'A_BEFORE_SHIPMENT'), 'title');
        self::assertContains('PHPUnit Before-Shipment Only', $matching);

        $other = array_column(TermsClauseRepository::forDocumentTypeCodeAndGroup('QT', 'legal_terms', 'B_AGAINST_BL'), 'title');
        self::assertNotContains('PHPUnit Before-Shipment Only', $other);

        $null = array_column(TermsClauseRepository::forDocumentTypeCodeAndGroup('QT', 'legal_terms', null), 'title');
        self::assertNotContains('PHPUnit Before-Shipment Only', $null);
    }

    public function testVisibilityRuleAgainstBlOnlyShowsForMatchingOption(): void
    {
        $this->seedClause('PHPUnit Against-BL Only', 'x', 'legal_terms', 'balance_trigger_against_bl', 905);

        $matching = array_column(TermsClauseRepository::forDocumentTypeCodeAndGroup('QT', 'legal_terms', 'B_AGAINST_BL'), 'title');
        self::assertContains('PHPUnit Against-BL Only', $matching);

        $other = array_column(TermsClauseRepository::forDocumentTypeCodeAndGroup('QT', 'legal_terms', 'A_BEFORE_SHIPMENT'), 'title');
        self::assertNotContains('PHPUnit Against-BL Only', $other);
    }

    public function testVisibilityRuleAlwaysShowsRegardlessOfBalanceTriggerOption(): void
    {
        $this->seedClause('PHPUnit Always Visible', 'x', 'legal_terms', 'always', 906);

        foreach ([null, 'A_BEFORE_SHIPMENT', 'B_AGAINST_BL'] as $option) {
            $titles = array_column(TermsClauseRepository::forDocumentTypeCodeAndGroup('QT', 'legal_terms', $option), 'title');
            self::assertContains('PHPUnit Always Visible', $titles);
        }
    }
}
