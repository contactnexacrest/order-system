<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use App\Repositories\AuditLogRepository;

/**
 * Owner decision D-358 (13 Nov 2026): "Duplicate a product".
 *
 * Copies one catalogue product and every row that belongs to it, in ONE transaction:
 *  - catalog_products (the product row, its category, HS code, unit, costs to FOB, price rules
 *    and pricing overrides) — with a new name, Inactive, no Zoho item, created now by the user;
 *  - spec_definitions with product_id (the product's own specifications), and spec_options with
 *    product_id or under one of those specifications (option ↔ specification links re-pointed to
 *    the copies; the D-357 dropdown_option_id link kept as it is);
 *  - product_spec_hidden and product_option_overrides (re-pointed when they name a copied row);
 *  - catalog_product_suppliers, with their catalog_supplier_thickness_prices and
 *    catalog_product_supplier_options (re-pointed to the copied rows);
 *  - catalog_product_misc_charges;
 *  - catalog_product_images — the image FILE is copied too, so deleting an image on one product
 *    never removes the other's.
 * Not copied: catalog_supplier_price_history (an audit trail of changes made on the source's
 * supplier rows — the current price itself is on the copied supplier and thickness rows),
 * price_requests and anything on orders (order_products, quotations).
 *
 * Every column is copied by an INSERT … SELECT on the live column list, so the values are
 * identical to the source's and a column added later is copied without a code change.
 * The source id is kept in the audit log (PRODUCT_DUPLICATED, old_value = source id) — no new column.
 */
final class ProductDuplicateService
{
    public const AUDIT_ACTION = 'PRODUCT_DUPLICATED';
    public const MAX_NAME = 255;

    /** @var array<string, list<string>> */
    private static array $columns = [];

    /** The name proposed on the confirm screen. */
    public static function proposedName(string $sourceName): string
    {
        $base = mb_substr(trim($sourceName) . ' (copy)', 0, self::MAX_NAME);
        $name = $base;
        for ($n = 2; self::nameTaken($name) && $n < 100; $n++) {
            $name = mb_substr(trim($sourceName), 0, self::MAX_NAME - 12) . " (copy {$n})";
        }
        return $name;
    }

