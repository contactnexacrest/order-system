<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\CompanySettingsRepository;
use App\Services\DailyDigestService;
use App\Services\SelfApprovalRules;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 8, part 2 (1 Nov 2026): the number of original BL copies
 * (R8-N13), the reviewer list leaving out the document's own generator
 * (R8-N06) and the daily digest counting only what really left (R8-N02).
 * The form label / "*" / "Choose…" / min checks (R8-N08) are in
 * FormLabelConventionsTest. The Node suite has the same test
 * (qaRound8Part2.test.js).
 */
final class QaRound8Part2Test extends DbTestCase
{
    private const BL_COUNT_MESSAGE = 'Enter how many original BL copies were received (1 to 10).';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
    }

    private function blOrder(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 8; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        return $orderId;
    }

    private function recordBlOriginals(int $orderId, string $count): void
    {
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        $_POST = ['bl_originals_count' => $count];
        ob_start();
        (new OrderController())->recordBlOriginalsReceived(['id' => (string) $orderId]);
        ob_end_clean();
    }

    private function savedBlCount(int $orderId): ?int
    {
        $v = Database::connection()->query("SELECT bl_originals_received_count FROM order_shipping WHERE order_id = {$orderId}")->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    }

    /** R8-N13: blank, 0, negative and over 10 were saved as 3 or as typed. */
    public function testOriginalBlCountMustBeAWholeNumberFromOneToTen(): void
    {
        foreach (['', '0', '-2', '11', '2.5', 'abc'] as $bad) {
            $orderId = $this->blOrder();
            $this->recordBlOriginals($orderId, $bad);
            self::assertSame([['type' => 'error', 'message' => self::BL_COUNT_MESSAGE]], $_SESSION['_flash'] ?? null, "count '{$bad}'");
            self::assertNull($this->savedBlCount($orderId), "count '{$bad}' must not be saved");
        }

        $orderId = $this->blOrder();
        $this->recordBlOriginals($orderId, '3');
        self::assertSame('success', $_SESSION['_flash'][0]['type'] ?? null);
        self::assertSame(3, $this->savedBlCount($orderId));
        $this->recordBlOriginals($orderId, '10');
        self::assertSame(10, $this->savedBlCount($orderId));
    }

    public function testTheBlCountFieldIsRequiredOneToTenInTheBrowser(): void
    {
        $view = (string) file_get_contents(__DIR__ . '/../../views/orders/show.php');
        self::assertStringContainsString('<label>No. of Original BL Copies Received *<input type="number" name="bl_originals_count" value="3" required min="1" max="10" step="1" placeholder="e.g. 3"></label>', $view);
    }

    /** The options of the "Assign reviewer(s)" list for one document, as shown on the order page. */
    private function reviewerOptions(int $orderId, int $documentId, int $viewer): array
    {
        $_SESSION = ['_auth_user_id' => $viewer];
        ob_start();
        http_response_code(200);
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        $form = strpos($html, 'action="/documents/' . $documentId . '/reviewers"');
        self::assertNotFalse($form, 'the Assign reviewers form is shown');
        $select = substr($html, $form, (int) strpos($html, '</select>', $form) - $form);
        preg_match_all('/<option value="(\d+)">([^<]*)<\/option>/', $select, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $o) {
            $out[(int) $o[1]] = html_entity_decode($o[2], ENT_QUOTES);
        }
        return $out;
    }

    /** R8-N06: the reviewer list offered the document's own generator. */
    public function testTheGeneratorIsOfferedAsReviewerOnlyWhenSelfReviewIsAllowed(): void
    {
        $pdo = Database::connection();
        $admin = $this->createTestUser('Admin');
        $generator = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        $orderId = $this->createTestOrder($this->createTestClient());
        $doc = $this->createTestDocument($orderId, 'QT', 'draft');
        $pdo->prepare('UPDATE documents SET generated_by = :g WHERE id = :d')->execute(['g' => $generator, 'd' => $doc]);

        // Default rules (MD + ED): an Export Executive may not review their own document.
        $options = $this->reviewerOptions($orderId, $doc, $admin);
        self::assertArrayNotHasKey($generator, $options);
        self::assertArrayHasKey($md, $options);
        self::assertStringNotContainsString('self-review', $options[$md]);

        // An MD who generated it is offered, labelled as self-review.
        $pdo->prepare('UPDATE documents SET generated_by = :g WHERE id = :d')->execute(['g' => $md, 'd' => $doc]);
        $options = $this->reviewerOptions($orderId, $doc, $admin);
        self::assertSame('PHPUnit Test User (Managing Director) (generated it — self-review)', $options[$md]);

        // Admin → Self-approval rules widened to Export Executive: now offered too.
        $pdo->prepare('UPDATE documents SET generated_by = :g WHERE id = :d')->execute(['g' => $generator, 'd' => $doc]);
        $before = array_map('intval', $pdo->query("SELECT role_id FROM self_approval_rules WHERE rule_key = 'document_review'")->fetchAll(\PDO::FETCH_COLUMN));
        $exportExec = (int) $pdo->query("SELECT id FROM roles WHERE name = 'Export Executive'")->fetchColumn();
        SelfApprovalRules::set('document_review', array_merge($before, [$exportExec]));
        try {
            $options = $this->reviewerOptions($orderId, $doc, $admin);
        } finally {
            SelfApprovalRules::set('document_review', $before);
        }
        self::assertSame('PHPUnit Test User (Export Executive) (generated it — self-review)', $options[$generator]);
    }

    /** A person with a document waiting for their review, so the digest has something to send them. */
    private function personWithAReview(): int
    {
        $pdo = Database::connection();
        $person = $this->createTestUser('Procurement Manager');
        $doc = $this->createTestDocument($this->createTestOrder($this->createTestClient()), 'QT', 'draft');
        \App\Repositories\DocumentReviewRepository::assign($doc, $person);
        return $person;
    }

    private function digestRows(int $userId, string $date): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM daily_digest_log WHERE user_id = {$userId} AND digest_date = '{$date}'")->fetchColumn();
    }

    /** R8-N02: with mail off the digest said "sent" and used up the day. */
    public function testWithMailSendingOffTheDigestSendsNothingAndKeepsTheDay(): void
    {
        $person = $this->personWithAReview();
        $date = '2098-02-' . sprintf('%02d', random_int(10, 28));
        $before = CompanySettingsRepository::get('email_testing_mode');
        \App\Services\EmailPolicy::setSwitchForTest('staff_daily_summary', 'staff', false); // D-280: this e-mail switched off in Admin › E-mail settings
        try {
            DailyDigestService::setSender(null);
            $result = DailyDigestService::run($date);
        } finally {
            CompanySettingsRepository::set('email_testing_mode', $before);
            \App\Services\EmailPolicy::setSwitchForTest('staff_daily_summary', 'staff', true);
        }
        self::assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0, 'mail_off' => true], $result);
        self::assertSame('Daily digest: the daily summary e-mail is switched off in Admin › E-mail settings — nothing sent.', DailyDigestService::summary($result));
        self::assertSame(0, $this->digestRows($person, $date));
    }

    /** R8-N02: a failed send was counted as sent and never retried. */
    public function testAFailedDigestIsNotCountedAndIsRetriedOnTheNextRun(): void
    {
        $person = $this->personWithAReview();
        $date = '2098-03-' . sprintf('%02d', random_int(10, 28));
        $to = [];
        DailyDigestService::setSender(static function (string $email) use (&$to): bool { $to[] = $email; return false; });
        try {
            $failed = DailyDigestService::run($date);
        } finally {
            DailyDigestService::setSender(null);
        }
        self::assertSame(0, $failed['sent']);
        self::assertSame(count($to), $failed['failed']);
        self::assertGreaterThan(0, $failed['failed']);
        self::assertSame(0, $this->digestRows($person, $date), 'a failed send does not use up the day');
        self::assertStringContainsString(", {$failed['failed']} not sent (will be retried on the next run).", DailyDigestService::summary($failed));

        // The next run sends it, once; a third run the same day sends nothing.
        $sent = 0;
        DailyDigestService::setSender(static function () use (&$sent): bool { $sent++; return true; });
        try {
            $retry = DailyDigestService::run($date);
            $again = DailyDigestService::run($date);
        } finally {
            DailyDigestService::setSender(null);
        }
        self::assertSame($retry['sent'], $sent);
        self::assertGreaterThan(0, $retry['sent']);
        self::assertSame(0, $again['sent']);
        self::assertSame(1, $this->digestRows($person, $date));
        self::assertSame("Daily digest: {$retry['sent']} sent, {$retry['skipped']} with nothing waiting or already sent.", DailyDigestService::summary($retry));
    }
}
