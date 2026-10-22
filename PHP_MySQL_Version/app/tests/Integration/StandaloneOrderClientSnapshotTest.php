<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\ClientRepository;
use App\Repositories\OrderRepository;
use App\Services\OrderDuplicationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "standalone order" follow-on: `clients` is fully
 * admin-editable (ClientRepository::update()) and, in the ordinary
 * PI-intake workflow (PiIntakeReviewController::accept()), is actually
 * overwritten as a matter of routine every time a buyer confirms/corrects
 * their own details — OrderRepository::find() used to live-join it, so
 * that correction on ANY order for a client would silently rewrite what
 * every OTHER order for the same client — including ones closed years
 * ago — displays and prints. This proves the isolation actually holds:
 * editing `clients` directly (an admin correction) must never change an
 * existing order's own resolved values; a PI-intake acceptance must only
 * ever update the one order it was submitted for, never a sibling order
 * for the same client; and duplicating an order re-resolves TODAY's
 * current client details (a duplicate is a new commercial decision made
 * today).
 */
final class StandaloneOrderClientSnapshotTest extends DbTestCase
{
    private function createFullTestClient(): int
    {
        $pdo = \App\Config\Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO clients
                (client_unique_number, company_legal_name, billing_address, vat_eori_tax_no, contact_person,
                 email, phone, country_of_destination, consignee_same_as_buyer, notify_party_same_as_consignee, created_by)
             VALUES
                (:num, :name, :addr, :vat, :contact, :email, :phone, :country, 1, 1, NULL)'
        );
        $stmt->execute([
            'num'     => 'TEST-' . bin2hex(random_bytes(4)),
            'name'    => 'Original Buyer Ltd',
            'addr'    => '1 Original Street, Original City',
            'vat'     => 'VAT-ORIGINAL',
            'contact' => 'Original Contact',
            'email'   => 'original@buyer.test',
            'phone'   => '1000000000',
            'country' => 'Netherlands',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function testOrderSnapshotsClientDetailsAtCreation(): void
    {
        $clientId = $this->createFullTestClient();
        $orderId = $this->createTestOrder($clientId);

        $order = OrderRepository::find($orderId);

        self::assertSame('Original Buyer Ltd', $order['company_legal_name']);
        self::assertSame('1 Original Street, Original City', $order['billing_address']);
        self::assertSame('VAT-ORIGINAL', $order['vat_eori_tax_no']);
        self::assertSame('Original Contact', $order['contact_person']);
        self::assertSame('original@buyer.test', $order['client_email']);
        self::assertSame('1000000000', $order['client_phone']);
        self::assertSame('Netherlands', $order['country_of_destination']);
    }

    public function testEditingTheClientsTableDoesNotChangeAnExistingOrder(): void
    {
        $clientId = $this->createFullTestClient();
        $orderId = $this->createTestOrder($clientId);
        self::assertSame('Original Buyer Ltd', OrderRepository::find($orderId)['company_legal_name']);

        ClientRepository::update($clientId, [
            'company_legal_name' => 'Renamed Buyer Ltd', 'billing_address' => 'Renamed Address',
            'billing_address_line1' => null, 'billing_address_line2' => null, 'billing_city' => null,
            'billing_postcode' => null, 'billing_country' => null,
            'consignee_name' => null, 'consignee_address' => null,
            'consignee_same_as_buyer' => 1, 'consignee_address_line1' => null, 'consignee_address_line2' => null,
            'consignee_city' => null, 'consignee_postcode' => null, 'consignee_country' => null,
            'consignee_vat_eori_tax_no' => null, 'consignee_contact_person' => null,
            'consignee_phone' => null, 'consignee_email' => null,
            'vat_eori_tax_no' => 'VAT-RENAMED', 'contact_person' => 'Renamed Contact',
            'email' => 'renamed@buyer.test', 'phone' => '2000000000',
            'country_of_destination' => 'Germany', 'coo_type' => null,
            'notify_party_same_as_consignee' => 1, 'notify_party' => null,
            'notify_party_address_line1' => null, 'notify_party_address_line2' => null,
            'notify_party_city' => null, 'notify_party_postcode' => null, 'notify_party_country' => null,
            'notify_party_contact_person' => null, 'notify_party_phone' => null, 'notify_party_email' => null,
        ]);

        $order = OrderRepository::find($orderId);
        self::assertSame('Original Buyer Ltd', $order['company_legal_name'], 'an existing order must keep its own snapshot even after the client record changes');
        self::assertSame('1 Original Street, Original City', $order['billing_address']);
        self::assertSame('VAT-ORIGINAL', $order['vat_eori_tax_no']);
        self::assertSame('original@buyer.test', $order['client_email']);
        self::assertSame('Netherlands', $order['country_of_destination']);
    }

    /** createTestOrder() hardcodes sequence_no=1, so a second order for the same client needs its own sequence number. */
    private function createTestOrderWithSequence(int $clientId, int $sequenceNo): int
    {
        $pdo = \App\Config\Database::connection();
        $incoterm = $pdo->query("SELECT id, code FROM incoterms WHERE code = 'FOB'")->fetch();
        $currency = $pdo->query("SELECT id, code FROM currencies WHERE code = 'USD'")->fetch();
        $preset = $pdo->query(
            "SELECT id, preset_name, advance_pct, advance_trigger_text, balance_pct, balance_trigger_option,
                    balance_days, balance_trigger_wording, show_bl_instructions
             FROM payment_presets WHERE preset_name = 'Standard — New Buyer'"
        )->fetch();
        $client = $pdo->prepare('SELECT company_legal_name, vat_eori_tax_no FROM clients WHERE id = :id');
        $client->execute(['id' => $clientId]);
        $client = $client->fetch() ?: [];

        $stmt = $pdo->prepare(
            'INSERT INTO orders
                (order_reference, client_id, sequence_no, buyer_inquiry_ref, payment_preset_id, incoterm_id,
                 currency_id, coo_type, buyers_po_ref, quotation_date, quotation_valid_until, status,
                 incoterm_code, currency_code, preset_name, advance_pct, advance_trigger_text,
                 balance_pct, balance_trigger_option, balance_days, balance_trigger_wording, show_bl_instructions,
                 company_legal_name, vat_eori_tax_no)
             VALUES
                (:ref, :client_id, :seq, :inquiry_ref, :preset_id, :incoterm_id, :currency_id, \'TBC\', \'NIL\',
                 CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), \'active\',
                 :incoterm_code, :currency_code, :preset_name, :advance_pct, :advance_trigger_text,
                 :balance_pct, :balance_trigger_option, :balance_days, :balance_trigger_wording, :show_bl_instructions,
                 :company_legal_name, :vat_eori_tax_no)'
        );
        $stmt->execute([
            'ref'         => 'PHPUNIT-TEST-' . bin2hex(random_bytes(4)),
            'client_id'   => $clientId,
            'seq'         => $sequenceNo,
            'inquiry_ref' => 'PHPUNIT-' . bin2hex(random_bytes(3)),
            'preset_id'   => $preset['id'],
            'incoterm_id' => $incoterm['id'],
            'currency_id' => $currency['id'],
            'incoterm_code'           => $incoterm['code'],
            'currency_code'           => $currency['code'],
            'preset_name'             => $preset['preset_name'],
            'advance_pct'             => $preset['advance_pct'],
            'advance_trigger_text'    => $preset['advance_trigger_text'],
            'balance_pct'             => $preset['balance_pct'],
            'balance_trigger_option'  => $preset['balance_trigger_option'],
            'balance_days'            => $preset['balance_days'],
            'balance_trigger_wording' => $preset['balance_trigger_wording'],
            'show_bl_instructions'    => $preset['show_bl_instructions'],
            'company_legal_name'      => $client['company_legal_name'] ?? null,
            'vat_eori_tax_no'         => $client['vat_eori_tax_no'] ?? null,
        ]);
        $orderId = (int) $pdo->lastInsertId();
        \App\Repositories\OrderStageRepository::initializeForOrder($orderId);
        return $orderId;
    }

    public function testPiIntakeAcceptanceAppliesCorrectionToThisOrderOnlyNeverASiblingOrder(): void
    {
        $clientId = $this->createFullTestClient();
        $orderA = $this->createTestOrder($clientId);
        $orderB = $this->createTestOrderWithSequence($clientId, 2);

        // Mirrors PiIntakeReviewController::accept() exactly: it corrects
        // the `clients` master row, then applies that same correction
        // onto THIS order's own snapshot — never a sibling order's.
        ClientRepository::update($clientId, [
            'company_legal_name' => 'Confirmed Buyer Ltd', 'billing_address' => 'Confirmed Address',
            'billing_address_line1' => null, 'billing_address_line2' => null, 'billing_city' => null,
            'billing_postcode' => null, 'billing_country' => null,
            'consignee_name' => null, 'consignee_address' => null,
            'consignee_same_as_buyer' => 1, 'consignee_address_line1' => null, 'consignee_address_line2' => null,
            'consignee_city' => null, 'consignee_postcode' => null, 'consignee_country' => null,
            'consignee_vat_eori_tax_no' => null, 'consignee_contact_person' => null,
            'consignee_phone' => null, 'consignee_email' => null,
            'vat_eori_tax_no' => 'VAT-CONFIRMED', 'contact_person' => 'Confirmed Contact',
            'email' => 'confirmed@buyer.test', 'phone' => '3000000000',
            'country_of_destination' => 'France', 'coo_type' => null,
            'notify_party_same_as_consignee' => 1, 'notify_party' => null,
            'notify_party_address_line1' => null, 'notify_party_address_line2' => null,
            'notify_party_city' => null, 'notify_party_postcode' => null, 'notify_party_country' => null,
            'notify_party_contact_person' => null, 'notify_party_phone' => null, 'notify_party_email' => null,
        ]);
        OrderRepository::applyClientSnapshot($orderA, $clientId);

        $reloadedA = OrderRepository::find($orderA);
        $reloadedB = OrderRepository::find($orderB);

        self::assertSame('Confirmed Buyer Ltd', $reloadedA['company_legal_name'], 'the order the PI-intake was submitted for must pick up the confirmed details');
        self::assertSame('VAT-CONFIRMED', $reloadedA['vat_eori_tax_no']);
        self::assertSame('Original Buyer Ltd', $reloadedB['company_legal_name'], 'a sibling order for the same client must be completely unaffected');
        self::assertSame('VAT-ORIGINAL', $reloadedB['vat_eori_tax_no']);
    }

    public function testDuplicatingAnOrderReResolvesTodaysCurrentClientDetails(): void
    {
        $clientId = $this->createFullTestClient();
        $sourceOrderId = $this->createTestOrder($clientId);
        self::assertSame('Original Buyer Ltd', OrderRepository::find($sourceOrderId)['company_legal_name']);

        // "Later" the client's own details change (e.g. a legitimate admin correction).
        ClientRepository::update($clientId, [
            'company_legal_name' => 'Updated Buyer Ltd', 'billing_address' => 'Updated Address',
            'billing_address_line1' => null, 'billing_address_line2' => null, 'billing_city' => null,
            'billing_postcode' => null, 'billing_country' => null,
            'consignee_name' => null, 'consignee_address' => null,
            'consignee_same_as_buyer' => 1, 'consignee_address_line1' => null, 'consignee_address_line2' => null,
            'consignee_city' => null, 'consignee_postcode' => null, 'consignee_country' => null,
            'consignee_vat_eori_tax_no' => null, 'consignee_contact_person' => null,
            'consignee_phone' => null, 'consignee_email' => null,
            'vat_eori_tax_no' => 'VAT-UPDATED', 'contact_person' => 'Updated Contact',
            'email' => 'updated@buyer.test', 'phone' => '4000000000',
            'country_of_destination' => 'Italy', 'coo_type' => null,
            'notify_party_same_as_consignee' => 1, 'notify_party' => null,
            'notify_party_address_line1' => null, 'notify_party_address_line2' => null,
            'notify_party_city' => null, 'notify_party_postcode' => null, 'notify_party_country' => null,
            'notify_party_contact_person' => null, 'notify_party_phone' => null, 'notify_party_email' => null,
        ]);

        $duplicateOrderId = OrderDuplicationService::duplicate($sourceOrderId, $this->createTestUser('Admin'));

        $source = OrderRepository::find($sourceOrderId);
        $duplicate = OrderRepository::find($duplicateOrderId);

        self::assertSame('Original Buyer Ltd', $source['company_legal_name'], 'the original order must still reflect what was true when IT was created');
        self::assertSame('Updated Buyer Ltd', $duplicate['company_legal_name'], 'a duplicate is a new commercial decision made today — it must reflect the client\'s CURRENT details, not freeze-copy the source\'s old snapshot');
        self::assertSame('VAT-UPDATED', $duplicate['vat_eori_tax_no']);
    }
}
