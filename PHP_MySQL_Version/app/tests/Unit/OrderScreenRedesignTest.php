<?php

declare(strict_types=1);

namespace App\Tests\Unit;

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

    /**
     * 90-item backlog #67 superseded this field's original behavior: the
     * low/high range this test used to pin became a single indicative
     * figure, and the literal "order currency" placeholder text became a
     * live label reflecting the currency actually selected above — see
     * FreightInsuranceSingleValueTest for the full coverage of that change.
     */
    public function testFreightSectionExplainsTheSingleIndicativeFigure(): void
    {
        $html = $this->createPhp();
        $this->assertStringContainsString('current indicative rate only', $html);
        $this->assertStringContainsString('id="freight-currency-unit"', $html);
    }

    /**
     * External QA report: the approved "NexaCrest: Standard vs Kanban UI"
     * design (KanbanDetail.dc.html/Main.dc.html) replaced the Concept C
     * sidebar + single-panel layout with a vertical sequence of
     * <details>/<summary> step-cards plus a persistent reference sidebar
     * (documents + at-a-glance) — reached identically whether the order
     * was opened from the Standard list or the Kanban board.
     */
    public function testOrderShowPageWrapsAllSectionsInStepCardSequencePlusSidebar(): void
    {
        $html = $this->showPhp();
        $this->assertStringContainsString('id="order-layout"', $html);
        $this->assertStringContainsString('id="order-panels"', $html);
        $this->assertStringContainsString('id="order-detail-sidebar"', $html);
        $this->assertStringContainsString('class="step-sequence"', $html);

        $layoutStart = strpos($html, 'id="order-layout"');
        $panelsStart = strpos($html, 'id="order-panels"');
        $sidebarStart = strpos($html, 'id="order-detail-sidebar"');
        $this->assertNotFalse($layoutStart);
        $this->assertNotFalse($panelsStart);
        $this->assertNotFalse($sidebarStart);
        $this->assertGreaterThan($layoutStart, $panelsStart);
        $this->assertGreaterThan($panelsStart, $sidebarStart);

        // Every existing top-level section must now live inside the
        // step-sequence wrapper as its own step-card — nothing was
        // dropped, only re-parented and re-labelled with the user's own
        // 0-17 step numbering.
        $sectionCountTotal = substr_count($html, '<details class="step-card');
        $afterPanels = substr($html, $panelsStart);
        $sectionCountInsidePanels = substr_count($afterPanels, '<details class="step-card');
        $this->assertSame($sectionCountTotal, $sectionCountInsidePanels);
        $this->assertGreaterThanOrEqual(18, $sectionCountTotal);
    }

    public function testOrderShowPageDegradesGracefullyWithoutJavascript(): void
    {
        $html = $this->showPhp();
        // Step-cards are native <details>/<summary> — collapse/expand,
        // keyboard toggling, and reaching every card's content all come
        // from the browser itself, with zero script required (unlike the
        // old design, whose CSS hid every inactive panel behind a
        // JS-built ".js-enabled" gate). The panel-switching script this
        // test used to pin the absence of a CSS/JS coupling for is gone
        // entirely — there is nothing left to gate.
        $this->assertStringNotContainsString('order-sidebar', $html);
        $this->assertStringNotContainsString('active-panel', $html);
        $this->assertStringNotContainsString("js-enabled", $html);
        $this->assertGreaterThanOrEqual(18, substr_count($html, '<details class="step-card'));
    }

    public function testOrderShowPagePreservesTheOrderUpdatesAnchorId(): void
    {
        // #order-updates is linked from elsewhere in the app (e.g. the
        // "Compose an email" flow) — the redesign must not rename it.
        $html = $this->showPhp();
        $this->assertStringContainsString('id="order-updates"', $html);
    }

    /**
     * QA Round 4, N-01: the Node version of this template was missing a
     * closing </div> after "Production & Estimated Shipment", silently
     * nesting Stage 6 onward (and everything after it) one level deep —
     * the sidebar builder only reads direct children of #order-panels, so
     * Node showed 11 tabs where PHP showed 24. This test pins the
     * structural invariant directly (not just "the count matches", which
     * the existing test above already checks but which a missing-</div>
     * bug can still satisfy by accident) — walks the div-nesting depth and
     * fails if any `.section` div's immediate parent isn't #order-panels
     * itself.
     */
    public function testEveryTopLevelSectionIsADirectChildOfOrderPanels(): void
    {
        $html = $this->showPhp();
        $this->assertSectionsAreDirectChildrenOfOrderPanels($html);
    }

    private function assertSectionsAreDirectChildrenOfOrderPanels(string $html): void
    {
        $panelsOpenPos = strpos($html, '<div class="step-sequence" id="order-panels">');
        $this->assertNotFalse($panelsOpenPos, 'Could not find the #order-panels opening tag.');

        // Walk every <div.../<details ...> / </div>/</details> from the
        // panels wrapper's own opening tag onward, tracking nesting depth.
        // #order-panels itself is depth 1; anything opened while depth
        // === 1 is a direct child (depth becomes 2).
        //
        // Since the parent-group redesign, a direct child of #order-panels
        // is now either (a) a standalone step-card, same as before, or
        // (b) a <div class="step-group"> wrapper — purely a visual
        // container for a multi-letter step's own cards (4a/4b/4c, etc.),
        // each of which sits one level deeper (depth 3) as that group's
        // own direct child. Both patterns are asserted explicitly, so
        // N-01's original bug class (a panel's own markup never closing,
        // silently nesting every later panel inside it) still fails this
        // test — on either axis, a card swallowed by an unclosed sibling
        // either vanishes from its expected depth or appears one level
        // deeper than it should.
        preg_match_all('/<div\b[^>]*>|<\/div>|<details\b[^>]*>|<\/details>/', substr($html, $panelsOpenPos), $matches, PREG_OFFSET_CAPTURE);
        $tokens = $matches[0];

        $depth = 0;
        $directStepCardCount = 0;
        $groupCount = 0;
        $groupedStepCardCount = 0;
        $inGroupAtDepth = null;
        foreach ($tokens as [$token, ]) {
            if ($token === '</div>' || $token === '</details>') {
                $depth--;
                if ($depth === 0) {
                    // #order-panels itself has closed.
                    break;
                }
                if ($inGroupAtDepth !== null && $depth < $inGroupAtDepth) {
                    $inGroupAtDepth = null;
                }
                continue;
            }
            $depth++;
            if ($depth === 2) {
                if (str_starts_with($token, '<details class="step-card')) {
                    $directStepCardCount++;
                } elseif (str_starts_with($token, '<div class="step-group"')) {
                    $groupCount++;
                    $inGroupAtDepth = $depth;
                }
            } elseif ($depth === 3 && $inGroupAtDepth === 2 && str_starts_with($token, '<details class="step-card')) {
                $groupedStepCardCount++;
            }
        }

        $this->assertSame(
            19,
            $directStepCardCount,
            'Expected exactly 19 standalone step-card panels as direct children of #order-panels — a lower count means one panel\'s own markup never closed, silently nesting every panel after it inside the unclosed one (N-01).'
        );
        $this->assertSame(8, $groupCount, 'Expected exactly 8 step-group wrapper boxes (Step 4, 5, 6, 7, 9-freight, 9-packing/BL, CI&Balance, 11) as direct children of #order-panels.');
        $this->assertSame(37, $groupedStepCardCount, 'Expected exactly 37 step-cards nested one level inside a step-group box — together with the 19 standalone ones, every one of the 56 individual step/sub-step cards.');
    }

    private function readCss(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public_html/assets/css/app.css');
    }
}
