<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientIntakeReviewController;
use App\Controllers\ComplaintController;
use App\Controllers\CoordinationController;
use App\Controllers\DisputeController;
use App\Controllers\OrderController;
use App\Controllers\PiIntakeReviewController;
use App\Controllers\PurchasingController;
use App\Controllers\ReorderRequestController;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\DisputeRepository;
use App\Repositories\OrderReorderRequestRepository;
use App\Repositories\PiIntakeRepository;
use App\Repositories\PurchasingRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\OrderComplaintService;
use App\Tests\Support\DbTestCase;

/**
 * Owner hard rule (2026-11-01): the Standard and Kanban templates stay
 * parallel — any user may pick either, so every list page offers the same
 * on its board: every link to a record, every action (form), every filter,
 * and a way to switch to the other view. For each list page
 * this renders the list (?view=list) and the board for the same Kanban user
 * and the same data, and compares them. The Node suite has the same test
 * (kanbanParity.test.js).
 */
final class KanbanParityTest extends DbTestCase
{
    public static function tearDownAfterClass(): void
    {
        self::clearDivisionTradeData(); // leave no division-trade records for later test classes
        parent::tearDownAfterClass();
    }

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($this->userId, (int) UiTemplateRepository::findByKey('kanban')['id']);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function render(object $controller, string $action, array $get): string
    {
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_GET = $get;
        \App\Services\PermissionService::resetCache();
        \App\Services\ApprovalsInbox::resetCache(); // D-310: the Approvals counts are per person
        ob_start();
        $controller->{$action}([]);
        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private static function all(string $pattern, string $html): array
    {
        preg_match_all($pattern, $html, $m);
        return array_values(array_unique($m[1]));
    }

    /** The parts a user acts on: links to records, form actions, filter chips / status options. */
    private static function parts(string $html): array
    {
        $html = preg_replace('#<(header|nav)\b.*?</\1>#s', '', $html) ?? $html; // the site menu is the same either way
        return [
            'records' => self::all('#href="(/(?:orders|clients|reorder-requests|procurement|associates|agreements)/\d+)(?:[?"/])#', $html),
            'actions' => self::all('#<form method="post" action="([^"]+)"#', $html),
            'filters' => array_merge(self::all('#class="filter-chip[^"]*" href="[^"]*">([^<(]+)#', $html), self::all('#<option value="([a-z_]*)"[^>]*>#', $html)),
        ];
    }

        private function seedPiSubmission(): array
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name'             => $submission['company_legal_name'],
            'billing_address'                => $submission['billing_address'],
            'billing_address_line1'          => $submission['billing_address_line1'],
            'billing_address_line2'          => $submission['billing_address_line2'],
            'billing_city'                   => $submission['billing_city'],
            'billing_postcode'               => $submission['billing_postcode'],
            'billing_country'                => $submission['billing_country'],
            'vat_eori_tax_no'                => $submission['vat_eori_tax_no'],
            'contact_person'                 => $submission['contact_person'],
            'email'                          => $submission['email'],
            'phone'                          => $submission['phone'],
            'port_of_discharge_text'         => $submission['port_of_discharge_text'],
            'country_of_destination'         => $submission['country_of_destination'],
            'incoterm_confirmed'             => $submission['incoterm_confirmed'],
            'container_type_text'            => $submission['container_type_text'],
            'payment_terms_confirmation'      => 'CONFIRMED — parity test.',
            'quotation_acceptance_reference'  => 'We accept Quotation X',
            'coo_type'                        => $submission['coo_type'],
            'buyer_po_ref'                    => null,
            'changes_from_quotation'          => null,
            'special_document_requirements'   => null,
            'consignee_same_as_buyer'        => 1,
            'consignee_name' => null, 'consignee_address_line1' => null, 'consignee_address_line2' => null,
            'consignee_city' => null, 'consignee_postcode' => null, 'consignee_country' => null,
            'consignee_vat_eori_tax_no' => null, 'consignee_contact_person' => null,
            'consignee_phone' => null, 'consignee_email' => null,
            'notify_party_same_as_consignee' => 1,
            'notify_party' => null, 'notify_party_address_line1' => null, 'notify_party_address_line2' => null,
            'notify_party_city' => null, 'notify_party_postcode' => null, 'notify_party_country' => null,
            'notify_party_contact_person' => null, 'notify_party_phone' => null, 'notify_party_email' => null,
        ], '127.0.0.1');

        return [(int) $submission['id'], $orderId];
    }

    private function seed(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);
        DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Parity test dispute', null, null);
        OrderComplaintService::raiseAsClient($orderId, $clientId, 'Parity test complaint.');
        OrderReorderRequestRepository::create($clientId, $orderId, 'Parity test reorder.');
        ClientIntakeRepository::create([
            'company_legal_name' => 'Parity Intake Ltd', 'billing_address' => '1 Parity Street', 'billing_address_line1' => null, 'billing_address_line2' => null,
            'billing_city' => null, 'billing_postcode' => null, 'billing_country' => null, 'vat_eori_tax_no' => null, 'contact_person' => 'Chief Buyer',
            'email' => 'parity-' . bin2hex(random_bytes(4)) . '@intake.example', 'phone' => null, 'country_of_destination' => 'Netherlands',
            'port_of_discharge_text' => null, 'coo_type' => null, 'incoterm_preference' => null, 'container_type_text' => null, 'buyer_own_reference' => null, 'notes' => null,
        ], '127.0.0.1');
        $this->seedPiSubmission();
        $associateId = \App\Repositories\AssociateRepository::create(['name' => 'Parity Associate', 'based_in' => 'india', 'currency_code' => 'INR', 'status' => 'active'], $this->userId);
        \App\Services\AssociateService::linkClient($clientId, $associateId, 'staff', null, date('Y-m-d'), $this->userId);
        PurchasingRepository::create($orderId, null, 'Parity test blocks', null, 5.0, 'CBM', date('Y-m-d', strtotime('+5 days')), $this->userId, null);
        // Owner decision 2 Nov 2026 (point 4): the Agreements register.
        \App\Repositories\AgreementRepository::create(['party_type' => 'client', 'party_id' => $clientId, 'agreement_type' => 'Commercial NDA', 'signed_on' => date('Y-m-d', strtotime('-10 days')), 'expires_on' => date('Y-m-d', strtotime('+20 days')), 'file_id' => $this->createTestFile()], $this->userId);
    }

    /** @return list<array{0: string, 1: object, 2: string, 3: array, 4: array, 5: string}> page, controller, action, list query, board query, board path */
    private function pages(): array
    {
        return [
            ['Orders', new OrderController(), 'index', ['view' => 'list', 'status' => 'all'], ['status' => 'all'], '/orders'],
            ['Disputes', new DisputeController(), 'index', ['view' => 'list'], [], '/disputes'],
            ['Complaints', new ComplaintController(), 'index', ['view' => 'list'], [], '/complaints'],
            ['Reorder Requests', new ReorderRequestController(), 'index', ['view' => 'list'], [], '/reorder-requests'],
            ['Quotation Intake', new ClientIntakeReviewController(), 'index', ['view' => 'list'], [], '/client-intake'],
            ['PI Intake', new PiIntakeReviewController(), 'index', ['view' => 'list'], [], '/pi-intake-review'],
            ['Procurement', new PurchasingController(), 'queue', ['view' => 'list'], [], '/procurement'],
            ['My tasks', new CoordinationController(), 'myTasks', ['view' => 'list'], [], '/my-tasks'],
            // Owner decision 1 Nov 2026: the associates register, list and board.
            ['Associates', new \App\Controllers\AssociateAdminController(), 'index', ['view' => 'list'], [], '/associates'],
            // Owner decision 2 Nov 2026 (point 4): the Agreements register, list and board.
            ['Agreements', new \App\Controllers\AgreementController(), 'index', ['view' => 'list'], [], '/agreements'],
            // Owner decision D-310 (point 27): the Approvals page, list and board.
            ['Approvals', new \App\Controllers\ApprovalsController(), 'index', ['view' => 'list'], [], '/approvals'],
        ];
    }

    public function testEveryBoardOffersWhatItsListOffers(): void
    {
        $this->seed();
        foreach ($this->pages() as [$page, $controller, $action, $listQuery, $boardQuery, $path]) {
            $list = $this->render($controller, $action, $listQuery);
            $board = $this->render($controller, $action, $boardQuery);
            self::assertStringNotContainsString('kanban-board', $list, "{$page}: the list view");
            self::assertStringContainsString('kanban-board', $board, "{$page}: the board view");

            $l = self::parts($list);
            $b = self::parts($board);
            self::assertSame([], array_values(array_diff($l['records'], $b['records'])), "{$page}: records linked on the list but not on the board");
            self::assertSame([], array_values(array_diff($l['actions'], $b['actions'])), "{$page}: actions on the list but not on the board");
            self::assertSame([], array_values(array_diff($b['actions'], $l['actions'])), "{$page}: actions on the board but not on the list");
            self::assertSame([], array_values(array_diff(array_map('trim', $l['filters']), array_map('trim', $b['filters']))), "{$page}: filters on the list but not on the board");

            self::assertStringContainsString('Switch to list view', $board, "{$page}: the board switches to the list");
            self::assertStringContainsString('Switch to board view', $list, "{$page}: the list switches back to the board");
            self::assertStringContainsString('href="' . $path, $list, "{$page}: the list links back to the board");
        }
    }

    /**
     * Owner decision D-320 (point 26): the section menu bar is one shared
     * partial drawn by the layout, so the list and the board of every page
     * show the very same bar (same items, same counts, same page marked).
     */
    public function testTheSectionMenuBarIsTheSameOnTheListAndTheBoard(): void
    {
        $this->seed();
        $pages = $this->pages();
        try {
            foreach ($pages as [$page, $controller, $action, $listQuery, $boardQuery, $path]) {
                $_SERVER['REQUEST_URI'] = $path;
                \App\Services\ApprovalsInbox::resetCache();
                $list = $this->render($controller, $action, $listQuery);
                \App\Services\ApprovalsInbox::resetCache();
                $board = $this->render($controller, $action, $boardQuery);
                $bar = static function (string $html): string {
                    return preg_match('#<nav class="section-nav".*?</nav>#s', $html, $m) ? $m[0] : '';
                };
                if ($page !== 'My tasks') {
                    self::assertNotSame('', $bar($list), "{$page}: the list shows its section's menu bar");
                }
                self::assertSame($bar($list), $bar($board), "{$page}: the same menu bar on the list and the board");
                preg_match('#<nav class="sidebar-nav".*?</nav>#s', $list, $l);
                preg_match('#<nav class="sidebar-nav".*?</nav>#s', $board, $b);
                self::assertSame($l[0] ?? '', $b[0] ?? '', "{$page}: the same left menu on the list and the board");
            }
        } finally {
            unset($_SERVER['REQUEST_URI']);
        }
    }

    /**
     * QA Round 7, R7-N08: with no filter chosen, the list and the board open
     * on the same orders (Active) — the board used to add Docs Sent and
     * Complete orders.
     */
    public function testTheOrdersListAndBoardOpenOnTheSameOrders(): void
    {
        $active = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);
        $complete = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);
        $docsSent = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);
        $pdo = \App\Config\Database::connection();
        $pdo->prepare("UPDATE orders SET status = 'complete' WHERE id = :id")->execute(['id' => $complete]);
        $pdo->prepare("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id")->execute(['id' => $docsSent]);

        $list = self::parts($this->render(new OrderController(), 'index', ['view' => 'list']));
        $board = self::parts($this->render(new OrderController(), 'index', []));
        self::assertContains("/orders/{$active}", $board['records']);
        self::assertNotContains("/orders/{$complete}", $board['records']);
        self::assertNotContains("/orders/{$docsSent}", $board['records']);
        self::assertContains("/orders/{$active}", $list['records']);
        self::assertSame(array_values(array_intersect($list['records'], ["/orders/{$active}", "/orders/{$complete}", "/orders/{$docsSent}"])),
            array_values(array_intersect($board['records'], ["/orders/{$active}", "/orders/{$complete}", "/orders/{$docsSent}"])));
        self::assertStringContainsString('class="filter-chip active" href="/orders?status=active">Active', $this->render(new OrderController(), 'index', []));
    }

    /**
     * D-257: a Domestic Trade order (or an Import & Distribution local sale)
     * shows its own stage (of 8) and its rupee amount, on the list and on
     * the board alike; the board gives Domestic stages their own columns
     * only when there are such orders. Export cards are unchanged.
     */
    public function testADomesticOrderShowsItsOwnStageAndRupeeAmountOnTheListAndTheBoard(): void
    {
        $pdo = \App\Config\Database::connection();
        $pdo->prepare('UPDATE users SET division_id = NULL WHERE id = :id')->execute(['id' => $this->userId]);
        $export = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);
        $board = $this->render(new OrderController(), 'index', []);
        self::assertStringNotContainsString('Domestic · ', $board, 'no Domestic columns without Domestic orders');
        $pdo->exec("UPDATE divisions SET status = 'active' WHERE id = 2");
        \App\Services\DivisionService::resetCache();
        try {
            $dom = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);
            $pdo->prepare("UPDATE orders SET division_id = 2, currency_code = 'INR' WHERE id = :id")->execute(['id' => $dom]);
            $pdo->prepare("INSERT INTO domestic_orders (order_id, stage, place_of_supply, seller_state_code) VALUES (:o, 'purchase', '29', '29')")->execute(['o' => $dom]);
            $pdo->prepare("INSERT INTO domestic_order_lines (order_id, line_no, description, hsn_code, quantity, unit, rate_inr, gst_rate_pct) VALUES (:o, 1, 'Granite', '6802', 10, 'm2', 1500.50, 18)")->execute(['o' => $dom]);
            foreach (['list' => ['view' => 'list'], 'board' => []] as $what => $get) {
                $html = $this->render(new OrderController(), 'index', $get);
                $card = substr($html, (int) strpos($html, "/orders/{$dom}"), 2500);
                self::assertStringContainsString('Stage 4 of 8 &middot; Purchase', $card, $what);
                self::assertStringContainsString('INR 15,005.00 + GST', $card, "{$what}: before the tax invoice, the lines without GST");
                $exportCard = substr($html, (int) strpos($html, "/orders/{$export}"), 2500);
                self::assertStringContainsString('of 9 &middot;', $exportCard, "{$what}: Export cards unchanged");
            }
            $board = $this->render(new OrderController(), 'index', []);
            self::assertStringContainsString('data-column="dom_purchase"', $board);
            self::assertMatchesRegularExpression('#data-column="dom_purchase".*?/orders/' . $dom . '\?from=kanban#s', $board);
            $pdo->prepare("UPDATE domestic_orders SET stage = 'payment', invoice_total = 17706.00 WHERE order_id = :o")->execute(['o' => $dom]);
            $list = $this->render(new OrderController(), 'index', ['view' => 'list']);
            self::assertStringContainsString('INR 17,706.00</span>', substr($list, (int) strpos($list, "/orders/{$dom}"), 2500), 'the tax invoice total once fixed');
        } finally {
            $pdo->exec("UPDATE divisions SET status = 'planned' WHERE id <> 1");
            \App\Services\DivisionService::resetCache();
        }
    }

    /**
     * Owner decision 1 Nov 2026 (A-5, Q-R7-2): the board pages like the
     * list — 25 per column (the list shows 25 per page), then "Show more".
     */
    public function testTheOrdersBoardPagesLikeTheList(): void
    {
        // A Kanban user who sees only their own orders (an Admin sees every order in the test database).
        $this->userId = $this->createTestUser('Export Executive');
        UserRepository::updateUiTemplatePreference($this->userId, (int) UiTemplateRepository::findByKey('kanban')['id']);
        $ids = [];
        for ($i = 0; $i < 27; $i++) {
            $ids[] = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->userId]);
        }
        $list = self::parts($this->render(new OrderController(), 'index', ['view' => 'list']));
        self::assertCount(25, array_intersect($list['records'], array_map(static fn (int $id): string => "/orders/{$id}", $ids)), 'the list shows 25 per page');

        $html = $this->render(new OrderController(), 'index', []);
        $board = self::parts($html);
        self::assertCount(25, array_intersect($board['records'], array_map(static fn (int $id): string => "/orders/{$id}", $ids)), 'the board shows 25 per column');
        self::assertStringContainsString('<span class="kanban-column-count">27</span>', $html);
        self::assertStringContainsString('href="/orders?status=active&amp;more_stage1=50#col-stage1">Show more (2 more)</a>', $html);
        self::assertNotContains('/orders/' . $ids[0], $board['records'], 'the oldest is behind "Show more"');

        $more = self::parts($this->render(new OrderController(), 'index', ['status' => 'active', 'more_stage1' => '50']));
        self::assertCount(27, array_intersect($more['records'], array_map(static fn (int $id): string => "/orders/{$id}", $ids)));
    }

    public function testAStandardUserSeesNoSwitchToTheBoard(): void
    {
        UserRepository::updateUiTemplatePreference($this->userId, null);
        foreach ($this->pages() as [$page, $controller, $action]) {
            $list = $this->render($controller, $action, []);
            self::assertStringNotContainsString('kanban-board', $list, $page);
            self::assertStringNotContainsString('Switch to board view', $list, "{$page}: only a Kanban user is offered the board");
        }
    }

    /** Every Kanban board view has its Standard list view, on both sides of the template switch. */
    public function testEveryBoardTemplateHasItsListTemplate(): void
    {
        $views = dirname(__DIR__, 2) . '/views';
        $boards = array_merge(glob($views . '/*/index_kanban.php') ?: [], glob($views . '/*/*_kanban.php') ?: []);
        self::assertNotEmpty($boards);
        foreach (array_unique($boards) as $board) {
            $list = str_replace('_kanban.php', '.php', $board);
            self::assertFileExists($list, basename(dirname($board)) . ': a board needs its list');
            self::assertStringContainsString('Switch to list view', (string) file_get_contents($board), $board);
            self::assertStringContainsString('Switch to board view', (string) file_get_contents($list), $list);
        }
    }
}
