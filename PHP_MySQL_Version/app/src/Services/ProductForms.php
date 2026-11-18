<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\ReasonValidator;
use App\Repositories\HsCodeRepository;
use App\Repositories\ProductRepository;

/**
 * Owner decisions D-382 … D-385 (18 Nov 2026, mockups approved, plan confirmed twice):
 *
 *  - D-382 forms of stone (product_forms): the units each form is sold in (LineUnits::canonical
 *    codes), the unit a new line starts on, its group (one of ProductSpecs::FORMS — so
 *    spec_definitions.applies_to keeps working), its default finish. Admin › Pricing › Forms of stone.
 *  - D-383 the specification list (hs_specifications): name, form, usual finish, HS code (an active
 *    HS Code Master code), when to use. Products › Specifications (HS). Which finishes a form offers:
 *    surface_finish_forms (no rows for a form = every finish).
 *  - D-384 a product's "Sold as" ticks (product_hs_specifications); none = every active specification.
 *    The product's forms for spec applies_to = the groups of its ticked specifications' forms; none
 *    ticked → the old catalog_products.forms (kept, read-only) → all.
 *  - D-385 an order line's Sold as (order_products.hs_spec_id): it sets the line's HS code (locked —
 *    only override_line_hs_code with a reason may change it), the units offered and the finishes.
 *
 * A form or specification once used is switched off, never deleted. Node twin: src/services/productForms.js.
 */
final class ProductForms
{
    /** The units a form can tick, in this order (LineUnits::canonical codes). */
    public const UNITS = ['CBM', 'M2', 'SQFT', 'PCS', 'TON', 'RMT', 'KG'];

    /** The unit option preferred for a canonical code (the Natural Stone unit list's names). */
    private const PREFERRED_UNIT = ['CBM' => 'CBM', 'M2' => 'SQM', 'SQFT' => 'SQFT', 'PCS' => 'PCS', 'TON' => 'TON', 'RMT' => 'RMT', 'KG' => 'KG'];

    public const PERMISSION_OVERRIDE = 'override_line_hs_code';

    // ------------------------------------------------------------------ forms

