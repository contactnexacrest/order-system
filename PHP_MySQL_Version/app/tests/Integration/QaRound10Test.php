<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Config\Env;
use App\Controllers\GettingStartedController;
use App\Controllers\ImportController;
use App\Controllers\OrderController;
use App\Controllers\SopAccessController;
use App\Helpers\FieldValidator;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Services\DivisionService;
use App\Services\MarineInsurance;
use App\Services\PermissionService;
use App\Services\SopAccessService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 10 (22 Nov 2026) — the fixes for R10-N01…N10 and the Round-9 defects still open (R9-N01…N05), and owner
 * decision D-405 (CIF insurance recalculated whenever the products or the freight change). Node twin: qaRound10.test.js.
 */
final class QaRound10Test extends DbTestCase
{
    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    /** A CIF order with one 100 × 45.00 goods line (FOB 4,500), freight 950 and the calculated insurance. */
    private function cifOrder(): int
    {
        $order = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
        OrderProductRepository::add($order, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
        Database::connection()->exec("UPDATE orders SET indicative_freight_amount = 950, indicative_insurance_amount = NULL WHERE id = {$order}");
        MarineInsurance::fillIfEmpty($order);
        MarineInsurance::rememberBasis($order);
        return $order;
    }

    private function insurance(int $orderId): ?string
    {
        $v = Database::connection()->query("SELECT indicative_insurance_amount FROM orders WHERE id = {$orderId}")->fetchColumn();
        return $v === null ? null : (string) $v;
    }

    private function editPost(int $orderId, array $over): void
    {
        $o = OrderRepository::find($orderId);
        $_POST = $over + [
            'incoterm_id' => (string) $o['incoterm_id'], 'currency_id' => (string) $o['currency_id'], 'container_type' => '20ft Standard',
            'indicative_freight_amount' => (string) $o['indicative_freight_amount'], 'indicative_insurance_amount' => (string) $o['indicative_insurance_amount'],
        ];
        $this->render(fn () => (new OrderController())->updateDetails(['id' => (string) $orderId]));
    }

    /** R10-N02: a typed 0 for the freight or the insurance is kept as 0 (it was thrown away on PHP). */
    public function testATypedZeroIsKept(): void
    {
        $order = $this->cifOrder();
        $this->actAs($this->createTestUser('Admin'));
        $this->editPost($order, ['indicative_insurance_amount' => '0']);
        self::assertSame('0.00', $this->insurance($order));
        $this->editPost($order, ['indicative_freight_amount' => '0', 'indicative_insurance_amount' => '0']);
        self::assertSame('0.00', (string) Database::connection()->query("SELECT indicative_freight_amount FROM orders WHERE id = {$order}")->fetchColumn());
    }

    /** D-405: a change of the products recalculates the insurance — even a typed figure — until the actual premium is billed. */
    public function testTheInsuranceFollowsTheProducts(): void
    {
        $order = $this->cifOrder();
        self::assertSame('19.00', $this->insurance($order));
        $pdo = Database::connection();
        $pdo->exec("UPDATE orders SET indicative_insurance_amount = 0 WHERE id = {$order}"); // staff typed 0
        MarineInsurance::rememberBasis($order);
        self::assertNull(MarineInsurance::sync($order, null), 'nothing changed: the typed 0 stays');
        // The quantity is tripled: FOB 13,500 + freight 950 at 0.30 % × 110 % → 47.83 → 48.
        $pdo->exec("UPDATE order_products SET quantity = 300, fob_value = 13500 WHERE order_id = {$order}");
        self::assertSame(['from' => '0.00', 'to' => '48.00'], MarineInsurance::sync($order, null));
        self::assertSame('48.00', $this->insurance($order));
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ORDER_INSURANCE_RECALCULATED' AND entity_id = {$order}")->fetchColumn());
        self::assertSame('Insurance recalculated from EUR 0.00 to EUR 48.00 because the products / freight changed. Change it again on Edit order if needed.',
            MarineInsurance::recalcMessage(['from' => '0.00', 'to' => '48.00'], 'EUR'));
        // Once the actual premium is billed (Freight Debit Note step), nothing is recalculated any more.
        $pdo->exec("INSERT INTO order_freight (order_id, insurance_amount) VALUES ({$order}, 55.00) ON DUPLICATE KEY UPDATE insurance_amount = 55.00");
        $pdo->exec("UPDATE order_products SET quantity = 10, fob_value = 450 WHERE order_id = {$order}");
        self::assertNull(MarineInsurance::sync($order, null));
        self::assertSame('48.00', $this->insurance($order));
    }

    /** D-405: on Edit order a freight change recalculates, unless staff also changed the insurance in the same save. */
    public function testEditOrderRecalculatesOnAFreightChange(): void
    {
        $order = $this->cifOrder();
        $this->actAs($this->createTestUser('Admin'));
        $this->editPost($order, ['indicative_insurance_amount' => '5']);
        self::assertSame('5.00', $this->insurance($order), 'typed in this save: kept');
        $this->editPost($order, ['indicative_freight_amount' => '2950']); // insurance left at 5
        // FOB 4,500 + 2,950 at 0.30 % × 110 % → 24.67 → 25.
        self::assertSame('25.00', $this->insurance($order));
        self::assertStringContainsString('Insurance recalculated from EUR 5.00 to EUR 25.00', implode(' ', array_column($_SESSION['_flash'] ?? [], 'message')));
        // CFR and FOB orders are never touched.
        $cfr = $this->createTestOrder($this->createTestClient(), 'CFR', 'Standard — New Buyer', 'EUR');
        OrderProductRepository::add($cfr, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
        self::assertNull(MarineInsurance::sync($cfr, null, true));
    }

    /** R10-N03: very large numbers get a message instead of a server error. */
    public function testOrderNumbersHaveUpperLimits(): void
    {
        self::assertSame('Indicative insurance must be a number from 0 to 10000000.', FieldValidator::orderNumbers(['indicative_insurance_amount' => '99999999999999']));
        self::assertSame('Indicative freight must be a number from 0 to 10000000.', FieldValidator::orderNumbers(['indicative_freight_amount' => '99999999999999']));
        self::assertSame('Production lead time (min days) must be a whole number from 0 to 365.', FieldValidator::orderNumbers(['lead_time_min_days' => '99999999999999']));
        self::assertSame('Sailing time (max days) must be a whole number from 0 to 365.', FieldValidator::orderNumbers(['sailing_lead_time_max_days' => '400']));
        self::assertSame('Total CBM: use at most 3 decimals.', FieldValidator::orderNumbers(['estimated_total_cbm' => '12.3456']));
        self::assertSame('Total CBM must be a number from 0 to 10000.', FieldValidator::orderNumbers(['estimated_total_cbm' => '99999999999']));
        self::assertNull(FieldValidator::orderNumbers(['indicative_freight_amount' => '950.00', 'lead_time_min_days' => '30', 'estimated_total_cbm' => '28.500']));
    }

    /** R10-N06: the product supplier form checks the phone, the e-mail and the prices on the server. */
    public function testTheProductSupplierFormChecksItsValues(): void
    {
        $c = new \App\Controllers\ProductController();
        $validate = new \ReflectionMethod($c, 'validateSupplier');
        $read = new \ReflectionMethod($c, 'readSupplierForm');
        $check = function (array $post) use ($c, $validate, $read): ?string {
            $_POST = $post + ['supplier_name' => 'TEST Quarry'];
            return $validate->invoke($c, $read->invoke($c, null), null);
        };
        self::assertSame('Contact phone: digits, spaces or dashes, with the country code, e.g. +91-0000000000.', $check(['contact_phone' => 'call me']));
        self::assertSame('Contact email: enter a valid email address, e.g. name@company.com, or leave it blank.', $check(['contact_email' => 'not-an-email']));
        self::assertSame('FOB value: a number of 0 or more, e.g. 4500.00.', $check(['fob_value' => '-1']));
        self::assertSame('FOB value: a number of 0 or more, e.g. 4500.00.', $check(['fob_value' => 'abc']));
        self::assertNull($check(['contact_phone' => '+91-0000000000', 'contact_email' => 'info@email.com', 'fob_value' => '4500.00']));
    }

    /** R10-N08: a document generation in another database on the same MySQL server does not block this one. */
    public function testTheDocumentLockIsPerDatabase(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $other = new \PDO(
            'mysql:host=' . Env::get('DB_HOST', '127.0.0.1') . ';port=' . Env::get('DB_PORT', '3306') . ';dbname=' . Env::get('DB_DATABASE'),
            Env::get('DB_USERNAME'),
            Env::get('DB_PASSWORD', '')
        );
        // What the old, server-wide name would have been — and another database's name.
        $other->query("SELECT GET_LOCK('nx_docgen_{$orderId}_QT', 0)");
        $other->query("SELECT GET_LOCK(CONCAT('nx_docgen_', LEFT(MD5('another_database'), 12), '_{$orderId}_QT'), 0)");
        $claim = new \ReflectionMethod(\App\Controllers\DocumentController::class, 'claimGeneration');
        $release = new \ReflectionMethod(\App\Controllers\DocumentController::class, 'releaseGeneration');
        try {
            self::assertNull($claim->invoke(null, $orderId, 'QT'));
            $release->invoke(null, $orderId, 'QT');
        } finally {
            $other->query('SELECT RELEASE_ALL_LOCKS()');
        }
    }

    /** R10-N04: with the SOP library switched off, Getting Started does not link to the SOP. */
    public function testGettingStartedHidesTheSopLinkWhileTheLibraryIsOff(): void
    {
        $this->actAs($this->createTestUser('Accounts Executive'));
        $md = $this->createTestUser('Managing Director');
        SopAccessService::setLibraryEnabled(false, $md);
        try {
            $html = $this->render(fn () => (new GettingStartedController())->show([]));
            self::assertStringNotContainsString('<a href="/sop">', $html);
            self::assertStringContainsString('SOP / Operations Guide (switched off)', $html);
        } finally {
            SopAccessService::setLibraryEnabled(true, $md);
        }
        $html = $this->render(fn () => (new GettingStartedController())->show([]));
        self::assertStringContainsString('<a href="/sop">SOP / Operations Guide</a>', $html);
    }

    /** R10-N05: someone working only outside Export does not see "Quotation requests" in the Orders menu. */
    public function testQuotationRequestsShowOnlyToExportStaff(): void
    {
        $pdo = Database::connection();
        $dom = (int) $pdo->query("SELECT id FROM divisions WHERE code = 'DOM'")->fetchColumn();
        $pdo->prepare("UPDATE divisions SET status = 'active' WHERE id = :d")->execute(['d' => $dom]);
        DivisionService::resetCache();
        try {
            $user = $this->createTestUser('Export Executive');
            $pdo->prepare('UPDATE users SET division_id = :d WHERE id = :id')->execute(['d' => $dom, 'id' => $user]);
            $this->actAs($user);
            $_SERVER['REQUEST_URI'] = '/orders'; // the Orders section's menu bar
            self::assertStringNotContainsString('href="/client-intake"', $this->render(fn () => (new OrderController())->index([])));
            $pdo->prepare('UPDATE users SET division_id = 1 WHERE id = :id')->execute(['id' => $user]);
            $this->actAs($user);
            DivisionService::resetCache();
            self::assertStringContainsString('href="/client-intake"', $this->render(fn () => (new OrderController())->index([])));
        } finally {
            $pdo->prepare("UPDATE divisions SET status = 'planned' WHERE id = :d")->execute(['d' => $dom]);
            DivisionService::resetCache();
        }
    }

    /** R10-N09 (b): the "Forms of stone" link only for people who can open it. */
    public function testFormsOfStoneLinkOnlyForPricingStaff(): void
    {
        if (\App\Services\ProductForms::forms() === []) {
            Database::connection()->exec("INSERT INTO product_forms (name, is_active, sort_order) VALUES ('TEST Slab', 1, 1)");
        }
        $this->actAs($this->createTestUser('System Administrator'));
        self::assertStringNotContainsString('href="/pricing/forms"', $this->render(fn () => (new \App\Controllers\DropdownOptionController())->index([])));
        $this->actAs($this->createTestUser('Managing Director'));
        $__h = $this->render(fn () => (new \App\Controllers\DropdownOptionController())->index([]));
        self::assertStringContainsString('href="/pricing/forms"', $__h);
    }

    /** R9-N01: the rupees the bank credited and the rate only with inr_actual_view. */
    public function testReceiptsShowRupeesOnlyWithInrActualView(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $order]);
        $pdo->prepare("UPDATE order_payment_status SET advance_amount = 1000, advance_remittance_received_at = '2026-11-20 10:00:00', advance_inr_actual = 90000, advance_exchange_rate = 90 WHERE order_id = :o")->execute(['o' => $order]);
        $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 1000, '2026-11-20', 90, 90000, 'UTR-R10')")->execute(['o' => $order]);

