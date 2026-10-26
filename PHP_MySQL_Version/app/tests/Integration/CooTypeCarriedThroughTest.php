<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Controllers\ClientIntakeController;
use App\Controllers\ClientIntakeReviewController;
use App\Controllers\OrderController;
use App\Controllers\PiIntakeController;
use App\Controllers\PiIntakeReviewController;
use App\Repositories\ClientRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner report (2026-10-26): the Certificate of Origin Type a client gave
 * on the Quotation details form was lost — the form took free text
 * ("GSP Form A") but only an exact match to an admin option ("GSP Form A
 * (Preferential)") was kept. It now is a dropdown, loose spellings are
 * matched, and the value follows the client to the client record, the
 * New Order form, the order and the PI-details confirmation.
 */
final class CooTypeCarriedThroughTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $_GET = [];
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    public function testLooseSpellingsMatchTheAdminOptions(): void
    {
        $options = ['GSP Form A (Preferential)', 'Non-Preferential'];
        self::assertSame('GSP Form A (Preferential)', LookupRepository::matchOption($options, 'GSP Form A'));
        self::assertSame('GSP Form A (Preferential)', LookupRepository::matchOption($options, '  gsp form a (preferential) '));
        self::assertSame('Non-Preferential', LookupRepository::matchOption($options, 'non-preferential'));
        self::assertSame('', LookupRepository::matchOption($options, 'Something else'));
        self::assertSame('', LookupRepository::matchOption($options, ''));
    }

    public function testTheClientsQuotationAnswerReachesTheClientRecordAndTheNewOrderForm(): void
    {
        $form = $this->render(fn () => (new ClientIntakeController())->show([]));
        self::assertMatchesRegularExpression('/<select name="coo_type">/', $form, 'the client picks from the real options');
        self::assertStringContainsString('>GSP Form A (Preferential)<', $form);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(10, 250);
        $_POST = [
            'company_legal_name'     => 'COO Carry Test Co ' . bin2hex(random_bytes(3)),
            'billing_address_line1'  => '1 Test Street',
            'billing_city'           => 'Testville',
            'billing_country'        => 'United Kingdom',
            'vat_eori_tax_no'        => 'GB123456789',
            'contact_person'         => 'Jane Test',
            'email'                  => 'coo-' . bin2hex(random_bytes(4)) . '@example.test',
            'country_of_destination' => 'United Kingdom',
            'incoterm_preference'    => 'FOB',
            'coo_type'               => 'GSP Form A',
        ];
        $this->render(fn () => (new ClientIntakeController())->submit([]));
        $submission = Database::connection()->query('SELECT id, coo_type FROM client_intake_submissions ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('GSP Form A (Preferential)', $submission['coo_type'], 'a loosely typed answer is kept, in the option\'s own spelling');

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = [];
        (new ClientIntakeReviewController())->accept(['id' => (string) $submission['id']]);
        $clientId = (int) Database::connection()->query('SELECT converted_client_id FROM client_intake_submissions WHERE id = ' . (int) $submission['id'])->fetchColumn();
        self::assertSame('GSP Form A (Preferential)', ClientRepository::find($clientId)['coo_type']);

        $clientPage = $this->render(fn () => (new ClientController())->show(['id' => (string) $clientId]));
        self::assertStringContainsString('Certificate of Origin Type', $clientPage);
        self::assertStringContainsString('GSP Form A (Preferential)', $clientPage);

        $_GET = ['client_id' => (string) $clientId];
        $newOrder = $this->render(fn () => (new OrderController())->create([]));
        self::assertMatchesRegularExpression('/<option value="GSP Form A \(Preferential\)" selected>/', $newOrder, 'the New Order form starts from the client\'s answer');
    }

    public function testThePiConfirmationUpdatesTheOrderAndABlankAnswerKeepsTheKnownValue(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        foreach (['GSP Form A (Preferential)' => 'GSP Form A (Preferential)', '' => 'Non-Preferential'] as $answer => $expectedClient) {
            $clientId = $this->createTestClient();
            Database::connection()->prepare('UPDATE clients SET coo_type = :c WHERE id = :id')->execute(['c' => 'Non-Preferential', 'id' => $clientId]);
            $orderId = $this->createTestOrder($clientId, 'FOB');
            $token = PiIntakeRepository::createLink($orderId, null);
            $submission = PiIntakeRepository::findValidByToken($token);
            $form = $this->render(fn () => (new PiIntakeController())->show(['token' => $token]));
            self::assertStringContainsString('<select name="coo_type">', $form);

            PiIntakeRepository::submit((int) $submission['id'], [
                'company_legal_name' => $submission['company_legal_name'], 'billing_address' => $submission['billing_address'],
                'vat_eori_tax_no' => $submission['vat_eori_tax_no'], 'contact_person' => $submission['contact_person'],
                'email' => $submission['email'], 'phone' => $submission['phone'],
                'port_of_discharge_text' => null, 'country_of_destination' => $submission['country_of_destination'],
                'incoterm_confirmed' => null, 'container_type_text' => null, 'payment_terms_confirmation' => null,
                'quotation_acceptance_reference' => null, 'coo_type' => $answer ?: null, 'buyer_po_ref' => null,
                'changes_from_quotation' => null, 'special_document_requirements' => null,
            ], '127.0.0.1');
            (new PiIntakeReviewController())->accept(['id' => (string) $submission['id']]);

            self::assertSame($expectedClient, ClientRepository::find($clientId)['coo_type'], "client after PI answer '{$answer}'");
            if ($answer !== '') {
                self::assertSame($answer, OrderRepository::find($orderId)['coo_type'], 'the order (and so its documents) uses the confirmed COO type');
            }
        }
    }
}
