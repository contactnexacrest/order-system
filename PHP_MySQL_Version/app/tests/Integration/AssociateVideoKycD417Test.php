<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociateRepository;
use App\Services\AssociateCodeService;
use App\Services\AssociateKyc;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions of 25 Nov 2026:
 *  - D-417: a live video KYC item — staff record that they saw the associate on a live video call holding the ID
 *    proof and the address proof clearly (date, who, what was seen); existing associates get 60 days
 *    (video_kyc_due_on) before payouts wait for it.
 *  - D-418: the associate's bank form says why we ask for bank details.
 * Node twin: associateVideoKycD417.test.js.
 */
final class AssociateVideoKycD417Test extends DbTestCase
{
    private static ?Router $router = null;
    private int $staff;
    private string $tag;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__d417-routes-build__';
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
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    private function associate(string $basedIn = 'india'): array
    {
        $id = AssociateRepository::create(['name' => 'D417 Associate ' . $this->tag . ' ' . bin2hex(random_bytes(2)), 'based_in' => $basedIn, 'currency_code' => $basedIn === 'india' ? 'INR' : 'USD',
            'email' => 'associate-' . bin2hex(random_bytes(4)) . '@email.com', 'status' => 'active', 'portal_enabled' => 1], $this->staff);
        Database::connection()->exec("UPDATE associates SET portal_enabled = 1 WHERE id = {$id}");
        AssociateCodeService::issue($id, 'V' . $this->tag . substr((string) $id, -2));
        return AssociateRepository::find($id);
    }

    /** @return array{body: string, flash: ?string} */
    private function call(string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
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
        $all = Flash::pull();
        return ['body' => $body, 'flash' => (end($all) ?: [])['message'] ?? null];
    }

    public function testVideoKycIsARequiredItemInIndiaAndAbroad(): void
    {
        foreach (['india', 'abroad'] as $where) {
            $keys = array_column(AssociateKyc::requiredItems(['based_in' => $where]), 'key');
            self::assertContains('video', $keys, $where);
        }
        $a = $this->associate();
        $item = array_values(array_filter(AssociateKyc::checklist($a), static fn (array $i): bool => $i['key'] === 'video'))[0];
        self::assertSame('missing', $item['status']);
        self::assertSame('Not done yet', $item['status_label']);
        self::assertContains('Live video KYC (ID proof and address proof clearly visible)', AssociateKyc::missing($a));
    }

    public function testStaffRecordItWithADateAndWhatWasSeen(): void
    {
        $a = $this->associate();
        self::assertSame('Enter the date of the video call (today or earlier).', AssociateKyc::recordVideo($a, '2099-01-01', 'Passport and electricity bill shown', $this->staff));
        self::assertStringStartsWith('Say what was seen on the call', (string) AssociateKyc::recordVideo($a, '2026-11-20', 'ok', $this->staff));
        self::assertNull(AssociateKyc::recordVideo($a, '2026-11-20', 'Passport and electricity bill shown; name and address match', $this->staff));

        $a = AssociateRepository::find((int) $a['id']);
        $item = array_values(array_filter(AssociateKyc::checklist($a), static fn (array $i): bool => $i['key'] === 'video'))[0];
        self::assertSame('accepted', $item['status']);
        self::assertSame('2026-11-20', $item['video']['on']);
        self::assertSame($this->staff, $item['video']['by']);
        self::assertNotContains(AssociateKyc::VIDEO_LABEL, AssociateKyc::missing($a));
        $audit = Database::connection()->query("SELECT action_type FROM audit_log WHERE entity_type = 'associates' AND entity_id = {$a['id']} AND action_type = 'ASSOCIATE_VIDEO_KYC_RECORDED'")->fetchAll();
        self::assertCount(1, $audit);
    }

    public function testExistingAssociatesGet60DaysBeforePayoutsWait(): void
    {
        $a = $this->associate();
        $this->completeAssociateKyc((int) $a['id']);
        Database::connection()->exec("UPDATE associates SET video_kyc_at = NULL, video_kyc_note = NULL WHERE id = {$a['id']}");
        $a = AssociateRepository::find((int) $a['id']);
        self::assertSame([AssociateKyc::VIDEO_LABEL], AssociateKyc::missingForPayout($a, '2026-11-25'), 'without a grace date the payout waits');

        Database::connection()->exec("UPDATE associates SET video_kyc_due_on = '2027-01-24' WHERE id = {$a['id']}");
        $a = AssociateRepository::find((int) $a['id']);
        self::assertSame([], AssociateKyc::missingForPayout($a, '2027-01-24'), 'within the 60 days payouts are not held');
        self::assertSame([AssociateKyc::VIDEO_LABEL], AssociateKyc::missingForPayout($a, '2027-01-25'));
        self::assertSame('Live video KYC needed by 24 Jan 2027 — after that, commission payouts wait until it is done.', AssociateKyc::videoGraceNote($a, '2026-11-25'));
    }

    public function testTheStaffPageHasTheFormAndThePortalExplainsBoth(): void
    {
        $a = $this->associate();
        $_SESSION = ['_auth_user_id' => $this->staff, '_session_version' => AuthService::sessionVersion() ?? 0];
        $page = $this->call('GET', '/associates/' . $a['id'])['body'];
        self::assertStringContainsString('data-video-kyc-form', $page);
        self::assertStringContainsString('Live video KYC (ID proof and address proof clearly visible)', $page);

        $r = $this->call('POST', '/associates/' . $a['id'] . '/video-kyc', ['seen_on' => date('Y-m-d'), 'note' => 'Passport and bank statement shown clearly']);
        self::assertSame('Live video KYC recorded.', $r['flash']);
        self::assertStringContainsString('Passport and bank statement shown clearly', $this->call('GET', '/associates/' . $a['id'])['body']);

        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
        $portal = $this->call('GET', '/associate/profile')['body'];
        self::assertStringContainsString('data-bank-why', $portal, 'D-418');
        self::assertStringContainsString('so nobody can redirect your money', $portal);
        self::assertStringContainsString('shown everywhere else only as its last 4 digits', $portal);
        self::assertStringContainsString('Done on ', $portal);
    }

    public function testTheVideoNoteNeedsManageAssociates(): void
    {
        $a = $this->associate();
        $clerk = $this->createTestUser('Export Executive');
        $_SESSION = ['_auth_user_id' => $clerk, '_session_version' => AuthService::sessionVersion() ?? 0];
        $this->call('POST', '/associates/' . $a['id'] . '/video-kyc', ['seen_on' => date('Y-m-d'), 'note' => 'Passport and bank statement shown clearly']);
        self::assertNull(AssociateRepository::find((int) $a['id'])['video_kyc_at']);
    }
}
