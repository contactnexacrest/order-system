<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-215 (5a): product categories with their own attributes.
 * The system category "Natural Stone" is today's products — its attributes
 * are the order line's existing Dimensions and Finish, so stone lines print
 * word for word as before. Another category's attribute values are kept on
 * the order line (order_products.attribute_values, JSON label => value).
 * Port: productCategoryRepository.js.
 */
final class ProductCategoryRepository
{
    public const MAX_ATTRIBUTES = 6;

    /** @return list<array<string,mixed>> every category with its attribute labels, the system one first */
    public static function all(bool $activeOnly = false): array
    {
        $rows = Database::connection()->query(
            'SELECT * FROM product_categories' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_system DESC, sort_order, name'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['attributes'] = self::attributeLabels((int) $row['id']);
        }
        return $rows;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM product_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['attributes'] = self::attributeLabels($id);
        return $row;
    }

    /** @return list<string> */
    public static function attributeLabels(int $categoryId): array
    {
        $stmt = Database::connection()->prepare('SELECT label FROM product_category_attributes WHERE category_id = :c ORDER BY sort_order, id');
        $stmt->execute(['c' => $categoryId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM product_categories WHERE name = :n AND id <> :id');
        $stmt->execute(['n' => $name, 'id' => $exceptId ?? 0]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param list<string> $labels */
    public static function create(string $name, array $labels, int $userId): int
    {
        return (int) Database::transaction(static function () use ($name, $labels, $userId): int {
            $pdo = Database::connection();
            $pdo->prepare('INSERT INTO product_categories (name, is_system, is_active, sort_order, created_by) VALUES (:n, 0, 1, 10, :u)')
                ->execute(['n' => $name, 'u' => $userId]);
            $id = (int) $pdo->lastInsertId();
            self::writeLabels($id, $labels);
            return $id;
        });
    }

    /** @param list<string> $labels */
    public static function update(int $id, string $name, array $labels, bool $active): void
    {
        Database::transaction(static function () use ($id, $name, $labels, $active): void {
            Database::connection()->prepare('UPDATE product_categories SET name = :n, is_active = :a WHERE id = :id AND is_system = 0')
                ->execute(['n' => $name, 'a' => $active ? 1 : 0, 'id' => $id]);
            Database::connection()->prepare('DELETE FROM product_category_attributes WHERE category_id = :id')->execute(['id' => $id]);
            self::writeLabels($id, $labels);
        });
    }

    /** @param list<string> $labels */
    private static function writeLabels(int $categoryId, array $labels): void
    {
        $insert = Database::connection()->prepare('INSERT INTO product_category_attributes (category_id, label, sort_order) VALUES (:c, :l, :s)');
        foreach (array_values($labels) as $i => $label) {
            $insert->execute(['c' => $categoryId, 'l' => $label, 's' => $i + 1]);
        }
    }

    /** @return array{0: list<string>, 1: ?string} the attribute labels, or the error */
    public static function parseLabels(array $raw): array
    {
        $labels = [];
        foreach ($raw as $value) {
            $label = trim((string) $value);
            if ($label === '') {
                continue;
            }
            if (mb_strlen($label) > 60) {
                return [[], "Attribute names can be at most 60 characters (\"{$label}\")."];
            }
            if (in_array(mb_strtolower($label), array_map('mb_strtolower', $labels), true)) {
                return [[], "\"{$label}\" is listed twice — each attribute once."];
            }
            $labels[] = $label;
        }
        if ($labels === []) {
            return [[], 'Give the category at least one attribute (e.g. Size, Grade, Colour).'];
        }
        if (count($labels) > self::MAX_ATTRIBUTES) {
            return [[], 'A category can have at most ' . self::MAX_ATTRIBUTES . ' attributes.'];
        }
        return [$labels, null];
    }

    /**
     * Owner decision D-257 Phase 0B (5 Nov 2026): the T&C clause sets a
     * category can name. The documents print them from Phase 1; until then
     * the choice is only stored (Natural Stone = today's stone clauses).
     */
    public const TC_CLAUSE_SETS = [
        'natural_stone' => 'Natural stone clauses (today\'s)',
        'general_goods' => 'General goods clauses',
        'engineering'   => 'Engineering goods clauses',
    ];
    public const MAX_QUALITY_DOCUMENTS = 10;

    /** @return list<string> the units a category allows (upper case); [] = any unit */
    public static function allowedUnits(?array $category): array
    {
        $raw = trim((string) ($category['allowed_units'] ?? ''));
        return $raw === '' ? [] : array_values(array_filter(array_map(static fn ($u) => strtoupper(trim($u)), explode(',', $raw)), static fn ($u) => $u !== ''));
    }

    /** @return list<string> the quality documents a category asks for on an order; [] = none */
    public static function qualityDocuments(?array $category): array
    {
        $raw = trim((string) ($category['quality_documents'] ?? ''));
        return $raw === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: []), static fn ($d) => $d !== ''));
    }

    /**
     * Reads the posted category settings.
     *
     * @param list<string> $knownUnits the active Units list
     * @param list<string> $packageTypes the active Package Types list
     * @return array{0: array<string,?string>, 1: ?string} the settings, or the error
     */
    public static function parseSettings(array $post, array $knownUnits, array $packageTypes): array
    {
        $known = array_map('strtoupper', $knownUnits);
        $allowed = [];
        foreach ((array) ($post['allowed_units'] ?? []) as $u) {
            $u = strtoupper(trim((string) $u));
            if ($u === '') {
                continue;
            }
            if (!in_array($u, $known, true)) {
                return [[], "Unit \"{$u}\" is not on the Units list."];
            }
            if (!in_array($u, $allowed, true)) {
                $allowed[] = $u;
            }
        }
        $default = strtoupper(trim((string) ($post['default_unit'] ?? '')));
        if ($default !== '' && !in_array($default, $known, true)) {
            return [[], "Unit \"{$default}\" is not on the Units list."];
        }
        if ($default !== '' && $allowed !== [] && !in_array($default, $allowed, true)) {
            return [[], "The default unit {$default} must be one of the allowed units."];
        }
        $hs = trim((string) ($post['default_hs_code'] ?? ''));
        if ($hs !== '' && !HsCodeRepository::isActiveCode($hs)) {
            return [[], "HS code \"{$hs}\" is not on the HS Code master list — add it there first (HS Codes, under Admin)."];
        }
        $packing = trim((string) ($post['packing_type'] ?? ''));
        if ($packing !== '' && !in_array($packing, $packageTypes, true)) {
            return [[], 'Choose the packing type from the Package Types list.'];
        }
        $docs = [];
        foreach (preg_split('/\R/', (string) ($post['quality_documents'] ?? '')) ?: [] as $d) {
            $d = trim($d);
            if ($d === '') {
                continue;
            }
            if (mb_strlen($d) > 120) {
                return [[], "A quality document name can be at most 120 characters (\"{$d}\")."];
            }
            if (in_array(mb_strtolower($d), array_map('mb_strtolower', $docs), true)) {
                return [[], "\"{$d}\" is listed twice — each quality document once."];
            }
            $docs[] = $d;
        }
        if (count($docs) > self::MAX_QUALITY_DOCUMENTS) {
            return [[], 'A category can ask for at most ' . self::MAX_QUALITY_DOCUMENTS . ' quality documents.'];
        }
        $tc = trim((string) ($post['tc_clause_set'] ?? ''));
        if ($tc !== '' && !array_key_exists($tc, self::TC_CLAUSE_SETS)) {
            return [[], 'Choose the T&C clause set from the list.'];
        }
        return [[
            'default_unit' => $default !== '' ? $default : null,
            'allowed_units' => $allowed !== [] ? implode(',', $allowed) : null,
            'default_hs_code' => $hs !== '' ? $hs : null,
            'packing_type' => $packing !== '' ? $packing : null,
            'quality_documents' => $docs !== [] ? implode("\n", $docs) : null,
            'tc_clause_set' => $tc !== '' ? $tc : null,
        ], null];
    }

    /** @param array<string,?string> $settings from parseSettings() */
    public static function updateSettings(int $id, array $settings): void
    {
        Database::connection()->prepare(
            'UPDATE product_categories SET default_unit = :du, allowed_units = :au, default_hs_code = :hs, packing_type = :pt, quality_documents = :qd, tc_clause_set = :tc WHERE id = :id'
        )->execute([
            'du' => $settings['default_unit'], 'au' => $settings['allowed_units'], 'hs' => $settings['default_hs_code'],
            'pt' => $settings['packing_type'], 'qd' => $settings['quality_documents'], 'tc' => $settings['tc_clause_set'], 'id' => $id,
        ]);
    }

    /** One line for the audit log: what the settings are. */
    public static function settingsSummary(array $c): string
    {
        return 'unit ' . (($c['default_unit'] ?? null) ?: 'as today') . ' (allowed: ' . (($c['allowed_units'] ?? null) ?: 'any') . ')'
            . '; HS ' . (($c['default_hs_code'] ?? null) ?: '-') . '; packing ' . (($c['packing_type'] ?? null) ?: '-')
            . '; quality documents: ' . (self::qualityDocuments($c) !== [] ? implode(', ', self::qualityDocuments($c)) : 'none')
            . '; T&C ' . (($c['tc_clause_set'] ?? null) ?: '-');
    }

    /**
     * D-257 0B: the attributes of a category's option on the order-line
     * form — its default HS code and unit (filled by app.js) and its allowed
     * units (checked by app.js). '' for Natural Stone, which stays as today.
     */
    public static function lineOptionAttributes(array $category): string
    {
        if ((int) ($category['is_system'] ?? 0) === 1) {
            return '';
        }
        $fills = array_filter(['hs_code' => (string) ($category['default_hs_code'] ?? ''), 'unit' => (string) ($category['default_unit'] ?? '')], static fn ($v) => $v !== '');
        $out = $fills !== [] ? ' data-fills="' . htmlspecialchars((string) json_encode($fills), ENT_QUOTES) . '"' : '';
        $allowed = self::allowedUnits($category);
        return $out . ($allowed !== [] ? ' data-allowed-units="' . htmlspecialchars(implode(',', $allowed), ENT_QUOTES) . '"' : '');
    }

    /**
     * D-257 0B: a new or edited order line in a category other than Natural
     * Stone takes the category's default HS code (goods) and unit when they
     * were left empty, and refuses a unit the category does not allow.
     * Natural Stone (and no category) is left exactly as today.
     *
     * @param array<string,mixed> $post the posted line (changed in place)
     * @return ?string the error, if any
     */
    public static function applyLineDefaults(array &$post): ?string
    {
        $categoryId = (int) ($post['category_id'] ?? 0);
        if ($categoryId === 0 || (string) ($post['line_type'] ?? 'goods') === 'service') {
            return null;
        }
        $category = self::find($categoryId);
        if ($category === null || (int) $category['is_system'] === 1) {
            return null;
        }
        if ((string) ($post['line_type'] ?? 'goods') === 'goods' && trim((string) ($post['hs_code'] ?? '')) === '' && !empty($category['default_hs_code'])) {
            $post['hs_code'] = (string) $category['default_hs_code'];
        }
        if (trim((string) ($post['unit'] ?? '')) === '' && !empty($category['default_unit'])) {
            $post['unit'] = (string) $category['default_unit'];
        }
        $allowed = self::allowedUnits($category);
        $unit = strtoupper(trim((string) ($post['unit'] ?? '')));
        if ($allowed !== [] && $unit !== '' && !in_array($unit, $allowed, true)) {
            return "Unit {$unit} is not one of the units for {$category['name']} (" . implode(', ', $allowed) . ').';
        }
        return null;
    }

    /**
     * Saves an order line's category and, for a non-stone category, its
     * attribute values (label => value, in the category's order).
     *
     * @param array<string,string>|null $values
     */
    public static function setLineCategory(int $orderProductId, ?int $categoryId, ?array $values): void
    {
        Database::connection()->prepare('UPDATE order_products SET category_id = :c, attribute_values = :v WHERE id = :id')
            ->execute(['c' => $categoryId, 'v' => $values === null ? null : json_encode($values, JSON_UNESCAPED_UNICODE), 'id' => $orderProductId]);
    }

    /**
     * What a document prints for the line's attributes: [] for a stone line
     * (it keeps Dimensions / Finish as today), else label => value with
     * "To Be Confirmed" for an empty value.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function documentAttributes(array $line): array
    {
        if (empty($line['category_id']) || empty($line['attribute_values'])) {
            return [];
        }
        $values = json_decode((string) $line['attribute_values'], true);
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $label => $value) {
            $out[] = ['label' => (string) $label, 'value' => trim((string) $value) !== '' ? (string) $value : 'To Be Confirmed'];
        }
        return $out;
    }
}
