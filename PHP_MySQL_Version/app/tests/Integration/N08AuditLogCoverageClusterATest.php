<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AccountController;
use App\Controllers\EmailTemplateController;
use App\Controllers\SignatoryController;
use App\Controllers\TestModeController;
use App\Controllers\UiTemplateController;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\SignatoryRepository;
use App\Repositories\UiTemplateRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report N-08, Cluster A: Settings/Account/Signatories/
 * Test-Mode/Email-template/UI-template routes never called
 * AuditLogRepository::log(), so none of them ever notified anyone either
 * (every log() call fans out to ActionNotificationService). This checks
 * the 16 of Appendix 1's 19 Cluster-A actions that are reachable without
 * a live SMTP/Zoho connection or a real HTTP file upload — testZohoEmail,
 * testSmtpEmail (both throw before reaching the log call without real
 * mail credentials) and uploadUserAsset (move_uploaded_file() refuses a
 * file this test harness didn't genuinely POST) were verified by code
 * review instead; see the Node mirror for uploadUserAsset's coverage,
 * which doesn't share PHP's upload-context restriction.
 */
final class N08AuditLogCoverageClusterATest extends DbTestCase
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

    public function testEmailTemplateStoreIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['template_key' => 'n08_test_key', 'subject' => 'Subject', 'body' => 'Body text'];
        ob_start();
        (new EmailTemplateController())->store([]);
        ob_end_clean();
        self::assertSame('EMAIL_TEMPLATE_CREATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testEmailTemplateUpdateIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = EmailTemplateRepository::create('n08_update_key', 'Old subject', 'Body', null, $userId);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['subject' => 'New subject', 'body' => 'Body'];
        ob_start();
        (new EmailTemplateController())->update(['id' => (string) $id]);
        ob_end_clean();
        self::assertSame('EMAIL_TEMPLATE_UPDATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testEmailTemplateToggleActiveIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = EmailTemplateRepository::create('n08_toggle_key', 'Subject', 'Body', null, $userId);
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new EmailTemplateController())->toggleActive(['id' => (string) $id]);
        ob_end_clean();
        self::assertSame('EMAIL_TEMPLATE_TOGGLED_ACTIVE', $this->lastAuditLogAction()['action_type']);
    }

    public function testUiTemplateToggleActiveIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $kanban = UiTemplateRepository::findByKey('kanban');
        ob_start();
        (new UiTemplateController())->toggleActive(['id' => (string) $kanban['id']]);
        ob_end_clean();
        self::assertSame('UI_TEMPLATE_TOGGLED_ACTIVE', $this->lastAuditLogAction()['action_type']);
        UiTemplateRepository::setActive((int) $kanban['id'], true);
    }

    public function testAccountUpdateSignatureIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['email_signature' => 'Regards, Test'];
        ob_start();
        (new AccountController())->updateSignature([]);
        ob_end_clean();
        self::assertSame('ACCOUNT_SIGNATURE_UPDATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testAccountUpdateNotificationPreferenceIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['email_action_notifications_enabled' => '1'];
        ob_start();
        (new AccountController())->updateNotificationPreference([]);
        ob_end_clean();
        self::assertSame('ACCOUNT_NOTIFICATION_PREFERENCE_UPDATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testAccountUpdateUiTemplateIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['ui_template_preference_id' => ''];
        ob_start();
        (new AccountController())->updateUiTemplate([]);
        ob_end_clean();
        self::assertSame('ACCOUNT_UI_TEMPLATE_PREFERENCE_UPDATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testSignatoryCreateDesignationIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['title' => 'N08 Test Designation'];
        ob_start();
        (new SignatoryController())->createDesignation([]);
        ob_end_clean();
        self::assertSame('SIGNATORY_DESIGNATION_CREATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testSignatoryToggleDesignationIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = SignatoryRepository::createDesignation('N08 Toggle Designation', $userId);
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new SignatoryController())->toggleDesignation(['id' => (string) $id]);
        ob_end_clean();
        self::assertSame('SIGNATORY_DESIGNATION_TOGGLED', $this->lastAuditLogAction()['action_type']);
    }

    public function testSignatoryUpdateDesignationIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = SignatoryRepository::createDesignation('N08 Rename Designation', $userId);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['title' => 'N08 Renamed Designation'];
        ob_start();
        (new SignatoryController())->updateDesignation(['id' => (string) $id]);
        ob_end_clean();
        self::assertSame('SIGNATORY_DESIGNATION_RENAMED', $this->lastAuditLogAction()['action_type']);
    }

    public function testSignatoryDeleteDesignationIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = SignatoryRepository::createDesignation('N08 Delete Designation', $userId);
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new SignatoryController())->deleteDesignation(['id' => (string) $id]);
        ob_end_clean();
        self::assertSame('SIGNATORY_DESIGNATION_DELETED', $this->lastAuditLogAction()['action_type']);
    }

    public function testSignatoryDeactivateUserAssetIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $assetId = SignatoryRepository::addUserAsset($userId, 'signature', 'Default', '/tmp/n08-fake-asset.png', 'image/png', $userId);
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new SignatoryController())->deactivateUserAsset(['id' => (string) $assetId]);
        ob_end_clean();
        self::assertSame('SIGNATORY_USER_ASSET_DEACTIVATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testTestModeEnableIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new TestModeController())->enable([]);
        ob_end_clean();
        self::assertSame('TEST_MODE_ENABLED', $this->lastAuditLogAction()['action_type']);
        \App\Services\TestModeService::disable();
    }

    public function testTestModeDisableIsLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        \App\Services\TestModeService::enable($userId);
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new TestModeController())->disable([]);
        ob_end_clean();
        self::assertSame('TEST_MODE_DISABLED', $this->lastAuditLogAction()['action_type']);
    }

    public function testTestModeUpdateTestEmailIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['test_email' => 'n08-test@nexacrest.test'];
        ob_start();
        (new TestModeController())->updateTestEmail([]);
        ob_end_clean();
        self::assertSame('TEST_MODE_TEST_EMAIL_UPDATED', $this->lastAuditLogAction()['action_type']);
    }

    public function testTestModeDeleteTestDataIsLogged(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new TestModeController())->deleteTestData([]);
        ob_end_clean();
        self::assertSame('TEST_MODE_TEST_DATA_DELETED', $this->lastAuditLogAction()['action_type']);
    }
}