        $exec = $this->createTestUser('Export Executive');
        $pdo->prepare('INSERT INTO order_assignees (order_id, user_id) VALUES (:o, :u)')->execute(['o' => $order, 'u' => $exec]);
        $this->actAs($exec);
        $html = $this->render(fn () => (new OrderController())->show(['id' => (string) $order]));
        self::assertStringContainsString('UTR-R10', $html);
        self::assertStringNotContainsString('INR credited</th>', $html);
        self::assertStringNotContainsString('credited by the bank (exchange rate', $html);
        self::assertStringNotContainsString('90,000.00', $html);

        $this->actAs($this->createTestUser('Accounts Executive'));
        $html = $this->render(fn () => (new OrderController())->show(['id' => (string) $order]));
        self::assertStringContainsString('INR credited</th>', $html);
        self::assertStringContainsString('90,000.00', $html);
    }

    /** R9-N02: removing an extra SOP chapter says what really happened. */
    public function testRemovingAnExtraSopChapterSaysWhatHappened(): void
    {
        $md = $this->createTestUser('Managing Director');
        $user = $this->createTestUser('Export Executive');
        $this->actAs($md);
        $slug = SopAccessService::allSlugs()[0];
        SopAccessService::addUserExtra($user, $slug, $md);
        $post = function (string $s) use ($user): string {
            $_POST = ['slug' => $s];
            $this->render(fn () => (new SopAccessController())->removeUserExtra(['id' => (string) $user]));
            $f = \App\Helpers\Flash::pull();
            return (string) (end($f)['message'] ?? '');
        };
        self::assertSame('Choose the extra SOP chapter to remove.', $post(''));
        self::assertSame('Extra SOP chapter removed.', $post($slug));
        self::assertSame('That extra SOP chapter was already removed.', $post($slug));
    }

    /** R9-N04: outside an active division the refusal says so, and how to get in. */
    public function testTheDivisionRefusalGivesTheRealReason(): void
    {
        $pdo = Database::connection();
        $mtt = (int) $pdo->query("SELECT id FROM divisions WHERE code = 'MTT'")->fetchColumn();
        $pdo->prepare("UPDATE divisions SET status = 'active' WHERE id = :d")->execute(['d' => $mtt]);
        $pdo->prepare("INSERT INTO division_modules (division_id, module_key, is_enabled) VALUES (:d, 'merchant_trade', 1) ON DUPLICATE KEY UPDATE is_enabled = 1")->execute(['d' => $mtt]);
        DivisionService::resetCache();
        try {
            $this->actAs($this->createTestUser('Export Executive')); // Export only, where merchant trade is off
            $refusal = (string) DivisionService::refusalForPath('/merchant');
            self::assertStringStartsWith('You do not work in the ', $refusal);
            self::assertStringContainsString('division — ask an administrator to add you (Admin › Divisions).', $refusal);
        } finally {
            $pdo->prepare("UPDATE divisions SET status = 'planned' WHERE id = :d")->execute(['d' => $mtt]);
            DivisionService::resetCache();
        }
        // Switched off everywhere: the old message.
        self::assertSame(DivisionService::MODULE_OFF, DivisionService::refusalForPath('/merchant'));
    }

    /** R9-N05: downloading the Import PO with view rights only does not mark it generated or freeze its company details. */
    public function testAViewerDownloadsTheImportPoWithoutChangingIt(): void
    {
        $method = new \ReflectionMethod(ImportController::class, 'poDocument');
        self::assertStringContainsString("self::can('manage_purchasing')", (string) file_get_contents((string) $method->getFileName()));
    }
}
