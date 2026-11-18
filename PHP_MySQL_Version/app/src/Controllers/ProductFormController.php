<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\HsCodeRepository;
use App\Repositories\ProductRepository;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\ProductForms;
use App\Services\ProductSpecs;

/**
 * Owner decisions D-382 … D-384 (18 Nov 2026):
 *  - Admin › Pricing › Forms of stone (/pricing/forms) — list, add, edit; switched off, never deleted
 *    once a specification uses it. manage_pricing (the permission of Pricing › Specifications).
 *  - Products › Specifications (HS) (/products/hs-specifications) — list, add, edit; switched off,
 *    never deleted once a product ticks it or an order line has it. manage_pricing too.
 *  - A product's "Sold as" ticks (/products/{id}/sold-as, on the product's Specifications page) —
 *    manage_product_specifications, as the rest of that page.
 * Every change needs a reason (ReasonValidator) and is audited (PRODUCT_FORM_CHANGED,
 * HS_SPECIFICATION_CHANGED, PRODUCT_SOLD_AS_CHANGED).
 * Node twin: src/controllers/productFormController.js.
 */
final class ProductFormController
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

    // ------------------------------------------------------------------ forms of stone

    public function forms(array $params): void
    {
        $forms = ProductForms::forms();
        foreach ($forms as &$f) {
            $f['used'] = ProductForms::formUsed((int) $f['id']);
        }
        unset($f);
        $old = Flash::pullOld();
        View::render('pricing/forms', [
            'forms' => $forms,
            'edit' => (int) ($_GET['edit'] ?? 0),
            'canManage' => self::can('manage_pricing'),
            'finishNames' => ProductForms::finishNames(),
            'old' => $old,
        ], 'layout/base');
    }

    public function saveForm(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $current = $id !== null ? ProductForms::findForm($id) : null;
        if ($id !== null && $current === null) {
            Flash::set('error', 'That form no longer exists.');
            header('Location: /pricing/forms');
            return;
        }
        [$d, $errors] = ProductForms::readFormInput($_POST, $id);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            Flash::setOld($_POST);
            header('Location: /pricing/forms' . ($id !== null ? '?edit=' . $id . '#form-' . $id : '#add-form'));
            return;
        }
        $userId = (int) self::user()['id'];
        $savedId = ProductForms::saveForm($id, $d, $userId);
        $after = ProductForms::findForm($savedId);
        AuditLogRepository::log($userId, 'PRODUCT_FORM_CHANGED', 'product_forms', $savedId, $id === null ? 'added' : 'changed',
            ProductForms::formSummary($current), ProductForms::formSummary($after), trim((string) $_POST['reason']));
        Flash::set('success', $id === null ? "Form \"{$d['name']}\" added." : "Form \"{$d['name']}\" saved.");
        header('Location: /pricing/forms#form-' . $savedId);
    }

    public function deleteForm(array $params): void
    {
        $form = ProductForms::findForm((int) ($params['id'] ?? 0));
        if ($form === null) {
            Flash::set('error', 'That form no longer exists.');
            header('Location: /pricing/forms');
            return;
        }
        if (($reasonError = ReasonValidator::check((string) ($_POST['reason'] ?? ''))) !== null) {
            Flash::set('error', $reasonError);
            header('Location: /pricing/forms#form-' . (int) $form['id']);
            return;
        }
        if (ProductForms::formUsed((int) $form['id'])) {
            Flash::set('error', "\"{$form['name']}\" is used by a specification — switch it off instead (Edit › Active).");
            header('Location: /pricing/forms#form-' . (int) $form['id']);
            return;
        }
        ProductForms::deleteForm((int) $form['id']);
        AuditLogRepository::log((int) self::user()['id'], 'PRODUCT_FORM_CHANGED', 'product_forms', (int) $form['id'], 'deleted',
            ProductForms::formSummary($form), null, trim((string) $_POST['reason']));
        Flash::set('success', "Form \"{$form['name']}\" deleted.");
        header('Location: /pricing/forms');
    }

    // ------------------------------------------------------------------ specifications (HS)

    public function specifications(array $params): void
    {
        $specs = ProductForms::specifications();
        foreach ($specs as &$s) {
            $s['used'] = ProductForms::specificationUsed((int) $s['id']);
        }
        unset($s);
        View::render('products/hs_specifications', [
            'specs' => $specs,
            'forms' => ProductForms::forms(),
            'hsCodes' => HsCodeRepository::active(),
            'finishNames' => ProductForms::finishNames(),
            'edit' => (int) ($_GET['edit'] ?? 0),
            'canManage' => self::can('manage_pricing'),
            'old' => Flash::pullOld(),
        ], 'layout/base');
    }

    public function saveSpecification(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : null;
        $current = $id !== null ? ProductForms::findSpecification($id) : null;
        if ($id !== null && $current === null) {
            Flash::set('error', 'That specification no longer exists.');
            header('Location: /products/hs-specifications');
            return;
        }
        [$d, $errors] = ProductForms::readSpecInput($_POST, $current);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            Flash::setOld($_POST);
            header('Location: /products/hs-specifications' . ($id !== null ? '?edit=' . $id . '#spec-' . $id : '#add-spec'));
            return;
        }
        $userId = (int) self::user()['id'];
        $savedId = ProductForms::saveSpecification($id, $d, $userId);
        AuditLogRepository::log($userId, 'HS_SPECIFICATION_CHANGED', 'hs_specifications', $savedId, $id === null ? 'added' : 'changed',
            ProductForms::specSummary($current), ProductForms::specSummary(ProductForms::findSpecification($savedId)), trim((string) $_POST['reason']));
        Flash::set('success', ($id === null ? "Specification \"{$d['name']}\" added" : "Specification \"{$d['name']}\" saved")
            . ' — order lines saved before keep their HS code.');
        header('Location: /products/hs-specifications#spec-' . $savedId);
    }

    public function deleteSpecification(array $params): void
    {
        $spec = ProductForms::findSpecification((int) ($params['id'] ?? 0));
        if ($spec === null) {
            Flash::set('error', 'That specification no longer exists.');
            header('Location: /products/hs-specifications');
            return;
        }
        if (($reasonError = ReasonValidator::check((string) ($_POST['reason'] ?? ''))) !== null) {
            Flash::set('error', $reasonError);
            header('Location: /products/hs-specifications#spec-' . (int) $spec['id']);
            return;
        }
        if (ProductForms::specificationUsed((int) $spec['id'])) {
            Flash::set('error', "\"{$spec['name']}\" is ticked on a product or used on an order line — switch it off instead (Edit › Active).");
            header('Location: /products/hs-specifications#spec-' . (int) $spec['id']);
            return;
        }
        ProductForms::deleteSpecification((int) $spec['id']);
        AuditLogRepository::log((int) self::user()['id'], 'HS_SPECIFICATION_CHANGED', 'hs_specifications', (int) $spec['id'], 'deleted',
            ProductForms::specSummary($spec), null, trim((string) $_POST['reason']));
        Flash::set('success', "Specification \"{$spec['name']}\" deleted.");
        header('Location: /products/hs-specifications');
    }

    // ------------------------------------------------------------------ a product's "Sold as"

    public function saveSoldAs(array $params): void
    {
        $product = ProductRepository::find((int) ($params['id'] ?? 0));
        if ($product === null) {
            Flash::set('error', 'That product no longer exists.');
            header('Location: /products');
            return;
        }
        $pid = (int) $product['id'];
        $before = ProductForms::productSpecIds($pid);
        $allowed = [];
        foreach (ProductForms::specifications() as $s) {
            // Active ones, and any switched-off one the product already ticks (it may stay ticked).
            if (((int) $s['is_active'] === 1 && (int) $s['form_is_active'] === 1) || in_array((int) $s['id'], $before, true)) {
                $allowed[(int) $s['id']] = (string) $s['name'];
            }
        }
        $ticked = [];
        foreach (array_keys((array) ($_POST['sold_as'] ?? [])) as $sid) {
            if (isset($allowed[(int) $sid])) {
                $ticked[] = (int) $sid;
            }
        }
        sort($ticked);
        $errors = [];
        if ($ticked === []) {
            $errors[] = 'Tick at least one specification the product is sold as.';
        }
        if (($reasonError = ReasonValidator::check((string) ($_POST['reason'] ?? ''))) !== null) {
            $errors[] = $reasonError;
        }
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header("Location: /products/{$pid}/specifications#sold-as");
            return;
        }
        if ($ticked === $before) {
            Flash::set('success', 'No changes were made.');
            header("Location: /products/{$pid}/specifications#sold-as");
            return;
        }
        ProductForms::setProductSpecs($pid, $ticked);
        $names = static function (array $ids) use ($allowed): string {
            $out = [];
            foreach ($ids as $i) {
                $out[] = $allowed[$i] ?? ('#' . $i);
            }
            return $out === [] ? 'none (every specification)' : implode(', ', $out);
        };
        AuditLogRepository::log((int) self::user()['id'], 'PRODUCT_SOLD_AS_CHANGED', 'catalog_products', $pid, 'sold_as',
            $names($before), $names($ticked), trim((string) $_POST['reason']));
        Flash::set('success', 'Sold as saved — ' . $names($ticked) . '. New order lines offer these; lines saved before keep theirs.');
        header("Location: /products/{$pid}/specifications#sold-as");
    }

    /** The groups (ProductSpecs::FORMS labels) a set of specifications brings, for the Specifications page. */
    public static function groupsText(array $specs): string
    {
        $groups = [];
        foreach ($specs as $s) {
            $g = (string) ($s['form_spec_group'] ?? '');
            if (isset(ProductSpecs::FORMS[$g])) {
                $groups[$g] = strtolower(ProductSpecs::FORMS[$g]);
            }
        }
        return implode(', ', $groups);
    }
}
