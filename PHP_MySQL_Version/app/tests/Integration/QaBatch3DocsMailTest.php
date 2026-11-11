<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DashboardRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\PaymentMilestoneRepository;
use App\Services\BuyerActionService;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Services\EmailDispatchService;
use App\Services\EmailService;
use App\Services\OrderAccessService;
use App\Services\PaymentActionService;
use App\Services\ReviewWorkflowService;
use App\Tests\Support\DbTestCase;

require_once __DIR__ . '/../../cron/check_alerts.php';

/**
 * QA batch 3 (5 Nov 2026): documents and e-mail findings DOC-1..7 and
 * MAIL-1..13 from the fresh QA review. DOC-1..4 change generated documents
 * and were confirmed twice by the owner. Node twin: qaBatch3DocsMail.test.js.
 */
final class QaBatch3DocsMailTest extends DbTestCase
{
    private ?string $mailBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailBefore = CompanySettingsRepository::get('email_testing_mode');
        $this->allowHandTypedGoodsLines();
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('email_testing_mode', $this->mailBefore);
        parent::tearDown();
    }

    private function twoLineOrder(string $preset = 'Standard — New Buyer', string $incoterm = 'CIF'): int
    {
        $orderId = $this->createTestOrder($this->createTestClient('qa3-' . bin2hex(random_bytes(4)) . '@example.test'), $incoterm, $preset, 'USD');
        OrderProductRepository::add($orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
        OrderProductRepository::add($orderId, 2, 'Kashmir White Granite Tiles', '60 x 60 x 2 cm', 'Flamed', '80', false, 'SQM', '32.25');
        return $orderId;
    }

    private function pdfText(int $fileId): string
    {
        return (string) shell_exec('pdftotext -layout ' . escapeshellarg((string) FileStoreRepository::find($fileId)['server_path']) . ' -');
    }

    private function docxText(int $fileId): string
    {
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) FileStoreRepository::find($fileId)['server_path']) . ' word/document.xml');
        return html_entity_decode((string) preg_replace('/<[^>]+>/', '', (string) preg_replace('/<\/w:p>/', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public function testDoc1DatesHaveATwoDigitDay(): void
    {
        self::assertSame('05 November 2026', DocumentDataAssembler::formatDate('2026-11-05'));
    }

    public function testDoc2FinalAndSupersededCopiesShowWhatWasGeneratedNotLiveData(): void
    {
        $user = $this->createTestUser('Admin');
        $orderId = $this->twoLineOrder();
        $r = DocumentGenerationService::generate($orderId, 'PI', $user, null, false, true);
        self::assertNotNull(DocumentRepository::renderContext((int) $r['document_id']), 'the render context is stored');

        // The order changes after generation — the final copy must not pick it up.
        Database::connection()->prepare("UPDATE order_products SET quantity = 777 WHERE order_id = :o AND line_no = 1")->execute(['o' => $orderId]);
        DocumentGenerationService::finalizeApproval((int) $r['document_id']);
        $final = $this->pdfText((int) DocumentRepository::find((int) $r['document_id'])['pdf_file_id']);
        self::assertStringContainsString('120', $final);
        self::assertStringNotContainsString('777', $final);

        DocumentGenerationService::markSuperseded((int) $r['document_id']);
        $superseded = $this->pdfText((int) DocumentRepository::find((int) $r['document_id'])['pdf_file_id']);
        self::assertStringNotContainsString('777', $superseded, 'the superseded copy keeps the figures it really carried');
        self::assertStringContainsString('120', $superseded);
    }

    public function testDoc3WordCopyMatchesThePdf(): void
    {
        $user = $this->createTestUser('Admin');
        $orderId = $this->twoLineOrder();
        DocumentGenerationService::generate($orderId, 'QT', $user, null, true, true);
        $r = DocumentGenerationService::generate($orderId, 'QT', $user, null, true, true); // internal regeneration: Rev.01
        self::assertNotEmpty($r['docx_file_id']);
        $docx = FileStoreRepository::find((int) $r['docx_file_id']);
        self::assertStringEndsWith(' Rev.0 (internal).docx', (string) $docx['original_filename'], 'same revision as the PDF name');
        $text = $this->docxText((int) $r['docx_file_id']);
        self::assertStringNotContainsString('Rev.01', $text, 'the internal count never reaches the Word copy');
        self::assertStringContainsString('40% Advance Payment on FOB Value against Proforma Invoice.', $text);
        self::assertStringContainsString('Balance Payment on FOB Value', $text);
        self::assertStringNotContainsString('Telegraphic Transfer) on FOB Value', $text);
        self::assertStringNotContainsString('invoiced separately by Freight Debit Note', $text);
    }

    public function testDoc4BuyerPoAndQuotationFollowThePaymentTermsAndEveryLine(): void
    {
        $user = $this->createTestUser('Admin');
        $lc = $this->twoLineOrder('Irrevocable Letter of Credit at Sight');
        $po = $this->pdfText((int) DocumentGenerationService::generate($lc, 'BUYERPO', $user, null, false, true)['pdf_file_id']);
        self::assertStringNotContainsString('0% advance', $po);
        self::assertStringContainsString('100% Payment on FOB Value by Letter of Credit', $po);
        self::assertStringNotContainsString('balance T/T before shipment', $po);
        self::assertMatchesRegularExpression('/120\s*;\s*80/', $po, 'every line\'s quantity');

        $ms = $this->twoLineOrder();
        PaymentMilestoneRepository::replaceSchedule($ms, [['pct' => 20.0, 'due_text' => 'on completion of production']], 40.0);
        $terms = DocumentDataAssembler::assemble($ms, 'BUYERPO')['financial']['buyer_po_payment_terms'];
        self::assertStringStartsWith('40% advance T/T (Telegraphic Transfer) against Proforma Invoice before production. 20% Payment on FOB Value on completion of production. 40% balance T/T — ', $terms);
        $qt = $this->pdfText((int) DocumentGenerationService::generate($ms, 'QT', $user, null, false, true)['pdf_file_id']);
        self::assertStringContainsString('20% Payment on FOB Value on completion of production.', $qt, 'the QT lists the milestone');
    }

    public function testDoc5DossierFollowsTheOrderAccessRule(): void
    {
        $orderId = $this->twoLineOrder();
        $outsider = $this->createTestUser('Export Executive');
        self::assertNotNull(OrderAccessService::denialReasonForPath("/orders/{$orderId}/dossier", $outsider, $this->roleIdOf($outsider)),
            'the central order guard refuses the dossier to someone the order is not assigned to');
    }

    private function roleIdOf(int $userId): int
    {
        return (int) Database::connection()->query("SELECT role_id FROM users WHERE id = {$userId}")->fetchColumn();
    }

    public function testDoc6ReleaseRuleAndTheValidPiStaysVisibleDuringACorrection(): void
    {
        CompanySettingsRepository::set('email_testing_mode', '0');
        $orderId = $this->twoLineOrder();
        $file = $this->createTestFile($orderId);
        $sent = $this->createTestDocument($orderId, 'PI', 'sent', $file);
        Database::connection()->exec("UPDATE documents SET revision_number = 0 WHERE id = {$sent}");
        $draft = $this->createTestDocument($orderId, 'PI', 'draft', $file);
        Database::connection()->exec("UPDATE documents SET revision_number = 1 WHERE id = {$draft}");

        self::assertSame($sent, (int) PaymentActionService::issuedDocument($orderId, 'advance')['id'], 'a newer draft does not hide the sent PI');
        self::assertSame($sent, (int) BuyerActionService::issuedBuyerDocument($orderId, 'PI')['id']);

        // DOC-6 part A (approval vs send as the release to the portal) is the owner's decision — unchanged here.
    }

    public function testDoc7ASentDocumentCannotBePulledBackIntoReview(): void
    {
        $orderId = $this->twoLineOrder();
        $doc = $this->createTestDocument($orderId, 'OC', 'sent', $this->createTestFile($orderId));
        $reviewer = $this->createTestUser('Admin');
        try {
            ReviewWorkflowService::assignReviewers($doc, [$reviewer], $this->createTestUser('Admin'));
            self::fail('assigning reviewers to a sent document must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already sent', $e->getMessage());
        }
        // A stray pending review on a sent document: rejecting it does not pull the document back.
        Database::connection()->prepare("INSERT INTO document_reviews (document_id, reviewer_id, status) VALUES (:d, :r, 'pending')")->execute(['d' => $doc, 'r' => $reviewer]);
        $reviewId = (int) Database::connection()->lastInsertId();
        ReviewWorkflowService::reject($reviewId, $reviewer, 'This should not move the sent document back to draft.');
        self::assertSame('sent', DocumentRepository::find($doc)['status']);
        self::assertNotNull(DocumentReviewRepository::find($reviewId));
    }

    public function testMail1TheOverdueFreightAlertSeesTheIssuedDebitNote(): void
    {
        CompanySettingsRepository::set('email_testing_mode', '0');
        $orderId = $this->twoLineOrder();
        $fdn = $this->createTestDocument($orderId, 'FDN', 'sent', $this->createTestFile($orderId));
        Database::connection()->exec("UPDATE documents SET document_date = DATE_SUB(CURDATE(), INTERVAL 12 DAY) WHERE id = {$fdn}");
        $orders = array_map(static fn (array $r): int => (int) $r['order_id'], DashboardRepository::overdueFreightPayments());
        self::assertContains($orderId, $orders);

        Database::connection()->exec("UPDATE orders SET is_locked = 1 WHERE id = {$orderId}");
        $orders = array_map(static fn (array $r): int => (int) $r['order_id'], DashboardRepository::overdueFreightPayments());
        self::assertNotContains($orderId, $orders, 'not for a locked order');
    }

    public function testMail2TheSendingOffSwitchStopsLinkAndNotificationMail(): void
    {
        CompanySettingsRepository::set('email_testing_mode', '1');
        $log = tempnam(sys_get_temp_dir(), 'qa3mail');
        $previous = ini_set('error_log', $log);
        try {
            self::assertFalse(EmailService::sendPlainText('buyer@example.test', 'Link', 'Body', false, 'buyer_links', 'buyer'));
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertStringContainsString('testing mode holds e-mails to buyers and suppliers', (string) file_get_contents($log));
        @unlink($log);
        self::assertSame('PI-stage form link generated — not e-mailed to b@x.test: testing mode holds e-mails to buyers and suppliers. Copy the link and send it yourself.',
            EmailDispatchService::linkMailOutcome('PI-stage form link', 'b@x.test', false, 'L'));
        self::assertSame('PI-stage form link e-mailed to b@x.test. If they did not get it, copy the link and send it yourself.', EmailDispatchService::linkMailOutcome('PI-stage form link', 'b@x.test', true, 'L'));
    }

    public function testMail3LcRemindersGoOutOncePerDate(): void
    {
        $admin = $this->createTestUser('Admin');
        $assignee = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Irrevocable Letter of Credit at Sight', 'USD', null, null, [$assignee]);
        OrderLcDetailRepository::saveDates($orderId, null, date('Y-m-d', strtotime('+20 days')), date('Y-m-d', strtotime('+5 days')), null, $admin);
        \run();
        // As if the first reminder went out yesterday, when the same date was 6 days away: today's
        // run (5 days away) must not send a second one for the same date.
        Database::connection()->exec("UPDATE notifications SET created_at = DATE_SUB(created_at, INTERVAL 1 DAY), message = REPLACE(message, '(5 day(s) away)', '(6 day(s) away)') WHERE related_order_id = {$orderId}");
        \AlertRunCache::reset();
        \run();
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$assignee} AND related_order_id = {$orderId} AND type = 'lc_last_shipment_soon'")->fetchColumn());
    }

    public function testMail4AndMail5ClientEmailFallbackAndTheSendersOwnTitle(): void
    {
        $email = 'qa3-record-' . bin2hex(random_bytes(4)) . '@example.test';
        $clientId = $this->createTestClient($email);
        $orderId = $this->createTestOrder($clientId);
        Database::connection()->exec("UPDATE orders SET client_email = NULL WHERE id = {$orderId}");
        $order = \App\Repositories\OrderRepository::find($orderId);
        self::assertSame($email, EmailDispatchService::clientEmailFor($order));

        $exec = $this->createTestUser('Export Executive');
        $md = (string) CompanySettingsRepository::get('md_title');
        self::assertSame('Export Executive', EmailDispatchService::senderTitle(\App\Repositories\UserRepository::findById($exec)));
        self::assertNotSame($md, EmailDispatchService::senderTitle(\App\Repositories\UserRepository::findById($exec)));
    }

    public function testMail6Mail7Mail8Mail11SeededTemplates(): void
    {
        $tpl = static fn (string $k): ?array => \App\Repositories\EmailTemplateRepository::find($k);
        self::assertStringContainsString('based on quotation {quotation_ref}', (string) $tpl('send_buyerpo')['body']);
        self::assertStringNotContainsString('{oc_ref}', (string) $tpl('send_buyerpo')['body']);
        self::assertNotNull($tpl('send_esi'), 'every customer-facing document type has its send template');
        foreach (Database::connection()->query("SELECT code FROM document_types WHERE category = 'customer_facing' AND is_active = 1")->fetchAll(\PDO::FETCH_COLUMN) as $code) {
            self::assertNotNull($tpl('send_' . strtolower((string) $code)), "send_{$code}");
        }
        self::assertStringNotContainsString('attached', (string) $tpl('shipment_readiness')['body']);
        self::assertStringContainsString('download your purchase order', /* D-330 plain wording */ (string) $tpl('buyer_action_link')['body']);
    }

    public function testMail9ResendOfASentDocumentAndFailureIsReported(): void
    {
        $orderId = $this->twoLineOrder();
        $requester = $this->createTestUser('Admin');
        $doc = $this->createTestDocument($orderId, 'PI', 'sent', null);
        Database::connection()->prepare(
            "INSERT INTO email_log (order_id, document_id, template_key, recipient_email, subject, body_snapshot, status, requested_by)
             VALUES (:o, :d, 'send_pi', 'b@x.test', 'PI again', 'Body', 'approved', :u)"
        )->execute(['o' => $orderId, 'd' => $doc, 'u' => $requester]);
        $row = Database::connection()->query('SELECT * FROM email_log WHERE id = ' . (int) Database::connection()->lastInsertId())->fetch();
        self::assertFalse(EmailDispatchService::dispatch($row), 'no PDF on file: the send fails');
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$requester} AND type = 'email_send_failed'")->fetchColumn(), 'the requester is told');
        Database::connection()->exec("UPDATE email_log SET status = 'sending' WHERE id = {$row['id']}");
        self::assertTrue(\App\Repositories\EmailLogRepository::hasActiveSendFor($doc), 'a row being sent is in flight');
    }

    public function testMail10AMissingAttachmentFailsTheSendClearly(): void
    {
        self::assertFalse(EmailService::deliverWithAttachments('b@x.test', 'S', 'B', [['path' => '/nonexistent/file.pdf', 'name' => 'file.pdf']]));
    }

    public function testMail12TheEmailAsksForThePaymentTheOrderNeeds(): void
    {
        $lc = \App\Repositories\OrderRepository::find($this->twoLineOrder('Irrevocable Letter of Credit at Sight'));
        self::assertSame('Please open the letter of credit (LC) as agreed, so that we can start production.', EmailDispatchService::paymentRequestSentence($lc, ['document_type_code' => 'PI']));
        $cash = \App\Repositories\OrderRepository::find($this->twoLineOrder());
        self::assertSame('Please pay the advance as agreed, so that we can start production.', EmailDispatchService::paymentRequestSentence($cash, ['document_type_code' => 'PI']));
        self::assertStringContainsString('pay the balance as agreed', EmailDispatchService::paymentRequestSentence($cash, ['document_type_code' => 'CI']));
    }
}
