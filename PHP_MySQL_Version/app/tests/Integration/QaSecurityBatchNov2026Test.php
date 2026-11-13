<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ComplaintController;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA round 13 Nov 2026 — security, access, data-integrity and robustness
 * fixes. Each test names the bug id from the QA register. The Node suite has
 * the same checks in qaSecurityBatchNov2026.test.js.
 */
final class QaSecurityBatchNov2026Test extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function render(int $userId, object $controller, string $action, array $get = [], array $params = []): string
    {
        $_SESSION = ['_auth_user_id' => $userId];
        $_GET = $get;
        \App\Services\PermissionService::resetCache();
        \App\Services\ActiveDepartmentService::resetCache();
        ob_start();
        $controller->{$action}($params);
        return (string) ob_get_clean();
    }

    /** UI-001: the Kanban Complaints board never writes ?status= raw into its "Switch to list view" link. */
    public function testUi001ComplaintsBoardEscapesStatus(): void
    {
        $md = $this->createTestUser('Managing Director');
        UserRepository::updateUiTemplatePreference($md, (int) UiTemplateRepository::findByKey('kanban')['id']);
        $html = $this->render($md, new ComplaintController(), 'index', ['status' => '"><script>window.__x=1</script>']);
        self::assertStringNotContainsString('<script>window.__x=1</script>', $html);
        self::assertStringContainsString('/complaints?view=list&amp;status=%22%3E%3Cscript%3Ewindow.__x%3D1%3C%2Fscript%3E', $html);
    }
}
