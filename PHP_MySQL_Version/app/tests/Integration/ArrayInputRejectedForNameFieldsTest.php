<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ComplianceTaskTypeController;
use App\Controllers\PermissionAdminController;
use App\Helpers\Flash;
use App\Repositories\RoleRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report R-06/R-15: "Array-shaped input creates junk master
 * records: role named 'Array' and compliance task type 'Array' (PHP)."
 * `trim((string) ($_POST['name'] ?? ''))` never rejected an array —
 * PHP's (string) cast on an array evaluates to the literal string
 * "Array" and sails past the empty-value check. Input::scalarString()
 * now rejects array input explicitly, falling back to '' so the
 * caller's existing "name is required" validation catches it.
 */
final class ArrayInputRejectedForNameFieldsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testRoleCreateRejectsArrayShapedNameInsteadOfStoringTheWordArray(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['name' => ['a', 'b']];

        ob_start();
        (new PermissionAdminController())->roleCreate([]);
        ob_end_clean();

        self::assertNull(RoleRepository::findByName('Array'), 'array-shaped input must never create a role literally named "Array"');
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type'] ?? null);
        self::assertStringContainsString('required', $flash[0]['message'] ?? '');
    }

    public function testComplianceTaskTypeCreateRejectsArrayShapedNameInsteadOfStoringTheWordArray(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['name' => ['a', 'b']];

        ob_start();
        (new ComplianceTaskTypeController())->create([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type'] ?? null);
        self::assertStringContainsString('required', $flash[0]['message'] ?? '');
    }
}
