<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
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
        Flash::set('success', 'Test Mode enabled. Client access is suspended and outbound mail is now redirected.');
        header('Location: /test-mode');
    }

    public function disable(array $params): void
    {
        try {
            TestModeService::disable();
            Flash::set('success', 'Test Mode disabled.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
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
        Flash::set('success', 'Test email updated.');
        header('Location: /test-mode');
    }

    public function deleteTestData(array $params): void
    {
        $result = TestModeService::deleteAllTestData();
        Flash::set(
            'success',
            "Test data deleted: {$result['clients']} client(s), {$result['orders']} order(s), {$result['files']} file(s)."
        );
        header('Location: /test-mode');
    }
}
