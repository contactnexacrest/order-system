<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociateAdminController;
use App\Controllers\AssociatePortalController;
use App\Controllers\ClientIntakeController;
use App\Helpers\Flash;
use App\Middleware\AssociateAuth;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociateEventRepository;
use App\Repositories\AssociateInvitationRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\GstRefundRepository;
use App\Repositories\IncentiveRepository;
use App\Services\AssociateCodeService;
use App\Services\AssociateIntakeService;
use App\Services\AssociatePortalService;
use App\Services\AssociateService;
use App\Services\IncentiveService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 8, part 1 (1 Nov 2026): money saved twice at once (R8-N11 GST
 * refund credits, R8-N12 associate claw-backs), the commission CSV
 * (R8-N03), one-off invitation links (R8-N04), the associate's client
 * numbering (R8-N05, AS-17), a suspended associate's read-only portal
 * (Q-R8-2, AS-3) and the bank e-mails (R8-N01). The Node suite has the
 * same test (qaRound8Part1.test.js).
 */
final class QaRound8Part1Test extends DbTestCase
{
    private int $admin;
    private int $md;
    private int $finance;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $_SESSION = [];
        $this->tag = bin2hex(random_bytes(3));
        $this->admin = $this->createTestUser('Admin');
        $this->md = $this->createTestUser('Managing Director');
        $this->finance = $this->createTestUser('Finance Manager');
        $this->as($this->admin);
        foreach (['associate_rate_1_5' => '0.70', 'associate_rate_6_10' => '1.00', 'associate_rate_11_20' => '1.50', 'associate_rate_21_40' => '1.75',
            'associate_rate_41_plus' => '2.00', 'associate_bank_wait_days' => '7', 'associate_protection_months' => '12', 'associate_tds_pct' => '2.00', 'cutoff_day' => '20'] as $k => $v) {
            IncentiveRepository::setSetting($k, $v, $this->admin);
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $_SESSION = [];
        Flash::pull();
        parent::tearDown();
    }

    private function as(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
    }

    private function associate(array $fields = []): array
    {
        $id = AssociateRepository::create($fields + ['name' => 'R8 Assoc ' . $this->tag . ' ' . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'r8-' . bin2hex(random_bytes(4)) . '@assoc-' . $this->tag . '.test', 'status' => 'active'], $this->admin);
        AssociateCodeService::issue($id, 'Q' . strtoupper($this->tag) . bin2hex(random_bytes(1)));
        return AssociateRepository::find($id);
    }

    private function client(string $name): int
    {
        $id = $this->createTestClient();
        Database::connection()->prepare('UPDATE clients SET company_legal_name = :n, vat_eori_tax_no = :v, email = :e WHERE id = :id')
            ->execute(['n' => $name, 'v' => 'VAT' . strtoupper(bin2hex(random_bytes(4))), 'e' => 'buyer@' . bin2hex(random_bytes(4)) . '.example', 'id' => $id]);
        return $id;
    }