    /** @return list<array<string,mixed>> */
    public static function forms(bool $activeOnly = false): array
    {
        return Database::connection()->query('SELECT * FROM product_forms' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name, id')->fetchAll();
    }

    public static function findForm(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM product_forms WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<string> the form's units (canonical codes) */
    public static function formUnits(array $form): array
    {
        return array_values(array_filter(array_map(static fn ($u) => strtoupper(trim((string) $u)), explode(',', (string) ($form['units'] ?? ''))), static fn ($u) => $u !== ''));
    }

    /** "CBM", "SQM" … for screens and messages. */
    public static function unitLabel(string $code): string
    {
        return $code === 'M2' ? 'SQM' : $code;
    }

    public static function unitsText(array $form): string
    {
        return implode(', ', array_map([self::class, 'unitLabel'], self::formUnits($form)));
    }

    /** The unit option a new line starts on for this form's default unit ("SQM" for M2). */
    public static function preferredUnit(string $code): string
    {
        return self::PREFERRED_UNIT[$code] ?? $code;
    }

    /**
     * Reads the Forms of stone form.
     *
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    public static function readFormInput(array $post, ?int $currentId = null): array
    {
        $errors = [];
        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = 'Name: up to 80 characters (e.g. Raw block).';
        }
        $units = [];
        foreach ((array) ($post['units'] ?? []) as $u) {
            $u = strtoupper(trim((string) $u));
            if (in_array($u, self::UNITS, true) && !in_array($u, $units, true)) {
                $units[] = $u;
            }
        }
        // Keep the order of UNITS.
        $units = array_values(array_intersect(self::UNITS, $units));
        if ($units === []) {
            $errors[] = 'Tick at least one unit the form is sold in.';
        }
        $default = strtoupper(trim((string) ($post['default_unit'] ?? '')));
        if ($default === '' || !in_array($default, $units, true)) {
            $errors[] = 'Starts on: choose one of the ticked units.';
        }
        $group = trim((string) ($post['spec_group'] ?? ''));
        if (!isset(ProductSpecs::FORMS[$group])) {
            $errors[] = 'Group: choose which specifications the form uses (Blocks, Slabs, Tiles …).';
        }
        $finish = trim((string) ($post['default_finish'] ?? ''));
        if ($finish !== '' && !in_array($finish, self::finishNames(), true)) {
            $errors[] = 'Default finish: choose one of the finishes in Settings › Lists › surface_finish, or none.';
        }
        $order = filter_var($post['sort_order'] ?? '0', FILTER_VALIDATE_INT);
        if ($order === false || $order < 0 || $order > 999) {
            $errors[] = 'Order: a whole number from 0 to 999.';
            $order = 0;
        }
        if ($name !== '') {
            foreach (self::forms() as $f) {
                if ((int) $f['id'] !== (int) $currentId && mb_strtolower((string) $f['name']) === mb_strtolower($name)) {
                    $errors[] = "There is already a form called \"{$f['name']}\".";
                }
            }
        }
        $reasonError = ReasonValidator::check((string) ($post['reason'] ?? ''));
        if ($reasonError !== null) {
            $errors[] = $reasonError;
        }
        return [[
            'name' => $name, 'units' => implode(',', $units), 'default_unit' => $default, 'spec_group' => $group,
            'default_finish' => $finish === '' ? null : $finish, 'sort_order' => (int) $order,
            'is_active' => !empty($post['is_active']) ? 1 : 0,
        ], $errors];
    }

    public static function saveForm(?int $id, array $d, int $userId): int
    {
        $pdo = Database::connection();
        if ($id === null) {
            $code = self::uniqueCode($d['name']);
            $pdo->prepare('INSERT INTO product_forms (code, name, units, default_unit, spec_group, default_finish, sort_order, is_active, updated_by)
                           VALUES (:c, :n, :u, :du, :g, :f, :o, :a, :by)')
                ->execute(['c' => $code, 'n' => $d['name'], 'u' => $d['units'], 'du' => $d['default_unit'], 'g' => $d['spec_group'],
                    'f' => $d['default_finish'], 'o' => $d['sort_order'], 'a' => $d['is_active'], 'by' => $userId]);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE product_forms SET name = :n, units = :u, default_unit = :du, spec_group = :g, default_finish = :f, sort_order = :o, is_active = :a, updated_by = :by WHERE id = :id')
            ->execute(['n' => $d['name'], 'u' => $d['units'], 'du' => $d['default_unit'], 'g' => $d['spec_group'],
                'f' => $d['default_finish'], 'o' => $d['sort_order'], 'a' => $d['is_active'], 'by' => $userId, 'id' => $id]);
        return $id;
    }

    private static function uniqueCode(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'form';
        $base = substr($base, 0, 34);
        $code = $base;
        $n = 2;
        $stmt = Database::connection()->prepare('SELECT 1 FROM product_forms WHERE code = :c');
        while (true) {
            $stmt->execute(['c' => $code]);
            if (!$stmt->fetchColumn()) {
                return $code;
            }
            $code = $base . '_' . $n++;
        }
    }

    /** A form is used once a specification or an order line (through its specification) uses it. */
    public static function formUsed(int $id): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM hs_specifications WHERE form_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function deleteForm(int $id): void
    {
        Database::connection()->prepare('DELETE FROM product_forms WHERE id = :id')->execute(['id' => $id]);
    }

    /** "Raw block: CBM, TON · starts on CBM · Blocks · Roughly trimmed · order 4 · active" for the audit log. */
    public static function formSummary(?array $f): ?string
    {
        if ($f === null) {
            return null;
        }
        return $f['name'] . ': ' . self::unitsText($f) . ' · starts on ' . self::unitLabel((string) $f['default_unit'])
            . ' · ' . (ProductSpecs::FORMS[(string) $f['spec_group']] ?? (string) $f['spec_group'])
            . ' · ' . ($f['default_finish'] ?? 'no default finish') . ' · order ' . (int) $f['sort_order'] . ' · ' . ((int) $f['is_active'] === 1 ? 'active' : 'switched off');
    }

    // ----------------------------------------------------------- specifications (HS)

    private const SPEC_SELECT = 'SELECT s.*, f.name AS form_name, f.units AS form_units, f.default_unit AS form_default_unit,
            f.spec_group AS form_spec_group, f.default_finish AS form_default_finish, f.is_active AS form_is_active
          FROM hs_specifications s JOIN product_forms f ON f.id = s.form_id';

    /** @return list<array<string,mixed>> with the form's name, units, default unit, group and default finish */
    public static function specifications(bool $activeOnly = false): array
    {
        return Database::connection()->query(self::SPEC_SELECT . ($activeOnly ? ' WHERE s.is_active = 1 AND f.is_active = 1' : '') . ' ORDER BY s.sort_order, s.name, s.id')->fetchAll();
    }

    public static function findSpecification(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::SPEC_SELECT . ' WHERE s.id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** The specification's form as a product_forms-shaped row. */
    public static function formOf(array $spec): array
    {
        return ['id' => $spec['form_id'], 'name' => $spec['form_name'], 'units' => $spec['form_units'], 'default_unit' => $spec['form_default_unit'],
            'spec_group' => $spec['form_spec_group'], 'default_finish' => $spec['form_default_finish']];
    }

    /**
     * Reads the Specifications (HS) form.
     *
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    public static function readSpecInput(array $post, ?array $current = null): array
    {
        $errors = [];
        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Name: up to 120 characters (e.g. Gangsaw slab).';
        }
        $formId = (int) ($post['form_id'] ?? 0);
        $form = $formId > 0 ? self::findForm($formId) : null;
        if ($form === null || ((int) $form['is_active'] !== 1 && (int) ($current['form_id'] ?? 0) !== $formId)) {
            $errors[] = 'Form: choose one of the active forms of stone.';
        }
        $finish = trim((string) ($post['usual_finish'] ?? ''));
        if ($finish !== '' && !in_array($finish, self::finishNames(), true)) {
            $errors[] = 'Usual finish: choose one of the finishes in Settings › Lists › surface_finish, or none.';
        }
        $hs = trim((string) ($post['hs_code'] ?? ''));
        if (!preg_match('/^(\d{6}|\d{8})$/', $hs)) {
            $errors[] = 'HS code: 6 or 8 digits, no dots (e.g. 68022310).';
        } elseif (!HsCodeRepository::isActiveCode($hs)) {
            $errors[] = "HS code {$hs} is not an active code of the HS Code Master — add or switch it on in HS codes first.";
        }
        $when = trim((string) ($post['when_to_use'] ?? ''));
        if (mb_strlen($when) > 255) {
            $errors[] = 'When to use: up to 255 characters.';
        }
        $order = filter_var($post['sort_order'] ?? '0', FILTER_VALIDATE_INT);
        if ($order === false || $order < 0 || $order > 999) {
            $errors[] = 'Order: a whole number from 0 to 999.';
            $order = 0;
        }
        if ($name !== '') {
            foreach (self::specifications() as $s) {
                if ((int) $s['id'] !== (int) ($current['id'] ?? 0) && mb_strtolower((string) $s['name']) === mb_strtolower($name)) {
                    $errors[] = "There is already a specification called \"{$s['name']}\".";
                }
            }
        }
        $reasonError = ReasonValidator::check((string) ($post['reason'] ?? ''));
        if ($reasonError !== null) {
            $errors[] = $reasonError;
        }
        return [[
            'name' => $name, 'form_id' => $formId, 'usual_finish' => $finish === '' ? null : $finish, 'hs_code' => $hs,
            'when_to_use' => $when === '' ? null : $when, 'sort_order' => (int) $order, 'is_active' => !empty($post['is_active']) ? 1 : 0,
        ], $errors];
    }

    public static function saveSpecification(?int $id, array $d, int $userId): int
    {
        $pdo = Database::connection();
        $p = ['n' => $d['name'], 'f' => $d['form_id'], 'uf' => $d['usual_finish'], 'hs' => $d['hs_code'], 'w' => $d['when_to_use'],
            'o' => $d['sort_order'], 'a' => $d['is_active'], 'by' => $userId];
        if ($id === null) {
            $pdo->prepare('INSERT INTO hs_specifications (name, form_id, usual_finish, hs_code, when_to_use, sort_order, is_active, updated_by)
                           VALUES (:n, :f, :uf, :hs, :w, :o, :a, :by)')->execute($p);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE hs_specifications SET name = :n, form_id = :f, usual_finish = :uf, hs_code = :hs, when_to_use = :w, sort_order = :o, is_active = :a, updated_by = :by WHERE id = :id')
            ->execute($p + ['id' => $id]);
        return $id;
    }

    /** A specification is used once a product ticks it or an order line has it. */
    public static function specificationUsed(int $id): bool
    {
        $pdo = Database::connection();
        foreach (['SELECT 1 FROM product_hs_specifications WHERE hs_spec_id = :id LIMIT 1', 'SELECT 1 FROM order_products WHERE hs_spec_id = :id LIMIT 1'] as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
        return false;
    }

    public static function deleteSpecification(int $id): void
    {
        Database::connection()->prepare('DELETE FROM hs_specifications WHERE id = :id')->execute(['id' => $id]);
    }

    public static function specSummary(?array $s): ?string
    {
        if ($s === null) {
            return null;
        }
        return $s['name'] . ' · ' . ($s['form_name'] ?? ('form #' . (int) $s['form_id'])) . ' · ' . ($s['usual_finish'] ?? 'no usual finish')
            . ' · HS ' . $s['hs_code'] . ' · order ' . (int) $s['sort_order'] . ' · ' . ((int) $s['is_active'] === 1 ? 'active' : 'switched off');
    }

    // --------------------------------------------------------------- a product's "Sold as"

    /** @return list<int> the product's ticked specification ids */
    public static function productSpecIds(int $productId): array
    {
        $stmt = Database::connection()->prepare('SELECT hs_spec_id FROM product_hs_specifications WHERE product_id = :p ORDER BY hs_spec_id');
        $stmt->execute(['p' => $productId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The specifications a line of this product may be sold as: its ticked ones that are active,
     * or every active one when none is ticked.
     *
     * @return list<array<string,mixed>>
     */
    public static function offeredFor(?int $productId): array
    {
        $active = self::specifications(true);
        $ticked = $productId !== null ? self::productSpecIds($productId) : [];
        if ($ticked === []) {
            return $active;
        }
        return array_values(array_filter($active, static fn (array $s): bool => in_array((int) $s['id'], $ticked, true)));
    }

    /**
     * Each product's ticked specifications ("Sold as"), one query, for lists.
     *
     * @param list<int> $productIds
     * @return array<int, list<array{id:int, name:string, hs_code:string}>>
     */
    public static function soldAsByProduct(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT x.product_id, s.id, s.name, s.hs_code FROM product_hs_specifications x JOIN hs_specifications s ON s.id = x.hs_spec_id
              WHERE x.product_id IN ({$in}) ORDER BY x.product_id, s.sort_order, s.name, s.id"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['product_id']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'hs_code' => (string) $r['hs_code']];
        }
        return $out;
    }

    /** @param list<int> $specIds */
    public static function setProductSpecs(int $productId, array $specIds): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM product_hs_specifications WHERE product_id = :p')->execute(['p' => $productId]);
        $ins = $pdo->prepare('INSERT INTO product_hs_specifications (product_id, hs_spec_id) VALUES (:p, :s)');
        foreach (array_values(array_unique(array_map('intval', $specIds))) as $sid) {
            $ins->execute(['p' => $productId, 's' => $sid]);
        }
    }

    /**
     * The product's forms for spec_definitions.applies_to (ProductSpecs::FORMS keys): the groups of
     * its ticked specifications' forms; none ticked → null (the caller falls back to the old
     * catalog_products.forms, then all).
     *
     * @return list<string>|null
     */
    public static function productSpecGroups(int $productId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT f.spec_group FROM product_hs_specifications x JOIN hs_specifications s ON s.id = x.hs_spec_id
               JOIN product_forms f ON f.id = s.form_id WHERE x.product_id = :p ORDER BY f.spec_group'
        );
        $stmt->execute(['p' => $productId]);
        $groups = array_values(array_filter(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), static fn (string $g): bool => isset(ProductSpecs::FORMS[$g])));
        return $groups === [] ? null : $groups;
    }

    // --------------------------------------------------------------- finishes per form

    /** @return list<string> the active surface_finish names, in order */
    public static function finishNames(): array
    {
        $stmt = Database::connection()->prepare("SELECT option_value FROM dropdown_options WHERE list_key = 'surface_finish' AND is_active = 1 ORDER BY sort_order, id");
        $stmt->execute();
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<int, list<int>> dropdown option id => the form ids it is offered for */
    public static function finishFormMap(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT dropdown_option_id, form_id FROM surface_finish_forms ORDER BY dropdown_option_id, form_id')->fetchAll() as $r) {
            $out[(int) $r['dropdown_option_id']][] = (int) $r['form_id'];
        }
        return $out;
    }

    /** @param list<int> $formIds */
    public static function setFinishForms(int $dropdownOptionId, array $formIds): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM surface_finish_forms WHERE dropdown_option_id = :d')->execute(['d' => $dropdownOptionId]);
        $ins = $pdo->prepare('INSERT INTO surface_finish_forms (dropdown_option_id, form_id) VALUES (:d, :f)');
        foreach (array_values(array_unique(array_map('intval', $formIds))) as $fid) {
            $ins->execute(['d' => $dropdownOptionId, 'f' => $fid]);
        }
    }

    /**
     * The finishes a form offers: surface_finish dropdown rows (id, name), or null when the form
     * has no rows (= every finish, the safety rule).
     *
     * @return list<array{id:int, name:string}>|null
     */
    public static function finishesForForm(int $formId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT d.id, d.option_value FROM surface_finish_forms x JOIN dropdown_options d ON d.id = x.dropdown_option_id
              WHERE x.form_id = :f AND d.list_key = 'surface_finish' ORDER BY d.sort_order, d.id"
        );
        $stmt->execute(['f' => $formId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return null;
        }
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['option_value']], $rows);
    }

