<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\AdminOverrideController;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "sitewide collapsible sections beyond Settings": the
 * independently-collapsible, per-browser-remembered <details> pattern
 * 90-item backlog #27 built for Company Settings was inline in
 * views/settings/index.php's own <script>, keyed to class="settings-
 * section" — nothing else in the app could reuse it without copy-pasting
 * the same ~10 lines of JS. The persistence logic moved to
 * views/layout/base.php (every page already loads this layout), keyed to
 * the generic class="collapsible-section" instead, and the Admin Field
 * Overrides screen (Document Reference Formats / Terms & Conditions,
 * Legal Terms & Definitions / Payment Presets — three large, independent
 * <form> sections on one long scroll, same shape as Settings) is the
 * first page beyond Settings to actually use it.
 *
 * Deliberately NOT applied to the order page (views/orders/show.php):
 * investigating it first turned up an existing, more capable mechanism
 * already solving the same "one long page" problem — each top-level
 * .section is a tab-like "panel" switched via an auto-built sidebar
 * (order-sidebar/order-panels, see the <script> at the bottom of that
 * view), found by `el.classList.contains('section')`. Wrapping those in
 * <details class="collapsible-section"> (dropping the "section" class in
 * the process) silently broke that panel switcher — nothing throws, the
 * sidebar just populates empty and every panel stays permanently hidden.
 * Reverted; a page that already has an equivalent-or-better mechanism
 * doesn't need a second, conflicting one layered on top.
 */
final class SitewideCollapsibleSectionsTest extends DbTestCase
{
    private function renderAdminOverridesPage(): string
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new AdminOverrideController())->index([]);
        return (string) ob_get_clean();
    }

    public function testEachAdminOverridesSectionIsWrappedInACollapsibleDetailsSection(): void
    {
        $html = $this->renderAdminOverridesPage();

        foreach ([
            'admin-overrides-document-types' => 'Document Reference Formats',
            'admin-overrides-tc-clauses' => 'Terms &amp; Conditions, Legal Terms &amp; Definitions',
            'admin-overrides-payment-presets' => 'Payment Presets',
        ] as $category => $heading) {
            self::assertMatchesRegularExpression(
                '/<details class="collapsible-section" data-category="' . preg_quote($category, '/') . '"\s+open>\s*<summary><h2>' . preg_quote($heading, '/') . '<\/h2><\/summary>/s',
                $html,
                "the {$heading} section must be wrapped in a sitewide collapsible <details>, with its heading intact inside a <summary>"
            );
        }
    }

    public function testFieldsAndFormsStillRenderInsideTheDetailsSection(): void
    {
        $html = $this->renderAdminOverridesPage();

        // the regression: form fields must still render inside the <details>
        // wrapper, not be lost or moved outside their own <form>.
        self::assertMatchesRegularExpression(
            '/<details class="collapsible-section" data-category="admin-overrides-document-types"[^>]*>.*?<form[^>]*action="\/admin\/overrides\/document-types"[^>]*>.*?name="ref_format\[/s',
            $html
        );
    }

    public function testTheSharedCollapsibleSectionScriptIsLoadedFromTheSitewideLayout(): void
    {
        $html = $this->renderAdminOverridesPage();

        // the mechanism now lives in views/layout/base.php (every page
        // loads this layout), not duplicated per-page — proven here by
        // its presence on a page (Admin Overrides) that never had any
        // collapsible-section JS of its own before this change.
        self::assertStringContainsString("querySelectorAll('.collapsible-section[data-category]')", $html);
        self::assertStringContainsString("'settings-section-open:' + details.dataset.category", $html);
    }
}
