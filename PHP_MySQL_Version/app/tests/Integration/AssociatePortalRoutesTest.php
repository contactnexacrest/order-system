<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociateDocumentRepository;
use App\Repositories\AssociateEventRepository;
use App\Repositories\AssociateInvitationRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Services\AssociateCodeService;
use App\Services\AssociateIntakeService;
use App\Services\AssociatePortalService;
use App\Services\AssociateService;
use App\Services\AuthService;
use App\Services\EmailDispatchService;
use App\Services\PermissionService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 8 ("15 routes without a behaviour test", rule D-211): each of
 * these routes is driven through the real Router and its middleware, and
 * the test checks what it does — the record changed, the audit row, the page
 * shown — and that another associate's record is answered as not found with
 * nothing written. Associate portal: bank, clients, code, commission
 * invoice, documents (upload and download), invitation cancel, messages
 * (page and send), set-password (page and form); public: /invite/{token},
 * /quotation-details/verify; staff: inspection result, OC sent to buyer.
 * The Node suite has the same test (associatePortalRoutes.test.js).
 */
final class AssociatePortalRoutesTest extends DbTestCase
{
    private static ?Router $router = null;
    private int $staff;
    private string $tag;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__associate-routes-build__';
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
        self::router();
        $this->tag = strtoupper(bin2hex(random_bytes(3)));
        $this->staff = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$this->staff}");
    }

    protected function tearDown(): void
    {
        Flash::pull();
        Flash::pullOld();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    /** @return array{code: int, body: string, flash: ?string, type: ?string} */
    private function call(string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_GET = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch($method, (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        $flash = Flash::pull()[0] ?? null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null, 'type' => $flash['type'] ?? null];
    }

    private function value(string $sql): mixed
    {
        $v = Database::connection()->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }

    private function associate(): array
    {
        $id = AssociateRepository::create(['name' => 'Route Assoc ' . $this->tag . ' ' . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'route-assoc-' . bin2hex(random_bytes(4)) . '@assoc.test', 'status' => 'active', 'portal_enabled' => 1], $this->staff);
        Database::connection()->exec("UPDATE associates SET portal_enabled = 1 WHERE id = {$id}");
        AssociateCodeService::issue($id, 'R' . $this->tag);
        return AssociateRepository::find($id);
    }

    private function asAssociate(array $a): void
    {
        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
    }

    private function asStaff(): void
    {
        $_SESSION = ['_auth_user_id' => $this->staff, '_session_version' => AuthService::sessionVersion() ?? 0];
    }

    private function storedFile(string $bytes): int
    {
        $fileId = $this->createTestFile();
        $path = sys_get_temp_dir() . '/assoc-route-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, $bytes);
        Database::connection()->prepare('UPDATE file_store SET server_path = :p, file_size_bytes = :s, original_filename = :n WHERE id = :id')
            ->execute(['p' => $path, 's' => strlen($bytes), 'n' => 'pan.pdf', 'id' => $fileId]);
        return $fileId;
    }

    private function payout(array $a): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare("INSERT INTO incentive_payouts (order_id, payee_kind, role, associate_id, amount_inr, incentive_month) VALUES (:o, 'associate', 'associate', :a, 1500, '2026-10')")
            ->execute(['o' => $orderId, 'a' => $a['id']]);
        return (int) Database::connection()->lastInsertId();
    }

    // ---------------------------------------------------------------- set-password

    public function testSetPasswordPageAndForm(): void
    {
        $a = $this->associate();
        $token = AssociatePortalService::issueToken((int) $a['id'], 'set_password', 60);
        $page = $this->call('GET', "/associate/set-password/{$token}");
        self::assertSame(200, $page['code']);
        self::assertStringContainsString(htmlspecialchars((string) $a['email']), $page['body']);
        self::assertStringContainsString('name="new_password"', $page['body']);
        self::assertStringContainsString('This link has expired or was already used.', $this->call('GET', '/associate/set-password/' . str_repeat('0', 64))['body']);

        self::assertSame('The two passwords do not match.', $this->call('POST', "/associate/set-password/{$token}", ['new_password' => 'Route1234567', 'confirm_password' => 'Route1234568'])['flash']);
        self::assertSame('Use at least 10 characters, with letters and digits.', $this->call('POST', "/associate/set-password/{$token}", ['new_password' => 'short', 'confirm_password' => 'short'])['flash']);
        self::assertEmpty(AssociateRepository::find((int) $a['id'])['password_hash'] ?? null, 'nothing saved on a refusal');
        $ok = $this->call('POST', "/associate/set-password/{$token}", ['new_password' => 'Route1234567', 'confirm_password' => 'Route1234567']);
        self::assertSame(['success', 'Password set. Log in with your e-mail and the new password.'], [$ok['type'], $ok['flash']]);
        self::assertTrue(password_verify('Route1234567', (string) AssociateRepository::find((int) $a['id'])['password_hash']));
        self::assertSame('This link has expired or was already used. Ask NexaCrest for a new one.',
            $this->call('POST', "/associate/set-password/{$token}", ['new_password' => 'Other1234567', 'confirm_password' => 'Other1234567'])['flash'], 'the link works once');
    }

    // ---------------------------------------------------------------- clients, code, invitations

    public function testClientsPageCodeAndInvitationCancel(): void
    {
        $a = $this->associate();
        $b = $this->associate();
        $mine = $this->createTestClient();
        $theirs = $this->createTestClient();
        $pdo = Database::connection();
        $pdo->prepare('UPDATE clients SET company_legal_name = :n WHERE id = :id')->execute(['n' => "Mine Buyer {$this->tag}", 'id' => $mine]);
        $pdo->prepare('UPDATE clients SET company_legal_name = :n WHERE id = :id')->execute(['n' => "Theirs Buyer {$this->tag}", 'id' => $theirs]);
        AssociateService::linkClient($mine, (int) $a['id'], 'code', null, '2026-10-01', $this->staff);
        AssociateService::linkClient($theirs, (int) $b['id'], 'code', null, '2026-10-01', $this->staff);

        $this->asAssociate($a);
        $page = $this->call('GET', '/associate/clients');
        self::assertSame(200, $page['code']);
        self::assertStringContainsString("Mine Buyer {$this->tag}", $page['body']);
        self::assertStringNotContainsString("Theirs Buyer {$this->tag}", $page['body'], 'only their own clients');
        $this->asAssociate($a);
        self::assertStringContainsString((string) $a['personal_code'], $this->call('GET', '/associate/clients')['body']);

        // D-254: a new code is proposed ("New ending"), then confirmed ("Use this code"); the old code keeps working.
        $this->asAssociate($a);
        self::assertSame('Use 3 to 12 letters or digits.', $this->call('POST', '/associate/code/propose', ['readable' => 'x!'])['flash']);
        $this->asAssociate($a);
        $this->call('POST', '/associate/code/propose', ['readable' => 'Mine' . $this->tag]);
        $proposed = (string) $_SESSION['associate_code_proposal']['code'];
        $chosen = $this->call('POST', '/associate/code', ['code' => $proposed]);
        $now = AssociateRepository::find((int) $a['id'])['personal_code'];
        self::assertSame([$proposed, 'success', "Your personal code is now {$now}. Your old code keeps working."], [$now, $chosen['type'], $chosen['flash']]);
        self::assertNotSame($a['personal_code'], $now);
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ASSOCIATE_CODE_CHOSEN' AND entity_id = {$a['id']}"));
        self::assertSame((int) $a['id'], (int) \App\Services\AssociateCodeService::resolve((string) $a['personal_code'])['id'], 'the old code still brings buyers');

        $mineInv = AssociateInvitationRepository::createLink((int) $a['id'], "Invited {$this->tag}", 'Netherlands');
        $theirsInv = AssociateInvitationRepository::createLink((int) $b['id'], "Other {$this->tag}", 'Germany');
        $this->asAssociate($a);
        $notMine = $this->call('POST', "/associate/invitations/{$theirsInv['id']}/cancel");
        self::assertSame([404, 'Invitation not found.'], [$notMine['code'], $notMine['body']]);
        self::assertSame('created', AssociateInvitationRepository::find($theirsInv['id'])['status'], 'another associate\'s invitation is untouched');
        $this->asAssociate($a);
        self::assertSame('Invitation cancelled.', $this->call('POST', "/associate/invitations/{$mineInv['id']}/cancel")['flash']);
        self::assertSame('cancelled', AssociateInvitationRepository::find($mineInv['id'])['status']);
        $this->asAssociate($a);
        self::assertSame('This invitation can no longer be cancelled.', $this->call('POST', "/associate/invitations/{$mineInv['id']}/cancel")['flash']);

        $_SESSION = [];
        self::assertSame('', $this->call('GET', '/associate/clients')['body'], 'not signed in: sent to the login page');
    }

    // ---------------------------------------------------------------- commission invoice, documents, bank

    public function testCommissionInvoiceDocumentsAndBank(): void
    {
        $a = $this->associate();
        $b = $this->associate();
        $mine = $this->payout($a);
        $theirs = $this->payout($b);
        $docs = static fn (int $id): int => count(AssociateDocumentRepository::forAssociate($id));
        $before = $docs((int) $a['id']);

        $this->asAssociate($a);
        $r = $this->call('POST', "/associate/commission/{$theirs}/invoice", ['invoice_no' => 'INV-1']);
        self::assertSame([404, 'Payout not found.'], [$r['code'], $r['body']]);
        $this->asAssociate($a);
        self::assertSame('Enter your invoice number.', $this->call('POST', "/associate/commission/{$mine}/invoice", ['invoice_no' => ''])['flash']);
        $this->asAssociate($a);
        self::assertSame('error', $this->call('POST', "/associate/commission/{$mine}/invoice", ['invoice_no' => 'INV-1'])['type'], 'no file attached');
        self::assertSame($before, $docs((int) $a['id']), 'nothing stored on a refusal');

        $this->asAssociate($a);
        self::assertSame('Choose what the document is.', $this->call('POST', '/associate/documents', ['kind' => 'invoice'])['flash']);
        $this->asAssociate($a);
        self::assertSame('error', $this->call('POST', '/associate/documents', ['kind' => 'pan'])['type'], 'no file attached');
        self::assertSame($before, $docs((int) $a['id']));

        // As stored by a real upload: the associate downloads their own document, never another's.
        $own = AssociateDocumentRepository::add((int) $a['id'], 'pan', $this->storedFile('%PDF-1.4 own pan'), null, null, true, null);
        $other = AssociateDocumentRepository::add((int) $b['id'], 'pan', $this->storedFile('%PDF-1.4 other pan'), null, null, true, null);
        $this->asAssociate($a);
        $dl = $this->call('GET', "/associate/documents/{$own}");
        self::assertSame([200, '%PDF-1.4 own pan'], [$dl['code'], $dl['body']]);
        $this->asAssociate($a);
        self::assertSame([404, 'Document not found.'], array_values(array_intersect_key($this->call('GET', "/associate/documents/{$other}"), ['code' => 1, 'body' => 1])));

        $requests = fn (): int => (int) $this->value("SELECT COUNT(*) FROM associate_bank_accounts WHERE associate_id = {$a['id']}");
        $bankBefore = $requests();
        $bank = ['holder_name' => 'Route Assoc', 'account_number' => '123456789012', 'account_number_confirm' => '123456789012', 'ifsc' => 'HDFC0001234', 'bank_name' => 'HDFC Bank'];
        $this->asAssociate($a);
        self::assertSame('The two account numbers do not match — type it again.', $this->call('POST', '/associate/bank', ['account_number_confirm' => '999'] + $bank)['flash']);
        $this->asAssociate($a);
        self::assertSame('Attach a cancelled cheque or a bank letter (PDF or image).', $this->call('POST', '/associate/bank', $bank)['flash']);
        self::assertSame($bankBefore, $requests(), 'no bank change stored without the proof');
    }

    // ---------------------------------------------------------------- messages

    public function testMessagesPageAndSend(): void
    {
        $a = $this->associate();
        AssociateEventRepository::addMessage((int) $a['id'], false, $this->staff, "Welcome aboard {$this->tag}");
        $this->asAssociate($a);
        $page = $this->call('GET', '/associate/messages');
        self::assertSame(200, $page['code']);
        self::assertStringContainsString("Welcome aboard {$this->tag}", $page['body']);
        self::assertStringContainsString('name="body"', $page['body']);

        $count = fn (): int => count(AssociateEventRepository::messages((int) $a['id']));
        $before = $count();
        $this->asAssociate($a);
        self::assertSame('Write your message (up to 4,000 characters).', $this->call('POST', '/associate/messages', ['body' => '  '])['flash']);
        self::assertSame($before, $count());
        $this->asAssociate($a);
        self::assertSame('Message sent.', $this->call('POST', '/associate/messages', ['body' => "Question about order {$this->tag}"])['flash']);
        self::assertSame($before + 1, $count());
        $this->asAssociate($a);
        self::assertStringContainsString("Question about order {$this->tag}", $this->call('GET', '/associate/messages')['body']);
    }

    // ---------------------------------------------------------------- public: /invite/{token}, /quotation-details/verify

    public function testInviteLinkAndVerifyPage(): void
    {
        $a = $this->associate();
        $inv = AssociateInvitationRepository::createLink((int) $a['id'], "Link Buyer {$this->tag}", 'Netherlands');
        $r = $this->call('GET', '/invite/' . $inv['token']);
        self::assertSame([302, ''], [$r['code'], $r['body']], 'a redirect to /quotation-details?inv=… (the Location header is not visible in the CLI; the Node test checks it)');
        $form = $this->call('GET', '/quotation-details?inv=' . $inv['token']);
        self::assertStringContainsString('<input type="hidden" name="associate_inv" value="' . $inv['token'] . '">', $form['body'], 'where the redirect lands: the form carries the link');

        $_SESSION = [];
        self::assertStringContainsString('Link No Longer Valid', $this->call('GET', '/quotation-details/verify')['body'], 'no submission in this browser');
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO client_intake_submissions (company_legal_name, billing_address, contact_person, email, country_of_destination, status, associate_id, associate_invitation_id)
                       VALUES (:c, '1 Harbour Road', 'Jan Buyer', :e, 'Netherlands', 'pending', :a, :i)")
            ->execute(['c' => "Verify Buyer {$this->tag}", 'e' => "jan-{$this->tag}@buyer.example", 'a' => $a['id'], 'i' => $inv['id']]);
        $_SESSION = [AssociateIntakeService::SESSION_KEY => (int) $pdo->lastInsertId()];
        $page = $this->call('GET', '/quotation-details/verify');
        self::assertSame(200, $page['code']);
        self::assertStringContainsString('Confirm your request', $page['body']);
        self::assertStringContainsString("Verify Buyer {$this->tag}", $page['body']);
        self::assertStringContainsString('name="code"', $page['body']);
    }

    // ---------------------------------------------------------------- staff: OC sent to buyer, inspection result

    public function testOcSentToBuyer(): void
    {
        $original = CompanySettingsRepository::get('mail_sending_enabled');
        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        try {
            $orderId = $this->createTestOrder($this->createTestClient());
            $this->asStaff();
            self::assertSame('Stage 4 has not been unlocked for this order yet — complete Stage 3 first.', $this->call('POST', "/orders/{$orderId}/oc-sent-to-buyer")['flash']);
            for ($stage = 1; $stage <= 3; $stage++) {
                StageGateService::passAndUnlockNext($orderId, $stage, null);
            }
            $this->asStaff();
            $noOc = $this->call('POST', "/orders/{$orderId}/oc-sent-to-buyer");
            self::assertSame('error', $noOc['type']);
            self::assertStringContainsString('still outstanding: Order Confirmation', (string) $noOc['flash']);
            self::assertNull(OrderOcAcknowledgmentRepository::find($orderId), 'no clock started without an approved OC');
            $docId = $this->createTestDocument($orderId, 'OC', 'approved');
            EmailDispatchService::markSentIfMailDisabled($docId);
            $this->asStaff();
            $ok = $this->call('POST', "/orders/{$orderId}/oc-sent-to-buyer");
            self::assertSame('success', $ok['type'], (string) $ok['flash']);
            self::assertStringStartsWith('Order Confirmation marked as sent to the buyer.', (string) $ok['flash']);
            self::assertNotNull(OrderOcAcknowledgmentRepository::find($orderId));
            self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action_type = 'OC_MARKED_SENT_TO_BUYER' AND entity_id = {$orderId}"));
            $this->asStaff();
            self::assertSame('This Order Confirmation is already marked as sent to the buyer.', $this->call('POST', "/orders/{$orderId}/oc-sent-to-buyer")['flash']);
        } finally {
            CompanySettingsRepository::set('mail_sending_enabled', $original, null);
        }
    }

    public function testInspectionResult(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 6; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        $count = fn (): int => (int) $this->value("SELECT COUNT(*) FROM order_inspections WHERE order_id = {$orderId}");
        $this->asStaff();
        self::assertSame('Choose whether the inspection passed or failed.', $this->call('POST', "/orders/{$orderId}/inspection-result", ['inspection_result' => 'maybe', 'inspected_on' => '2026-10-30', 'agency_name' => 'SGS India'])['flash']);
        $this->asStaff();
        self::assertSame('Enter the date of the inspection (today or earlier).', $this->call('POST', "/orders/{$orderId}/inspection-result", ['inspection_result' => 'passed', 'inspected_on' => '2099-01-01', 'agency_name' => 'SGS India'])['flash']);
        $this->asStaff();
        self::assertSame('Enter the inspection agency (2 to 150 characters).', $this->call('POST', "/orders/{$orderId}/inspection-result", ['inspection_result' => 'passed', 'inspected_on' => '2026-10-30', 'agency_name' => 'S'])['flash']);
        $this->asStaff();
        self::assertSame('Attach the inspection report (PDF or image).', $this->call('POST', "/orders/{$orderId}/inspection-result", ['inspection_result' => 'passed', 'inspected_on' => '2026-10-30', 'agency_name' => 'SGS India'])['flash']);
        self::assertSame(0, $count(), 'nothing recorded on a refusal');

        $other = $this->createTestOrder($this->createTestClient());
        Database::connection()->exec("UPDATE orders SET status = 'lost' WHERE id = {$other}");
        $this->asStaff();
        self::assertSame('The inspection can be recorded only on an active order.', $this->call('POST', "/orders/{$other}/inspection-result", ['inspection_result' => 'passed', 'inspected_on' => '2026-10-30', 'agency_name' => 'SGS India'])['flash']);
    }
}
