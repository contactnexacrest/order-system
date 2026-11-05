<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\ProductRepository;
use App\Services\AuthService;
use App\Services\MoneyVisibility;
use App\Services\OrderLinePricing;
use App\Services\PermissionService;
use App\Services\PriceRequestService;
use App\Services\ProductSpecs;

/**
 * Owner decision D-255 (5 Nov 2026), staff side of associate price requests:
 *  - Associates → Price requests: the queue (automatic and manual) and the
 *    90-day "requests against orders" report — view_price_buildup or manage_pricing;
 *    pricing a manual request — manage_pricing (MD).
 *  - An associate's price access (categories and forms, daily limit, validity) — manage_pricing.
 *  - The order form's live quote for a product and options — selling-price visibility.
 * Node twin: src/controllers/priceRequestController.js.
 */
final class PriceRequestController
{
    private static function user(): array
    {
        return AuthService::currentUser() ?? [];
    }

    private static function can(string $key): bool
    {
        $u = self::user();
        return $u !== [] && PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, $key);
    }

    public function index(array $params): void
    {
        $filter = in_array($_GET['show'] ?? '', ['pending', 'auto', 'manual'], true) ? (string) $_GET['show'] : 'all';
        View::render('associates/price_requests', [
            'rows' => PriceRequestService::queue($filter),
            'report' => PriceRequestService::report(),
            'filter' => $filter,
            'canPrice' => self::can('manage_pricing'),
            'warnAt' => PriceRequestService::WARN_REQUESTS,
        ], 'layout/base');
    }

    public function price(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $error = PriceRequestService::priceManual($id, (int) self::user()['id'], trim((string) ($_POST['quote'] ?? '')),
            trim((string) ($_POST['lowest'] ?? '')), (string) ($_POST['unit'] ?? ''), (string) ($_POST['note'] ?? ''));
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Priced — the associate has been told.');
        header('Location: /associates/price-requests');
    }

    public function access(array $params): void
    {
        $a = AssociateRepository::find((int) ($params['id'] ?? 0));
        if ($a === null) {
            http_response_code(404);
            echo 'Associate not found.';
            return;
        }
        $settings = \App\Services\PricingService::settings();
        View::render('associates/price_access', [
            'associate' => $a,
            'access' => PriceRequestService::access((int) $a['id']),
            'categories' => ProductCategoryRepository::all(true),
            'forms' => ProductSpecs::FORMS,
            'globalLimit' => $settings['price_request_daily_limit'],
            'globalDays' => $settings['price_request_valid_days'],
        ], 'layout/base');
    }

    public function saveAccess(array $params): void
    {
        $a = AssociateRepository::find((int) ($params['id'] ?? 0));
        if ($a === null) {
            Flash::set('error', 'That associate no longer exists.');
            header('Location: /associates');
            return;
        }
        $id = (int) $a['id'];
        $errors = [];
        $access = [];
        foreach ((array) ($_POST['category'] ?? []) as $cid => $on) {
            $cid = (int) $cid;
            if ($on && ProductCategoryRepository::find($cid) !== null) {
                $forms = [];
                foreach ((array) ($_POST['forms'][$cid] ?? []) as $f) {
                    if (isset(ProductSpecs::FORMS[(string) $f])) {
                        $forms[] = (string) $f;
                    }
                }
                $access[$cid] = $forms;
            }
        }
        $limit = null;
        $days = null;
        foreach (['price_request_daily_limit' => [0, 1000, 'Price requests per day'], 'price_valid_days' => [1, 365, 'A price stays valid for']] as $key => [$min, $max, $label]) {
            $v = trim((string) ($_POST[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            $n = filter_var($v, FILTER_VALIDATE_INT);
            if ($n === false || $n < $min || $n > $max) {
                $errors[] = "{$label}: a whole number from {$min} to {$max}, or leave it empty for the global value.";
            } elseif ($key === 'price_request_daily_limit') {
                $limit = $n;
            } else {
                $days = $n;
            }
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header("Location: /associates/{$id}/price-access");
            return;
        }
        $before = json_encode(PriceRequestService::access($id)) . " limit {$a['price_request_daily_limit']} days {$a['price_valid_days']}";
        PriceRequestService::saveAccess($id, $access);
        \App\Config\Database::connection()->prepare('UPDATE associates SET price_request_daily_limit = :l, price_valid_days = :d WHERE id = :id')
            ->execute(['l' => $limit, 'd' => $days, 'id' => $id]);
        AuditLogRepository::log((int) self::user()['id'], 'ASSOCIATE_PRICE_ACCESS_CHANGED', 'associates', $id, 'price access', $before,
            json_encode($access) . ' limit ' . ($limit ?? 'global') . ' days ' . ($days ?? 'global'));
        Flash::set('success', "Price access saved for {$a['name']}.");
        header("Location: /associates/{$id}");
    }

    /**
     * The order form's live quote for a product with chosen options (JSON). Only the
     * seller's quote and lowest price — never cost.
     */
    public function lineQuote(array $params): void
    {
        header('Content-Type: application/json');
        if (!MoneyVisibility::can(MoneyVisibility::SELLING)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'reason' => 'You may not see selling prices.']);
            return;
        }
        $product = ProductRepository::find((int) ($_GET['product_id'] ?? 0));
        if ($product === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'reason' => 'Product not found.']);
            return;
        }
        $order = null;
        if (!empty($_GET['order_id'])) {
            $order = OrderRepository::find((int) $_GET['order_id']);
            if ($order === null || !\App\Services\OrderAccessService::currentUserMayOpenOrderId((int) $order['id'])) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'reason' => 'Order not found.']);
                return;
            }
        } else {
            $order = ['client_id' => !empty($_GET['client_id']) ? (int) $_GET['client_id'] : null, 'currency_code' => 'USD'];
            // QA ACC-17: a client-specific price only for a client of the user's division(s).
            if ($order['client_id'] !== null && !\App\Services\DivisionService::allowsClient($order['client_id'])) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'reason' => 'Client not found.']);
                return;
            }
        }
        $ctx = OrderLinePricing::context($order);
        echo json_encode(OrderLinePricing::lineQuote($product, $ctx, OrderLinePricing::selectionInput((string) ($_GET['spec'] ?? '')) ?? []));
    }
}
