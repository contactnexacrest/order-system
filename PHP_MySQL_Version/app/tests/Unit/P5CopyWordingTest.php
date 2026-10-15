<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog P5 content/copy polish — items #20 (payment-screenshot
 * upload label wording) and #79 (spell out ETD/ETA in full rather than
 * the bare abbreviation). Pure copy guards: no behavior to exercise,
 * just pinning the wording so a future edit doesn't silently revert it.
 */
final class P5CopyWordingTest extends TestCase
{
    private function read(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../' . $relativePath);
    }

    public function testPaymentScreenshotLabelClarifiesProofOfPaymentOnBuyerActionForm(): void
    {
        $html = $this->read('views/buyer_action/show.php');
        self::assertStringContainsString('Payment Screenshot / Proof of Payment (optional)', $html);
    }

    public function testPaymentScreenshotLabelClarifiesProofOfPaymentOnClientPortalForm(): void
    {
        $html = $this->read('views/client_portal/order_show.php');
        self::assertStringContainsString('Payment Screenshot / Proof of Payment (optional)', $html);
    }

    public function testEtdEtaAreSpelledOutOnShippingDetailsForm(): void
    {
        $html = $this->read('views/orders/show.php');
        self::assertStringContainsString('ETD <small class="muted">(Estimated Time of Departure)</small>', $html);
        self::assertStringContainsString('ETA <small class="muted">(Estimated Time of Arrival)</small>', $html);
    }

    public function testEtdEtaAreSpelledOutOnBliPdfTemplate(): void
    {
        $html = $this->read('templates/BLI/bl_instruction.html.twig');
        self::assertStringContainsString('ETD (Estimated Time of Departure) *', $html);
        self::assertStringContainsString('ETA (Estimated Time of Arrival) *', $html);
        self::assertStringNotContainsString('Est. Departure', $html);
        self::assertStringNotContainsString('Est. Arrival', $html);
    }

    public function testEtdEtaAreSpelledOutOnDocxBuilder(): void
    {
        $php = $this->read('src/Services/Docx/DocxDocumentBuilder.php');
        self::assertStringContainsString('ETD (Estimated Time of Departure) *', $php);
        self::assertStringContainsString('ETA (Estimated Time of Arrival) *', $php);
    }
}
