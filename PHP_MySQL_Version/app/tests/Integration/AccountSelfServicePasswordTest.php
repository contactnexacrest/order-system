<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AccountController;
use App\Helpers\Flash;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #26 — a voluntary, logged-in self-service password
 * change for staff users (AccountController::updatePassword). Mirrors
 * the existing client-portal self-service password change
 * (ClientPortalController::changePassword): current password must be
 * re-verified, then PasswordPolicyService, then a match check.
 */
final class AccountSelfServicePasswordTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    private function createTestUserWithPassword(string $password): int
    {
        $userId = $this->createTestUser('Admin');
        Database::connection()
            ->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $userId]);
        return $userId;
    }

    public function testUpdatePasswordRejectsWrongCurrentPassword(): void
    {
        $userId = $this->createTestUserWithPassword('OldPass123!');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['current_password' => 'WrongPass!', 'new_password' => 'NewPass456!', 'confirm_password' => 'NewPass456!'];

        ob_start();
        (new AccountController())->updatePassword([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('incorrect', $flash[0]['message']);

        $user = UserRepository::findById($userId);
        self::assertTrue(password_verify('OldPass123!', $user['password_hash']), 'password must be unchanged');
    }

    public function testUpdatePasswordRejectsMismatchedConfirmation(): void
    {
        $userId = $this->createTestUserWithPassword('OldPass123!');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['current_password' => 'OldPass123!', 'new_password' => 'NewPass456!', 'confirm_password' => 'Different789!'];

        ob_start();
        (new AccountController())->updatePassword([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('match', $flash[0]['message']);
    }

    public function testUpdatePasswordRejectsPolicyViolation(): void
    {
        $userId = $this->createTestUserWithPassword('OldPass123!');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['current_password' => 'OldPass123!', 'new_password' => 'short', 'confirm_password' => 'short'];

        ob_start();
        (new AccountController())->updatePassword([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }

    public function testUpdatePasswordSucceedsWithCorrectCurrentPasswordAndValidNew(): void
    {
        $userId = $this->createTestUserWithPassword('OldPass123!');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['current_password' => 'OldPass123!', 'new_password' => 'BrandNewPass789!', 'confirm_password' => 'BrandNewPass789!'];

        ob_start();
        (new AccountController())->updatePassword([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);

        $user = UserRepository::findById($userId);
        self::assertTrue(password_verify('BrandNewPass789!', $user['password_hash']));
        self::assertFalse(password_verify('OldPass123!', $user['password_hash']));
    }
}
