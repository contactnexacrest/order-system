<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\AnnexureController;
use App\Helpers\Flash;
use App\Repositories\OrderAnnexureTermsRepository;
use App\Repositories\OrderRepository;
use App\Services\AnnexureTermsSanitizer;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section AT — Annexure A content mode (Product
 * Specification / Additional Terms / Both). Covers: mode persistence and
 * validation, the WYSIWYG "Additional Terms" HTML sanitizer (the one
 * barrier between a stored payload and it executing in a generated
 * PDF/DOCX), and DocumentDataAssembler's mode-aware flags that drive both
 * the standalone Annexure A document and the appendix baked into every
 * other buyer-facing document.
 */
final class AnnexureModeTermsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    // ---------------------------------------------------------------
    // AnnexureController::updateMode
    // ---------------------------------------------------------------

    public function testUpdateModeAcceptsEachValidMode(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        foreach (['SPEC', 'TERMS', 'BOTH'] as $mode) {
            $_POST = ['mode' => $mode];
            ob_start();
            (new AnnexureController())->updateMode(['id' => (string) $orderId]);
            ob_end_clean();

            $flash = Flash::pull();
            self::assertSame('success', $flash[0]['type']);
            $order = OrderRepository::find($orderId);
            self::assertSame($mode, $order['annexure_mode']);
        }
    }

    public function testUpdateModeRejectsInvalidValue(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        $_POST = ['mode' => 'EVERYTHING'];
        ob_start();
        (new AnnexureController())->updateMode(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        $order = OrderRepository::find($orderId);
        self::assertSame('SPEC', $order['annexure_mode'], 'default must be unchanged');
    }

    // ---------------------------------------------------------------
    // AnnexureController::updateTerms + AnnexureTermsSanitizer
    // ---------------------------------------------------------------

    public function testUpdateTermsSanitizesScriptAndEventHandlers(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        $_POST = ['content_html' => '<p onclick="alert(1)">Hello <script>alert(1)</script><b>world</b></p>'];
        ob_start();
        (new AnnexureController())->updateTerms(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);

        $saved = OrderAnnexureTermsRepository::find($orderId);
        self::assertNotNull($saved);
        self::assertStringNotContainsString('<script', $saved['content_html']);
        self::assertStringNotContainsString('onclick', $saved['content_html']);
        self::assertStringContainsString('<p>Hello <b>world</b></p>', $saved['content_html']);
    }

    public function testSanitizerStripsRemoteImagesJavascriptAndDataLinks(): void
    {
        $dirty = '<img src="http://evil.example/x.png" onerror="alert(2)">'
            . '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=" alt="ok">'
            . '<a href="javascript:alert(3)">bad link</a>'
            . '<a href="data:text/html,hi">data link</a>'
            . '<a href="https://example.com">good link</a>'
            . '<video src="x.mp4"></video>'
            . '<iframe src="https://evil.example"></iframe>';

        $clean = AnnexureTermsSanitizer::sanitize($dirty);

        self::assertStringNotContainsString('evil.example', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
        self::assertStringNotContainsString('data:text/html', $clean);
        self::assertStringNotContainsString('<video', $clean);
        self::assertStringNotContainsString('<iframe', $clean);
        self::assertStringContainsString('data:image/png;base64,', $clean, 'a legitimate embedded image must survive');
        self::assertStringContainsString('href="https://example.com"', $clean, 'a legitimate external link must survive');
    }

    public function testSanitizerPreservesAllowedFormattingTagsAndTable(): void
    {
        $html = '<p><strong>Bold</strong> and <em>italic</em></p><ul><li>One</li></ul><table><tr><td>A</td></tr></table>';
        $clean = AnnexureTermsSanitizer::sanitize($html);

        self::assertStringContainsString('<strong>Bold</strong>', $clean);
        self::assertStringContainsString('<em>italic</em>', $clean);
        self::assertStringContainsString('<ul>', $clean);
        self::assertStringContainsString('<table', $clean);
    }

    // ---------------------------------------------------------------
    // DocumentDataAssembler mode-aware flags
    // ---------------------------------------------------------------

    public function testAssembleFlagsForSpecModeWithProductsOnly(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setIncludeAnnexureA($orderId, true);
        OrderRepository::setAnnexureMode($orderId, 'SPEC');

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertSame('SPEC', $data['annexure_mode']);
        self::assertTrue($data['annexure_show_spec']);
        self::assertFalse($data['annexure_show_terms']);
        self::assertSame('', $data['annexure_terms_html']);
        self::assertFalse($data['annexure_has_content'], 'no product entries exist yet, so SPEC mode has nothing to show');
    }

    public function testAssembleFlagsForTermsModeWithSavedTerms(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setIncludeAnnexureA($orderId, true);
        OrderRepository::setAnnexureMode($orderId, 'TERMS');
        OrderAnnexureTermsRepository::upsert($orderId, '<p>Inspect within 48 hours.</p>', $userId);

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertSame('TERMS', $data['annexure_mode']);
        self::assertFalse($data['annexure_show_spec']);
        self::assertTrue($data['annexure_show_terms']);
        self::assertStringContainsString('Inspect within 48 hours', $data['annexure_terms_html']);
        self::assertTrue($data['annexure_has_content']);
    }

    public function testAssembleFlagsForBothModeWithOnlyTermsFilled(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setIncludeAnnexureA($orderId, true);
        OrderRepository::setAnnexureMode($orderId, 'BOTH');
        OrderAnnexureTermsRepository::upsert($orderId, '<p>Some terms.</p>', $userId);

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertTrue($data['annexure_show_spec']);
        self::assertTrue($data['annexure_show_terms']);
        self::assertEmpty($data['annexure_products'], 'no product entries were added');
        self::assertTrue($data['annexure_has_content'], 'BOTH mode has content because terms alone are non-empty');
    }

    public function testAssembleTermsHtmlIsReSanitizedEvenIfDbRowWerePoisoned(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setAnnexureMode($orderId, 'TERMS');
        // Bypass the controller/sanitizer entirely to simulate a row written
        // by something else (or before a stricter sanitizer config shipped).
        OrderAnnexureTermsRepository::upsert($orderId, '<p>Hi</p><script>alert(1)</script>', $userId);

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertStringNotContainsString('<script', $data['annexure_terms_html'], 're-sanitization at read time must still strip it');
    }
}
