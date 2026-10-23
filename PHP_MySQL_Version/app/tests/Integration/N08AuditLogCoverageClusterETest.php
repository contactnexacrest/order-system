<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AnnexureController;
use App\Repositories\OrderAnnexureRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report N-08, Cluster E: 5 of the 6 Annexure actions never
 * called AuditLogRepository::log(), so none of them ever notified anyone
 * either. uploadImage wraps FileUploadService::handleUpload(), whose
 * move_uploaded_file() call cannot succeed outside a real HTTP upload
 * (same constraint documented in BlCopyUploadTest.php) — verified by
 * code review instead; see the Node mirror for its coverage.
 */
final class N08AuditLogCoverageClusterETest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
    }

    private function lastAuditLogAction(): ?array
    {
        $stmt = Database::connection()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
        return $stmt->fetch() ?: null;
    }

    public function testToggleIncludeIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['include_annexure_a' => '1'];

        ob_start();
        (new AnnexureController())->toggleInclude(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ANNEXURE_INCLUDE_TOGGLED', $this->lastAuditLogAction()['action_type']);
    }

    public function testCreateProductIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['name' => 'N08 Test Product'];

        ob_start();
        (new AnnexureController())->createProduct(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('ANNEXURE_PRODUCT_CREATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testUpdateProductIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $productId = OrderAnnexureRepository::createProduct($orderId, ['name' => 'Original Name', 'description' => '', 'dimensions' => '', 'finish' => '', 'components' => '', 'technical_notes' => '']);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['name' => 'Updated Name'];

        ob_start();
        (new AnnexureController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $productId]);
        ob_end_clean();

        self::assertSame('ANNEXURE_PRODUCT_UPDATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testDeleteProductIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $productId = OrderAnnexureRepository::createProduct($orderId, ['name' => 'To Delete', 'description' => '', 'dimensions' => '', 'finish' => '', 'components' => '', 'technical_notes' => '']);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new AnnexureController())->deleteProduct(['id' => (string) $orderId, 'productId' => (string) $productId]);
        ob_end_clean();

        self::assertSame('ANNEXURE_PRODUCT_DELETED', $this->lastAuditLogAction()['action_type']);
    }

    public function testRemoveImageIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $productId = OrderAnnexureRepository::createProduct($orderId, ['name' => 'With Image', 'description' => '', 'dimensions' => '', 'finish' => '', 'components' => '', 'technical_notes' => '']);
        $fileId = $this->createTestFile($orderId);
        $imageId = OrderAnnexureRepository::addImage($productId, $fileId);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new AnnexureController())->removeImage(['id' => (string) $orderId, 'imageId' => (string) $imageId]);
        ob_end_clean();

        self::assertSame('ANNEXURE_IMAGE_REMOVED', $this->lastAuditLogAction()['action_type']);
    }
}
