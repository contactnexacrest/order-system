<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\PiIntakeController;
use App\Repositories\PiIntakeRepository;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * User-reported batch item 8: "Acceptance of Quotation No. *" ==> this
 * should also autofill - no more placeholder". Identical bug to 90-item
 * backlog #16 (fixed for Payment Terms Confirmation in
 * PiIntakePaymentTermsPlaceholderTest) — the computed sentence was only
 * ever passed into the HTML placeholder attribute, never into the
 * textarea's real value, so a fresh required field looked empty. Fixed
 * by prefilling the real Quotation reference/date this order's own
 * Quotation document was actually issued with.
 */
final class QuotationAcceptancePlaceholderTest extends DbTestCase
{
    private function renderShow(string $token): string
    {
        ob_start();
        (new PiIntakeController())->show(['token' => $token]);
        return ob_get_clean();
    }

    private function textareaBody(string $html, string $name): ?string
    {
        $start = strpos($html, "name=\"{$name}\"");
        if ($start === false) {
            return null;
        }
        $fragment = substr($html, $start, 600);
        $closeTagPos = strpos($fragment, '</textarea>');
        $innerStart = strpos($fragment, '>') + 1;
        return substr($fragment, $innerStart, $closeTagPos - $innerStart);
    }

    public function testFreshSubmissionPrefillsTheRealQuotationReferenceAndDateNotJustAPlaceholder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $qtDocId = $this->createTestDocument($orderId, 'QT');
        $qtRef = \App\Config\Database::connection()
            ->query("SELECT document_reference FROM documents WHERE id = {$qtDocId}")->fetchColumn();
        $token = PiIntakeRepository::createLink($orderId, null);

        $output = $this->renderShow($token);
        $body = $this->textareaBody($output, 'quotation_acceptance_reference');

        self::assertNotNull($body);
        self::assertNotSame('', trim($body), 'the textarea body must not be empty on a fresh submission');
        self::assertStringContainsString("We accept Quotation {$qtRef} dated", $body);
        self::assertStringContainsString(DocumentDataAssembler::formatDate(date('Y-m-d')), $body);
    }

    public function testClientsOwnPreviousAnswerIsShownVerbatimNotTheComputedDefault(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'QT');
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        \App\Config\Database::connection()
            ->prepare('UPDATE pi_intake_submissions SET quotation_acceptance_reference = :v WHERE id = :id')
            ->execute(['v' => 'We accept Quotation MY-OWN-WORDING', 'id' => $submission['id']]);

        $output = $this->renderShow($token);
        $body = $this->textareaBody($output, 'quotation_acceptance_reference');

        self::assertSame('We accept Quotation MY-OWN-WORDING', trim($body));
    }
}
