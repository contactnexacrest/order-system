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
 * and a way to switch to the other view. For each of the eight list pages
 * this renders the list (?view=list) and the board for the same Kanban user
 * and the same data, and compares them. The Node suite has the same test
 * (kanbanParity.test.js).
 */
final class KanbanParityTest extends DbTestCase
{
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
            'records' => self::all('#href="(/(?:orders|clients|reorder-requests|procurement)/\d+)(?:[?"/])#', $html),
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
            'billing_city' => null, 'billing_postcode' => null, 'billing_country' => null, 'vat_eori_tax_no' => null, 'contact_person' => 'Jane Buyer',
            'email' => 'parity-' . bin2hex(random_bytes(4)) . '@intake.example', 'phone' => null, 'country_of_destination' => 'Netherlands',
            'port_of_discharge_text' => null, 'coo_type' => null, 'incoterm_preference' => null, 'container_type_text' => null, 'buyer_own_reference' => null, 'notes' => null,
        ], '127.0.0.1');
        $this->seedPiSubmission();
        PurchasingRepository::create($orderId, null, 'Parity test blocks', null, 5.0, 'CBM', date('Y-m-d', strtotime('+5 days')), $this->userId, null);
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
