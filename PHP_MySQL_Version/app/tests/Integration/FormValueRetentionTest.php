<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Tests\Support\DbTestCase;

/**
 * Batch 3 #4: a validation failure on the Client or Order creation form used
 * to lose every value the buyer/staff had already typed — Flash::set() only
 * carried the error message across the redirect, never the submitted data,
 * so the create() view always re-rendered from a blank $_POST. Flash::setOld()/
 * pullOld() now carries the raw submission across that one redirect so the
 * re-shown form is pre-filled, including each row of a multi-line product
 * table. Verified by rendering the create view's actual output after a
 * failed store(), the same seam the buyer/staff would see.
 */
final class FormValueRetentionTest extends DbTestCase
{
    private int $userId;
    private int $incotermId;
    private int $currencyId;
    private int $paymentPresetId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $this->userId;

        $pdo = Database::connection();
        $this->incotermId = (int) $pdo->query("SELECT id FROM incoterms WHERE code = 'FOB'")->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
        $this->paymentPresetId = (int) $pdo->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")->fetchColumn();
    }

    public function testFlashSetOldAndPullOldRoundTrip(): void
    {
        self::assertSame([], Flash::pullOld(), 'pullOld() must return an empty array when nothing was ever set');

        Flash::setOld(['company_legal_name' => 'Acme Exports']);
        self::assertSame(['company_legal_name' => 'Acme Exports'], Flash::pullOld());

        // one-shot: a second pull after the first must come back empty
        self::assertSame([], Flash::pullOld(), 'pullOld() must clear the data after one read');
    }

    public function testClientCreateFormIsRepopulatedAfterAValidationFailure(): void
    {
        $_POST = [
            'company_legal_name' => '',
            'billing_address'    => '',
            'contact_person'     => 'Jane Buyer',
            'email'              => 'jane@example.com',
        ];

        ob_start();
        (new ClientController())->store([]);
        ob_end_clean();

        // store() redirected without creating a client; simulate the browser's
        // next request by rendering create() again in the same "session".
        ob_start();
        (new ClientController())->create([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('jane@example.com', $html, 'previously typed email must be pre-filled on the re-shown form');
        self::assertStringContainsString('Jane Buyer', $html, 'previously typed contact person must be pre-filled on the re-shown form');
    }

    public function testClientCreateFormOldValuesAreOneShot(): void
    {
        $_POST = ['company_legal_name' => '', 'billing_address' => '', 'contact_person' => 'One Shot Co'];
        ob_start();
        (new ClientController())->store([]);
        ob_end_clean();

        ob_start();
        (new ClientController())->create([]); // first re-render consumes the old() data
        ob_end_clean();

        ob_start();
        (new ClientController())->create([]); // a later, unrelated visit must not see it again
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('One Shot Co', $html, 'old() values must not resurface on a later unrelated visit');
    }

    public function testOrderCreateFormRepopulatesScalarFieldsAfterAnInvalidHsCode(): void
    {
        $clientId = $this->createTestClient();

        $_POST = [
            'client_id'           => (string) $clientId,
            'incoterm_id'         => (string) $this->incotermId,
            'currency_id'         => (string) $this->currencyId,
            'payment_preset_id'   => (string) $this->paymentPresetId,
            'est_lead_time_text'  => '45-60 days from advance receipt',
            'special_requirements' => 'Handle with extreme care',
            'product_description' => ['Granite slab — Batch 3 #4 regression'],
            'product_hs_code'     => ['NOT-A-REAL-CODE'],
            'product_quantity'    => ['10'],
            'product_unit_price'  => ['5.00'],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();

        ob_start();
        (new OrderController())->create([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('45-60 days from advance receipt', $html, 'est. lead time must be pre-filled');
        self::assertStringContainsString('Handle with extreme care', $html, 'special requirements must be pre-filled');
        self::assertStringContainsString('Granite slab — Batch 3 #4 regression', $html, 'the product description must be pre-filled');
        self::assertStringContainsString('NOT-A-REAL-CODE', $html, 'the (invalid) HS code the user typed must be pre-filled so they can see and fix it');
    }

    public function testOrderCreateFormRepopulatesEveryProductRowAfterAValidationFailure(): void
    {
        $clientId = $this->createTestClient();

        $_POST = [
            'client_id'           => (string) $clientId,
            'incoterm_id'         => (string) $this->incotermId,
            'currency_id'         => (string) $this->currencyId,
            'payment_preset_id'   => (string) $this->paymentPresetId,
            'product_description' => ['First line granite', 'Second line marble'],
            'product_dimensions'  => ['600x600', '300x300'],
            'product_hs_code'     => ['680293', 'BAD-CODE'],
            'product_quantity'    => ['10', '20'],
            'product_unit_price'  => ['5.00', '7.50'],
        ];

        $before = (int) Database::connection()->query('SELECT COUNT(*) FROM orders')->fetchColumn();

        ob_start();
        (new OrderController())->store([]); // line 2's bad HS code must fail the whole submission
        ob_end_clean();

        // A delta, not an absolute count — this disposable DB accumulates
        // orders from every other Integration test class in the same
        // process/run (see OrderCreationValidationTest for the same pattern).
        self::assertSame($before, (int) Database::connection()->query('SELECT COUNT(*) FROM orders')->fetchColumn(), 'no order must be created when any line fails validation');

        ob_start();
        (new OrderController())->create([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('First line granite', $html, 'row 1 description must survive');
        self::assertStringContainsString('Second line marble', $html, 'row 2 description must survive, not just row 1');
        self::assertStringContainsString('600x600', $html, 'row 1 dimensions must survive');
        self::assertStringContainsString('300x300', $html, 'row 2 dimensions must survive');
    }
}
