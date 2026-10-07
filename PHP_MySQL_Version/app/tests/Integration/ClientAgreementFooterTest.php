<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Repositories\ClientRepository;
use App\Repositories\OrderRepository;
use App\Services\DocumentDataAssembler;
use App\Services\Docx\DocxComponents;
use App\Tests\Support\DbTestCase;
use PhpOffice\PhpWord\PhpWord;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFilesystemLoader;

/**
 * Batch 3 #12 — a client-level agreement T&C footer (clients.agreement_footer_text),
 * shown as an extra note on every buyer-facing document in addition to the
 * standard numbered T&C list, including CI, which has zero seeded clauses.
 *
 * Deliberately NOT part of the client data-lock: it's a staff-authored
 * annotation of an externally-negotiated term, not a client-submitted
 * identity detail, so it has its own endpoint (updateAgreementFooter) that
 * bypasses is_data_locked entirely — unlike update(), which the main
 * Buyer/Consignee/Contact fields go through and which IS gated by the lock.
 */
final class ClientAgreementFooterTest extends DbTestCase
{
    private ClientController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new ClientController();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_POST = [];
        $_SESSION = [];
    }

    private function auditLogCount(string $field, int $clientId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action_type = 'CLIENT_UPDATED' AND entity_type = 'clients'
             AND entity_id = :entity_id AND field_name = :field"
        );
        $stmt->execute(['entity_id' => $clientId, 'field' => $field]);
        return (int) $stmt->fetchColumn();
    }

    public function testUpdateAgreementFooterPersistsText(): void
    {
        $clientId = $this->createTestClient();
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['agreement_footer_text'] = "Pre-shipment inspection permitted.\nSecond clause.";

        ob_start();
        $this->controller->updateAgreementFooter(['id' => $clientId]);
        ob_end_clean();

        $client = ClientRepository::find($clientId);
        self::assertSame("Pre-shipment inspection permitted.\nSecond clause.", $client['agreement_footer_text']);
        self::assertSame(1, $this->auditLogCount('agreement_footer_text', $clientId));
    }

    public function testUpdateAgreementFooterCanClearText(): void
    {
        $clientId = $this->createTestClient();
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        $_POST['agreement_footer_text'] = 'Some clause.';
        ob_start();
        $this->controller->updateAgreementFooter(['id' => $clientId]);
        ob_end_clean();
        self::assertSame('Some clause.', ClientRepository::find($clientId)['agreement_footer_text']);

        $_POST['agreement_footer_text'] = '   ';
        ob_start();
        $this->controller->updateAgreementFooter(['id' => $clientId]);
        ob_end_clean();
        self::assertNull(ClientRepository::find($clientId)['agreement_footer_text']);
    }

    public function testNoAuditLogWrittenWhenTextIsUnchanged(): void
    {
        $clientId = $this->createTestClient();
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        $_POST['agreement_footer_text'] = 'Same clause.';
        ob_start();
        $this->controller->updateAgreementFooter(['id' => $clientId]);
        $this->controller->updateAgreementFooter(['id' => $clientId]);
        ob_end_clean();

        self::assertSame(1, $this->auditLogCount('agreement_footer_text', $clientId));
    }

    /**
     * The core design guarantee: once a client is permanently data-locked,
     * the main Buyer/Consignee/Contact fields can no longer be saved without
     * a Super Admin override (see testLockedClientCannotBeSavedThroughMainUpdate
     * below) — but the agreement footer, going through its own endpoint,
     * must save with no override of any kind, for an ordinary non-Super-Admin
     * staff member.
     */
    public function testAgreementFooterStaysEditableAfterClientIsLocked(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::lockData($clientId, 'PI-details confirmed by client');
        self::assertSame(1, (int) ClientRepository::find($clientId)['is_data_locked']);

        $staffId = $this->createTestUser('Admin'); // ordinary staff, not Super Admin
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['agreement_footer_text'] = 'Added after lock.';

        ob_start();
        $this->controller->updateAgreementFooter(['id' => $clientId]);
        ob_end_clean();

        self::assertSame('Added after lock.', ClientRepository::find($clientId)['agreement_footer_text']);
    }

    /** Confirms the contrast this feature relies on: the main form IS blocked once locked. */
    public function testLockedClientCannotBeSavedThroughMainUpdate(): void
    {
        $clientId = $this->createTestClient();
        $client = ClientRepository::find($clientId);
        ClientRepository::lockData($clientId, 'PI-details confirmed by client');

        $staffId = $this->createTestUser('Admin'); // ordinary staff, not Super Admin
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['company_legal_name'] = 'Attempted Rename Ltd';
        $_POST['billing_address'] = $client['billing_address'];

        ob_start();
        $this->controller->update(['id' => $clientId]);
        ob_end_clean();

        self::assertSame($client['company_legal_name'], ClientRepository::find($clientId)['company_legal_name']);
    }

    public function testUpdateAgreementFooter404sForMissingClient(): void
    {
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['agreement_footer_text'] = 'x';

        ob_start();
        $this->controller->updateAgreementFooter(['id' => 999999999]);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    public function testDocumentDataAssemblerIncludesAgreementFooterText(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::updateAgreementFooterText($clientId, 'Inspection clause.');
        $orderId = $this->createTestOrder($clientId);

        $order = OrderRepository::find($orderId);
        self::assertSame('Inspection clause.', $order['agreement_footer_text'] ?? null);

        $context = DocumentDataAssembler::assemble($orderId, 'QT');
        self::assertSame('Inspection clause.', $context['buyer']['agreement_footer_text'] ?? null);
    }

    public function testDocumentDataAssemblerFooterIsNullWhenNotSet(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $context = DocumentDataAssembler::assemble($orderId, 'QT');
        self::assertArrayHasKey('agreement_footer_text', $context['buyer']);
        self::assertNull($context['buyer']['agreement_footer_text']);
    }

    public function testDocxClientAgreementFooterAddsBoxWhenTextSet(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $before = count($section->getElements());

        DocxComponents::addClientAgreementFooter($section, [
            'buyer' => ['agreement_footer_text' => "Clause one.\nClause two."],
        ]);

        self::assertGreaterThan($before, count($section->getElements()), 'addClientAgreementFooter should add elements when text is set');
    }

    public function testDocxClientAgreementFooterAddsNothingWhenTextBlank(): void
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $before = count($section->getElements());

        DocxComponents::addClientAgreementFooter($section, ['buyer' => ['agreement_footer_text' => '   ']]);
        DocxComponents::addClientAgreementFooter($section, ['buyer' => []]);
        DocxComponents::addClientAgreementFooter($section, []);

        self::assertSame($before, count($section->getElements()), 'addClientAgreementFooter must no-op when there is no footer text');
    }

    private function twigEnvironment(): TwigEnvironment
    {
        return new TwigEnvironment(new TwigFilesystemLoader(__DIR__ . '/../../templates'), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
    }

    /** @return array<string,mixed> */
    private function minimalDocContext(): array
    {
        return [
            'company' => [], 'assets' => [], 'watermark' => ['enabled' => false], 'meta' => [],
            'order' => ['quotation_ref' => 'QT-1'], 'financial' => ['advance_pct' => 30],
            'terms' => [], 'terms_section_number' => 9, 'terms_section_title' => 'TERMS & CONDITIONS',
            'doc_title' => 'commercial invoice', 'products' => [],
        ];
    }

    /**
     * The core motivating case: CI (Commercial Invoice) has ZERO seeded T&C
     * clauses (see docs/seed.sql — no tc_clause_documents row for 'CI'), so
     * the numbered terms_section block never renders for it. The client
     * agreement footer must still show, because it sits deliberately
     * outside that block's "terms is not empty" guard in _layout.html.twig.
     */
    public function testCiTemplateShowsFooterEvenWithZeroSeededClauses(): void
    {
        $context = $this->minimalDocContext() + ['buyer' => ['agreement_footer_text' => 'Inspection permitted by buyer agent.']];
        $html = $this->twigEnvironment()->render('CI/commercial_invoice.html.twig', $context);

        self::assertStringContainsString('Special Terms (per Client Agreement)', $html);
        self::assertStringContainsString('Inspection permitted by buyer agent.', $html);
        self::assertStringNotContainsString('9. TERMS', $html, 'this context seeds zero terms, so the numbered section must not render');
    }

    public function testCiTemplateOmitsFooterWhenNotSet(): void
    {
        $context = $this->minimalDocContext() + ['buyer' => ['agreement_footer_text' => null]];
        $html = $this->twigEnvironment()->render('CI/commercial_invoice.html.twig', $context);

        self::assertStringNotContainsString('Special Terms (per Client Agreement)', $html);
    }

    public function testQtTemplateShowsFooterAlongsideNumberedTerms(): void
    {
        $context = $this->minimalDocContext() + [
            'terms' => ['30: Clause thirty text', '60: Clause sixty text'],
            'buyer' => ['agreement_footer_text' => 'Special QT clause.'],
        ];
        $html = $this->twigEnvironment()->render('QT/quotation.html.twig', $context);

        self::assertStringContainsString('Special Terms (per Client Agreement)', $html);
        self::assertStringContainsString('Special QT clause.', $html);
    }

    /** BUYERPO overrides terms_section entirely, with its own separate insertion point. */
    public function testBuyerPoTemplateShowsFooter(): void
    {
        $context = $this->minimalDocContext() + [
            'terms' => ['30: Clause thirty text'],
            'buyer' => ['agreement_footer_text' => 'Special BUYERPO clause.'],
        ];
        $html = $this->twigEnvironment()->render('BUYERPO/buyer_po.html.twig', $context);

        self::assertStringContainsString('Special Terms (per Client Agreement)', $html);
        self::assertStringContainsString('Special BUYERPO clause.', $html);
    }
}