    /** A USD 4,500 order of the client, paid in full, with the associate's rate fixed as at creation. */
    private function order(int $clientId): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->admin]);
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM orders WHERE client_id = ' . $clientId)->fetchColumn();
        $pdo->prepare('UPDATE orders SET client_id = :c, sequence_no = :n WHERE id = :o')->execute(['c' => $clientId, 'n' => $next, 'o' => $orderId]);
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = :a, advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
            balance_amount = 2700, balance_cleared_at = :b, balance_inr_actual = 250000, balance_exchange_rate = 92.5926 WHERE order_id = :o')
            ->execute(['a' => '2026-10-10 10:00:00', 'b' => '2026-10-25 11:00:00', 'o' => $orderId]);
        AssociateService::onOrderCreated($orderId);
        return $orderId;
    }

    private function flash(): array
    {
        return Flash::pull()[0] ?? ['type' => '', 'message' => ''];
    }

    private function render(callable $call): string
    {
        ob_start();
        try {
            $call();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    /** @return list<string> what each worker printed */
    private function runWorkers(int $count, array $args): array
    {
        $script = __DIR__ . '/../Support/qaRound8Worker.php';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processes = [];
        for ($i = 0; $i < $count; $i++) {
            $proc = proc_open(array_merge(['php', $script], $args), $descriptors, $pipes);
            self::assertIsResource($proc);
            fclose($pipes[0]);
            $processes[] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }
        $out = [];
        foreach ($processes as $p) {
            $stdout = stream_get_contents($p['stdout']);
            $stderr = stream_get_contents($p['stderr']);
            fclose($p['stdout']);
            fclose($p['stderr']);
            self::assertSame(0, proc_close($p['proc']), "worker failed: {$stderr}");
            $out[] = trim((string) $stdout);
        }
        return $out;
    }

    private function lastEvent(int $associateId, string $event): string
    {
        $stmt = Database::connection()->prepare('SELECT message FROM associate_events WHERE associate_id = :a AND event = :e ORDER BY id DESC LIMIT 1');
        $stmt->execute(['a' => $associateId, 'e' => $event]);
        return (string) $stmt->fetchColumn();
    }

    private function bank(array $a, string $number, bool $verify): int
    {
        $id = AssociateBankRepository::request((int) $a['id'], ['holder_name' => $a['name'], 'account_number' => $number, 'ifsc' => 'HDFC0001234', 'swift' => '',
            'bank_name' => 'HDFC Bank', 'bank_branch_address' => '', 'intermediary_bank' => '', 'account_currency' => 'INR'], true, null);
        if ($verify) {
            AssociateBankRepository::firstCheck($id, $this->md, 'Called back on the known number; ₹1 test credit confirmed.');
            AssociateBankRepository::verify($id, $this->finance, 0);
        }
        return $id;
    }

    // ---------------------------------------------------------------- R8-N11, R8-N12: one save at a time

    public function testEightGstRefundCreditsSentAtOnceNeverCreditMoreThanTheClaim(): void
    {
        $claimId = GstRefundRepository::createClaim('2026-04-01', '2026-06-30', 'AA' . strtoupper(bin2hex(random_bytes(6))) . 'R', '2026-07-10', 1000, $this->admin);
        $out = $this->runWorkers(8, ['gst_credit', (string) $claimId, '600', (string) $this->admin]);
        self::assertSame(1, count(array_filter($out, static fn (string $o): bool => $o === 'saved')), implode(',', $out));
        self::assertSame(7, count(array_filter($out, static fn (string $o): bool => $o === 'refused')));
        self::assertCount(1, GstRefundRepository::creditsFor($claimId));
        self::assertSame(600.0, array_sum(array_map(static fn (array $c): float => (float) $c['amount'], GstRefundRepository::creditsFor($claimId))));

        // The form says what is still open and saves nothing.
        $this->as($this->admin);
        $_POST = ['amount' => '600', 'refused_amount' => '', 'credited_on' => date('Y-m-d'), 'bank_reference' => 'UTR' . strtoupper($this->tag), 'part' => 'final'];
        (new \App\Controllers\GstRefundController())->recordCredit(['id' => (string) $claimId]);
        self::assertSame('Credited plus refused (₹600.00) is more than what is still open on this claim (₹400.00). Nothing was saved.', $this->flash()['message']);
        self::assertCount(1, GstRefundRepository::creditsFor($claimId));
    }

    public function testSimultaneousClawBacksNeverTakeBackMoreThanWasPaid(): void
    {
        $a = $this->associate();
        $c = $this->client('R8 Claw Buyer ' . $this->tag);
        AssociateService::linkClient($c, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        $o = $this->order($c);
        $pid = IncentiveRepository::addPayout(['order_id' => $o, 'payee_kind' => 'associate', 'associate_id' => $a['id'], 'role' => 'associate',
            'amount_inr' => 1000, 'amount_fc' => null, 'currency_code' => 'INR', 'incentive_month' => '2026-10']);
        Database::connection()->prepare("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-10-30', paid_inr = 1000, tds_inr = 0 WHERE id = :id")->execute(['id' => $pid]);

        $out = $this->runWorkers(8, ['clawback', (string) $pid, '600']);
        self::assertSame(1, count(array_filter($out, static fn (string $o): bool => $o === 'saved')), implode(',', $out));
        $total = (float) Database::connection()->query("SELECT -SUM(amount_inr) FROM incentive_payouts WHERE clawback_of_payout_id = {$pid}")->fetchColumn();
        self::assertSame(600.0, $total);

        $this->as($this->admin);
        $_POST = ['amount_inr' => '600', 'reason' => 'Buyer refunded part of the order.'];
        (new AssociateAdminController())->clawback(['payoutId' => (string) $pid]);
        self::assertSame('Enter the amount to claw back, above 0 and at most what is left of the paid commission (₹400.00).', $this->flash()['message']);
        $_POST = ['amount_inr' => '400', 'reason' => 'Buyer refunded the rest of the order.'];
        (new AssociateAdminController())->clawback(['payoutId' => (string) $pid]);
        self::assertSame('success', $this->flash()['type']);
        self::assertSame(1000.0, (float) Database::connection()->query("SELECT -SUM(amount_inr) FROM incentive_payouts WHERE clawback_of_payout_id = {$pid}")->fetchColumn());
    }

    // ---------------------------------------------------------------- R8-N03: the portal's commission CSV

    public function testTheCommissionCsvNeutralisesAFormulaInACompanyName(): void
    {
        $a = $this->associate();
        $c = $this->client('=HYPERLINK("http://evil.example","Click")');
        AssociateService::linkClient($c, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        $o = $this->order($c);
        IncentiveRepository::addPayout(['order_id' => $o, 'payee_kind' => 'associate', 'associate_id' => $a['id'], 'role' => 'associate',
            'amount_inr' => 2500, 'amount_fc' => null, 'currency_code' => 'INR', 'incentive_month' => '2026-10']);
        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
        $_GET = ['format' => 'csv'];
        $csv = $this->render(fn () => (new AssociatePortalController())->commission([]));
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM for Excel');
        self::assertStringContainsString("\"'=HYPERLINK(\"\"http://evil.example\"\",\"\"Click\"\")\"", $csv, 'the formula is written as text');
        self::assertStringNotContainsString(',"=HYPERLINK', $csv);
        self::assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    // ---------------------------------------------------------------- R8-N04: a one-off link registers one company

    private function intakePost(string $company, string $token): array
    {
        return ['company_legal_name' => $company, 'billing_address_line1' => '1 Harbour Road', 'billing_city' => 'Rotterdam', 'billing_country' => 'Netherlands',
            'vat_eori_tax_no' => 'NL' . strtoupper(bin2hex(random_bytes(5))), 'contact_person' => 'Jan Buyer', 'email' => 'jan@' . bin2hex(random_bytes(4)) . '-buyer.example',
            'country_of_destination' => 'Netherlands', 'incoterm_preference' => 'FOB', 'consignee_same_as_buyer' => '1', 'website' => '', 'associate_inv' => $token];
    }

    private function submissionsFor(int $associateId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM client_intake_submissions WHERE associate_id = :a ORDER BY id');
        $stmt->execute(['a' => $associateId]);
        return $stmt->fetchAll();
    }

    public function testTwoRegistrationsThroughOneLinkOnlyTheFirstIsTaken(): void
    {
        $a = $this->associate();
        $inv = AssociateInvitationRepository::createLink((int) $a['id'], 'Link One BV ' . $this->tag, 'Netherlands');
        foreach (['Link One BV ' . $this->tag, 'Someone Else GmbH ' . $this->tag] as $company) {
            $_SERVER['REMOTE_ADDR'] = '10.8.' . random_int(0, 250) . '.' . random_int(1, 250);
            $_POST = $this->intakePost($company, $inv['token']);
            $_SESSION = [];
            $this->render(fn () => (new ClientIntakeController())->submit([]));
        }
        self::assertSame(AssociateIntakeService::LINK_USED, $this->flash()['message']);
        $subs = $this->submissionsFor((int) $a['id']);
        self::assertCount(1, $subs, 'the second registration through the same link is not saved');
        self::assertSame('Link One BV ' . $this->tag, $subs[0]['company_legal_name']);
        self::assertSame((int) $subs[0]['id'], (int) AssociateInvitationRepository::find($inv['id'])['submission_id']);
        $_GET = ['inv' => $inv['token']];
        $_POST = [];
        self::assertStringContainsString('has expired or was already used', $this->render(fn () => (new ClientIntakeController())->show([])));

        // At the same moment: the claim is atomic — only one submission takes the link.
        $inv2 = AssociateInvitationRepository::createLink((int) $a['id'], 'Race BV ' . $this->tag, 'Netherlands');
        self::assertTrue(AssociateInvitationRepository::claim((int) $inv2['id'], (int) $subs[0]['id']));
        self::assertFalse(AssociateInvitationRepository::claim((int) $inv2['id'], (int) $subs[0]['id'] + 1));
    }

    public function testAcceptingLinksOnlyTheSubmissionRecordedOnTheLinkAndStaffSeeANameMismatch(): void
    {
        $a = $this->associate();
        $inv = AssociateInvitationRepository::createLink((int) $a['id'], 'Named Buyer BV ' . $this->tag, 'Netherlands');
        $_SERVER['REMOTE_ADDR'] = '10.7.' . random_int(0, 250) . '.' . random_int(1, 250);
        $_POST = $this->intakePost('Different Trading LLC ' . $this->tag, $inv['token']);
        $_SESSION = [];
        $this->render(fn () => (new ClientIntakeController())->submit([]));
        $s = $this->submissionsFor((int) $a['id'])[0];
        $info = AssociateIntakeService::reviewInfo($s);
        self::assertContains("The associate made this link for Named Buyer BV {$this->tag}, but the buyer registered as Different Trading LLC {$this->tag}.", $info['flags']);

        // A submission that carries the link but is not the one recorded on it (an older copy) is not linked.
        $other = $s;
        $other['id'] = (int) $s['id'] + 100000;
        $other['introduction_confirmed'] = 1;
        $client = $this->client('Other Copy ' . $this->tag);
        self::assertSame('Not linked to the associate: their invitation link was used by another registration.', AssociateIntakeService::onAccepted($other, $client, $this->admin));
        self::assertNull(Database::connection()->query("SELECT associate_id FROM clients WHERE id = {$client}")->fetchColumn() ?: null);
    }

    // ---------------------------------------------------------------- R8-N05: client no. N, in the order they were brought

    public function testClientsAreNumberedWhenLinkedAndOrdersTakeTheRateForThatNumber(): void
    {
        $a = $this->associate();
        $rates = [];
        $numbers = [];
        for ($i = 1; $i <= 7; $i++) {
            $c = $this->client("R8 Numbered {$i} {$this->tag}");
            AssociateService::linkClient($c, (int) $a['id'], 'code', null, '2026-10-0' . $i, $this->admin);
            $o = $this->order($c);
            $row = Database::connection()->query("SELECT associate_client_number, associate_rate_pct, associate_rate_source FROM orders WHERE id = {$o}")->fetch();
            $numbers[] = (int) $row['associate_client_number'];
            $rates[] = $row['associate_rate_pct'];
            self::assertSame('global', $row['associate_rate_source']);
        }
        self::assertSame([1, 2, 3, 4, 5, 6, 7], $numbers, 'numbered when linked, not when paid');
        self::assertSame(['0.70', '0.70', '0.70', '0.70', '0.70', '1.00', '1.00'], $rates);
        $calc = IncentiveService::calculate($o);
        self::assertSame(7, $calc['associate']['order_number']);

        // A second order of client 3 keeps number 3; re-linking the same associate never renumbers.
        $c3 = (int) Database::connection()->query("SELECT id FROM clients WHERE company_legal_name = 'R8 Numbered 3 {$this->tag}'")->fetchColumn();
        AssociateService::linkClient($c3, (int) $a['id'], 'staff', null, '2026-10-03', $this->admin);
        self::assertSame(3, AssociateRepository::orderNumberForClient((int) $a['id'], $c3));
        $o3b = $this->order($c3);
        self::assertSame('3', (string) Database::connection()->query("SELECT associate_client_number FROM orders WHERE id = {$o3b}")->fetchColumn());

        // The MD moves client 3 to another associate: it becomes that associate's next client.
        $b = $this->associate();
        $bFirst = $this->client('R8 B First ' . $this->tag);
        AssociateService::linkClient($bFirst, (int) $b['id'], 'code', null, '2026-10-01', $this->admin);
        $this->as($this->md);
        $_POST = ['reason' => 'The buyer says another agent introduced them.', 'move_to' => (string) $b['id']];
        (new AssociateAdminController())->unlinkClient(['id' => (string) $a['id'], 'clientId' => (string) $c3]);
        self::assertSame('success', $this->flash()['type']);
        $moved = Database::connection()->query("SELECT associate_id, associate_client_number FROM clients WHERE id = {$c3}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([(int) $b['id'], 2], [(int) $moved['associate_id'], (int) $moved['associate_client_number']]);
        // Removing the link clears the number; the old associate's other clients keep theirs.
        $_POST = ['reason' => 'The agreement with this buyer was cancelled.', 'move_to' => ''];
        (new AssociateAdminController())->unlinkClient(['id' => (string) $b['id'], 'clientId' => (string) $c3]);
        self::assertNull(Database::connection()->query("SELECT associate_client_number FROM clients WHERE id = {$c3}")->fetchColumn());
        self::assertSame('7', (string) Database::connection()->query("SELECT associate_client_number FROM clients WHERE company_legal_name = 'R8 Numbered 7 {$this->tag}'")->fetchColumn());
    }

    public function testTwoLinksAtOnceNeverShareANumber(): void
    {
        $a = $this->associate();
        $c1 = $this->client('R8 Twin One ' . $this->tag);
        $c2 = $this->client('R8 Twin Two ' . $this->tag);
        AssociateRepository::linkClient($c1, (int) $a['id'], '2026-10-01');
        AssociateRepository::linkClient($c2, (int) $a['id'], '2026-10-01');
        $n = Database::connection()->query("SELECT associate_client_number FROM clients WHERE id IN ({$c1}, {$c2}) ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['1', '2'], array_map('strval', $n));
    }

    public function testTheMigrationNumbersExistingClientsByRegistrationThenIdAndFixesOpenOrders(): void
    {
        $a = $this->associate(['rate_1_5' => '1.25']);
        $pdo = Database::connection();
        $ids = [];
        foreach (['2026-10-05', '2026-10-01', '2026-10-01', '2026-09-20'] as $i => $date) {
            $ids[$i] = $this->client("R8 Backfill {$i} {$this->tag}");
            AssociateService::linkClient($ids[$i], (int) $a['id'], 'code', null, $date, $this->admin);
        }
        $open = $this->order($ids[0]);
        $locked = $this->order($ids[1]);
        // As the old code left them: no client numbers; every order "client no. 1".
        $pdo->exec("UPDATE clients SET associate_client_number = NULL WHERE associate_id = {$a['id']}");
        $pdo->exec("UPDATE orders SET associate_client_number = 1, associate_rate_pct = 0.70, associate_rate_source = 'global' WHERE id IN ({$open}, {$locked})");
        $pdo->exec("INSERT INTO order_incentives (order_id, locked_at, associate_id, associate_order_number) VALUES ({$locked}, NOW(), {$a['id']}, 1)");
        $pdo->exec("UPDATE associates SET rate_1_5 = NULL, rate_6_10 = NULL WHERE id = {$a['id']}");

        $run = new \ReflectionMethod(DbTestCase::class, 'runSqlFile');
        $file = __DIR__ . '/../../../../docs/migrations/2026-11-02g-qa-r8-associates.sql';
        $run->invoke(null, 'nexacrest_phpunit_test', $file);
        $run->invoke(null, 'nexacrest_phpunit_test', $file);

        $numbers = [];
        foreach ($ids as $i => $id) {
            $numbers[$i] = (int) $pdo->query("SELECT associate_client_number FROM clients WHERE id = {$id}")->fetchColumn();
        }
        // 2026-09-20 first, then the two of 2026-10-01 by client id, then 2026-10-05.
        self::assertSame([4, 2, 3, 1], [$numbers[0], $numbers[1], $numbers[2], $numbers[3]]);
        self::assertSame(['4', '0.70', 'global'], array_map('strval', array_values($pdo->query("SELECT associate_client_number, associate_rate_pct, associate_rate_source FROM orders WHERE id = {$open}")->fetch(\PDO::FETCH_ASSOC))));
        self::assertSame('1', (string) $pdo->query("SELECT associate_client_number FROM orders WHERE id = {$locked}")->fetchColumn(), 'a locked order is never changed');
    }

    // ---------------------------------------------------------------- Q-R8-2: a suspended associate's portal is read-only

    public function testASuspendedAssociateSeesEverythingButCannotChangeAnything(): void
    {
        $a = $this->associate(['status' => 'suspended']);
        AssociateRepository::setFields((int) $a['id'], ['portal_enabled' => 1]);
        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::assertTrue((AssociateAuth::required())([]), 'a suspended associate stays logged in');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $portal = new AssociatePortalController();
        foreach (['home', 'clients', 'orders', 'commission', 'profile', 'messages'] as $page) {
            self::assertStringContainsString(htmlspecialchars(AssociatePortalService::SUSPENDED_BANNER), $this->render(fn () => $portal->{$page}([])), "banner on {$page}");
        }
        self::assertSame('Your account is suspended — you can see your clients, orders and commission, but cannot invite clients or change your details.', AssociatePortalService::SUSPENDED_BANNER);

        $inv = AssociateInvitationRepository::createLink((int) $a['id'], 'Before Suspension Ltd', 'UK');
        $before = AssociateRepository::find((int) $a['id']);
        $refused = [
            'createInvitation' => [[], ['company_name' => 'New Buyer Ltd', 'country' => 'UK']],
            'cancelInvitation' => [['id' => (string) $inv['id']], []],
            'chooseCode' => [[], ['readable' => 'NEWCODE']],
            'saveProfile' => [[], ['phone' => '+91 99999 00000', 'address' => 'New address', 'pan' => '', 'gstin' => '']],
            'uploadDocument' => [[], ['kind' => 'pan_card']],
            'requestBank' => [[], ['holder_name' => 'X', 'account_number' => '999988887777', 'ifsc' => 'HDFC0001234', 'bank_name' => 'HDFC Bank']],
        ];
        foreach ($refused as $action => [$params, $post]) {
            $_POST = $post;
            $portal->{$action}($params);
            self::assertSame(['error', AssociatePortalService::SUSPENDED_REFUSED], array_values(array_intersect_key($this->flash(), ['type' => 1, 'message' => 1])), $action);
        }
        self::assertSame('Your account is suspended — you cannot invite clients or change your details. Please contact your NexaCrest relationship manager.', AssociatePortalService::SUSPENDED_REFUSED);
        $after = AssociateRepository::find((int) $a['id']);
        self::assertSame([$before['phone'], $before['personal_code']], [$after['phone'], $after['personal_code']]);
        self::assertCount(1, AssociateInvitationRepository::forAssociate((int) $a['id']));
        self::assertNotSame('cancelled', AssociateInvitationRepository::find($inv['id'])['status']);
        self::assertSame([], AssociateBankRepository::forAssociate((int) $a['id']));

        // Messages stay open: a suspended associate can still ask NexaCrest a question.
        $_POST = ['body' => 'Why is my account suspended?'];
        $portal->sendMessage([]);
        self::assertSame('Message sent.', $this->flash()['message']);

        // An active associate sees no banner.
        $b = $this->associate();
        $_SESSION = ['_associate_portal_id' => (int) $b['id']];
        self::assertStringNotContainsString('Your account is suspended', $this->render(fn () => $portal->home([])));
    }

    // ---------------------------------------------------------------- R8-N01: the bank e-mails

    public function testTheBankRejectionEmailHasOneFullStopAndNamesAVerifiedAccountOnlyWhenThereIsOne(): void
    {
        $a = $this->associate();
        $pending = $this->bank($a, '111122223333', false);
        $this->as($this->finance);
        $_POST = ['reason' => 'Account holder name does not match the PAN.. '];
        (new AssociateAdminController())->rejectBank(['id' => (string) $a['id'], 'bankId' => (string) $pending]);
        self::assertSame('success', $this->flash()['type']);
        self::assertSame('Your new bank details (HDFC Bank ••••3333) were not accepted: Account holder name does not match the PAN. No payment can be made until new bank details are verified.',
            $this->lastEvent((int) $a['id'], 'bank_rejected'));

        $this->bank($a, '444455556666', true);
        $second = $this->bank($a, '777788889999', false);
        $_POST = ['reason' => 'The cancelled cheque is not readable.'];
        (new AssociateAdminController())->rejectBank(['id' => (string) $a['id'], 'bankId' => (string) $second]);
        self::assertSame('Your new bank details (HDFC Bank ••••9999) were not accepted: The cancelled cheque is not readable. Payments keep going to your verified account (••••6666).',
            $this->lastEvent((int) $a['id'], 'bank_rejected'));
    }

    public function testThePaymentsSentenceUsedInTheBankChangeEmails(): void
    {
        $a = $this->associate();
        self::assertSame('No payment can be made until new bank details are verified.', AssociateService::bankPaymentsSentence((int) $a['id']));
        $this->bank($a, '123412341234', false);
        self::assertSame('No payment can be made until new bank details are verified.', AssociateService::bankPaymentsSentence((int) $a['id']), 'a pending request is not a verified account');
        $this->bank($a, '567856785678', true);
        self::assertSame('Payments keep going to your verified account (••••5678).', AssociateService::bankPaymentsSentence((int) $a['id']));
        self::assertSame('Too short', AssociateService::cleanReason(" Too short .. \n"));
    }
}
