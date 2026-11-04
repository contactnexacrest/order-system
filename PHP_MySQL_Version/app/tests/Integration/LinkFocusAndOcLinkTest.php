<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner requests (3 Nov 2026), part A:
 *  1. after any link is generated from the order page, the page comes back to that card,
 *     opened, with the new link focused (…?open=<card>#<card> + data-focus-link + app.js);
 *  2. the OC confirmation link (Step 6b) is created only by its button — generating /
 *     approving the OC no longer shows a ready link with a green tick, Step 6c waits for it,
 *     and the auto-confirm job only counts from the link;
 *  3. a click anywhere in a date box opens the calendar (app.js).
 * Node twin: tests/integration/linkFocusAndOcLink.test.js.
 */
final class LinkFocusAndOcLinkTest extends DbTestCase
{
    private const APP = __DIR__ . '/../..';

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testLinkReturnUrlOpensAndScrollsToTheCard(): void
    {
        self::assertSame('/orders/42?open=step-6b-buyer-action-link#step-6b-buyer-action-link', OrderController::linkReturnUrl(42, 'step-6b-buyer-action-link'));
    }

    /** Every order-page link action returns through linkReturnUrl() with its own card. */
    public function testEveryLinkActionReturnsToItsCard(): void
    {
        $src = (string) file_get_contents(self::APP . '/src/Controllers/OrderController.php');
        $expect = [
            'generatePiFormLink'         => "self::linkReturnUrl(\$orderId, 'step-5a-pi-form-link')",
            'generateBuyerActionLink'    => "self::linkReturnUrl(\$orderId, \$returnAnchor)",
            'generatePaymentActionLink'  => "self::linkReturnUrl(\$orderId, self::linkReturnAnchor('step-5d-payment-record'))",
            'generateSupplierActionLink' => "self::linkReturnUrl(\$orderId, 'step-7c-supplier-po-attach')",
        ];
        foreach ($expect as $method => $call) {
            self::assertMatchesRegularExpression('/function ' . $method . '\(array \$params\): void\s*\{(?:(?!\n    public function ).)*' . preg_quote($call, '/') . '/s', $src, $method);
        }
        self::assertStringContainsString("self::linkReturnAnchor('step-4b-buyer-po-link')", $src);
    }

    /** The link inputs carry data-focus-link, and the forms send their card as return_to. */
    public function testTheViewMarksEveryLinkInputForFocus(): void
    {
        $view = (string) file_get_contents(self::APP . '/views/orders/show.php');
        foreach (['$buyerActionFullLink', '$paymentFullLink', '$piFormFullLink', '$supplierActionFullLink'] as $var) {
            self::assertMatchesRegularExpression('/value="<\?= htmlspecialchars\(' . preg_quote($var, '/') . '\) \?>" onclick="this\.select\(\)" data-focus-link/', $view, $var);
        }
        foreach (["'step-5d-payment-record'", "'step-9c-freight-remittance'", "'step-balance-record'", "'step-4b-buyer-po-link'", "'step-6b-buyer-action-link', true"] as $anchor) {
            self::assertStringContainsString($anchor, $view);
        }
        foreach (['id="step-5a-pi-form-link"', 'id="step-7c-supplier-po-attach"', 'id="step-6b-buyer-action-link"'] as $id) {
            self::assertStringContainsString($id, $view);
        }
    }

    public function testAppJsFocusesTheLinkAndOpensDatePickers(): void
    {
        $js = (string) file_get_contents(self::APP . '/../public_html/assets/js/app.js');
        self::assertStringContainsString("card.querySelector('[data-focus-link]')", $js);
        self::assertStringContainsString('input.showPicker()', $js);
        self::assertMatchesRegularExpression("/\['date', 'datetime-local', 'month', 'week', 'time'\]/", $js);
        self::assertStringContainsString('initDatePickers();', $js);
        self::assertStringContainsString('initFocusLink();', $js);
    }

    /** Generating from Step 6b records the OC link; from Step 4b it does not. */
    public function testOnlyTheStep6bButtonRecordsTheOcLink(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Managing Director');
        $controller = new OrderController();

        $_POST = ['return_to' => 'step-4b-buyer-po-link'];
        @$controller->generateBuyerActionLink(['id' => (string) $orderId]);
        self::assertNull(OrderRepository::find($orderId)['oc_link_generated_at']);
        self::assertNotEmpty(OrderRepository::find($orderId)['buyer_action_token_hash']);

        $_POST = ['return_to' => 'step-6b-buyer-action-link'];
        @$controller->generateBuyerActionLink(['id' => (string) $orderId]);
        self::assertNotNull(OrderRepository::find($orderId)['oc_link_generated_at']);
    }

    /** The auto-confirm job ignores an order whose OC link was never generated, and counts from the link. */
    public function testAutoConfirmWaitsForTheOcLink(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderOcAcknowledgmentRepository::recordSent($orderId, $this->createTestDocument($orderId, 'OC', 'approved'), date('Y-m-d H:i:s', strtotime('-3 days')), date('Y-m-d H:i:s', strtotime('-1 day')));
        $due = static fn (): array => array_map('intval', array_column(OrderOcAcknowledgmentRepository::dueForAutoConfirm(), 'order_id'));

        self::assertNotContains($orderId, $due(), 'no OC link yet — never auto-confirmed');

        Database::connection()->prepare('UPDATE orders SET oc_link_generated_at = NOW() - INTERVAL 1 HOUR WHERE id = :id')->execute(['id' => $orderId]);
        self::assertNotContains($orderId, $due(), 'link made an hour ago — the buyer still has time');

        Database::connection()->prepare('UPDATE orders SET oc_link_generated_at = NOW() - INTERVAL 3 DAY WHERE id = :id')->execute(['id' => $orderId]);
        self::assertContains($orderId, $due());
    }

    /** Step 6b is done only once the link was generated there; the card's button says so. */
    public function testStep6bIsDoneOnlyAfterTheButton(): void
    {
        $view = (string) file_get_contents(self::APP . '/views/orders/show.php');
        self::assertStringContainsString("'6b' => \$docComplete('OC') && !empty(\$order['oc_link_generated_at']),", $view);
        self::assertStringContainsString("'Generate OC Confirmation Link'", $view);
        self::assertStringContainsString('if ($ocMode && !$ocLinkMade) {', $view);
    }
}
