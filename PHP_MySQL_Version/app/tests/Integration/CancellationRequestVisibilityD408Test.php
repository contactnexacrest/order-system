<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderCancellationRepository;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Owner decision D-408 (22 Nov 2026): after "Request cancellation" the person sees plainly that the
 * request waits for approval — the order page opens on a "waiting for approval" box (with the
 * requester's real name, not "unknown"), the order number carries a badge, and the Standard list
 * and the Kanban board both show the badge and a "Cancellation requested" filter. The order stays
 * Active until approved (D-082). Twin: tests/integration/cancellationRequestVisibilityD408.test.js.
 */
final class CancellationRequestVisibilityD408Test extends DbTestCase
{
    private function useKanban(int $userId, bool $kanban): void
    {
        $pdo = Database::connection();
        $id = $kanban ? (int) $pdo->query("SELECT id FROM ui_templates WHERE template_key = 'kanban'")->fetchColumn() : null;
        $pdo->prepare('UPDATE users SET ui_template_preference_id = :t WHERE id = :id')->execute(['t' => $id, 'id' => $userId]);
    }

    private function render(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testTheRequestSaysItWasSentForApprovalAndLandsOnTheBox(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');
        $_POST = ['reason' => 'Buyer withdrew the order by e-mail.'];
        Flash::pull();

        $this->render(fn () => (new OrderController())->requestCancellation(['id' => (string) $orderId]));

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type'] ?? null);
        self::assertSame('Your cancellation request has been sent for approval. The order stays active until it is approved.', $flash[0]['message'] ?? null);
        self::assertSame('active', OrderRepository::find($orderId)['status'], 'the order stays active until approved (D-082)');
        $source = (string) file_get_contents(__DIR__ . '/../../src/Controllers/OrderController.php');
        self::assertStringContainsString('header("Location: /orders/{$orderId}#cancellation-request");', $source, 'the page opens on the request box');
    }

    public function testThePendingLookupCarriesTheRequestersName(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderCancellationRepository::create($orderId, 'Buyer withdrew the order by e-mail.', $this->createTestUser('Export Executive'));

        self::assertSame('PHPUnit Test User', OrderCancellationRepository::findPendingForOrder($orderId)['requested_by_name']);
    }

    public function testTheOrderPageShowsTheWaitingBoxAndTheBadge(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $cancellationId = OrderCancellationRepository::create($orderId, 'Buyer withdrew the order by e-mail.', $this->createTestUser('Export Executive'));
        Database::connection()->prepare("UPDATE order_cancellations SET requested_at = '2026-11-22 17:40:02' WHERE id = :id")->execute(['id' => $cancellationId]);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        $html = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));

        self::assertStringContainsString('⏳ Cancellation requested — waiting for approval', $html);
        self::assertStringContainsString('Requested by <strong>PHPUnit Test User</strong> on 22 Nov 2026, 17:40 · Reason: Buyer withdrew the order by e-mail.', $html);
        self::assertStringContainsString('The order stays <strong>Active</strong> until someone approves', $html);
        self::assertStringNotContainsString('requested by unknown', $html);
        self::assertStringContainsString('href="#cancellation-request" data-testid="cancellation-pending-badge">Cancellation requested</a>', $html);
        self::assertStringContainsString('id="cancellation-request"', $html);
    }

    public function testNoBadgeWithoutAPendingRequest(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        $html = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));

        self::assertStringNotContainsString('cancellation-pending-badge', $html);
        self::assertStringNotContainsString('waiting for approval', $html);
    }

    /** @return array<string, array{bool}> */
    public static function templates(): array
    {
        return ['Standard list' => [false], 'Kanban board' => [true]];
    }

    #[DataProvider('templates')]
    public function testTheListAndTheBoardShowTheBadgeAndTheFilter(bool $kanban): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $other = $this->createTestOrder($this->createTestClient());
        OrderCancellationRepository::create($orderId, 'Buyer withdrew the order by e-mail.', $this->createTestUser('Export Executive'));
        $userId = $this->createTestUser('Admin');
        $this->useKanban($userId, $kanban);
        $_SESSION['_auth_user_id'] = $userId;
        $ref = OrderRepository::find($orderId)['order_reference'];
        $otherRef = OrderRepository::find($other)['order_reference'];

        $_GET = ['status' => 'cancellation_requested'];
        $html = $this->render(fn () => (new OrderController())->index([]));
        self::assertMatchesRegularExpression('/status=cancellation_requested[^"]*">Cancellation requested \(\d+\)</', $html);
        self::assertStringContainsString(htmlspecialchars($ref), $html);
        self::assertStringNotContainsString(htmlspecialchars($otherRef), $html, 'the filter keeps only orders with a request waiting');
        self::assertStringContainsString('data-testid="cancellation-pending-badge">⏳ Cancellation requested — waiting for approval</span>', $html);

        $_GET = ['status' => 'active'];
        $html = $this->render(fn () => (new OrderController())->index([]));
        self::assertStringContainsString(htmlspecialchars($ref), $html, 'the order is still active and still in the Active filter');
        self::assertStringContainsString('cancellation-pending-badge', $html);
    }

    #[DataProvider('templates')]
    public function testOnceApprovedTheOrderLeavesTheFilterAndLosesTheBadge(bool $kanban): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $cancellationId = OrderCancellationRepository::create($orderId, 'Buyer withdrew the order by e-mail.', $this->createTestUser('Export Executive'));
        OrderRepository::cancel($orderId);
        OrderCancellationRepository::approve($cancellationId, $this->createTestUser('Admin'), 'ok', null, 'not_applicable');
        $userId = $this->createTestUser('Admin');
        $this->useKanban($userId, $kanban);
        $_SESSION['_auth_user_id'] = $userId;
        $ref = OrderRepository::find($orderId)['order_reference'];

        $_GET = ['status' => 'cancellation_requested'];
        self::assertStringNotContainsString(htmlspecialchars($ref), $this->render(fn () => (new OrderController())->index([])));
        $_GET = ['status' => 'cancelled'];
        $html = $this->render(fn () => (new OrderController())->index([]));
        self::assertStringContainsString(htmlspecialchars($ref), $html);
        self::assertStringNotContainsString('cancellation-pending-badge', $html);
    }
}
