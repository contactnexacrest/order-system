<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Point 2/4 — New Order form section redesign (merged Commercial & Shipping
 * Terms fieldset, 2-fields-per-row via .field-grid, weight-label tags,
 * freight-range explanation) and the order detail page's Concept C
 * sidebar + single-panel layout (progressive enhancement: every existing
 * section stays in the DOM and renders normally if JS never runs).
 */
final class OrderScreenRedesignTest extends TestCase
{
    private function createPhp(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../views/orders/create.php');
    }

    private function showPhp(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../views/orders/show.php');
    }

    public function testNewOrderFormMergesCommercialAndShippingIntoOneTwoColumnFieldset(): void
    {
        $html = $this->createPhp();
        $this->assertStringContainsString('Commercial &amp; Shipping Terms', $html);
        $this->assertStringNotContainsString('<legend>Commercial Terms</legend>', $html);
        $this->assertStringNotContainsString('<legend>Shipping</legend>', $html);

        // Incoterm and Port of Loading paired on the first row, per the
        // user's own literal example of what "less bulky" means here.
        $incotermPos = strpos($html, 'name="incoterm_id"');
        $portOfLoadingPos = strpos($html, 'name="port_of_loading_id"');
        $this->assertNotFalse($incotermPos);
        $this->assertNotFalse($portOfLoadingPos);
        $this->assertGreaterThan($incotermPos, $portOfLoadingPos);
    }

    public function testNewOrderFormUsesFieldGridForTwoPerRowLayout(): void
    {
        $html = $this->createPhp();
        // field-grid is the pre-existing 2-column CSS utility — reused, not reinvented.
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'class="field-grid"'));
    }

    public function testWeightFieldsAreLabelledWithProductVsPackingHint(): void
    {
        $html = $this->createPhp();
        $this->assertStringContainsString('tag-hint">product + packing<', $html);
        $this->assertStringContainsString('tag-hint">product only<', $html);
    }

    public function testFreightSectionExplainsWhyItsARange(): void
    {
        $html = $this->createPhp();
        $this->assertStringContainsString('Why a range, not one figure', $html);
        $this->assertStringContainsString('order currency', $html);
    }

    public function testOrderShowPageWrapsAllSectionsInConceptCSidebarLayout(): void
    {
        $html = $this->showPhp();
        $this->assertStringContainsString('id="order-layout"', $html);
        $this->assertStringContainsString('id="order-sidebar"', $html);
        $this->assertStringContainsString('id="order-panels"', $html);

        $layoutStart = strpos($html, 'id="order-layout"');
        $panelsStart = strpos($html, 'id="order-panels"');
        $this->assertNotFalse($layoutStart);
        $this->assertNotFalse($panelsStart);
        $this->assertGreaterThan($layoutStart, $panelsStart);

        // Every existing top-level section must now live inside the panels
        // wrapper — nothing was dropped, only re-parented.
        $sectionCountTotal = substr_count($html, '<div class="section"');
        $afterPanels = substr($html, $panelsStart);
        $sectionCountInsidePanels = substr_count($afterPanels, '<div class="section"');
        $this->assertSame($sectionCountTotal, $sectionCountInsidePanels);
        $this->assertGreaterThanOrEqual(18, $sectionCountTotal);
    }

    public function testOrderShowPageDegradesGracefullyWithoutJavascript(): void
    {
        $html = $this->showPhp();
        // The CSS rule that hides inactive panels is scoped to
        // ".order-layout.js-enabled" only — never a bare ".section", so if
        // the script never runs, every section still renders in sequence.
        $this->assertStringContainsString('.js-enabled .order-panels > .section{display:none;}', $this->readCss());
        $this->assertStringContainsString("layout.classList.add('js-enabled')", $html);
    }

    public function testOrderShowPagePreservesTheOrderUpdatesAnchorId(): void
    {
        // #order-updates is linked from elsewhere in the app (e.g. the
        // "Compose an email" flow) — the redesign must not rename it.
        $html = $this->showPhp();
        $this->assertStringContainsString('id="order-updates"', $html);
    }

    private function readCss(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public_html/assets/css/app.css');
    }
}
