<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AmendmentController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\DropdownOptionController;
use App\Controllers\OrderController;
use App\Controllers\ReferenceDocController;
use App\Controllers\TestModeController;
use App\Controllers\WatermarkController;
use App\Helpers\FieldValidator;
use App\Helpers\View;
use App\Repositories\ClientRepository;
use App\Repositories\InternalReferenceDocRepository;
use App\Repositories\OrderRepository;
use App\Repositories\WatermarkSettingsRepository;
use App\Services\ReviewWorkflowService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, Batch 6 (QA package v3/v4): the server refuses what the
 * browser refuses, with a message and nothing saved — never an HTTP 500
 * (R5-29), never an empty save that wipes data (R5-30), never a malformed
 * value stored (R5-28). Plus the versioned app.js/app.css URLs (R5-26), the
 * COOPREP review message (R5-25) and the forgot-password wording.
 * Port: tests/integration/round5Validation.test.js.
 */
final class Round5ValidationTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function lastFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        $last = is_array($flash) ? end($flash) : null;
        return is_array($last) ? $last : ['type' => null, 'message' => ''];
    }

    private function quietly(callable $action): void
    {
        ob_start();
        try {
            $action();
        } finally {
            ob_end_clean();
        }
    }

    private function orderUnlockedTo(int $stage): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        for ($s = 1; $s < $stage; $s++) {
            StageGateService::passAndUnlockNext($orderId, $s, $this->userId);
        }
        return $orderId;
    }

    public function testTheSharedValidatorRules(): void
    {
        self::assertNull(FieldValidator::wholeNumber('', 'X'));
        self::assertNull(FieldValidator::wholeNumber('45', 'X'));
        self::assertSame('Lead must be a whole number of 0 or more.', FieldValidator::wholeNumber('-1', 'Lead'));
        self::assertNotNull(FieldValidator::wholeNumber('4.5', 'X'));
        self::assertNull(FieldValidator::decimal('12.5', 'X'));
        self::assertSame('Rate must be a number of 0 or more (digits and a decimal point only).', FieldValidator::decimal('12,5 kg', 'Rate'));
        self::assertSame('Opacity must be a number from 0 to 1.', FieldValidator::decimal('-1', 'Opacity', 0, 1));
        self::assertNull(FieldValidator::email('a@b.co', 'E'));
        self::assertSame('E must be a valid e-mail address.', FieldValidator::email('not-an-email', 'E'));
    }

    /** R5-29: a negative lead time on Edit Order used to crash with HTTP 500. */
    public function testEditOrderRefusesANegativeLeadTimeAndKeepsWhatWasTyped(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $order = OrderRepository::find($orderId);
        $_POST = ['incoterm_id' => (string) $order['incoterm_id'], 'currency_id' => (string) $order['currency_id'], 'lead_time_min_days' => '-1', 'lead_time_max_days' => '60'];
        $this->quietly(fn () => (new OrderController())->updateDetails(['id' => (string) $orderId]));

        self::assertSame('error', $this->lastFlash()['type']);
        self::assertStringContainsString('Production lead time (min days) must be a whole number', $this->lastFlash()['message']);
        self::assertNull(OrderRepository::find($orderId)['lead_time_min_days'], 'nothing saved');
        self::assertSame('-1', $_SESSION['_flash_old']['lead_time_min_days'] ?? null, 'the form comes back with what was typed');
    }

    /** R5-29: the same numbers on New Order. */
    public function testNewOrderRefusesTextInTheCbm(): void
    {
        self::assertSame('Total CBM must be a number of 0 or more (digits and a decimal point only).', FieldValidator::orderNumbers(['estimated_total_cbm' => '12,5 kg']));
        self::assertSame('Sailing time (max days) must be a whole number of 0 or more.', FieldValidator::orderNumbers(['sailing_lead_time_max_days' => '-1']));
        self::assertNull(FieldValidator::orderNumbers(['lead_time_min_days' => '45', 'estimated_total_cbm' => '28.5']));
    }

    /** R5-29: text in the freight rate used to crash Freight Terms with HTTP 500. */
    public function testFreightTermsRefusesTextInTheRate(): void
    {
        $orderId = $this->orderUnlockedTo(6);
        $_POST = ['confirmed_freight_rate' => '12,5 kg', 'freight_forwarder_partner_id' => '0'];
        $this->quietly(fn () => (new OrderController())->saveFreightTerms(['id' => (string) $orderId]));

        self::assertSame('Confirmed freight rate must be a number of 0 or more (digits and a decimal point only).', $this->lastFlash()['message']);
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM order_freight WHERE order_id = :id');
        $count->execute(['id' => $orderId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    /** R5-28: container count 0 is refused on the server, like the form's min="1". */
    public function testShippingRefusesZeroContainers(): void
    {
        $orderId = $this->orderUnlockedTo(7);
        $_POST = ['container_count' => '0'];
        $this->quietly(fn () => (new OrderController())->saveShipping(['id' => (string) $orderId]));
        self::assertSame('Number of containers must be a whole number of 1 or more.', $this->lastFlash()['message']);
    }

    /** R5-30: a built-in reference document saved empty used to wipe it. */
    public function testAReferenceDocumentCannotBeSavedEmpty(): void
    {
        $doc = InternalReferenceDocRepository::findByCode('STAGEGATE');
        $before = (string) ($doc['content'] ?? '');
        $_POST = ['content' => '   '];
        $this->quietly(fn () => (new ReferenceDocController())->update(['code' => 'STAGEGATE']));

        self::assertSame('error', $this->lastFlash()['type']);
        self::assertSame($before, (string) (InternalReferenceDocRepository::findByCode('STAGEGATE')['content'] ?? ''));
    }

    /** R5-30 / R5-28: a cleared option name or text in a sort order saves nothing (PHP used to save both). */
    public function testDropdownOptionsRefuseABlankNameOrATextSortOrder(): void
    {
        $rows = Database::connection()->query("SELECT id, option_value, sort_order FROM dropdown_options WHERE list_key = 'unit' ORDER BY id")->fetchAll();
        self::assertNotEmpty($rows);
        $first = $rows[0];

        foreach ([['option_value', ''], ['sort_order', '12,5 kg']] as [$field, $bad]) {
            $_POST = ['option_value' => [], 'sort_order' => [], 'is_active' => []];
            foreach ($rows as $r) {
                $_POST['option_value'][$r['id']] = $r['option_value'];
                $_POST['sort_order'][$r['id']] = (string) $r['sort_order'];
                $_POST['is_active'][$r['id']] = '1';
            }
            $_POST[$field][$first['id']] = $bad;
            $this->quietly(fn () => (new DropdownOptionController())->update(['listKey' => 'unit']));
            self::assertStringContainsString('nothing was saved', $this->lastFlash()['message'], $field);
        }
        $after = Database::connection()->query('SELECT option_value, sort_order FROM dropdown_options WHERE id = ' . (int) $first['id'])->fetch();
        self::assertSame($first['option_value'], $after['option_value']);
        self::assertSame((int) $first['sort_order'], (int) $after['sort_order']);
    }

    /** R5-28: a malformed client e-mail is refused on the server. */
    public function testClientUpdateRefusesAMalformedEmail(): void
    {
        $clientId = $this->createTestClient();
        $client = ClientRepository::find($clientId);
        $_POST = ['company_legal_name' => $client['company_legal_name'], 'billing_address' => $client['billing_address'] ?: 'Somewhere 1', 'email' => 'not-an-email'];
        $this->quietly(fn () => (new ClientController())->update(['id' => (string) $clientId]));

        self::assertSame('Email must be a valid e-mail address.', $this->lastFlash()['message']);
        self::assertNotSame('not-an-email', ClientRepository::find($clientId)['email']);
    }

    /** R5-28: text in the amended advance % is refused (it used to create the amendment). */
    public function testAmendmentRefusesTextInTheAdvancePct(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_POST = ['reason' => 'Buyer asked to change the advance split.', 'amended_advance_pct' => '12,5 kg'];
        $this->quietly(fn () => (new AmendmentController())->create(['id' => (string) $orderId]));

        self::assertSame('Amended advance % must be a number from 0 to 100.', $this->lastFlash()['message']);
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM amendments WHERE order_id = :id');
        $count->execute(['id' => $orderId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    /** R5-28: Test Mode's test e-mail and the watermark opacity. */
    public function testTestModeEmailAndWatermarkOpacityAreChecked(): void
    {
        $_POST = ['test_email' => 'not-an-email'];
        $this->quietly(fn () => (new TestModeController())->updateTestEmail([]));
        self::assertSame('Test email must be a valid e-mail address.', $this->lastFlash()['message']);

        $before = WatermarkSettingsRepository::findGlobal(true);
        $_POST = ['mode' => 'text', 'text_content' => 'DRAFT', 'opacity' => '-1'];
        $this->quietly(fn () => (new WatermarkController())->update(['which' => 'draft']));
        self::assertSame('Text opacity must be a number from 0 to 1.', $this->lastFlash()['message']);
        self::assertSame($before['opacity'] ?? null, WatermarkSettingsRepository::findGlobal(true)['opacity'] ?? null);
    }

    /** Forgot password with the e-mail left blank no longer answers "If  matches an account…". */
    public function testForgotPasswordAsksForAnEmail(): void
    {
        $_POST = ['email' => ''];
        $this->quietly(fn () => (new AuthController())->forgotPassword([]));
        self::assertSame(['error', 'Enter your e-mail address.'], [$this->lastFlash()['type'], $this->lastFlash()['message']]);
    }

    /** R5-25: a COOPREP (no reference of its own) is named by its order, never "COOPREP null". */
    public function testACoopPrepReviewMessageNamesTheOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'COOPREP');
        Database::connection()->prepare('UPDATE documents SET document_reference = NULL WHERE id = :id')->execute(['id' => $documentId]);
        $reviewerId = $this->createTestUser('Admin');

        ReviewWorkflowService::assignReviewers($documentId, [$reviewerId], $this->userId);

        $stmt = Database::connection()->prepare("SELECT message FROM notifications WHERE user_id = :u AND type = 'review_assigned' ORDER BY id DESC LIMIT 1");
        $stmt->execute(['u' => $reviewerId]);
        $message = (string) $stmt->fetchColumn();
        $orderReference = OrderRepository::find($orderId)['order_reference'];
        self::assertSame("You've been assigned to review COOPREP for order {$orderReference} Rev.0.", $message);
    }

    /** R5-26: app.js / app.css carry their last-change time, so a deploy reaches every browser at once. */
    public function testSitewideScriptAndStylesheetUrlsAreVersioned(): void
    {
        self::assertMatchesRegularExpression('#^/assets/js/app\.js\?v=\d{9,}$#', View::asset('/assets/js/app.js'));
        foreach (['base', 'bare', 'client'] as $layout) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . "/views/layout/{$layout}.php");
            self::assertStringContainsString("View::asset('/assets/js/app.js')", $source, $layout);
            self::assertStringContainsString("View::asset('/assets/css/app.css')", $source, $layout);
        }
    }

    /** R5-27: the browser stops these forms first (app.js reads these attributes). */
    public function testTheFormsCarryTheirBrowserRules(): void
    {
        $views = dirname(__DIR__, 2) . '/views/';
        $has = static fn (string $file, string $needle) => self::assertStringContainsString($needle, (string) file_get_contents($views . $file), $file);
        $has('dropdown_options/index.php', '<input type="text" name="option_value" required');
        $has('dropdown_options/index.php', 'name="option_value[<?= (int) $o[\'id\'] ?>]" required');
        $has('dropdown_options/index.php', '<input type="number" min="0" step="1" required name="sort_order[');
        $has('reference_docs/edit.php', 'name="content" required');
        $has('clients/edit.php', 'name="agreement_file" required');
        $has('orders/show.php', 'data-required-unless="attachments[]" data-required-unless-message="Write a message or attach a file before posting."');
        $has('orders/show.php', '<input type="number" min="0" step="any" name="factory_distance_km"');
        $has('reports/aggregate.php', 'name="name" required');
        $has('signatories/index.php', "\$u['is_signatory_eligible'] ? '' : ' required'");
        $has('settings/index.php', "\$row['value_type'] === 'number' ? 'number' : 'text'");
        $has('amendments/index.php', '<input type="number" min="0" max="100" step="any" name="amended_advance_pct"');
        $has('watermarks/index.php', '<input type="number" min="1" step="1" name="font_size"');
    }
}
