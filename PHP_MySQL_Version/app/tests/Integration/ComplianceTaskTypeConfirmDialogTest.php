<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ComplianceTaskTypeController;
use App\Repositories\ComplianceTaskTypeRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-38: "the 'Deactivate' and 'Permanently delete'
 * buttons skip their confirmation — the inline JavaScript is broken by
 * a \" in the template (views/compliance_task_types/index.php lines 20
 * and 24), so a single click deactivates/deletes immediately (browser
 * shows 'Invalid or unexpected token')." A literal `\"` typed directly
 * in an onsubmit="..." attribute has no HTML-escaping meaning (backslash
 * isn't special to the HTML attribute parser) — the real `"` character
 * right after it closes the attribute the instant a task type's name
 * needed those decorative quote marks, truncating the handler. Fixed by
 * moving the message into a data-confirm attribute (plain HTML-attribute
 * escaping only, read by a shared JS listener — see app.js) instead of
 * hand-building a JS string literal server-side.
 */
final class ComplianceTaskTypeConfirmDialogTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    private function htmlAttr(string $html, string $attr): ?string
    {
        if (!preg_match('/' . preg_quote($attr, '/') . '="([^"]*)"/', $html, $m)) {
            return null;
        }
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    public function testDataConfirmSurvivesATaskNameContainingADoubleQuote(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $id = ComplianceTaskTypeRepository::create('ECGC "Cover" Check', $userId);

        ob_start();
        (new ComplianceTaskTypeController())->index([]);
        $output = ob_get_clean();

        self::assertStringContainsString('data-confirm=', $output, 'the old broken onsubmit="return confirm(...)" pattern must be replaced');
        self::assertStringNotContainsString('onsubmit="return confirm', $output);

        $toggleMessage = $this->htmlAttr(
            substr($output, (int) strpos($output, "/compliance-task-types/{$id}/toggle\"")),
            'data-confirm'
        );
        self::assertSame('Deactivate "ECGC "Cover" Check"?', $toggleMessage);

        $deleteMessage = $this->htmlAttr(
            substr($output, (int) strpos($output, "/compliance-task-types/{$id}/delete\"")),
            'data-confirm'
        );
        self::assertStringStartsWith('Permanently delete "ECGC "Cover" Check"?', $deleteMessage);

        // The form's own closing structure (CSRF field + button) must
        // still be there, i.e. the attribute was never truncated early —
        // this is the actual symptom the pre-fix markup had.
        self::assertStringContainsString('name="_csrf"', $output);
        self::assertStringContainsString('btn-danger">Deactivate</button>', $output);
    }
}
