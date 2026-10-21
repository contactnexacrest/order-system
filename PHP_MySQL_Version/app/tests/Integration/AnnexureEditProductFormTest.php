<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\AnnexureController;
use App\Repositories\OrderAnnexureRepository;
use App\Tests\Support\DbTestCase;

/**
 * User-reported batch item 5: "why product table is single row format -
 * wach and every input form must be 2 column per row format wherever
 * possible". 90-item backlog #69 (AnnexureAddProductFormTest) already
 * fixed the "Add Product Entry" form below this one to the sitewide
 * .field-grid 2-column layout, but left the per-entry EDIT form (one per
 * already-saved product, in the "Product Entries" section above it)
 * as the original bare single column of <label> elements — the exact
 * screenshot-confirmed gap this closes.
 */
final class AnnexureEditProductFormTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
        $_GET = [];
    }

    private function renderAnnexureIndex(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new AnnexureController())->index(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testPerEntryEditFormUsesTwoColumnFieldGrid(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderAnnexureRepository::createProduct($orderId, [
            'name' => 'Marble Side Table',
            'description' => 'Round top',
            'dimensions' => '60 x 60 x 45 cm',
            'finish' => 'Polished',
            'components' => null,
            'technical_notes' => null,
        ]);

        $output = $this->renderAnnexureIndex($orderId);

        $entriesPos = strpos($output, 'Product Entries');
        $addFormPos = strpos($output, 'Add Product Entry');
        self::assertNotFalse($entriesPos);
        self::assertNotFalse($addFormPos);
        $editFormSection = substr($output, $entriesPos, $addFormPos - $entriesPos);

        self::assertStringContainsString('class="field-grid"', $editFormSection);
        self::assertStringContainsString('<label class="full">Description', $editFormSection);
        self::assertStringContainsString('<label class="full">Components', $editFormSection);
        self::assertStringContainsString('<label class="full">Technical Notes', $editFormSection);
    }
}
