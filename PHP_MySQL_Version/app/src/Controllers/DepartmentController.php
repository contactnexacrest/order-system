<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\DepartmentRepository;
use App\Services\ActiveDepartmentService;
use App\Services\AuthService;

/**
 * Owner decision D-214 (2026-10-31), phase 1: "Log in as" a department after
 * sign-in, switching department without logging out, and Admin's "Preview as"
 * a designation. Port: departmentController.js.
 */
final class DepartmentController
{
    public function chooseForm(array $params): void
    {
        $userId = (int) AuthService::currentUserId();
        $memberships = DepartmentRepository::activeMembershipsForUser($userId);
        if ($memberships === []) {
            header('Location: /');
            return;
        }
        View::render('departments/choose', [
            'user' => \App\Repositories\UserRepository::findById($userId),
            'memberships' => $memberships,
            'current' => ActiveDepartmentService::current($userId),
        ], 'layout/bare');
    }

    public function choose(array $params): void
    {
        $userId = (int) AuthService::currentUserId();
        $membershipId = (int) ($_POST['membership_id'] ?? 0);
        if (!ActiveDepartmentService::activate($userId, $membershipId, false)) {
            Flash::set('error', 'Choose one of your departments.');
            header('Location: /choose-department');
            return;
        }
        if (!empty($_POST['remember'])) {
            DepartmentRepository::setDefault($userId, $membershipId);
        }
        header('Location: /');
    }

    /** From the menu: work as another of one's own departments, without logging out. */
    public function switch(array $params): void
    {
        $userId = (int) AuthService::currentUserId();
        $membershipId = (int) ($_POST['membership_id'] ?? 0);
        if (!ActiveDepartmentService::activate($userId, $membershipId)) {
            Flash::set('error', 'That department is not one of yours (or it has ended).');
            header('Location: /');
            return;
        }
        $m = DepartmentRepository::findMembership($membershipId);
        Flash::set('success', 'You are now working as ' . ActiveDepartmentService::label($m ?? []) . '.');
        header('Location: /');
    }

    /** Admin "Preview the app as …" a designation (and MD/ED "view as" a department). */
    public function previewStart(array $params): void
    {
        $userId = (int) AuthService::currentUserId();
        if (!ActiveDepartmentService::startPreview($userId, (int) ($_POST['designation_id'] ?? 0))) {
            Flash::set('error', 'Designation not found.');
            header('Location: /admin/departments');
            return;
        }
        $p = ActiveDepartmentService::preview();
        Flash::set('success', 'Previewing the app as ' . $p['title'] . ' · ' . $p['department_name'] . '. Use "Exit preview" at the top to return.');
        header('Location: /');
    }

    public function previewEnd(array $params): void
    {
        ActiveDepartmentService::endPreview((int) AuthService::currentUserId());
        Flash::set('success', 'Preview ended.');
        header('Location: /admin/departments');
    }
}
