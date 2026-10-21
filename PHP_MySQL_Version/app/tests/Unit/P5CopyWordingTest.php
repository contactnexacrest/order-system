<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog P5 content/copy polish — items #20 (payment-screenshot
 * upload label wording), #79 (spell out ETD/ETA in full rather than
 * the bare abbreviation), and #78 (Supplier PO form field clarification:
 * placeholder hints on free-text fields that had none, plus a reciprocal
 * auto-calc hint on Unit Price mirroring the one Basic Value already had).
 * Pure copy guards: no behavior to exercise, just pinning the wording so
 * a future edit doesn't silently revert it.
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

    public function testSupplierPoFreeTextFieldsHavePlaceholderHints(): void
    {
        $html = $this->read('views/orders/show.php');
        self::assertStringContainsString('name="material_stone_type" placeholder="e.g., Black Granite"', $html);
        self::assertStringContainsString('name="dimensions" placeholder="e.g., 600 x 600 x 20 mm"', $html);
        // User-reported batch item 12: Dimensional Tolerance moved from a
        // bare placeholder hint to a real admin-editable dropdown (see
        // SupplierFactoryAddressAndDimensionalToleranceTest) — it's no
        // longer a free-text field, so no placeholder-hint guard applies
        // to it here any more.
        self::assertStringContainsString('name="colour_reference" placeholder="e.g., Jet Black"', $html);
        self::assertStringContainsString('name="special_requirements" placeholder="e.g., Polished finish, edge chamfer"', $html);
        // User-reported batch item 12: Delivery Location moved from a bare
        // placeholder hint to a dropdown of the selected supplier's own
        // factory addresses (see
        // SupplierFactoryAddressAndDimensionalToleranceTest) — it's no
        // longer a free-text field by default, so no placeholder-hint
        // guard applies to it here any more.
        self::assertStringContainsString('name="packing_requirement" placeholder="e.g., Wooden crates, export packing"', $html);
    }

    public function testSupplierPoUnitPriceHasReciprocalAutoCalcHint(): void
    {
        $html = $this->read('views/orders/show.php');
        self::assertStringContainsString('Unit Price (INR) <small class="muted">(auto-calculates Basic Value when multiplied by Quantity)</small>', $html);
    }
}
