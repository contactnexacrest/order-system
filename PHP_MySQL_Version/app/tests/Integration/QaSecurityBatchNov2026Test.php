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
}
