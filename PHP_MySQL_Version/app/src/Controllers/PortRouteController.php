<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\PortRouteRepository;
use App\Services\AuthService;

/**
 * 90-item backlog item 15: admin screen for `port_routes` (the port-to-
 * port sailing-days reference document), gated on the same manage_ports
 * permission as the Ports screen itself — these two reference lists are
 * managed together. lookup() is the one action a plain order-creating
 * user also needs (gated on manage_orders instead, see
 * public_html/index.php), so the order form can auto-suggest a sailing
 * lead time once both ports are picked.
 */
final class PortRouteController
{
    public function index(array $params): void
    {
        View::render('port_routes/index', [
            'routes' => PortRouteRepository::all(true),
        ], 'layout/base');
    }

    public function createForm(array $params): void
    {
        View::render('port_routes/create', [
            'loadingPorts' => LookupRepository::ports('loading'),
            'dischargePorts' => LookupRepository::ports('discharge'),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $data = $this->collectFormData();
        if ($data === null) {
            header('Location: /port-routes/create');
            return;
        }

        $user = AuthService::currentUser();
        $id = PortRouteRepository::create($data['port_of_loading_id'], $data['port_of_discharge_id'], $data['sailing_days_min'], $data['sailing_days_max'], $data['notes']);
        if ($id === null) {
            Flash::set('error', 'A route between those two ports is already recorded — edit the existing one instead.');
            header('Location: /port-routes/create');
            return;
        }
        AuditLogRepository::log((int) $user['id'], 'PORT_ROUTE_ADDED', 'port_routes', $id, null, null, "{$data['sailing_days_min']}-{$data['sailing_days_max']} days");
        Flash::set('success', 'Port route added.');
        header('Location: /port-routes');
    }

    public function editForm(array $params): void
    {
        $id = (int) $params['id'];
        $route = PortRouteRepository::find($id);
        if (!$route) {
            http_response_code(404);
            echo 'Port route not found.';
            return;
        }
        View::render('port_routes/edit', [
            'route' => $route,
            'loadingPorts' => LookupRepository::ports('loading'),
            'dischargePorts' => LookupRepository::ports('discharge'),
        ], 'layout/base');
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $route = PortRouteRepository::find($id);
        if (!$route) {
            http_response_code(404);
            echo 'Port route not found.';
            return;
        }

        $data = $this->collectFormData();
        if ($data === null) {
            header("Location: /port-routes/{$id}/edit");
            return;
        }

        $user = AuthService::currentUser();
        $ok = PortRouteRepository::update($id, $data['port_of_loading_id'], $data['port_of_discharge_id'], $data['sailing_days_min'], $data['sailing_days_max'], $data['notes']);
        if (!$ok) {
            Flash::set('error', 'A route between those two ports is already recorded under a different entry.');
            header("Location: /port-routes/{$id}/edit");
            return;
        }
        AuditLogRepository::log((int) $user['id'], 'PORT_ROUTE_UPDATED', 'port_routes', $id, 'sailing_days', "{$route['sailing_days_min']}-{$route['sailing_days_max']}", "{$data['sailing_days_min']}-{$data['sailing_days_max']}");
        Flash::set('success', 'Port route updated.');
        header('Location: /port-routes');
    }

    public function toggleActive(array $params): void
    {
        $id = (int) $params['id'];
        $route = PortRouteRepository::find($id);
        if (!$route) {
            Flash::set('error', 'Port route not found.');
            header('Location: /port-routes');
            return;
        }

        $user = AuthService::currentUser();
        PortRouteRepository::toggleActive($id);
        $nowActive = !((bool) $route['is_active']);
        AuditLogRepository::log((int) $user['id'], $nowActive ? 'PORT_ROUTE_REACTIVATED' : 'PORT_ROUTE_DEACTIVATED', 'port_routes', $id, 'is_active', (string) (int) $route['is_active'], (string) (int) $nowActive);
        Flash::set('success', 'Port route ' . ($nowActive ? 'reactivated' : 'deactivated') . '.');
        header('Location: /port-routes');
    }

    /**
     * JSON lookup the order create/edit form's JS calls once both ports
     * are selected, to offer (never force — the fields stay freely
     * editable) a sailing lead-time suggestion.
     */
    public function lookup(array $params): void
    {
        $loadingId = (int) ($_GET['from'] ?? 0);
        $dischargeId = (int) ($_GET['to'] ?? 0);

        header('Content-Type: application/json');
        if (!$loadingId || !$dischargeId) {
            echo json_encode(['found' => false]);
            return;
        }

        $route = PortRouteRepository::findByPorts($loadingId, $dischargeId);
        if ($route === null) {
            echo json_encode(['found' => false]);
            return;
        }
        echo json_encode(['found' => true, 'min' => (int) $route['sailing_days_min'], 'max' => (int) $route['sailing_days_max']]);
    }

    /** @return array{port_of_loading_id:int,port_of_discharge_id:int,sailing_days_min:int,sailing_days_max:int,notes:?string}|null null if validation failed (flash already set) */
    private function collectFormData(): ?array
    {
        $loadingId = (int) ($_POST['port_of_loading_id'] ?? 0);
        $dischargeId = (int) ($_POST['port_of_discharge_id'] ?? 0);
        if (!$loadingId || !$dischargeId) {
            Flash::set('error', 'Select both a port of loading and a port of discharge.');
            return null;
        }

        $min = ($_POST['sailing_days_min'] ?? '') !== '' ? (int) $_POST['sailing_days_min'] : null;
        $max = ($_POST['sailing_days_max'] ?? '') !== '' ? (int) $_POST['sailing_days_max'] : null;
        if ($min === null || $max === null || $min < 0 || $max < $min) {
            Flash::set('error', 'Enter a valid sailing-days range (min must be 0 or more, max must be at least min).');
            return null;
        }

        return [
            'port_of_loading_id'   => $loadingId,
            'port_of_discharge_id' => $dischargeId,
            'sailing_days_min'     => $min,
            'sailing_days_max'     => $max,
            'notes'                => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
    }
}
