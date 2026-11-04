<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\OrderShippingRepository;
use App\Services\AuthService;
use App\Services\DocumentGenerationService;
use App\Services\PermissionService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner points of 2 Nov 2026 on the order page:
 *  10 — nothing associate-related when the client has no associate;
 *  13 — Step 7c: the Supplier Link first, then the supplier's acknowledgment;
 *  14 — the COO Prep Sheet can be prepared once Stage 8 opens (before the balance);
 *  16 — "Sync this order to Zoho now" (POST /orders/{id}/zoho-sync) and the Zoho Sync menu link.
 * Node twin: orderPageOwnerPointsNov2.test.js.
 */
final class OrderPageOwnerPointsNov2Test extends DbTestCase
{
    private static ?Router $router = null;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__owner-points-build__';
            ob_start();
            require dirname(__DIR__, 3) . '/public_html/index.php';
            ob_end_clean();
            restore_exception_handler();
            self::$router = $router;
        }
        return self::$router;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::router(); // building the router resets the session, so before anyone signs in
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    private function signIn(string $role, bool $superAdmin = false): int
    {
        $userId = $this->createTestUser($role);
        if ($superAdmin) {
            Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$userId}");
        }
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
        PermissionService::resetCache();
        return $userId;
    }

    private function render(int $orderId): string
    {
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    /** @return array{code:int, body:string, flash:?string} */
    private function call(string $method, string $path): array
    {
        Flash::pull();
        $_POST = $method === 'POST' ? ['_csrf' => Csrf::token()] : [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch($method, $path);
        } finally {
            $body = (string) ob_get_clean();
        }
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => Flash::pull()[0]['message'] ?? null];
    }

    public function testPoint10NoAssociateSelectWhenTheClientHasNoAssociate(): void
    {
        $adminId = $this->signIn('Admin', true);
        // 3 Nov 2026: Step 12d shows the team part only while the program is on.
        \App\Repositories\IncentiveRepository::setSetting('program_enabled', '1', $adminId);
        $plain = $this->createTestOrder($this->createTestClient());
        $html = $this->render($plain);
        self::assertStringContainsString('Step 12d &middot; Team Incentive', $html);
        self::assertStringNotContainsString('Associate commission on this order', $html);
        self::assertStringContainsString('<input type="hidden" name="associate_mode" value="auto">', $html, 'the saved mode is kept on Save');

        $pdo = Database::connection();
        $pdo->exec("INSERT INTO associates (name) VALUES ('TEST-Associate Nov2')");
        $associateId = (int) $pdo->lastInsertId();
        $client = $this->createTestClient();
        $pdo->exec("UPDATE clients SET associate_id = {$associateId} WHERE id = {$client}");
        $html = $this->render($this->createTestOrder($client));
        self::assertStringContainsString('Associate commission on this order', $html);
        \App\Repositories\IncentiveRepository::setSetting('program_enabled', '0', $adminId);
    }

    public function testPoint13SupplierLinkComesBeforeTheAcknowledgment(): void
    {
        $this->signIn('Admin', true);
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 4; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO suppliers (supplier_legal_name) VALUES ('TEST-Supplier Nov2')");
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference) VALUES ({$orderId}, {$supplierId}, 'TEST-Supplier Nov2', 'TEST-SPO-N2')");
        $html = $this->render($orderId);
        $link = strpos($html, '1. Send the Supplier Link first');
        $ack = strpos($html, '2. When the supplier has acknowledged');
        $upload = strpos($html, '/supplier-po/documents"');
        self::assertNotFalse($link);
        self::assertNotFalse($ack);
        self::assertNotFalse($upload);
        self::assertLessThan($ack, $link);
        self::assertLessThan($upload, $ack);
        self::assertStringContainsString('Award = supplier chosen. The Supplier PO is then generated in Step 7b', file_get_contents(dirname(__DIR__, 2) . '/views/orders/show.php'));
    }

    public function testPoint14CooPrepSheetCanBePreparedOnceStage8OpensBeforeTheBalance(): void
    {
        $userId = $this->signIn('Admin', true);
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        OrderShippingRepository::recordBl($orderId, 'MSCU7654321', '2026-06-15');
        for ($stage = 1; $stage <= 7; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        self::assertTrue(StageGateService::isUnlocked($orderId, 8));
        self::assertFalse(StageGateService::isUnlocked($orderId, 9), 'the balance is not cleared yet');

        $html = $this->render($orderId);
        self::assertStringContainsString('document_type" value="COOPREP"', $html);
        self::assertStringContainsString('Prepared early — the Certificate of Origin can be applied for now', $html);
        self::assertMatchesRegularExpression('#<details class="step-card" id="step-11a-coo-prep"(?![^>]*data-locked)[^>]*>#', $html, 'the 13a card is not held by the step-by-step lock');

        $result = DocumentGenerationService::generate($orderId, 'COOPREP', $userId);
        self::assertNotEmpty($result['document_id'], 'generated while Stage 9 is still locked');

        // Before Stage 8 opens it stays locked.
        $early = $this->createTestOrder($this->createTestClient());
        self::assertStringContainsString('Opens once the BL is issued (Step 10)', $this->render($early));
        $this->expectException(\RuntimeException::class);
        DocumentGenerationService::generate($early, 'COOPREP', $userId);
    }

    public function testPoint16OrderZohoSyncRouteAndMenuLink(): void
    {
        $this->signIn('Admin', true);
        $orderId = $this->createTestOrder($this->createTestClient());
        $html = $this->render($orderId);
        self::assertStringContainsString('action="/orders/' . $orderId . '/zoho-sync"', $html);
        self::assertStringContainsString('Sync this order to Zoho now', $html);

        $r = $this->call('POST', "/orders/{$orderId}/zoho-sync");
        self::assertSame(302, $r['code']);
        self::assertStringContainsString('Zoho is not connected — Settings → Zoho Books. Nothing was synced.', (string) $r['flash']);

        $missing = $this->call('POST', '/orders/99999999/zoho-sync');
        self::assertSame(404, $missing['code']);

        $menu = $this->call('GET', '/');
        self::assertStringContainsString('href="/ca/zoho-sync"', $menu['body'], 'Zoho Sync in the menu for ca_module_manage');

        $this->signIn('Export Executive');
        $refused = $this->call('POST', "/orders/{$orderId}/zoho-sync");
        self::assertSame(403, $refused['code']);
        self::assertStringNotContainsString('href="/ca/zoho-sync"', $this->call('GET', '/')['body']);
    }
    /** Owner decision 2 Nov 2026 (D-233): "Waiting for Zoho: N" — only while Zoho is on, only for Zoho-status holders. */
    public function testWaitingForZohoBadgeOnTheOrderPageAndInTheMenu(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->signIn('Accounts Executive');
        \App\Repositories\CompanySettingsRepository::set('zoho_books_enabled', '0');
        self::assertStringNotContainsString('Waiting for Zoho', $this->render($orderId), 'nothing while Zoho is off');

        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        \App\Repositories\CompanySettingsRepository::set('zoho_books_enabled', '1');
        try {
            $html = $this->render($orderId);
            self::assertMatchesRegularExpression('#data-zoho-waiting="0"[^>]*><a href="/orders/' . $orderId . '/zoho-check">Waiting for Zoho: 0 — everything on this order is in Zoho Books</a>#u', $html);
            self::assertSame(1, substr_count($html, 'class="badge badge-success zoho-waiting"'), 'At a Glance (Accounts has no Step 12)');
            $this->signIn('Admin', true);
            self::assertSame(2, substr_count($this->render($orderId), 'class="badge badge-success zoho-waiting"'), 'Step 12 and At a Glance');

            $this->signIn('CA / Chartered Accountant'); // ca_module_view: sees the status, no link
            $html = $this->render($orderId);
            self::assertStringContainsString('Waiting for Zoho: 0', $html);
            self::assertStringNotContainsString('/zoho-check">Waiting', $html);

            $this->signIn('Export Executive');
            self::assertStringNotContainsString('Waiting for Zoho', $this->render($orderId), 'no Zoho status for roles without it');
        } finally {
            \App\Repositories\CompanySettingsRepository::set('zoho_books_enabled', '0');
            unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT']);
        }
    }
}
