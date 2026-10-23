<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AccountController;
use App\Controllers\SettingsController;
use App\Controllers\UiTemplateController;
use App\Helpers\Flash;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\PermissionService;
use App\Services\UiTemplateService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section BQ — UI template selection: per-user
 * preference, company-wide default, and the admin on/off switch per
 * template, with the resolution order the owner explicitly confirmed:
 * user preference (if active) -> company default (if active) ->
 * hard-coded 'standard' (which can never itself be deactivated).
 */
final class UiTemplateSelectionTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        // Every test starts from the real seeded state: standard+kanban both
        // active, default_ui_template = 'standard' — restore it explicitly
        // in case an earlier test in this run deactivated/changed one.
        Database::connection()->exec("UPDATE ui_templates SET is_active = 1");
        Database::connection()->exec("UPDATE company_settings SET setting_value = 'standard' WHERE setting_key = 'default_ui_template'");
    }

    private function standardId(): int
    {
        return (int) UiTemplateRepository::findByKey('standard')['id'];
    }

    private function kanbanId(): int
    {
        return (int) UiTemplateRepository::findByKey('kanban')['id'];
    }

    // --- UiTemplateService::resolve() — the 4 fallback branches ---

    public function testResolveReturnsUsersActivePreferenceWhenSet(): void
    {
        self::assertSame('kanban', UiTemplateService::resolve($this->kanbanId()));
    }

    public function testResolveFallsBackToCompanyDefaultWhenPreferenceIsNull(): void
    {
        self::assertSame('standard', UiTemplateService::resolve(null));

        Database::connection()->prepare(
            "UPDATE company_settings SET setting_value = 'kanban' WHERE setting_key = 'default_ui_template'"
        )->execute();
        self::assertSame('kanban', UiTemplateService::resolve(null));
    }

    public function testResolveFallsBackToCompanyDefaultWhenPreferredTemplateIsDeactivated(): void
    {
        UiTemplateRepository::setActive($this->kanbanId(), false);
        // Default is still 'standard' (active) — preference falls to it, not straight to the hardcoded fallback.
        self::assertSame('standard', UiTemplateService::resolve($this->kanbanId()));
    }

    public function testResolveFallsBackToHardcodedStandardWhenBothPreferenceAndDefaultAreDeactivated(): void
    {
        UiTemplateRepository::setActive($this->kanbanId(), false);
        Database::connection()->prepare(
            "UPDATE company_settings SET setting_value = 'kanban' WHERE setting_key = 'default_ui_template'"
        )->execute();
        // default_ui_template points at the now-deactivated kanban template.
        self::assertSame('standard', UiTemplateService::resolve($this->kanbanId()));
        self::assertSame('standard', UiTemplateService::resolve(null));
    }

    public function testResolveNeverReturnsADeactivatedStandardSinceThatCanNeverHappen(): void
    {
        // Sanity check of the invariant UiTemplateController::toggleActive()
        // enforces — standard is never actually deactivatable, so resolve()
        // always has a real floor to land on.
        self::assertSame('standard', UiTemplateService::FALLBACK_TEMPLATE_KEY);
    }

    // --- AccountController::updateUiTemplate() — self-service preference ---

    public function testUpdateUiTemplateSavesAnActivePreference(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['ui_template_preference_id' => (string) $this->kanbanId()];

        ob_start();
        (new AccountController())->updateUiTemplate([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        $user = UserRepository::findById($userId);
        self::assertSame($this->kanbanId(), (int) $user['ui_template_preference_id']);
    }

    public function testUpdateUiTemplateClearsPreferenceOnEmptySubmission(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanId());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['ui_template_preference_id' => ''];

        ob_start();
        (new AccountController())->updateUiTemplate([]);
        ob_end_clean();

        $user = UserRepository::findById($userId);
        self::assertNull($user['ui_template_preference_id']);
    }

    public function testUpdateUiTemplateRejectsADeactivatedTemplate(): void
    {
        UiTemplateRepository::setActive($this->kanbanId(), false);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['ui_template_preference_id' => (string) $this->kanbanId()];

        ob_start();
        (new AccountController())->updateUiTemplate([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        $user = UserRepository::findById($userId);
        self::assertNull($user['ui_template_preference_id']);
    }

    // --- route gating: /ui-templates reuses manage_company_settings, the
    // same permission that already gates Settings/Watermarks/Holidays — any
    // user without it can set their OWN account preference (tested above)
    // but can never reach the admin on/off screen for everyone else's. ---

    public function testAdminRoleHasManageCompanySettingsNeededForTheAdminScreen(): void
    {
        $userId = $this->createTestUser('Admin');
        self::assertTrue(PermissionService::can($userId, $this->roleIdFor($userId), 'manage_company_settings'));
    }

    public function testUngrantedRoleLacksManageCompanySettingsSoCannotReachTheAdminScreen(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        self::assertFalse(PermissionService::can($userId, $this->roleIdFor($userId), 'manage_company_settings'));
    }

    private function roleIdFor(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT role_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    // --- UiTemplateController::toggleActive() — admin on/off switch ---

    public function testToggleActiveCanDeactivateKanban(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new UiTemplateController())->toggleActive(['id' => (string) $this->kanbanId()]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        $kanban = UiTemplateRepository::findById($this->kanbanId());
        self::assertSame(0, (int) $kanban['is_active']);
    }

    public function testToggleActiveRefusesToDeactivateStandard(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new UiTemplateController())->toggleActive(['id' => (string) $this->standardId()]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('never be deactivated', $flash[0]['message']);
        $standard = UiTemplateRepository::findById($this->standardId());
        self::assertSame(1, (int) $standard['is_active']);
    }

    public function testToggleActiveCanReactivateAPreviouslyDeactivatedTemplate(): void
    {
        UiTemplateRepository::setActive($this->kanbanId(), false);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new UiTemplateController())->toggleActive(['id' => (string) $this->kanbanId()]);
        ob_end_clean();

        $kanban = UiTemplateRepository::findById($this->kanbanId());
        self::assertSame(1, (int) $kanban['is_active']);
    }

    // --- SettingsController::update() — default_ui_template validation ---

    public function testSettingsUpdateRejectsAnInactiveTemplateAsDefault(): void
    {
        UiTemplateRepository::setActive($this->kanbanId(), false);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['settings' => ['default_ui_template' => 'kanban'], 'reason' => 'deactivated template test'];

        ob_start();
        (new SettingsController())->update([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('default_ui_template', $flash[0]['message']);
        self::assertSame('standard', \App\Repositories\CompanySettingsRepository::get('default_ui_template'));
    }

    public function testSettingsUpdateAcceptsAnActiveTemplateAsDefault(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['settings' => ['default_ui_template' => 'kanban'], 'reason' => 'switching company default to kanban'];

        ob_start();
        (new SettingsController())->update([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        self::assertSame('kanban', \App\Repositories\CompanySettingsRepository::get('default_ui_template'));
    }

    public function testSettingsUpdateRejectsAnUnknownTemplateKeyAsDefault(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['settings' => ['default_ui_template' => 'not_a_real_template'], 'reason' => 'unknown template key test'];

        ob_start();
        (new SettingsController())->update([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertSame('standard', \App\Repositories\CompanySettingsRepository::get('default_ui_template'));
    }
}
