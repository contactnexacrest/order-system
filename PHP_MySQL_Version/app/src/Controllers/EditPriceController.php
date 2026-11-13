<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\AuthService;
use App\Services\LineUnits;
use App\Services\PermissionService;
use App\Services\PricingService;
use App\Services\SupplierOffers;
use App\Services\ThicknessPrices;

/**
 * Owner decision D-359 (13 Nov 2026, "go with recommendation", plan rule 8): the simple
 * "Edit price" form for one supplier's offer of one product — reached from the product's
 * Market prices page (an "Edit price" button per supplier row) and from the supplier's page
 * ("Prices given for products", an "Edit price" link per row). One form, one Save; the page
 * stays; a green message with Undo and "See price history". All maths and the price history
 * are the existing services' (SupplierOffers → ThicknessPrices / PricingService).
 * Node twin: src/controllers/editPriceController.js.
 */
final class EditPriceController
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

    /** @return array{0: ?array, 1: ?array} [offer, product] */
    private static function load(array $params): array
    {
        $row = ProductSupplierRepository::find((int) ($params['supplierId'] ?? 0));
        $product = $row !== null ? ProductRepository::find((int) $row['product_id']) : null;
        return [$row, $product];
    }

    /** Where Cancel / close goes: the supplier's page or the Suppliers list (D-360) when the form was opened from there. */
    private static function backUrl(array $row, string $from): string
    {
        if ($from === 'supplier' && !empty($row['supplier_id'])) {
            return '/suppliers/' . (int) $row['supplier_id'] . '/edit#product-prices';
        }
        if ($from === 'suppliers' && !empty($row['supplier_id'])) {
            return '/suppliers#supplier-' . (int) $row['supplier_id'];
        }
        return '/products/' . (int) $row['product_id'] . '/market-prices';
    }

    private static function from(): string
    {
        $f = (string) ($_GET['from'] ?? $_POST['from'] ?? '');
        return in_array($f, ['supplier', 'suppliers'], true) ? $f : 'product';
    }

    /** The form's own address, keeping where it was opened from. */
    private static function selfUrl(int $id, string $from): string
    {
        return "/products/suppliers/{$id}/edit-price" . ($from !== 'product' ? '?from=' . $from : '');
    }

    /** "185000" / "185,000.50" → 185000.5; null when not a ₹ amount with at most 2 decimals. */
    private static function money(mixed $raw): ?float
    {
        $v = trim(str_replace(',', '', (string) $raw));
        return preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v) === 1 ? (float) $v : null;
    }

    public function form(array $params): void
    {
        [$row, $product] = self::load($params);
        if ($row === null || $product === null) {
            http_response_code(404);
            echo 'Supplier price not found.';
            return;
        }
        $settings = PricingService::settings();
        $rules = ThicknessPrices::rules($product, $settings);
        $grid = ($row['price_basis'] ?? '') === 'grid';
        $base = SupplierOffers::offerBase($product, $row, $rules, $settings);
        $fromMm = $grid ? ThicknessPrices::baseOf($row, $rules) : null;
        $id = (int) $row['id'];
        $preview = SupplierOffers::preview($product, $row, $fromMm, (float) ($base['inr'] ?? 0), [], $settings);
        $saved = $_SESSION['edit_price_msg'][$id] ?? null;
        unset($_SESSION['edit_price_msg'][$id]);
        $old = Flash::pullOld();
        View::render('products/edit_price', [
            'product' => $product, 'row' => $row, 'rules' => $rules, 'grid' => $grid, 'base' => $base,
            'thicknesses' => $grid ? array_map(static fn (int $mm): array => ['mm' => $mm, 'label' => ThicknessPrices::label($mm, (int) $rules['cbm_from']), 'unit' => $mm === ThicknessPrices::CBM ? 'CBM' : 'm²'],
                SupplierOffers::formThicknesses($rules, $row)) : [],
            'fromMm' => $fromMm, 'preview' => $preview, 'saved' => $saved,
            'undoToken' => $_SESSION['edit_price_undo'][$id]['token'] ?? null,
            'unitLabel' => ($row['price_basis'] ?? '') === 'cbm' ? 'CBM' : (LineUnits::label((string) ($product['reference_unit'] ?? '')) ?: ((string) ($product['reference_unit'] ?? '') ?: 'unit')),
            'from' => self::from(), 'backUrl' => self::backUrl($row, self::from()),
            'canSeeSelling' => self::can('view_price_buildup'), 'old' => $old,
            'today' => date('Y-m-d'),
        ], 'layout/base');
    }

    /** The preview as JSON while typing: other thicknesses (old and new) and the selling price. */
    public function preview(array $params): void
    {
        header('Content-Type: application/json');
        [$row, $product] = self::load($params);
        if ($row === null || $product === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Supplier price not found.']);
            return;
        }
        $price = self::money($_GET['price'] ?? '') ?? 0.0;
        $mm = isset($_GET['thickness_mm']) && ctype_digit((string) $_GET['thickness_mm']) ? (int) $_GET['thickness_mm'] : null;
        $own = [];
        foreach ((array) ($_GET['own'] ?? []) as $k => $v) {
            $p = self::money($v);
            if ($p !== null && $p > 0 && ctype_digit((string) $k)) {
                $own[(int) $k] = $p;
            }
        }
        $out = SupplierOffers::preview($product, $row, $mm, $price, $own);
        if (!self::can('view_price_buildup')) {
            $out['selling'] = null; // Procurement sees supplier prices only, never selling prices.
        }
        echo json_encode($out);
    }

    public function save(array $params): void
    {
        [$row, $product] = self::load($params);
        if ($row === null || $product === null) {
            Flash::set('error', 'That supplier price no longer exists.');
            header('Location: /products');
            return;
        }
        $id = (int) $row['id'];
        $from = self::from();
        $self = self::selfUrl($id, $from);
        $rules = ThicknessPrices::rules($product);
        $grid = ($row['price_basis'] ?? '') === 'grid';
        [$in, $error] = self::read($rules, $row, $grid);
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
            header("Location: {$self}");
            return;
        }
        $snap = SupplierOffers::snapshot($id);
        [$summary, $error] = SupplierOffers::save($product, $row, $in, (int) self::user()['id']);
        if ($error !== null) {
            Flash::set('error', $error);
            Flash::setOld($_POST);
            header("Location: {$self}");
            return;
        }
        $_SESSION['edit_price_undo'][$id] = ['token' => bin2hex(random_bytes(12)), 'snap' => $snap];
        $_SESSION['edit_price_msg'][$id] = $summary;
        header("Location: {$self}");
    }

    public function undo(array $params): void
    {
        [$row] = self::load($params);
        $id = (int) ($params['supplierId'] ?? 0);
        $from = self::from();
        $self = self::selfUrl($id, $from);
        $u = $_SESSION['edit_price_undo'][$id] ?? null;
        if ($row === null || $u === null || !hash_equals((string) $u['token'], (string) ($_POST['undo_token'] ?? ''))) {
            Flash::set('error', 'There is nothing to undo here any more.');
            header("Location: {$self}");
            return;
        }
        $error = SupplierOffers::undo($u['snap'], (int) self::user()['id']);
        unset($_SESSION['edit_price_undo'][$id]);
        Flash::set($error !== null ? 'error' : 'success', $error ?? 'Undone — the price is back as it was before your save. The undo is in the price history.');
        header("Location: {$self}");
    }

    /**
     * Reads and checks the form (the same checks the browser does first).
     *
     * @return array{0: array<string,mixed>, 1: ?string}
     */
    private static function read(array $rules, array $row, bool $grid): array
    {
        $price = self::money($_POST['price'] ?? '');
        if ($price === null || $price <= 0) {
            return [[], 'New price: enter the price in ₹ (above 0, at most 2 decimals).'];
        }
        $fromMm = null;
        $own = [];
        if ($grid) {
            $allowed = SupplierOffers::formThicknesses($rules, $row);
            $raw = trim((string) ($_POST['thickness_mm'] ?? ''));
            $fromMm = $raw !== '' && ctype_digit($raw) ? (int) $raw : -1;
            if (!in_array($fromMm, $allowed, true)) {
                return [[], 'Choose the thickness of the price.'];
            }
            foreach ((array) ($_POST['own'] ?? []) as $k => $v) {
                if (trim((string) $v) === '' || !ctype_digit((string) $k) || (int) $k === $fromMm || !in_array((int) $k, $allowed, true)) {
                    continue;
                }
                $p = self::money($v);
                if ($p === null || $p <= 0) {
                    return [[], ThicknessPrices::label((int) $k, (int) $rules['cbm_from']) . ': enter the price in ₹ (above 0, at most 2 decimals), or leave it empty to work it out.'];
                }
                $own[(int) $k] = $p;
            }
        }
        $q = trim((string) ($_POST['quoted_on'] ?? ''));
        $d = \DateTime::createFromFormat('!Y-m-d', $q);
        if ($d === false || $d->format('Y-m-d') !== $q) {
            return [[], 'Quoted on: enter the date the supplier quoted this price.'];
        }
        if ($q > date('Y-m-d')) {
            return [[], 'Quoted on: the date cannot be in the future.'];
        }
        $v = trim((string) ($_POST['valid_until'] ?? ''));
        if ($v !== '') {
            $dv = \DateTime::createFromFormat('!Y-m-d', $v);
            if ($dv === false || $dv->format('Y-m-d') !== $v) {
                return [[], 'Valid until: enter a valid date, or leave it empty.'];
            }
            if ($v < $q) {
                return [[], '"Valid until" cannot be before the quoted date.'];
            }
        }
        $chaRaw = trim(str_replace(',', '', (string) ($_POST['cha_transport_per_container'] ?? '')));
        if ($chaRaw !== '' && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $chaRaw) !== 1) {
            return [[], 'CHA + transport per container: enter ₹ (at most 2 decimals), or leave it empty for the product\'s default.'];
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if (mb_strlen($notes) > 1000) {
            return [[], 'Notes: at most 1000 characters.'];
        }
        return [[
            'price' => $price, 'from_mm' => $fromMm, 'own' => $own, 'quoted_on' => $q, 'valid_until' => $v,
            'cha' => $chaRaw !== '' ? (float) $chaRaw : null,
            'includes_packing_loading' => (string) ($_POST['includes_packing_loading'] ?? '0') === '1',
            'notes' => $notes,
        ], null];
    }
}
