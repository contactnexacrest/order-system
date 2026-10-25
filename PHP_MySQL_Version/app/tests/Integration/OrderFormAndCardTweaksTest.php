<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Tests\Support\DbTestCase;

/**
 * Owner requests (2026-10-25), redone after the session that first built
 * them lost its uncommitted work:
 * - "Assignee name is cutting" on the Orders cards — the assignee tag may
 *   now wrap inside the card header instead of being clipped.
 * - The order page's client name links to that client's details.
 * - Indicative Freight / Insurance is hidden (and disabled) while the
 *   Incoterm is FOB:
 *   each Incoterm option carries its code (data-code) for the page script,
 *   placed after `selected` so `value="N" selected` still renders intact.
 */
final class OrderFormAndCardTweaksTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_auth_user_id']);
        parent::tearDown();
    }

    public function testOrderPageLinksTheClientNameToTheClientsDetails(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<span class="k">Client</span><span class="v"><a href="/clients/' . $clientId . '">', $html);
    }

    public function testEditFormMarksTheFreightFieldsetAndIncotermCodes(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');

        ob_start();
        (new OrderController())->editDetails(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('id="freight-insurance-fieldset"', $html);
        self::assertStringContainsString('id="incoterm-select"', $html);
        self::assertMatchesRegularExpression('/<option value="\d+" selected data-code="FOB">FOB<\/option>/', $html);
        self::assertStringContainsString('function updateFreightFieldsAvailability(clearOnDisable)', $html);
        self::assertStringContainsString('fieldset.hidden = isFob;', $html, 'owner request: the section is not shown at all for FOB');
    }

    public function testCreateFormMarksTheFreightFieldsetAndIncotermCodes(): void
    {
        ob_start();
        (new OrderController())->create([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('id="freight-insurance-fieldset"', $html);
        self::assertMatchesRegularExpression('/<option value="\d+"\s+data-code="CIF">CIF<\/option>/', $html);
    }

    public function testAssigneeTagMayWrapInsideTheCardHeader(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/css/app.css');

        self::assertStringContainsString('.entity-card-header{display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;}', $css);
        self::assertStringContainsString('.entity-card-header .tag-navy{white-space:normal;max-width:100%;text-align:left;}', $css);
    }
}
