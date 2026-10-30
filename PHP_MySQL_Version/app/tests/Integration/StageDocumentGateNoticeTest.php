<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\CompanySettingsRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2026-10-30: each stage's gating button says, before the
 * click, what the stage's documents still need (the rule
 * stageDocumentBlockedBy() enforces), and stays disabled until then. The
 * Node suite has the same test (stageDocumentGateNotice.test.js).
 */
final class StageDocumentGateNoticeTest extends DbTestCase
{
    private ?string $mailBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailBefore = CompanySettingsRepository::get('mail_sending_enabled');
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', (string) ($this->mailBefore ?? '0'), null);
        $_SESSION = [];
        parent::tearDown();
    }

    public function testTheGateSaysWhatEachDocumentStillNeeds(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
        self::assertSame([['name' => 'Proforma Invoice', 'todo' => 'generate', 'buyer_facing' => true]], DocumentGenerationService::stageDocumentGate($orderId, 3));

        $this->createTestDocument($orderId, 'PI', 'in_review');
        self::assertSame('approve_send', DocumentGenerationService::stageDocumentGate($orderId, 3)[0]['todo']);
        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        self::assertSame('approve', DocumentGenerationService::stageDocumentGate($orderId, 3)[0]['todo'], 'with email off, approval is enough');

        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
        $this->createTestDocument($orderId, 'PI', 'approved');
        self::assertSame('send', DocumentGenerationService::stageDocumentGate($orderId, 3)[0]['todo']);
        $this->createTestDocument($orderId, 'PI', 'sent');
        self::assertSame([], DocumentGenerationService::stageDocumentGate($orderId, 3));
        self::assertSame([], DocumentGenerationService::stageDocumentBlockedBy($orderId, 3), 'the notice and the server check agree');

        $this->createTestDocument($orderId, 'SUPPO', 'in_review');
        self::assertSame(['name' => 'Supplier PO — Material Procurement', 'todo' => 'approve', 'buyer_facing' => false],
            DocumentGenerationService::stageDocumentGate($orderId, 5)[0], 'an internal document only needs approval');
    }

    public function testTheOrderPageShowsTheNoticeAndDisablesTheButtonUntilTheDocumentIsDone(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Stage 2 open (the quotation stage passed), so Step 4d's form shows.
        \App\Config\Database::connection()->prepare(
            "UPDATE order_stages os JOIN stages_master sm ON sm.id = os.stage_id SET os.status = 'unlocked', os.unlocked_at = NOW() WHERE os.order_id = :o AND sm.stage_number = 2"
        )->execute(['o' => $orderId]);
        // ...and the buyer's signed PO copy is attached (Step 4c).
        \App\Config\Database::connection()->prepare('INSERT INTO order_buyer_po_documents (order_id, file_id) VALUES (:o, :f)')
            ->execute(['o' => $orderId, 'f' => $this->createTestFile($orderId)]);
        $render = static function (int $orderId): string {
            ob_start();
            (new OrderController())->show(['id' => (string) $orderId]);
            return (string) ob_get_clean();
        };
        $html = $render($orderId);
        self::assertStringContainsString('Before you can confirm the buyer&#039;s PO received:', $html);
        self::assertMatchesRegularExpression('#<button type="submit" disabled title="See the note above[^"]*" class="btn-sm">Confirm Buyer PO Received</button>#', $html);

        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        $this->createTestDocument($orderId, 'BUYERPO', 'approved');
        $html = $render($orderId);
        self::assertStringNotContainsString('Before you can confirm the buyer&#039;s PO received:', $html);
        self::assertStringContainsString('<button type="submit" class="btn-sm">Confirm Buyer PO Received</button>', $html);
    }
}
