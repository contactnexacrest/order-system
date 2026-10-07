<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ReferenceDocController;
use App\Repositories\ReferenceLibraryCategoryRepository;
use App\Repositories\ReferenceLibraryRepository;
use App\Tests\Support\DbTestCase;

/**
 * Batch 3 #13a (part 1) — Reference Library categories. A category is
 * purely a grouping label (name) with an optional required_permission:
 * NULL (the default, and the only state that existed before this
 * feature) means visible to every authenticated staff member; set, only
 * a user holding that permission can see documents filed under it. The
 * fixed 8 internal_reference_docs are never scoped by this — only the
 * free-form custom entries (reference_library_documents).
 */
final class ReferenceLibraryCategoriesTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    public function testCategoryRepositoryCrud(): void
    {
        $id = ReferenceLibraryCategoryRepository::create('CA / Accounts', 'ca_module_view');
        $category = ReferenceLibraryCategoryRepository::find($id);
        self::assertSame('CA / Accounts', $category['name']);
        self::assertSame('ca_module_view', $category['required_permission']);

        ReferenceLibraryCategoryRepository::update($id, 'CA / Accounts (renamed)', null);
        $category = ReferenceLibraryCategoryRepository::find($id);
        self::assertSame('CA / Accounts (renamed)', $category['name']);
        self::assertNull($category['required_permission']);
    }

    public function testDeletingACategoryUncategorizesItsDocumentsRatherThanDeletingThem(): void
    {
        $userId = $this->createTestUser('Admin');
        $categoryId = ReferenceLibraryCategoryRepository::create('Restricted', 'ca_module_view');
        $docId = ReferenceLibraryRepository::create('A restricted doc', 'content', $userId, $categoryId);

        ReferenceLibraryCategoryRepository::delete($categoryId);

        self::assertNull(ReferenceLibraryCategoryRepository::find($categoryId));
        $doc = ReferenceLibraryRepository::find($docId);
        self::assertNotNull($doc, 'the document itself must never be deleted');
        self::assertNull($doc['category_id']);
    }

    public function testIndexShowsAnUncategorizedDocumentToEveryStaffMember(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        ReferenceLibraryRepository::create('Visible To Everyone', null, $userId, null);

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new ReferenceDocController())->index([]);
        $html = ob_get_clean();

        self::assertStringContainsString('Visible To Everyone', $html);
    }

    public function testIndexHidesARestrictedCategoryDocFromAUserWithoutThePermission(): void
    {
        $viewerId = $this->createTestUser('Viewer / Auditor'); // holds view_reports/view_audit_log/view_product_catalog/view_orders/view_clients — not ca_module_view
        $categoryId = ReferenceLibraryCategoryRepository::create('CA Only ' . bin2hex(random_bytes(4)), 'ca_module_view');
        ReferenceLibraryRepository::create('CA Working Paper', null, $viewerId, $categoryId);

        $_SESSION['_auth_user_id'] = $viewerId;
        ob_start();
        (new ReferenceDocController())->index([]);
        $html = ob_get_clean();

        self::assertStringNotContainsString('CA Working Paper', $html);
    }

    public function testIndexShowsARestrictedCategoryDocToAUserWhoHoldsThePermission(): void
    {
        $caUserId = $this->createTestUser('CA / Chartered Accountant'); // holds ca_module_view
        $categoryId = ReferenceLibraryCategoryRepository::create('CA Only ' . bin2hex(random_bytes(4)), 'ca_module_view');
        ReferenceLibraryRepository::create('CA Working Paper', null, $caUserId, $categoryId);

        $_SESSION['_auth_user_id'] = $caUserId;
        ob_start();
        (new ReferenceDocController())->index([]);
        $html = ob_get_clean();

        self::assertStringContainsString('CA Working Paper', $html);
    }

    public function testCustomShowDirectUrlIsBlockedForARestrictedCategoryWithoutThePermission(): void
    {
        $viewerId = $this->createTestUser('Viewer / Auditor');
        $categoryId = ReferenceLibraryCategoryRepository::create('CA Only ' . bin2hex(random_bytes(4)), 'ca_module_view');
        $docId = ReferenceLibraryRepository::create('CA Working Paper', null, $viewerId, $categoryId);

        $_SESSION['_auth_user_id'] = $viewerId;
        ob_start();
        (new ReferenceDocController())->customShow(['id' => (string) $docId]);
        $html = ob_get_clean();

        self::assertSame(403, http_response_code());
        self::assertStringNotContainsString('CA Working Paper', $html);
    }

    public function testCustomShowDirectUrlWorksForTheRealOwnerPermission(): void
    {
        $caUserId = $this->createTestUser('CA / Chartered Accountant');
        $categoryId = ReferenceLibraryCategoryRepository::create('CA Only ' . bin2hex(random_bytes(4)), 'ca_module_view');
        $docId = ReferenceLibraryRepository::create('CA Working Paper', null, $caUserId, $categoryId);

        $_SESSION['_auth_user_id'] = $caUserId;
        http_response_code(200);
        ob_start();
        (new ReferenceDocController())->customShow(['id' => (string) $docId]);
        $html = ob_get_clean();

        self::assertNotSame(403, http_response_code());
        self::assertStringContainsString('CA Working Paper', $html);
    }

    public function testCustomCreatePersistsTheChosenCategory(): void
    {
        $userId = $this->createTestUser('Admin');
        $categoryId = ReferenceLibraryCategoryRepository::create('Logistics ' . bin2hex(random_bytes(4)), null);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['title' => 'Freight Rate Sheet', 'content' => 'x', 'category_id' => (string) $categoryId];

        ob_start();
        (new ReferenceDocController())->customCreate([]);
        ob_end_clean();

        $docs = array_values(array_filter(ReferenceLibraryRepository::all(), static fn (array $d): bool => $d['title'] === 'Freight Rate Sheet'));
        self::assertCount(1, $docs);
        self::assertSame($categoryId, (int) $docs[0]['category_id']);
    }

    public function testCustomUpdateCanClearTheCategoryBackToUncategorized(): void
    {
        $userId = $this->createTestUser('Admin');
        $categoryId = ReferenceLibraryCategoryRepository::create('Logistics ' . bin2hex(random_bytes(4)), null);
        $docId = ReferenceLibraryRepository::create('Freight Rate Sheet', 'x', $userId, $categoryId);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['title' => 'Freight Rate Sheet', 'content' => 'x', 'category_id' => ''];
        ob_start();
        (new ReferenceDocController())->customUpdate(['id' => (string) $docId]);
        ob_end_clean();

        $doc = ReferenceLibraryRepository::find($docId);
        self::assertNull($doc['category_id']);
    }

    public function testCategoryCreateRequiresAName(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['name' => '', 'required_permission' => 'ca_module_view'];

        $before = count(ReferenceLibraryCategoryRepository::all());
        ob_start();
        (new ReferenceDocController())->categoryCreate([]);
        ob_end_clean();

        self::assertCount($before, ReferenceLibraryCategoryRepository::all());
    }
}
