<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\DocumentRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report M-08: "Regenerating an earlier-stage document after
 * later stages passed: mandatory confirmation, automatic cancellation of
 * later stages, and 'drift' notices on affected stages. App only warns."
 * This covers the persistent-drift-notice half (DocumentRepository::
 * flagDrift(), wired into DocumentGenerationService::generate()) and the
 * mandatory-confirmation half (OrderController::show()'s regenerationRisk
 * map, consumed by the page's own JS). "Automatic cancellation of later
 * stages" is deliberately not covered — see the fix commit for why that
 * part needs an owner decision.
 */
final class DocumentDriftFlagTest extends DbTestCase
{
    public function testRegeneratingAnEarlierStageDocumentFlagsAnExistingDownstreamDocument(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        DocumentGenerationService::generate($orderId, 'QT', $userId);
        $ci = DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true); // override: out of sequence in a fresh test order
        self::assertNull(DocumentRepository::find((int) $ci['document_id'])['drift_flagged_at']);

        DocumentGenerationService::generate($orderId, 'QT', $userId); // regenerate QT

        $flagged = DocumentRepository::find((int) $ci['document_id']);
        self::assertNotNull($flagged['drift_flagged_at'], 'CI must be flagged as potentially stale once QT (an earlier stage) is regenerated');
        self::assertStringContainsString('QT', $flagged['drift_flagged_reason']);
    }

    public function testFirstEverGenerationNeverFlagsAnything(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        DocumentGenerationService::generate($orderId, 'QT', $userId);
        $ci = DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true);

        self::assertNull(DocumentRepository::find((int) $ci['document_id'])['drift_flagged_at'], 'a first-ever generation is normal forward progress, never a cascade flag');
    }

    public function testTheFlagNaturallyClearsOnceTheFlaggedDocumentIsItselfRegenerated(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        DocumentGenerationService::generate($orderId, 'QT', $userId);
        DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true);
        DocumentGenerationService::generate($orderId, 'QT', $userId); // regenerate QT — flags CI
        self::assertNotNull(DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'CI')['drift_flagged_at']);

        DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true); // regenerate CI itself

        $currentCi = DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'CI');
        self::assertNull($currentCi['drift_flagged_at'], 'the fresh CI revision must not carry the stale flag forward');
    }

    public function testTheOrderPageShowsThePersistentDriftBadge(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $userId]);
        $_SESSION['_auth_user_id'] = $userId;

        DocumentGenerationService::generate($orderId, 'QT', $userId);
        DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true);
        DocumentGenerationService::generate($orderId, 'QT', $userId); // regenerate QT — flags CI

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('DATA MAY BE STALE', $html);
    }

    public function testTheOrderPageComputesARegenerationRiskMapConsumedByItsOwnConfirmJs(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $userId]);
        $_SESSION['_auth_user_id'] = $userId;

        DocumentGenerationService::generate($orderId, 'QT', $userId);
        DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true);

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('confirmDocumentRegeneration', $html, 'the Generate forms must be wired to the mandatory-confirmation JS');
        self::assertMatchesRegularExpression('/var regenerationRisk = \{.*"QT":\["CI"\].*\};/', $html, 'QT must be marked as regeneration-risky once CI already exists');
    }
}
