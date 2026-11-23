<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientController;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-409 (23 Nov 2026): every client card on the Clients page has a "+ New Order" link
 * (the same link as "+ New Order for this client" on the client page), shown only to people who may
 * create orders. Twin: tests/integration/clientCardNewOrderLinkD409.test.js.
 */
final class ClientCardNewOrderLinkD409Test extends DbTestCase
{
    private function renderIndex(): string
    {
        $_GET = [];
        ob_start();
        (new ClientController())->index([]);
        return (string) ob_get_clean();
    }

    public function testTheCardCarriesANewOrderLinkForThatClient(): void
    {
        $clientId = $this->createTestClient();
        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');

        $html = $this->renderIndex();

        self::assertStringContainsString('<a class="entity-card-action" href="/orders/create?client_id=' . $clientId . '" data-testid="client-card-new-order">+ New Order</a>', $html);
    }

    public function testAReadOnlyUserSeesNoNewOrderLink(): void
    {
        $this->createTestClient();
        $_SESSION['_auth_user_id'] = $this->createTestUser('Viewer / Auditor');

        self::assertStringNotContainsString('client-card-new-order', $this->renderIndex());
    }
}
