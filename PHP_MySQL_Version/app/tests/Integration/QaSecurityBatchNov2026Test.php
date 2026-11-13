<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ComplaintController;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA round 13 Nov 2026 — security, access, data-integrity and robustness
 * fixes. Each test names the bug id from the QA register. The Node suite has
 * the same checks in qaSecurityBatchNov2026.test.js.
 */
final class QaSecurityBatchNov2026Test extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function render(int $userId, object $controller, string $action, array $get = [], array $params = []): string
    {
        $_SESSION = ['_auth_user_id' => $userId];
        $_GET = $get;
        \App\Services\PermissionService::resetCache();
        \App\Services\ActiveDepartmentService::resetCache();
        ob_start();
        $controller->{$action}($params);
        return (string) ob_get_clean();
    }

    /** UI-001: the Kanban Complaints board never writes ?status= raw into its "Switch to list view" link. */
    public function testUi001ComplaintsBoardEscapesStatus(): void
    {
        $md = $this->createTestUser('Managing Director');
        UserRepository::updateUiTemplatePreference($md, (int) UiTemplateRepository::findByKey('kanban')['id']);
        $html = $this->render($md, new ComplaintController(), 'index', ['status' => '"><script>window.__x=1</script>']);
        self::assertStringNotContainsString('<script>window.__x=1</script>', $html);
        self::assertStringContainsString('/complaints?view=list&amp;status=%22%3E%3Cscript%3Ewindow.__x%3D1%3C%2Fscript%3E', $html);
    }

    /**
     * Starts $count copies of tests/Support/qaSecurityWorker.php at the same moment
     * (real separate processes, each with its own DB connection). Returns their outputs.
     *
     * @param list<list<string>> $argsPerWorker
     * @return list<string>
     */
    private function runWorkersAtOnce(array $argsPerWorker): array
    {
        $script = __DIR__ . '/../Support/qaSecurityWorker.php';
        $startAt = sprintf('%.4f', microtime(true) + 1.5);
        $env = array_merge(getenv(), ['DB_DATABASE' => (string) getenv('DB_DATABASE')]);
        $procs = [];
        foreach ($argsPerWorker as $args) {
            $proc = proc_open(array_merge([PHP_BINARY, $script, $startAt], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $procs[] = [$proc, $pipes[1], $pipes[2]];
        }
        $out = [];
        foreach ($procs as [$proc, $stdout, $stderr]) {
            $o = (string) stream_get_contents($stdout);
            $e = (string) stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            self::assertSame(0, proc_close($proc), 'worker failed: ' . $e . $o);
            $out[] = trim($o);
        }
        return $out;
    }

    /** HNT-001: associate portal login code — wrong codes sent at once can never use more than the 5 tries. */
    public function testHnt001AssociateCodeTriesAreClaimedAtomically(): void
    {
        $pdo = \App\Config\Database::connection();
        $pdo->exec("INSERT INTO associates (name, status) VALUES ('TEST-QA Sec Associate', 'active')");
        $associateId = (int) $pdo->lastInsertId();
        $code = '246813';
        $pdo->prepare("INSERT INTO associate_tokens (associate_id, purpose, token_hash, expires_at) VALUES (:a, 'login_code', :h, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
            ->execute(['a' => $associateId, 'h' => hash('sha256', $code)]);

        $this->runWorkersAtOnce(array_fill(0, 12, ['assoc_code', (string) $associateId, '135790']));
        $attempts = (int) $pdo->query("SELECT attempts FROM associate_tokens WHERE associate_id = {$associateId} AND purpose = 'login_code'")->fetchColumn();
        self::assertSame(5, $attempts, 'the try counter stops at 5');
        self::assertSame(['no'], $this->runWorkersAtOnce([['assoc_code', (string) $associateId, $code]]), 'the right code after the 5 tries are used is refused');
    }

    /** HNT-001: associate bank-change confirmation code — a try is claimed in one atomic UPDATE, never past the limit. */
    public function testHnt001BankConfirmCodeTriesClaimedAtomically(): void
    {
        $pdo = \App\Config\Database::connection();
        $pdo->exec("INSERT INTO associates (name, status) VALUES ('TEST-QA Sec Bank Associate', 'active')");
        $associateId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO associate_bank_accounts (associate_id, holder_name, account_number_enc, account_last4, bank_name) VALUES ({$associateId}, 'TEST-QA Holder', 'x', '1234', 'TEST Bank')");
        $bankId = (int) $pdo->lastInsertId();
        $ok = 0;
        for ($i = 0; $i < 7; $i++) {
            $ok += \App\Repositories\AssociateBankRepository::claimCodeAttempt($bankId, 5) ? 1 : 0;
        }
        self::assertSame(5, $ok);
        self::assertSame(5, (int) $pdo->query("SELECT confirm_code_attempts FROM associate_bank_accounts WHERE id = {$bankId}")->fetchColumn());
    }

    /** HNT-001: the staff login code still allows the right code within 5 tries, and not after. */
    public function testHnt001StaffCodeFiveTries(): void
    {
        $_SESSION = [];
        $userId = $this->createTestUser('Export Executive');
        $user = \App\Repositories\UserRepository::findById($userId);
        $code = \App\Services\TwoFactorService::issueCodeFor($userId, 'email', (string) $user['email']);
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse(\App\Services\TwoFactorService::verify($code === '000000' ? '111111' : '000000'));
        }
        self::assertFalse(\App\Services\TwoFactorService::verify($code));
    }

    /** MTX-001: a role with only ca_module_manage + manage_pricing, assigned to the order, gets 404 on its Zoho check and line price approval. */
    public function testMtx001OrderSubPagesNeedOrderAccess(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $lineId = \App\Repositories\OrderProductRepository::add($orderId, 1, 'TEST-QA granite slab', '60x30', 'Polished', '10', false, 'PCS', '250.00');
        $roleId = \App\Repositories\RoleRepository::create('TEST-MX-only ' . bin2hex(random_bytes(3)), null);
        $pdo = \App\Config\Database::connection();
        foreach (['ca_module_manage', 'manage_pricing'] as $k) {
            $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, is_enabled) SELECT :r, id, 1 FROM permissions WHERE permission_key = :k')->execute(['r' => $roleId, 'k' => $k]);
        }
        $userId = $this->createTestUser('Admin');
        $pdo->prepare('UPDATE users SET role_id = :r WHERE id = :u')->execute(['r' => $roleId, 'u' => $userId]);
        $pdo->prepare('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)')->execute(['o' => $orderId, 'u' => $userId]);

        $html = $this->render($userId, new \App\Controllers\CaController(), 'orderZohoCheck', [], ['id' => (string) $orderId]);
        self::assertSame(404, http_response_code());
        self::assertSame('Order not found.', $html);
        http_response_code(200);
        $html = $this->render($userId, new \App\Controllers\OrderController(), 'linePriceApproval', [], ['id' => (string) $orderId, 'productId' => (string) $lineId]);
        self::assertSame(404, http_response_code());
        self::assertStringNotContainsString('TEST-QA granite slab', $html);
    }

    /** HNT-008 / PLN-001: {pid} is a record id too (as every {...Id}); "1abc" matches no route on either stack. */
    public function testHnt008PidIsAnIdParam(): void
    {
        self::assertTrue(\App\Helpers\Router::isIdParam('pid'));
        foreach (['milestoneId', 'lineId', 'specId', 'offerId', 'changeId', 'id'] as $n) {
            self::assertTrue(\App\Helpers\Router::isIdParam($n), $n);
        }
        $router = new \App\Helpers\Router();
        $hit = false;
        $router->post('/products/{pid}/own-specs/{id}/delete', function () use (&$hit): void {
            $hit = true;
        });
        ob_start();
        $router->dispatch('POST', '/products/1abc/own-specs/2/delete');
        ob_end_clean();
        self::assertFalse($hit, 'a non-numeric product id reaches no handler');
        self::assertSame(404, http_response_code());
    }

    /** HNT-004: a pending Quotation review cannot be approved once the order is lost or cancelled; it can still be rejected. */
    public function testHnt004NoApprovalOnLostOrCancelledOrder(): void
    {
        $pdo = \App\Config\Database::connection();
        $ed = $this->createTestUser('Executive Director');
        foreach (['lost', 'cancelled'] as $status) {
            $orderId = $this->createTestOrder($this->createTestClient());
            $docId = $this->createTestDocument($orderId, 'QT', 'in_review');
            $pdo->prepare('INSERT INTO document_reviews (document_id, reviewer_id) VALUES (:d, :u)')->execute(['d' => $docId, 'u' => $ed]);
            $reviewId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE orders SET status = :s, is_locked = 1 WHERE id = :id')->execute(['s' => $status, 'id' => $orderId]);
            try {
                \App\Services\ReviewWorkflowService::approve($reviewId, $ed, null);
                self::fail('approval on a ' . $status . ' order must be refused');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('can no longer be approved', $e->getMessage());
            }
            self::assertNotSame('sent', $pdo->query("SELECT status FROM documents WHERE id = {$docId}")->fetchColumn());
            self::assertNull($pdo->query("SELECT prices_fixed_at FROM orders WHERE id = {$orderId}")->fetchColumn() ?: null);
            \App\Services\ReviewWorkflowService::reject($reviewId, $ed, 'TEST-QA order lost, closing this review');
            self::assertSame('rejected', $pdo->query("SELECT status FROM document_reviews WHERE id = {$reviewId}")->fetchColumn());
        }
    }

    /** An order with USD 2,500.00 of goods and a 40 % advance (USD 1,000.00 due). */
    private function orderWithAdvanceDue(): int
    {
        $this->allowHandTypedGoodsLines();
        $orderId = $this->createTestOrder($this->createTestClient());
        \App\Repositories\OrderProductRepository::add($orderId, 1, 'TEST-QA granite slab', '60x30', 'Polished', '10', false, 'PCS', '250.00');
        $pdo = \App\Config\Database::connection();
        $pdo->prepare('UPDATE orders SET advance_pct = 40 WHERE id = :id')->execute(['id' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        return $orderId;
    }

    /** HNT-003: two advance remittances of the full advance recorded at the same moment — only one is saved. */
    public function testHnt003ConcurrentAdvanceRecordedOnce(): void
    {
        $orderId = $this->orderWithAdvanceDue();
        $md = $this->createTestUser('Managing Director');
        $ed = $this->createTestUser('Executive Director');
        $out = $this->runWorkersAtOnce([
            ['payment', (string) $md, (string) $orderId, 'advance', '1000.00', 'TEST-UTR-RACE-0', '84000'],
            ['payment', (string) $ed, (string) $orderId, 'advance', '1000.00', 'TEST-UTR-RACE-1', '84001'],
            ['payment', (string) $md, (string) $orderId, 'advance', '1000.00', 'TEST-UTR-RACE-2', '84002'],
        ]);
        $row = \App\Config\Database::connection()->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS t FROM order_payment_receipts WHERE order_id = {$orderId} AND leg = 'advance'")->fetch();
        self::assertSame(1, (int) $row['n'], implode("\n", $out));
        self::assertEqualsWithDelta(1000.0, (float) $row['t'], 0.001);
    }

    /** HNT-003 (hunt idea HNT-L02): one payment milestone recorded twice at the same moment is recorded once. */
    public function testHnt003ConcurrentMilestoneRecordedOnce(): void
    {
        $orderId = $this->orderWithAdvanceDue();
        \App\Repositories\PaymentMilestoneRepository::replaceSchedule($orderId, [['pct' => 10, 'due_text' => 'on shipment']], 30);
        $this->createTestDocument($orderId, 'PI', 'sent');
        $m = \App\Repositories\PaymentMilestoneRepository::forOrder($orderId)[0];
        $md = $this->createTestUser('Managing Director');
        $ed = $this->createTestUser('Executive Director');
        $out = $this->runWorkersAtOnce([
            ['payment', (string) $md, (string) $orderId, 'milestone', '250.00', 'TEST-UTR-MS-0', '21000', (string) $m['id']],
            ['payment', (string) $ed, (string) $orderId, 'milestone', '250.00', 'TEST-UTR-MS-1', '21001', (string) $m['id']],
            ['payment', (string) $md, (string) $orderId, 'milestone', '250.00', 'TEST-UTR-MS-2', '21002', (string) $m['id']],
        ]);
        $n = (int) \App\Config\Database::connection()->query("SELECT COUNT(*) FROM order_payment_receipts WHERE order_id = {$orderId} AND leg = 'milestone'")->fetchColumn();
        self::assertSame(1, $n, implode("\n", $out));
    }

    /** HNT-005: the public Payment Action form refuses an over-large amount and a 2099 / 0000 / 1900 date before anything is stored. */
    public function testHnt005PaymentActionReportBounds(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient('buyer-' . bin2hex(random_bytes(3)) . '@example.test'));
        \App\Config\Database::connection()->prepare("UPDATE orders SET payment_method = 'CASH_ADVANCE' WHERE id = :id")->execute(['id' => $orderId]);
        \App\Repositories\OrderProductRepository::add($orderId, 1, 'Absolute black granite', null, null, '50', false, 'PCS', '150.00');
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($orderId);
        $this->createTestDocument($orderId, 'PI', 'sent', $this->createTestFile($orderId));
        $order = \App\Repositories\OrderRepository::find($orderId);
        // No screenshot is attached: a refusal about the amount/date (not "Add a screenshot") proves the check runs before the upload.
        $_FILES = [];
        foreach ([['1e20', '2026-11-10', 'too large'], ['100', '2099-01-01', 'today or earlier'], ['100', '0000-01-01', 'today or earlier'], ['100', '1900-01-01', 'cannot be before']] as [$amount, $date, $expect]) {
            $_POST = ['transaction_ref' => 'TEST-UTR-4', 'amount' => $amount, 'payment_date' => $date];
            $r = \App\Services\PaymentActionService::report($order);
            self::assertFalse($r['ok']);
            self::assertStringContainsString($expect, $r['message'], "{$amount} / {$date}");
        }
        $token = \App\Repositories\OrderRepository::generatePaymentActionToken($orderId);
        ob_start();
        (new \App\Controllers\PaymentActionController())->show(['token' => $token]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('max="999999999999.99"', $html);
        self::assertStringContainsString('min="2000-01-01"', $html);
    }

    /** HNT-006: a .jpg holding a UTF-8 BOM + "<html><script>" and a .png holding SVG are refused; a real PNG is stored (same rule as Node). */
    public function testHnt006UploadContentCheckParity(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $png = (string) hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da63f8ffff3f0005fe02fea7d6a4b40000000049454e44ae426082');
        $cases = [
            ['x.jpg', "\xEF\xBB\xBF<html><script>alert(1)</script>", false],
            ['y.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', false],
            ['z.jpg', 'not really a jpeg', false],
            ['real.png', $png, true],
        ];
        foreach ($cases as [$name, $content, $accepted]) {
            $tmp = tempnam(sys_get_temp_dir(), 'hnt006');
            file_put_contents($tmp, $content);
            try {
                \App\Services\FileUploadService::checkUpload(['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK], 'order_additional_document');
                self::assertTrue($accepted, "{$name} must be refused");
            } catch (\RuntimeException $e) {
                self::assertFalse($accepted, "{$name} must be accepted: " . $e->getMessage());
                self::assertStringContainsString('does not match a safe file type', $e->getMessage());
            } finally {
                @unlink($tmp);
            }
        }
        self::assertFalse(\App\Services\FileUploadService::imageContentMismatch("GIF89a....", 'gif'));
        self::assertFalse(\App\Services\FileUploadService::imageContentMismatch("RIFF\x10\x00\x00\x00WEBPVP8 ", 'webp'));
        self::assertFalse(\App\Services\FileUploadService::imageContentMismatch("\x00\x00\x00\x18ftypheic", 'heic'));
        self::assertFalse(\App\Services\FileUploadService::imageContentMismatch('anything', 'pdf'));
    }

    /** HNT-010: the database refusing connections answers 503 "please try again" (as Node), not 500. */
    public function testHnt010DatabaseDownIs503(): void
    {
        $prop = new \ReflectionProperty(\App\Config\Database::class, 'connection');
        $saved = $prop->getValue();
        $savedHost = $_ENV['DB_HOST'] ?? null;
        $savedPort = $_ENV['DB_PORT'] ?? null;
        $prop->setValue(null, null);
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_ENV['DB_PORT'] = '3399'; // nothing listens here
        try {
            \App\Config\Database::connection();
            self::fail('connecting to a closed port must fail');
        } catch (\RuntimeException $e) {
            $response = \App\Helpers\DbResilience::errorResponse($e, false);
            self::assertSame(503, $response['status']);
            self::assertSame('10', $response['headers']['Retry-After'] ?? null);
            self::assertStringNotContainsString('3399', $response['body']);
        } finally {
            $prop->setValue(null, $saved);
            if ($savedHost === null) { unset($_ENV['DB_HOST']); } else { $_ENV['DB_HOST'] = $savedHost; }
            if ($savedPort === null) { unset($_ENV['DB_PORT']); } else { $_ENV['DB_PORT'] = $savedPort; }
        }
        // An ordinary error stays a 500.
        self::assertSame(500, \App\Helpers\DbResilience::errorResponse(new \RuntimeException('boom', 0, new \LogicException('x')), false)['status']);
    }

    /** UI-009: a refused form goes back to itself when the site runs on a port (dev/UAT), not to the Dashboard. */
    public function testUi009SafeRedirectBackKeepsThePort(): void
    {
        $saved = [$_SERVER['HTTP_REFERER'] ?? null, $_SERVER['HTTP_HOST'] ?? null];
        try {
            $_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
            $_SERVER['HTTP_REFERER'] = 'http://127.0.0.1:8080/clients/create?x=1';
            self::assertSame('/clients/create?x=1', \App\Helpers\Router::safeRedirectBack());
            $_SERVER['HTTP_REFERER'] = 'http://127.0.0.1:9999/clients/create';
            self::assertSame('/', \App\Helpers\Router::safeRedirectBack(), 'another port is another site');
            $_SERVER['HTTP_REFERER'] = '//evil.example.com/phish';
            self::assertSame('/', \App\Helpers\Router::safeRedirectBack());
            $_SERVER['HTTP_HOST'] = 'example.com';
            $_SERVER['HTTP_REFERER'] = 'https://example.com/clients/create';
            self::assertSame('/clients/create', \App\Helpers\Router::safeRedirectBack());
        } finally {
            [$_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']] = $saved;
        }
    }

    /** UI-003: Incentives owed with an impossible date (2026-13-45) uses the default month-end date, the same on both stacks. */
    public function testUi003OwedReportImpossibleDate(): void
    {
        $md = $this->createTestUser('Managing Director');
        foreach (['2026-13-45', '0000-00-00', '2026-02-30'] as $d) {
            $html = $this->render($md, new \App\Controllers\IncentiveReportController(), 'owed', ['date' => $d]);
            self::assertStringNotContainsString($d, $html, $d);
            self::assertStringContainsString(date('Y-m-t'), $html, $d);
        }
    }

    /**
     * HNT-011: 99,999,999,999 x 10 was saved. Owner decision item 10a (13 Nov 2026) replaced the
     * first cap of 10,000,000 with the hard block of 1,000,000 (QuantityLimitsItem10aTest has the
     * per-unit confirmation): above 1,000,000 is refused, 1,000,000 still passes.
     */
    public function testHnt011QuantitySanityLimit(): void
    {
        self::assertStringContainsString('quantity can be at most 1,000,000', (string) \App\Helpers\OrderLineValidator::checkLine('99999999999', false, '10'));
        self::assertNotNull(\App\Helpers\OrderLineValidator::checkLine('1000000.001', false, '1'));
        self::assertNull(\App\Helpers\OrderLineValidator::checkLine('1000000', false, '1'));
        self::assertNull(\App\Helpers\OrderLineValidator::checkLine('', true, '10'));
        foreach (['orders/_products_table.php', 'orders/create.php'] as $view) {
            self::assertStringContainsString('data-max-value="1000000"', (string) file_get_contents(__DIR__ . '/../../views/' . $view));
        }
    }

    /** HNT-L10: staff "view as associate" (and a department "Preview as") end when the staff member signs out. */
    public function testHntL10ViewAsEndsOnStaffLogout(): void
    {
        $pdo = \App\Config\Database::connection();
        $pdo->exec("INSERT INTO associates (name, status, portal_enabled) VALUES ('TEST-QA View-as Associate', 'active', 1)");
        $associateId = (int) $pdo->lastInsertId();
        $md = $this->createTestUser('Managing Director');
        $_SESSION = ['_auth_user_id' => $md];
        \App\Services\AssociatePortalService::startViewAs($md, $associateId);
        self::assertSame($md, \App\Services\AssociatePortalService::viewAsStaffId());

        // While the same staff member is signed in, the portal opens.
        self::assertTrue((\App\Middleware\AssociateAuth::required())([]));

        @\App\Services\AuthService::logout();
        self::assertNull(\App\Services\AssociatePortalService::viewAsStaffId());
        self::assertNull(\App\Services\AssociatePortalService::currentId());
        self::assertNull(\App\Services\ActiveDepartmentService::preview());

        // And a view-as left behind without its staff sign-in (e.g. the staff session expired) is refused too.
        $_SESSION = [];
        \App\Services\AssociatePortalService::startViewAs($md, $associateId);
        self::assertFalse((\App\Middleware\AssociateAuth::required())([]));
        self::assertNull(\App\Services\AssociatePortalService::viewAsStaffId());
    }
}