    /** Two products may not share a name (case and surrounding spaces ignored). */
    public static function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM catalog_products WHERE LOWER(TRIM(name)) = LOWER(TRIM(:n))' . ($exceptId !== null ? ' AND id <> :x' : ''));
        $stmt->execute($exceptId !== null ? ['n' => $name, 'x' => $exceptId] : ['n' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<string> every product name, for the browser check on the confirm screen */
    public static function allNames(): array
    {
        return array_map('strval', Database::connection()->query('SELECT name FROM catalog_products ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Server-side check of the typed name; null = fine. */
    public static function nameError(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Enter the name of the new product.';
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            return 'The name can be at most ' . self::MAX_NAME . ' characters.';
        }
        if (self::nameTaken($name)) {
            return 'A product called "' . $name . '" already exists — two products cannot have the same name. Choose another name.';
        }
        return null;
    }

    /**
     * What the copy will hold — the counts on the confirm screen.
     *
     * @return array<string,int>
     */
    public static function counts(int $sourceId): array
    {
        $one = static function (string $sql) use ($sourceId): int {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute(['id' => $sourceId]);
            return (int) $stmt->fetchColumn();
        };
        return [
            'specs'           => $one('SELECT COUNT(*) FROM spec_definitions WHERE product_id = :id'),
            'options'         => $one('SELECT COUNT(*) FROM spec_options o LEFT JOIN spec_definitions d ON d.id = o.spec_id AND d.product_id IS NOT NULL WHERE COALESCE(o.product_id, d.product_id) = :id'),
            'hidden_specs'    => $one('SELECT COUNT(*) FROM product_spec_hidden WHERE product_id = :id'),
            'option_overrides' => $one('SELECT COUNT(*) FROM product_option_overrides WHERE product_id = :id'),
            'suppliers'       => $one('SELECT COUNT(*) FROM catalog_product_suppliers WHERE product_id = :id'),
            'thickness_rows'  => $one('SELECT COUNT(*) FROM catalog_supplier_thickness_prices t JOIN catalog_product_suppliers s ON s.id = t.product_supplier_id WHERE s.product_id = :id'),
            'supplier_options' => $one('SELECT COUNT(*) FROM catalog_product_supplier_options so JOIN catalog_product_suppliers s ON s.id = so.supplier_id WHERE s.product_id = :id'),
            'misc_charges'    => $one('SELECT COUNT(*) FROM catalog_product_misc_charges WHERE product_id = :id'),
            'images'          => $one('SELECT COUNT(*) FROM catalog_product_images WHERE product_id = :id'),
            'price_history'   => $one('SELECT COUNT(*) FROM catalog_supplier_price_history h JOIN catalog_product_suppliers s ON s.id = h.product_supplier_id WHERE s.product_id = :id'),
        ];
    }

    /**
     * Copies the product; returns the new product's id. Throws on failure (nothing is kept).
     */
    public static function duplicate(int $sourceId, string $name, int $userId): int
    {
        $pdo = Database::connection();
        $name = trim($name);
        $copiedFiles = [];
        try {
            $newId = Database::transaction(static function () use ($pdo, $sourceId, $name, $userId, &$copiedFiles): int {
                $source = $pdo->prepare('SELECT id, name FROM catalog_products WHERE id = :id FOR UPDATE');
                $source->execute(['id' => $sourceId]);
                $src = $source->fetch();
                if (!$src) {
                    throw new \RuntimeException('Product not found.');
                }

                // 1. The product row: new name, Inactive, no Zoho item, created now by this user.
                $newId = self::copyRow('catalog_products', 'id = :src', ['src' => $sourceId], [
                    'name' => $name, 'is_active' => 0,
                    'zoho_item_id' => null, 'zoho_synced_hash' => null, 'zoho_synced_at' => null,
                    'created_by' => $userId, 'updated_by' => $userId,
                    'created_at' => ['raw' => 'NOW()'], 'updated_at' => ['raw' => 'NOW()'],
                ]);

                // 2. The product's own specifications, then its options (re-pointed to the copies).
                $specMap = [];
                foreach (self::ids('SELECT id FROM spec_definitions WHERE product_id = :p ORDER BY id', ['p' => $sourceId]) as $oldSpec) {
                    $specMap[$oldSpec] = self::copyRow('spec_definitions', 'id = :src', ['src' => $oldSpec], ['product_id' => $newId]);
                }
                $optionMap = [];
                $optSql = 'SELECT id, spec_id FROM spec_options WHERE product_id = :p'
                    . ($specMap !== [] ? ' OR spec_id IN (' . implode(',', array_map('intval', array_keys($specMap))) . ')' : '') . ' ORDER BY id';
                $optStmt = $pdo->prepare($optSql);
                $optStmt->execute(['p' => $sourceId]);
                foreach ($optStmt->fetchAll() as $o) {
                    $optionMap[(int) $o['id']] = self::copyRow('spec_options', 'id = :src', ['src' => (int) $o['id']], [
                        'product_id' => $newId, 'spec_id' => $specMap[(int) $o['spec_id']] ?? (int) $o['spec_id'],
                    ]);
                }

                // 3. Hidden category specifications and option overrides.
                $stmt = $pdo->prepare('SELECT spec_id FROM product_spec_hidden WHERE product_id = :p ORDER BY spec_id');
                $stmt->execute(['p' => $sourceId]);
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $specId) {
                    self::copyRow('product_spec_hidden', 'product_id = :src AND spec_id = :s', ['src' => $sourceId, 's' => (int) $specId],
                        ['product_id' => $newId, 'spec_id' => $specMap[(int) $specId] ?? (int) $specId]);
                }
                $stmt = $pdo->prepare('SELECT option_id FROM product_option_overrides WHERE product_id = :p ORDER BY option_id');
                $stmt->execute(['p' => $sourceId]);
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $optionId) {
                    self::copyRow('product_option_overrides', 'product_id = :src AND option_id = :o', ['src' => $sourceId, 'o' => (int) $optionId],
                        ['product_id' => $newId, 'option_id' => $optionMap[(int) $optionId] ?? (int) $optionId]);
                }

                // 4. Supplier rows with their thickness prices and offered options.
                foreach (self::ids('SELECT id FROM catalog_product_suppliers WHERE product_id = :p ORDER BY id', ['p' => $sourceId]) as $oldSup) {
                    $newSup = self::copyRow('catalog_product_suppliers', 'id = :src', ['src' => $oldSup], ['product_id' => $newId]);
                    foreach (self::ids('SELECT id FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :s ORDER BY id', ['s' => $oldSup]) as $oldT) {
                        self::copyRow('catalog_supplier_thickness_prices', 'id = :src', ['src' => $oldT], ['product_supplier_id' => $newSup]);
                    }
                    $so = $pdo->prepare('SELECT spec_id, option_id FROM catalog_product_supplier_options WHERE supplier_id = :s ORDER BY spec_id, option_id');
                    $so->execute(['s' => $oldSup]);
                    foreach ($so->fetchAll() as $r) {
                        self::copyRow('catalog_product_supplier_options', 'supplier_id = :src AND spec_id = :sp AND option_id = :op',
                            ['src' => $oldSup, 'sp' => (int) $r['spec_id'], 'op' => (int) $r['option_id']],
                            ['supplier_id' => $newSup, 'spec_id' => $specMap[(int) $r['spec_id']] ?? (int) $r['spec_id'], 'option_id' => $optionMap[(int) $r['option_id']] ?? (int) $r['option_id']]);
                    }
                }

                // 5. Misc charges.
                foreach (self::ids('SELECT id FROM catalog_product_misc_charges WHERE product_id = :p ORDER BY id', ['p' => $sourceId]) as $oldM) {
                    self::copyRow('catalog_product_misc_charges', 'id = :src', ['src' => $oldM], ['product_id' => $newId]);
                }

                // 6. Images: the file is copied into the new product's own folder.
                $img = $pdo->prepare('SELECT id, server_path FROM catalog_product_images WHERE product_id = :p ORDER BY id');
                $img->execute(['p' => $sourceId]);
                foreach ($img->fetchAll() as $i) {
                    $from = (string) $i['server_path'];
                    if (!is_file($from)) {
                        continue; // nothing on disk to show — the copy simply has no such image
                    }
                    $dir = Env::storageBasePath() . '/products/' . $newId . '/images';
                    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                        throw new \RuntimeException('Could not create the image folder for the copy.');
                    }
                    $to = $dir . '/img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower(pathinfo($from, PATHINFO_EXTENSION) ?: 'jpg');
                    if (!copy($from, $to)) {
                        throw new \RuntimeException('Could not copy a product image.');
                    }
                    $copiedFiles[] = $to;
                    self::copyRow('catalog_product_images', 'id = :src', ['src' => (int) $i['id']], [
                        'product_id' => $newId, 'server_path' => $to, 'uploaded_by' => $userId, 'uploaded_at' => ['raw' => 'NOW()'],
                    ]);
                }

                AuditLogRepository::log($userId, self::AUDIT_ACTION, 'products', $newId, 'copied_from_product_id', (string) $sourceId, $name,
                    'Copied from product #' . $sourceId . ' "' . (string) $src['name'] . '"');
                return $newId;
            });
        } catch (\Throwable $e) {
            foreach ($copiedFiles as $f) {
                @unlink($f);
            }
            throw $e;
        }
        return $newId;
    }

    /**
     * The "Copied from …" note on a product made by Duplicate: the source (id, name — null when
     * it was deleted since), when, and the role (or designation) of the person who copied it.
     *
     * @return array{source_id:int, source_name:?string, source_exists:bool, copied_at:string, by:string}|null
     */
    public static function copiedFrom(int $productId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT al.old_value, al.created_at, al.reason, r.name AS role_name, d.title AS designation, p.name AS source_name
               FROM audit_log al
               LEFT JOIN users u ON u.id = al.user_id
               LEFT JOIN roles r ON r.id = u.role_id
               LEFT JOIN designations d ON d.id = u.designation_id
               LEFT JOIN catalog_products p ON p.id = CAST(al.old_value AS UNSIGNED)
              WHERE al.action_type = :a AND al.entity_type = 'products' AND al.entity_id = :id
              ORDER BY al.id DESC LIMIT 1"
        );
        $stmt->execute(['a' => self::AUDIT_ACTION, 'id' => $productId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $by = trim((string) ($row['role_name'] ?? '')) ?: trim((string) ($row['designation'] ?? '')) ?: 'a staff member';
        return [
            'source_id' => (int) $row['old_value'],
            'source_name' => $row['source_name'] !== null ? (string) $row['source_name'] : null,
            'source_exists' => $row['source_name'] !== null,
            'copied_at' => date('j M Y', strtotime((string) $row['created_at']) ?: time()),
            'by' => $by,
        ];
    }

    /** @return list<int> */
    private static function ids(string $sql, array $params): array
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<string> the table's stored columns, without the auto-increment id and generated columns */
    private static function columns(string $table): array
    {
        if (!isset(self::$columns[$table])) {
            $stmt = Database::connection()->prepare(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                    AND EXTRA NOT LIKE '%auto_increment%' AND EXTRA NOT LIKE '%GENERATED%'
                  ORDER BY ORDINAL_POSITION"
            );
            $stmt->execute(['t' => $table]);
            self::$columns[$table] = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        return self::$columns[$table];
    }

    /**
     * INSERT INTO $table (every column) SELECT (every column, or the override) FROM $table WHERE $where.
     * An override is a value (bound) or ['raw' => 'NOW()']. Returns the new row's id (0 for tables without one).
     *
     * @param array<string,mixed> $whereParams
     * @param array<string,mixed> $overrides
     */
    private static function copyRow(string $table, string $where, array $whereParams, array $overrides): int
    {
        $cols = self::columns($table);
        $select = [];
        $params = $whereParams;
        foreach ($cols as $c) {
            if (array_key_exists($c, $overrides)) {
                $v = $overrides[$c];
                if (is_array($v) && isset($v['raw'])) {
                    $select[] = $v['raw'];
                } else {
                    $select[] = ':ov_' . $c;
                    $params['ov_' . $c] = $v;
                }
            } else {
                $select[] = '`' . $c . '`';
            }
        }
        $quoted = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $cols));
        $pdo = Database::connection();
        $stmt = $pdo->prepare("INSERT INTO `{$table}` ({$quoted}) SELECT " . implode(', ', $select) . " FROM `{$table}` WHERE {$where}");
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("Copy of {$table} wrote {$stmt->rowCount()} rows, expected 1.");
        }
        return (int) $pdo->lastInsertId();
    }
}
