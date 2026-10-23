<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Services\AuthService;
use App\Services\TestModeService;

/**
 * Test Mode admin screen (docs/schema.sql Section V) — Super Admin only,
 * wired via SuperAdminOnly in public_html/index.php, same as /super-admin
 * itself.
 */
final class TestModeController
{
    public function index(array $params): void
    {
        $counts = TestModeService::testDataCounts();
        View::render('test_mode/index', [
            'settings' => TestModeService::getSettings(),
            'counts' => $counts,
            'hasTestData' => $counts['clients'] > 0 || $counts['orders'] > 0 || $counts['suppliers'] > 0,
        ], 'layout/base');
    }

    public function enable(array $params): void
    {
        $user = AuthService::currentUser();
        TestModeService::enable((int) $user['id']);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log((int) $user['id'], 'TEST_MODE_ENABLED', 'test_mode_settings', 1, 'is_enabled', '0', '1');
        Flash::set('success', 'Test Mode enabled. Client access is suspended and outbound mail is now redirected.');
        header('Location: /test-mode');
    }

    public function disable(array $params): void
    {
        $user = AuthService::currentUser();
        try {
            TestModeService::disable();
            AuditLogRepository::log($user ? (int) $user['id'] : null, 'TEST_MODE_DISABLED', 'test_mode_settings', 1, 'is_enabled', '1', '0');
            Flash::set('success', 'Test Mode disabled.');
        } catch (\Throwable $e) {
            Flash::setErrorFromException($e);
        }
        header('Location: /test-mode');
    }

    public function updateTestEmail(array $params): void
    {
        $email = trim((string) ($_POST['test_email'] ?? ''));
        if ($email === '') {
            Flash::set('error', 'Test email cannot be blank.');
            header('Location: /test-mode');
            return;
        }
        TestModeService::setTestEmail($email);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        $user = AuthService::currentUser();
        AuditLogRepository::log($user ? (int) $user['id'] : null, 'TEST_MODE_TEST_EMAIL_UPDATED', 'test_mode_settings', 1, 'test_email', null, $email);
        Flash::set('success', 'Test email updated.');
        header('Location: /test-mode');
    }

    public function deleteTestData(array $params): void
    {
        $result = TestModeService::deleteAllTestData();
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        $user = AuthService::currentUser();
        AuditLogRepository::log($user ? (int) $user['id'] : null, 'TEST_MODE_TEST_DATA_DELETED', 'test_mode_settings', 1, null, null, "clients={$result['clients']}, orders={$result['orders']}, files={$result['files']}");
        Flash::set(
            'success',
            "Test data deleted: {$result['clients']} client(s), {$result['orders']} order(s), {$result['files']} file(s)."
        );
        header('Location: /test-mode');
    }
}
