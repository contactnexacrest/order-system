<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\PortRepository;
use App\Services\AuthService;

/**
 * 90-item backlog #59: admin screen for `ports`, gated on manage_ports
 * (same tier as manage_hs_codes/manage_logistics_partners). See
 * PortRepository's docblock for why there's no delete action.
 */
final class PortController
{
    private const ROLES = ['loading' => 'Loading only', 'discharge' => 'Discharge only', 'both' => 'Loading & Discharge'];

    public function index(array $params): void
    {
        View::render('ports/index', [
            'ports' => PortRepository::all(true),
            'roles' => self::ROLES,
        ], 'layout/base');
    }

    public function createForm(array $params): void
    {
        View::render('ports/create', ['roles' => self::ROLES], 'layout/base');
    }

    public function create(array $params): void
    {
        $data = $this->collectFormData();
        if ($data === null) {
            header('Location: /ports/create');
            return;
        }

        $user = AuthService::currentUser();
        $existing = PortRepository::all(true);
        $nextSortOrder = count($existing) > 0 ? max(array_column($existing, 'sort_order')) + 1 : 1;
        $id = PortRepository::create($data['name'], $data['country'], $data['port_role'], $nextSortOrder);
        AuditLogRepository::log((int) $user['id'], 'PORT_ADDED', 'ports', $id, null, null, "{$data['name']} ({$data['port_role']})");
        Flash::set('success', "\"{$data['name']}\" added to the ports list.");
        header('Location: /ports');
    }

    public function editForm(array $params): void
    {
        $id = (int) $params['id'];
        $port = PortRepository::find($id);
        if (!$port) {
            http_response_code(404);
            echo 'Port not found.';
            return;
        }
        View::render('ports/edit', ['port' => $port, 'roles' => self::ROLES], 'layout/base');
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $port = PortRepository::find($id);
        if (!$port) {
            http_response_code(404);
            echo 'Port not found.';
            return;
        }

        $data = $this->collectFormData();
        if ($data === null) {
            header("Location: /ports/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        PortRepository::update($id, $data['name'], $data['country'], $data['port_role'], (int) $port['sort_order']);
        AuditLogRepository::log((int) $user['id'], 'PORT_UPDATED', 'ports', $id, 'name', $port['name'], $data['name']);
        Flash::set('success', "\"{$data['name']}\" updated.");
        header('Location: /ports');
    }

    public function toggleActive(array $params): void
    {
        $id = (int) $params['id'];
        $port = PortRepository::find($id);
        if (!$port) {
            Flash::set('error', 'Port not found.');
            header('Location: /ports');
            return;
        }

        $user = AuthService::currentUser();
        PortRepository::toggleActive($id);
        $nowActive = !((bool) $port['is_active']);
        AuditLogRepository::log((int) $user['id'], $nowActive ? 'PORT_REACTIVATED' : 'PORT_DEACTIVATED', 'ports', $id, 'is_active', (string) (int) $port['is_active'], (string) (int) $nowActive);
        Flash::set('success', "\"{$port['name']}\" " . ($nowActive ? 'reactivated' : 'deactivated') . '.');
        header('Location: /ports');
    }

    public function setDefault(array $params): void
    {
        $id = (int) $params['id'];
        $port = PortRepository::find($id);
        if (!$port) {
            Flash::set('error', 'Port not found.');
            header('Location: /ports');
            return;
        }

        $user = AuthService::currentUser();
        PortRepository::setDefault($id);
        AuditLogRepository::log((int) $user['id'], 'PORT_DEFAULT_CHANGED', 'ports', $id, 'is_default', '0', '1');
        Flash::set('success', "\"{$port['name']}\" set as the default for its role.");
        header('Location: /ports');
    }

    /** @return array{name:string,country:?string,port_role:string}|null null if validation failed (flash already set) */
    private function collectFormData(): ?array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $portRole = (string) ($_POST['port_role'] ?? '');

        if ($name === '') {
            Flash::set('error', 'Port name is required.');
            return null;
        }
        if (!array_key_exists($portRole, self::ROLES)) {
            Flash::set('error', 'Select a valid role (Loading, Discharge, or both).');
            return null;
        }

        return [
            'name'      => $name,
            'country'   => trim((string) ($_POST['country'] ?? '')) ?: null,
            'port_role' => $portRole,
        ];
    }
}
