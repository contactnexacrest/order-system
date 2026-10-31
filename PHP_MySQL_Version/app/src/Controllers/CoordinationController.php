<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\DepartmentTaskRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Services\ActiveDepartmentService;
use App\Services\AuthService;
use App\Services\OrderAccessService;

/**
 * Owner decision D-214 (2026-10-31), phase 4: the system coordinates —
 * "My tasks" (the department's queue), one owner per department per order,
 * the management cockpit, and the task due days in Admin. Port:
 * coordinationController.js.
 */
final class CoordinationController
{
    /** @return list<string>|null the department codes whose work this person sees (null = every department) */
    private static function departmentsFor(int $userId): ?array
    {
        return DepartmentTaskRepository::scopeFor(ActiveDepartmentService::current($userId));
    }

    /** @param list<array<string,mixed>> $tasks @return list<array<string,mixed>> only tasks on orders this person can open */
    private static function visible(array $tasks): array
    {
        $seen = [];
        return array_values(array_filter($tasks, static function (array $t) use (&$seen): bool {
            $id = (int) $t['order_id'];
            return $seen[$id] ??= OrderAccessService::currentUserMaySeeOrderId($id);
        }));
    }

    public function myTasks(array $params): void
    {
        $userId = (int) AuthService::currentUserId();
        $mine = ($_GET['show'] ?? '') === 'mine';
        $tasks = self::visible(DepartmentTaskRepository::open(self::departmentsFor($userId)));
        if ($mine) {
            $tasks = array_values(array_filter($tasks, static fn (array $t): bool => (int) ($t['owner_id'] ?? 0) === $userId));
        }
        // The Kanban UI template shows the queue as a board: escalated, overdue, due today, coming up.
        if (\App\Services\UiTemplateService::showsBoard(AuthService::currentUser()['ui_template_preference_id'] ?? null)) {
            $today = date('Y-m-d');
            $columns = ['escalated' => ['label' => 'Escalated', 'rows' => []], 'overdue' => ['label' => 'Overdue', 'rows' => []],
                'today' => ['label' => 'Due today', 'rows' => []], 'upcoming' => ['label' => 'Coming up', 'rows' => []]];
            foreach ($tasks as $t) {
                $key = (int) $t['escalated'] === 1 ? 'escalated' : ((int) $t['overdue'] === 1 ? 'overdue' : ((string) $t['due_on'] === $today ? 'today' : 'upcoming'));
                $columns[$key]['rows'][] = $t;
            }
            View::render('coordination/my_tasks_kanban', [
                'columns' => $columns,
                'mine' => $mine,
                'acting' => ActiveDepartmentService::actingLabel($userId),
            ], 'layout/base');
            return;
        }
        View::render('coordination/my_tasks', [
            'tasks' => $tasks,
            'mine' => $mine,
            // Owner hard rule 2026-11-01: a Kanban user who switched to the list can switch back.
            'kanbanUser' => \App\Services\UiTemplateService::resolve(AuthService::currentUser()['ui_template_preference_id'] ?? null) === 'kanban',
            'acting' => ActiveDepartmentService::actingLabel($userId),
        ], 'layout/base');
    }

    public function cockpit(array $params): void
    {
        $tasks = self::visible(DepartmentTaskRepository::open());
        $byDepartment = [];
        foreach ($tasks as $t) {
            $key = (string) $t['department_name'];
            $byDepartment[$key] ??= ['color' => $t['department_color'], 'open' => 0, 'overdue' => 0, 'escalated' => 0, 'unowned' => 0];
            $byDepartment[$key]['open']++;
            $byDepartment[$key]['overdue'] += (int) $t['overdue'];
            $byDepartment[$key]['escalated'] += (int) $t['escalated'];
            $byDepartment[$key]['unowned'] += $t['owner_id'] === null ? 1 : 0;
        }
        View::render('coordination/cockpit', [
            'byDepartment' => $byDepartment,
            'escalated' => array_values(array_filter($tasks, static fn (array $t): bool => (int) $t['escalated'] === 1)),
            'supplierDelays' => array_values(array_filter($tasks, static fn (array $t): bool => $t['task_key'] === 'chase_supplier' && (int) $t['overdue'] === 1)),
            'ordersByStatus' => DepartmentTaskRepository::ordersByStatus(),
        ], 'layout/base');
    }

    public function taskTypes(array $params): void
    {
        View::render('coordination/task_types', ['types' => DepartmentTaskRepository::types()], 'layout/base');
    }

    public function updateTaskTypes(array $params): void
    {
        $user = AuthService::currentUser();
        $standard = (array) ($_POST['standard_days'] ?? []);
        $escalate = (array) ($_POST['escalate_after_days'] ?? []);
        $changes = [];
        foreach (DepartmentTaskRepository::types() as $t) {
            $key = (string) $t['task_key'];
            $s = trim((string) ($standard[$key] ?? ''));
            $e = trim((string) ($escalate[$key] ?? ''));
            if (!ctype_digit($s) || !ctype_digit($e) || (int) $s > 90 || (int) $e > 90) {
                Flash::set('error', "Enter whole days from 0 to 90 for \"{$t['title']}\".");
                header('Location: /admin/task-types');
                return;
            }
            if ((int) $s !== (int) $t['standard_days'] || (int) $e !== (int) $t['escalate_after_days']) {
                $changes[$key] = [(int) $s, (int) $e, $t];
            }
        }
        foreach ($changes as $key => [$s, $e, $t]) {
            DepartmentTaskRepository::updateType($key, $s, $e);
            AuditLogRepository::log((int) $user['id'], 'TASK_TYPE_DAYS_UPDATED', 'department_task_types', null, $key,
                "{$t['standard_days']} days, escalate after {$t['escalate_after_days']}", "{$s} days, escalate after {$e}");
        }
        Flash::set('success', $changes === [] ? 'Nothing changed.' : 'Task days saved (' . count($changes) . ' changed).');
        header('Location: /admin/task-types');
    }

    /** A department's manager (or MD/ED, Admin, the Order Coordinator) chooses who owns the order for that department. */
    public function setOwner(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        $back = "Location: /orders/{$orderId}#department-owners";
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $department = DepartmentRepository::findDepartment((int) ($_POST['department_id'] ?? 0));
        $owner = UserRepository::findById((int) ($_POST['user_id'] ?? 0));
        $mayFor = self::departmentsFor((int) $user['id']);
        $candidates = $department !== null ? array_map(static fn (array $c): int => (int) $c['id'], DepartmentTaskRepository::candidates((int) $department['id'])) : [];
        $error = match (true) {
            $department === null => 'Choose the department.',
            $mayFor !== null && !in_array((string) $department['code'], $mayFor, true) => "You assign owners for your own department only — not {$department['name']}.",
            $owner === null || !in_array((int) $owner['id'], $candidates, true) => "Choose a person who works in {$department['name']}.",
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header($back);
            return;
        }
        DepartmentTaskRepository::setOwner($orderId, (int) $department['id'], (int) $owner['id'], (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_DEPARTMENT_OWNER_SET', 'orders', $orderId, $department['code'], null, (string) $owner['name']);
        NotificationRepository::create((int) $owner['id'], null, 'order_department_owner', $orderId,
            "You now own order {$order['order_reference']} for {$department['name']} — its {$department['name']} tasks are in My tasks.");
        Flash::set('success', "{$owner['name']} now owns this order for {$department['name']}.");
        header($back);
    }
}
