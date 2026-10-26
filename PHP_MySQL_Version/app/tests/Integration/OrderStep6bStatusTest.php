<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner report (2026-10-27): Step 6b reuses the Buyer Action Link made in
 * 4b, so it went green — and painted 6a green with it — the moment the OC
 * draft was generated, before anyone approved it. 6b now counts only once
 * the OC is issued.
 */
final class OrderStep6bStatusTest extends DbTestCase
{
    private function icon(string $html, string $cardId): string
    {
        self::assertMatchesRegularExpression('/id="' . $cardId . '"/', $html);
        preg_match('/id="' . $cardId . '"[^>]*>\s*<summary><span class="step-card-icon (\w+)"/', $html, $m);
        return $m[1] ?? '';
    }

    public function testStep6bWaitsForTheOcToBeIssued(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 3; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        Database::connection()->prepare("UPDATE orders SET buyer_action_token_hash = 'x' WHERE id = :id")->execute(['id' => $orderId]);
        $docId = $this->createTestDocument($orderId, 'OC', 'in_review');
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        $render = function () use ($orderId): string {
            ob_start();
            (new OrderController())->show(['id' => (string) $orderId]);
            return (string) ob_get_clean();
        };

        $html = $render();
        self::assertNotSame('done', $this->icon($html, 'step-6b-buyer-action-link'), 'OC not approved yet');

        Database::connection()->prepare("UPDATE documents SET status = 'approved' WHERE id = :id")->execute(['id' => $docId]);
        self::assertSame('done', $this->icon($render(), 'step-6b-buyer-action-link'));
    }
}
