<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ComplianceTaskTypeRepository;
use App\Services\AuthService;
use PDOException;

/**
 * Admin screen for the compliance/pre-closure task-type list (docs/schema.sql
 * Section AR). Gated on manage_compliance_task_types, same tier as
 * manage_hs_codes/manage_logistics_partners — Admin/MD/ED and Super Admin
 * only by default. Does not itself grant access to the checklist on an
 * order's own page — that uses the existing close_orders permission.
 */
final class ComplianceTaskTypeController
{
    public function index(array $params): void
    {
        View::render('compliance_task_types/index', [
            'taskTypes' => ComplianceTaskTypeRepository::all(true),
        ], 'layout/base');
    }

    public function createForm(array $params): void
    {
        View::render('compliance_task_types/create', [], 'layout/base');
    }

    public function create(array $params): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Task name is required.');
            header('Location: /compliance-task-types/create');
            return;
        }

        $user = AuthService::currentUser();
        $id = ComplianceTaskTypeRepository::create($name, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'COMPLIANCE_TASK_TYPE_ADDED', 'compliance_task_types', $id, null, null, $name);
        Flash::set('success', "\"{$name}\" added to the compliance checklist — it now appears on every order's checklist.");
        header('Location: /compliance-task-types');
    }

    public function editForm(array $params): void
    {
        $id = (int) $params['id'];
        $taskType = ComplianceTaskTypeRepository::find($id);
        if (!$taskType) {
            http_response_code(404);
            echo 'Compliance task type not found.';
            return;
        }
        View::render('compliance_task_types/edit', ['taskType' => $taskType], 'layout/base');
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $taskType = ComplianceTaskTypeRepository::find($id);
        if (!$taskType) {
            http_response_code(404);
            echo 'Compliance task type not found.';
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Task name is required.');
            header("Location: /compliance-task-types/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        ComplianceTaskTypeRepository::update($id, $name);
        AuditLogRepository::log((int) $user['id'], 'COMPLIANCE_TASK_TYPE_UPDATED', 'compliance_task_types', $id, 'name', $taskType['name'], $name);
        Flash::set('success', "\"{$name}\" updated.");
        header('Location: /compliance-task-types');
    }

    public function toggleActive(array $params): void
    {
        $id = (int) $params['id'];
        $taskType = ComplianceTaskTypeRepository::find($id);
        if (!$taskType) {
            Flash::set('error', 'Compliance task type not found.');
            header('Location: /compliance-task-types');
            return;
        }

        $user = AuthService::currentUser();
        ComplianceTaskTypeRepository::toggleActive($id);
        $nowActive = !((bool) $taskType['is_active']);
        AuditLogRepository::log((int) $user['id'], $nowActive ? 'COMPLIANCE_TASK_TYPE_REACTIVATED' : 'COMPLIANCE_TASK_TYPE_DEACTIVATED', 'compliance_task_types', $id, 'is_active', (string) (int) $taskType['is_active'], (string) (int) $nowActive);
        Flash::set('success', "\"{$taskType['name']}\" " . ($nowActive ? 'reactivated' : 'deactivated') . '.');
        header('Location: /compliance-task-types');
    }

    public function delete(array $params): void
    {
        $id = (int) $params['id'];
        $taskType = ComplianceTaskTypeRepository::find($id);
        if (!$taskType) {
            Flash::set('error', 'Compliance task type not found.');
            header('Location: /compliance-task-types');
            return;
        }

        $user = AuthService::currentUser();
        try {
            ComplianceTaskTypeRepository::delete($id);
        } catch (PDOException $e) {
            Flash::set('error', "\"{$taskType['name']}\" is already recorded against at least one order and cannot be deleted — deactivate it instead.");
            header('Location: /compliance-task-types');
            return;
        }
        AuditLogRepository::log((int) $user['id'], 'COMPLIANCE_TASK_TYPE_DELETED', 'compliance_task_types', $id, 'name', $taskType['name'], null);
        Flash::set('success', "\"{$taskType['name']}\" removed from the task-type list.");
        header('Location: /compliance-task-types');
    }
}
