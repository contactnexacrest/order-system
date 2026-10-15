<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\AnnexureController;
use App\Helpers\Flash;
use App\Repositories\OrderAnnexureRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #69: the "Add Product Entry" form was a single bare
 * column of <label> elements — unlike every other multi-field form in the
 * app, which uses the sitewide .field-grid 2-column layout — and had no way
 * to attach an image at creation time; an image could only be added in a
 * separate step after the product entry already existed. Fixed by wrapping
 * the form in .field-grid (textareas spanning both columns via
 * label.full, matching the established convention) and adding an optional
 * image upload accepted directly on product creation.
 */
final class AnnexureAddProductFormTest extends DbTestCase
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

    public function testAddProductEntryFormUsesTwoColumnFieldGrid(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->renderAnnexureIndex($orderId);

        $addFormPos = strpos($output, 'Add Product Entry');
        self::assertNotFalse($addFormPos);
        $addFormSection = substr($output, $addFormPos, 2000);

        self::assertStringContainsString('class="field-grid"', $addFormSection);
        self::assertStringContainsString('<label class="full">Description', $addFormSection);
        self::assertStringContainsString('<label class="full">Components', $addFormSection);
        self::assertStringContainsString('<label class="full">Technical Notes', $addFormSection);
    }

    public function testAddProductEntryFormAcceptsAnImageUploadAtCreation(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->renderAnnexureIndex($orderId);

        $addFormPos = strpos($output, 'Add Product Entry');
        self::assertNotFalse($addFormPos);
        $addFormSection = substr($output, $addFormPos, 2000);

        self::assertStringContainsString('enctype="multipart/form-data"', $addFormSection);
        self::assertStringContainsString('<input type="file" name="image"', $addFormSection);
    }

    public function testCreateProductStillSucceedsWithNoImageSelected(): void
    {
        // Regression guard: adding the optional image-upload branch must not
        // break the pre-existing "no image" path that every prior Annexure
        // product entry has always used.
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = [
            'name' => 'Marble Side Table',
            'description' => 'Round top',
            'dimensions' => '60 x 60 x 45 cm',
            'finish' => 'Polished',
            'components' => '',
            'technical_notes' => '',
        ];
        $_FILES = [];

        ob_start();
        (new AnnexureController())->createProduct(['id' => (string) $orderId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);

        $products = OrderAnnexureRepository::forOrder($orderId);
        self::assertCount(1, $products);
        self::assertSame('Marble Side Table', $products[0]['name']);
        self::assertEmpty($products[0]['images'], 'no image was uploaded, so none should be attached');
    }
}
