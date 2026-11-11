<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Services\ApprovalsInbox;
use App\Services\AuthService;
use App\Services\UiTemplateService;

/**
 * Owner decision D-310 (point 27, 11 Nov 2026; mockup approvals.png): the
 * Approvals page — everything the signed-in person may approve, with tabs
 * and counts; each row opens the exact approval screen. A Kanban user gets
 * the same rows as a board (one column per tab) with the same tabs and
 * buttons (owner hard rule 2026-11-01). Node twin: approvalsController.js.
 */
final class ApprovalsController
{
    public function index(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $items = ApprovalsInbox::items((int) $user['id'], $roleId);
        $tab = (string) ($_GET['tab'] ?? 'all');
        if ($tab !== 'all' && !isset(ApprovalsInbox::TABS[$tab])) {
            $tab = 'all';
        }
        $board = UiTemplateService::showsBoard($user['ui_template_preference_id'] ?? null);
        $shown = $tab === 'all' ? $items : array_values(array_filter($items, static fn (array $i): bool => $i['tab'] === $tab));
        $columns = [];
        foreach (ApprovalsInbox::TABS as $key => $label) {
            if ($tab === 'all' || $tab === $key) {
                $columns[$key] = ['label' => $label, 'items' => array_values(array_filter($shown, static fn (array $i): bool => $i['tab'] === $key))];
            }
        }
        View::render($board ? 'approvals/index_kanban' : 'approvals/index', [
            'items' => $shown,
            'columns' => $columns,
            'counts' => ApprovalsInbox::counts($items),
            'tabs' => ApprovalsInbox::TABS,
            'tab' => $tab,
            'today' => date('Y-m-d'),
            'canEmail' => \App\Services\PermissionService::can((int) $user['id'], $roleId, 'approve_email_send'),
            'kanbanUser' => UiTemplateService::resolve($user['ui_template_preference_id'] ?? null) === 'kanban',
        ], 'layout/base');
    }
}
