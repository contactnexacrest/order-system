<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DocumentController;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog Phase 6 Item D — regenerating an earlier-stage document
 * after a later-stage document already exists should warn staff that the
 * later document was built from data that existed before whatever just
 * changed. This behavior (DocumentGenerationService::downstreamDocumentsAtRisk()
 * + its wiring into DocumentController::generate()'s flash message) was
 * already implemented (2026-09-25) but had no dedicated regression test
 * and no SOP documentation — both added here, no production code change
 * was needed.
 */
final class DocumentRegenerationDownstreamWarningTest extends DbTestCase
{
    private function makeSuperAdmin(): int
    {
        $userId = $this->createTestUser('Admin');
        Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $userId]);
        return $userId;
    }

    public function testNoDownstreamRiskWhenOnlyTheRegeneratedTypeExists(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        DocumentGenerationService::generate($orderId, 'QT', $userId);
        DocumentGenerationService::generate($orderId, 'QT', $userId); // regenerate

        self::assertSame([], DocumentGenerationService::downstreamDocumentsAtRisk($orderId, 'QT'));
    }

    public function testDownstreamRiskListsALaterStageTypeThatAlreadyExists(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        DocumentGenerationService::generate($orderId, 'QT', $userId);
        DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true); // override: out of sequence in a fresh test order

        self::assertSame(['CI'], DocumentGenerationService::downstreamDocumentsAtRisk($orderId, 'QT'));
    }

    public function testDownstreamRiskExcludesTypesAtTheSameStage(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        // PL and BLI both sit at stage 7 — neither is "downstream" of the other.
        DocumentGenerationService::generate($orderId, 'PL', $userId, null, false, true);
        DocumentGenerationService::generate($orderId, 'BLI', $userId, null, false, true);

        self::assertSame([], DocumentGenerationService::downstreamDocumentsAtRisk($orderId, 'PL'));
        self::assertSame([], DocumentGenerationService::downstreamDocumentsAtRisk($orderId, 'BLI'));
    }

    public function testDownstreamRiskExcludesEarlierStageTypes(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        DocumentGenerationService::generate($orderId, 'QT', $userId);
        DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true);

        // QT (stage 1) is earlier than CI (stage 8) — never "at risk" from CI's perspective.
        self::assertSame([], DocumentGenerationService::downstreamDocumentsAtRisk($orderId, 'CI'));
    }

    public function testDownstreamRiskReturnsEmptyForAnUnrecognisedDocumentType(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertSame([], DocumentGenerationService::downstreamDocumentsAtRisk($orderId, 'NOT-A-REAL-TYPE'));
    }

    public function testControllerFlashesNoWarningOnFirstGeneration(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->makeSuperAdmin();
        $_SESSION = [];
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['document_type' => 'QT'];

        ob_start();
        (new DocumentController())->generate(['id' => $orderId]);
        ob_end_clean();

        $flash = $_SESSION['_flash'] ?? [];
        $warnings = array_filter($flash, fn (array $m) => $m['type'] === 'warning');
        self::assertSame([], array_values($warnings), 'a first-ever generation is normal forward progress, never a cascade warning');
    }

    public function testControllerFlashesWarningWhenRegeneratingWithDownstreamDocsPresent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->makeSuperAdmin();

        DocumentGenerationService::generate($orderId, 'QT', $userId);
        DocumentGenerationService::generate($orderId, 'CI', $userId, null, false, true);

        $_SESSION = [];
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['document_type' => 'QT', 'override_gate' => '1', 'override_reason' => 'Regenerating QT to fix a typo in Section 1.'];

        ob_start();
        (new DocumentController())->generate(['id' => $orderId]);
        ob_end_clean();

        $flash = $_SESSION['_flash'] ?? [];
        $warningMessages = array_values(array_map(fn (array $m) => $m['message'], array_filter($flash, fn (array $m) => $m['type'] === 'warning')));
        self::assertNotEmpty($warningMessages, 'regenerating QT after CI already exists must flash a downstream-drift warning');
        self::assertStringContainsString('CI', $warningMessages[0]);
        self::assertStringContainsString('already has', $warningMessages[0]);
    }
}
