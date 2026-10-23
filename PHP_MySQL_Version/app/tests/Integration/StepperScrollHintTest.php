<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * External QA report U-12: "On phones the 1-9 progress line shows only
 * stages 1-4 with no hint that it scrolls; section tabs wrap and the
 * long tab names are cut." Two independent fixes: a mobile-only text
 * hint under the stepper, and a short data-tab-label alias on the
 * handful of section headings long enough to cause the wrapping/cutting
 * (the on-page <h2> itself is left exactly as it was — only the label the
 * JS uses to build a mobile tab button changes).
 */
final class StepperScrollHintTest extends \PHPUnit\Framework\TestCase
{
    private function view(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../views/orders/show.php');
    }

    public function testTheStepperHasAMobileScrollHint(): void
    {
        $html = $this->view();

        self::assertStringContainsString('stepper-scroll-hint', $html);
        self::assertStringContainsString('Swipe to see all stages', $html);
    }

    public function testScrollHintIsHiddenAtDesktopWidthAndShownOnlyBelowTheSameMobileBreakpointAsTheRestOfThePage(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/css/app.css');

        self::assertMatchesRegularExpression('/\.stepper-scroll-hint\{display:none;/', $css);
        self::assertMatchesRegularExpression('/@media \(max-width:900px\)\{\s*\.stepper-scroll-hint\{display:block;\}/', $css);
    }

    public function testTheLongestSectionHeadingsHaveAShortTabLabelAlias(): void
    {
        $html = $this->view();

        $expected = [
            'Stage 4 — Order Confirmation: Buyer Acknowledgement' => 'Stage 4 — OC Ack',
            'Stage 5 — Supplier Purchase Order (Material Procurement)' => 'Stage 5 — Supplier PO',
            'Order Financials — Government Benefits, Costs &amp; Profitability' => 'Financials',
        ];
        foreach ($expected as $fullHeading => $shortLabel) {
            self::assertStringContainsString(
                "data-tab-label=\"{$shortLabel}\">{$fullHeading}</h2>",
                $html,
                "the on-page heading for \"{$fullHeading}\" must be left unchanged, with only a short data-tab-label alias added"
            );
        }
    }

    public function testTheTabBuilderJsPrefersTheShortAliasOverTheFullHeadingText(): void
    {
        $html = $this->view();

        self::assertStringContainsString("heading.dataset.tabLabel || heading.textContent.trim()", $html);
    }
}