    // --------------------------------------------------------------- order lines

    /**
     * For other screens and the document builders: the form name of an order line's
     * specification (order_products.hs_spec_id), or null when the line has no Sold as.
     */
    public static function formNameForLine(array $line): ?string
    {
        $specId = (int) ($line['hs_spec_id'] ?? 0);
        if ($specId <= 0) {
            return null;
        }
        $spec = self::findSpecification($specId);
        return $spec !== null ? (string) $spec['form_name'] : null;
    }

    /**
     * Every line of an order that has a Sold as: order_products.id => its form name (one query).
     *
     * @return array<int, string>
     */
    public static function formNameForOrderLines(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT op.id, f.name FROM order_products op JOIN hs_specifications s ON s.id = op.hs_spec_id
               JOIN product_forms f ON f.id = s.form_id WHERE op.order_id = :o'
        );
        $stmt->execute(['o' => $orderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /**
     * The lines' specifications, one query: order_products.id => [spec name, form name, spec HS code].
     *
     * @return array<int, array{spec: string, form: string, hs_code: string}>
     */
    public static function soldAsForOrderLines(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT op.id, s.name AS spec, f.name AS form, s.hs_code FROM order_products op JOIN hs_specifications s ON s.id = op.hs_spec_id
               JOIN product_forms f ON f.id = s.form_id WHERE op.order_id = :o'
        );
        $stmt->execute(['o' => $orderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = ['spec' => (string) $r['spec'], 'form' => (string) $r['form'], 'hs_code' => (string) $r['hs_code']];
        }
        return $out;
    }

    public static function setLineSoldAs(int $lineId, ?int $specId, ?string $overrideReason): void
    {
        Database::connection()->prepare('UPDATE order_products SET hs_spec_id = :s, hs_override_reason = :r WHERE id = :id')
            ->execute(['s' => $specId, 'r' => $overrideReason, 'id' => $lineId]);
    }

    /** Whether the signed-in person may change a line's HS code from its specification. */
    public static function canOverride(): bool
    {
        $u = AuthService::currentUser();
        return $u !== null && PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, self::PERMISSION_OVERRIDE);
    }

    /**
     * D-385: an order line's Sold as, checked before the line is saved.
     *
     * In: line_no, posted_spec (the posted hs_spec_id; null = the form has no such field), catalog_product_id,
     * line_type, currency_code, hs_code (posted), hs_override_reason (posted), unit, size (piece_*_cm),
     * selection (spec id => option id), existing (the saved line, or null for a new one), can_override.
     *
     * Out: spec (the specification row or null), hs_code (the line's HS code when a Sold as is chosen, else
     * null = keep the posted one), reason (hs_override_reason to save), overridden (a new change of the HS code
     * to audit: [from, to]) and refusal (the message, or null).
     *
     * @return array{spec: ?array, hs_code: ?string, reason: ?string, overridden: ?array{0:string,1:string}, refusal: ?string}
     */
    public static function checkLine(array $in): array
    {
        $out = ['spec' => null, 'hs_code' => null, 'reason' => null, 'overridden' => null, 'refusal' => null];
        $lineNo = (int) ($in['line_no'] ?? 0);
        $existing = $in['existing'] ?? null;
        $catalogId = isset($in['catalog_product_id']) && $in['catalog_product_id'] !== null ? (int) $in['catalog_product_id'] : null;
        if ((string) ($in['line_type'] ?? 'goods') !== 'goods') {
            return $out;
        }
        $posted = $in['posted_spec'] ?? null;
        $specId = $posted === null ? (int) ($existing['hs_spec_id'] ?? 0) : (int) $posted;
        if ($specId <= 0) {
            // Required for a goods line picked from Products on a USD order (where picking is required);
            // a saved line whose product did not change may stay without one (lines saved before D-385).
            $productChanged = $existing === null || (int) ($existing['catalog_product_id'] ?? 0) !== (int) ($catalogId ?? 0);
            if ($catalogId !== null && $productChanged && OrderLinePricing::productPickRequired($in['currency_code'] ?? null)) {
                $out['refusal'] = 'Line ' . $lineNo . ': choose what the product is sold as (Sold as) — it sets the line\'s HS code.';
            }
            return $out;
        }
        $spec = self::findSpecification($specId);
        $kept = $existing !== null && (int) ($existing['hs_spec_id'] ?? 0) === $specId;
        if ($spec === null || (!$kept && ((int) $spec['is_active'] !== 1 || (int) $spec['form_is_active'] !== 1))) {
            $out['refusal'] = 'Line ' . $lineNo . ': that Sold as is no longer offered — choose another.';
            return $out;
        }
        if ($catalogId !== null && !$kept) {
            $ticked = self::productSpecIds($catalogId);
            if ($ticked !== [] && !in_array($specId, $ticked, true)) {
                $product = ProductRepository::find($catalogId);
                $out['refusal'] = 'Line ' . $lineNo . ': ' . ($product !== null ? '"' . $product['name'] . '"' : 'this product') . ' is not sold as "' . $spec['name'] . '" — choose one of its Sold as.';
                return $out;
            }
        }
        $out['spec'] = $spec;
        $form = self::formOf($spec);
        // The unit must be one the form is sold in.
        $unit = LineUnits::canonical((string) ($in['unit'] ?? ''));
        if ($unit === null || !in_array($unit, self::formUnits($form), true)) {
            $what = (string) $spec['name'] === (string) $form['name'] ? (string) $spec['name'] : $spec['name'] . ' (' . $form['name'] . ')';
            $out['refusal'] = 'Line ' . $lineNo . ': ' . $what . ' is sold in ' . self::unitsText($form) . ' — choose one of these units.';
            return $out;
        }
        // The chosen Surface finish must be offered for the form.
        if (($finishRefusal = self::finishRefusal($lineNo, $form, $catalogId, $in['selection'] ?? null)) !== null) {
            $out['refusal'] = $finishRefusal;
            return $out;
        }
        // The HS code comes from the specification; only the MD / ED change it, with a reason.
        $specCode = (string) $spec['hs_code'];
        $postedHs = trim((string) ($in['hs_code'] ?? ''));
        $postedReason = trim((string) ($in['hs_override_reason'] ?? ''));
        if ($postedHs === '' || $postedHs === $specCode) {
            $out['hs_code'] = $specCode;
            return $out;
        }
        $canOverride = !empty($in['can_override']);
        if ($kept && $postedHs === (string) ($existing['hs_code'] ?? '') && trim((string) ($existing['hs_override_reason'] ?? '')) !== '') {
            // A change made before, unchanged: kept (a new reason only from someone who may change it).
            $out['hs_code'] = $postedHs;
            $out['reason'] = $canOverride && $postedReason !== '' && ReasonValidator::check($postedReason) === null ? $postedReason : (string) $existing['hs_override_reason'];
            return $out;
        }
        if (!$canOverride) {
            $out['refusal'] = 'Line ' . $lineNo . ': the HS code comes from its Sold as ("' . $spec['name'] . '": ' . $specCode . ') — only the MD or the ED can change it.';
            return $out;
        }
        if (ReasonValidator::check($postedReason) !== null) {
            $out['refusal'] = 'Line ' . $lineNo . ': give the reason for changing the HS code from ' . $specCode . ' (at least ' . ReasonValidator::MIN_LENGTH . ' characters).';
            return $out;
        }
        if (!preg_match('/^(\d{6}|\d{8})$/', $postedHs) || !HsCodeRepository::isActiveCode($postedHs)) {
            $out['refusal'] = 'Line ' . $lineNo . ': HS code "' . $postedHs . '" is not an active code of the HS Code Master.';
            return $out;
        }
        $out['hs_code'] = $postedHs;
        $out['reason'] = $postedReason;
        $from = $kept ? (string) ($existing['hs_code'] ?? $specCode) : $specCode;
        $out['overridden'] = [$from, $postedHs];
        return $out;
    }

    /** The refusal when the line's chosen Surface finish option is not offered for the form, else null. */
    private static function finishRefusal(int $lineNo, array $form, ?int $catalogId, ?array $selection): ?string
    {
        if ($catalogId === null || $selection === null || $selection === []) {
            return null;
        }
        $offered = self::finishesForForm((int) $form['id']);
        if ($offered === null) {
            return null;
        }
        $optionIds = array_values(array_filter(array_map('intval', array_values($selection)), static fn (int $i): bool => $i > 0));
        if ($optionIds === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($optionIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT o.name, o.dropdown_option_id FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id
              WHERE o.id IN ({$in}) AND s.list_key = 'surface_finish' AND o.dropdown_option_id IS NOT NULL"
        );
        $stmt->execute($optionIds);
        $ids = array_column($offered, 'id');
        foreach ($stmt->fetchAll() as $o) {
            if (!in_array((int) $o['dropdown_option_id'], $ids, true)) {
                return 'Line ' . $lineNo . ': the finish "' . $o['name'] . '" is not offered for ' . $form['name'] . ' — choose another Surface finish.';
            }
        }
        return null;
    }

    /**
     * D-385: with a Sold as, the choices the line needs follow its unit (instead of D-348's Thickness and
     * Size / format): CBM / TON nothing more; SQM / SQFT the thickness (the Thickness option or the
     * Thickness (cm)); PCS the length, width and thickness. Null when nothing is missing.
     */
    public static function unitNeedsRefusal(int $lineNo, ?string $unit, array $size, ?int $catalogProductId, ?array $selection): ?string
    {
        $kind = LineUnits::canonical($unit);
        $thicknessKnown = (LineUnits::size($size['piece_thickness_cm'] ?? null) ?? 0.0) > 0.0 || self::thicknessChosen($catalogProductId, $selection);
        if ($kind === 'M2' || $kind === 'SQFT') {
            return $thicknessKnown ? null
                : 'Line ' . $lineNo . ': choose the Thickness or enter the Thickness (cm) — a price per ' . LineUnits::label($unit) . ' needs it.';
        }
        if ($kind === 'PCS') {
            $missing = [];
            foreach (['piece_length_cm' => 'length', 'piece_width_cm' => 'width'] as $field => $what) {
                if ((LineUnits::size($size[$field] ?? null) ?? 0.0) <= 0.0) {
                    $missing[] = $what;
                }
            }
            if (!$thicknessKnown) {
                $missing[] = 'thickness';
            }
            if ($missing !== []) {
                $last = array_pop($missing);
                return 'Line ' . $lineNo . ': enter the ' . ($missing !== [] ? implode(', ', $missing) . ' and ' : '') . $last . ' (cm) — a price per piece needs ' . ($missing !== [] ? 'them' : 'it') . '.';
            }
        }
        return null;
    }

    /** Whether the selection holds a Thickness option with a thickness in mm. */
    private static function thicknessChosen(?int $catalogProductId, ?array $selection): bool
    {
        if ($catalogProductId === null || $selection === null || $selection === []) {
            return false;
        }
        $product = ProductRepository::find($catalogProductId);
        if ($product === null) {
            return false;
        }
        foreach (ProductSpecs::offered($product) as $s) {
            if ($s['kind'] !== 'thickness' || !isset($selection[$s['id']])) {
                continue;
            }
            foreach ($s['options'] as $o) {
                if ((int) $o['id'] === (int) $selection[$s['id']] && $o['thickness_mm'] !== null) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * For "Sold as" on the line panel and the New Order rows: every active specification with what the
     * browser needs to refill the line — its HS code, its form's units (canonical), the unit a new line
     * starts on, the default finish and the finishes the form offers (names; null = all).
     *
     * @return list<array<string,mixed>>
     */
    public static function pickerSpecifications(): array
    {
        $finishCache = [];
        $out = [];
        foreach (self::specifications(true) as $s) {
            $fid = (int) $s['form_id'];
            if (!array_key_exists($fid, $finishCache)) {
                $f = self::finishesForForm($fid);
                $finishCache[$fid] = $f === null ? null : array_column($f, 'name');
            }
            $form = self::formOf($s);
            $out[] = [
                'id' => (int) $s['id'], 'name' => (string) $s['name'], 'hs_code' => (string) $s['hs_code'], 'form' => (string) $s['form_name'],
                'units' => self::formUnits($form), 'default_unit' => self::preferredUnit((string) $s['form_default_unit']),
                'default_finish' => $s['form_default_finish'] !== null ? (string) $s['form_default_finish'] : null,
                'finishes' => $finishCache[$fid],
            ];
        }
        return $out;
    }

    /**
     * For "Pick from Products": product id => its ticked active specification ids ([] = every one).
     *
     * @param list<array<string,mixed>> $pickerProducts
     * @return array<int, list<int>>
     */
    public static function pickerSoldAs(array $pickerProducts): array
    {
        $ids = array_map(static fn (array $p): int => (int) $p['id'], $pickerProducts);
        $out = [];
        foreach (self::soldAsByProduct($ids) as $pid => $specs) {
            $out[$pid] = array_column($specs, 'id');
        }
        return $out;
    }
}
