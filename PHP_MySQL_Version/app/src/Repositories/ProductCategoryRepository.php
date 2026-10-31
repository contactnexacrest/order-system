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
