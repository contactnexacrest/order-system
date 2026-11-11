<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\HsCodeRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\SupplierRepository;
use App\Services\AuthService;
use App\Services\PermissionService;

/**
 * Owner decisions D-302 and D-303 (points 11 and 15, 11 Nov 2026): one master for
 * every piece of data. Two one-time clean-up pages for what was typed before:
 *
 *  - Fix HS codes: products whose HS code is not on the HS Code Master List —
 *    choose a code from the list (or, with manage_hs_codes, add the code to it).
 *  - Link suppliers: supplier names typed on product prices that are not yet a
 *    record of the Suppliers directory — exact names were matched automatically
 *    ("Match by name"); pick or quick-add the rest.
 *
 * Node twin: src/controllers/catalogueMasterController.js.
 */
final class CatalogueMasterController
{
    private static function can(string $key): bool
    {
        $u = AuthService::currentUser();
        return $u !== null && PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, $key);
    }

    /** @return list<array<string,mixed>> products whose HS code is not an active master code */
    public static function productsToFix(): array
    {
        return Database::connection()->query(
            'SELECT p.id, p.name, p.hs_code, p.category_id, c.default_hs_code FROM catalog_products p
               LEFT JOIN product_categories c ON c.id = p.category_id
              WHERE NOT EXISTS (SELECT 1 FROM hs_codes h WHERE h.code = p.hs_code AND h.is_active = 1)
              ORDER BY p.name'
        )->fetchAll();
    }

    public function fixHsCodes(array $params): void
    {
        View::render('products/fix_hs_codes', [
            'products' => self::productsToFix(),
            'hsCodes' => HsCodeRepository::active(),
            'canManageHs' => self::can('manage_hs_codes'),
        ], 'layout/base');
    }

    public function saveHsCode(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $product = ProductRepository::find($id);
        if ($product === null) {
            Flash::set('error', 'That product no longer exists.');
            header('Location: /products/fix-hs-codes');
            return;
        }
        $code = trim((string) ($_POST['hs_code'] ?? ''));
        if (!HsCodeRepository::isActiveCode($code)) {
            Flash::set('error', "{$product['name']}: choose an HS code from the HS Code Master List.");
            header('Location: /products/fix-hs-codes');
            return;
        }
        Database::connection()->prepare('UPDATE catalog_products SET hs_code = :c WHERE id = :id')->execute(['c' => $code, 'id' => $id]);
        AuditLogRepository::log((int) AuthService::currentUserId(), 'PRODUCT_HS_CODE_FIXED', 'products', $id, 'hs_code', (string) $product['hs_code'], $code);
        Flash::set('success', "{$product['name']}: HS code set to {$code}.");
        header('Location: ' . ((string) ($_POST['return_to'] ?? '') === 'product' ? "/products/{$id}" : '/products/fix-hs-codes'));
    }

    public function linkSuppliers(array $params): void
    {
        $names = ProductSupplierRepository::unlinkedNames();
        foreach ($names as &$n) {
            $match = SupplierRepository::findByExactName((string) $n['supplier_name']);
            $n['match'] = $match !== null ? ['id' => (int) $match['id'], 'name' => (string) $match['supplier_legal_name']] : null;
        }
        unset($n);
        View::render('products/link_suppliers', [
            'names' => $names,
            'pickList' => SupplierRepository::pickList(),
            'supplierTypes' => array_map(static fn ($o) => (string) $o['option_value'], \App\Repositories\LookupRepository::dropdownOptions('supplier_type')),
        ], 'layout/base');
    }

    /** "Match by name" (every exact match at once), or one typed name linked to the supplier picked for it. */
    public function saveLinks(array $params): void
    {
        $userId = (int) AuthService::currentUserId();
        if ((string) ($_POST['action'] ?? '') === 'match_all') {
            $linked = 0;
            foreach (ProductSupplierRepository::unlinkedNames() as $n) {
                $match = SupplierRepository::findByExactName((string) $n['supplier_name']);
                if ($match !== null) {
                    $linked += ProductSupplierRepository::linkName((string) $n['supplier_name'], (int) $match['id'], (string) $match['supplier_legal_name']);
                }
            }
            AuditLogRepository::log($userId, 'PRODUCT_SUPPLIERS_LINKED', 'product_suppliers', null, 'supplier_id', null, "{$linked} price(s) linked by exact name");
            Flash::set('success', $linked === 0 ? 'No typed name matches a supplier exactly — pick them one by one below.' : "{$linked} price(s) linked to their supplier by name.");
            header('Location: /products/link-suppliers');
            return;
        }
        $typed = (string) ($_POST['typed_name'] ?? '');
        $supplier = (int) ($_POST['supplier_id'] ?? 0) > 0 ? SupplierRepository::find((int) $_POST['supplier_id']) : SupplierRepository::findByExactName((string) ($_POST['supplier_name'] ?? ''));
        if ($typed === '' || $supplier === null) {
            Flash::set('error', 'Pick the supplier from the Suppliers directory for "' . $typed . '" — or add it with "+ Add a new supplier (quick)".');
            header('Location: /products/link-suppliers');
            return;
        }
        $linked = ProductSupplierRepository::linkName($typed, (int) $supplier['id'], (string) $supplier['supplier_legal_name']);
        AuditLogRepository::log($userId, 'PRODUCT_SUPPLIERS_LINKED', 'product_suppliers', null, 'supplier_name', $typed, $supplier['supplier_legal_name'] . " #{$supplier['id']} ({$linked} price(s))");
        Flash::set('success', "\"{$typed}\" — {$linked} price(s) linked to {$supplier['supplier_legal_name']}.");
        header('Location: /products/link-suppliers');
    }
}
