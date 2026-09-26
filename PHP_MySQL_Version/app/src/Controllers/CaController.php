<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\View;
use App\Repositories\CaRepository;
use App\Repositories\UserRepository;

/**
 * CA / Accounting module (Phase 1) — independent of the order-pipeline
 * system. The route is gated on ca_module_view (see public_html/index.php),
 * never on manage_orders, so a CA/Accounts-only user can reach this without
 * any order-management access.
 */
final class CaController
{
    public function index(array $params): void
    {
        $usersById = [];
        foreach (UserRepository::listActive() as $u) {
            $usersById[(int) $u['id']] = $u['name'];
        }

        View::render('ca/index', [
            'settlements' => CaRepository::settlementRegister(),
            'usersById' => $usersById,
        ], 'layout/base');
    }
}
