<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\ClientRepository;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner, 14 Nov 2026 (D-375): "Create order" from a client's page opens the New Order form with only that
 * client in the Client drop-down; opened without a client it offers every client. Node twin:
 * tests/integration/orderCreateFromClient.test.js.
 */
final class OrderCreateFromClientTest extends DbTestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function page(array $get): string
    {
        $_GET = $get;
        ob_start();
        (new OrderController())->create([]);
        return (string) ob_get_clean();
    }

    public function testFromAClientOnlyThatClientIsOffered(): void
    {
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        PermissionService::resetCache();
        $a = (string) ClientRepository::find($this->createTestClient())['client_unique_number'];
        $bId = $this->createTestClient();
        $b = (string) ClientRepository::find($bId)['client_unique_number'];
        $aId = (int) \App\Config\Database::connection()->query("SELECT id FROM clients WHERE client_unique_number = '{$a}'")->fetchColumn();

        $html = $this->page(['client_id' => (string) $aId]);
        self::assertStringContainsString($a, $html);
        self::assertStringNotContainsString($b, $html, 'another client is not offered');
        self::assertStringContainsString("opened from the client's page", $html);

        $html = $this->page([]);
        self::assertStringContainsString($a, $html);
        self::assertStringContainsString($b, $html, 'without a client, every client is offered');
        self::assertStringNotContainsString("opened from the client's page", $html);
    }
}
