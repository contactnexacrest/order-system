<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * D-431 (owner 25–26 Nov 2026): brands for divisions, associate side only.
 * A brand is the division name an associate sees (name only, no logo), with its own
 * associate rules (empty = the company setting) and, optionally, its own ladder.
 */
final class AssociateBrandRepository
{
    /** @var array<int, ?array<string,mixed>> */
    private static array $byId = [];

    public static function reset(): void
    {
        self::$byId = [];
    }

    /** @return list<array<string,mixed>> with the number of associates */
    public static function all(): array
    {
        return Database::connection()->query(
            "SELECT b.*, (SELECT COUNT(*) FROM associates a WHERE a.brand_id = b.id) AS associate_count
               FROM associate_brands b ORDER BY FIELD(b.status, 'active', 'planned', 'inactive'), b.name"
        )->fetchAll();
    }

    /** @return list<array<string,mixed>> brands that can be given to an associate */
    public static function usable(): array
    {
        return Database::connection()->query("SELECT * FROM associate_brands WHERE status <> 'inactive' ORDER BY name")->fetchAll();
    }

    public static function find(int $id): ?array
    {
        if (!array_key_exists($id, self::$byId)) {
            $stmt = Database::connection()->prepare('SELECT * FROM associate_brands WHERE id = :id');
            $stmt->execute(['id' => $id]);
            self::$byId[$id] = $stmt->fetch() ?: null;
        }
        return self::$byId[$id];
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM associate_brands WHERE code = :c AND status = 'active'");
        $stmt->execute(['c' => strtolower(trim($code))]);
        return $stmt->fetch() ?: null;
    }

    public static function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM associate_brands WHERE name = :n AND id <> :id');
        $stmt->execute(['n' => $name, 'id' => (int) $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function codeTaken(string $code, ?int $exceptId = null): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM associate_brands WHERE code = :c AND id <> :id');
        $stmt->execute(['c' => $code, 'id' => (int) $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array{code:string,name:string,tagline:string,products:?string,website:?string,email:?string,status:string} $d */
    public static function save(?int $id, array $d, int $userId): int
    {
        $pdo = Database::connection();
        $p = ['code' => $d['code'], 'name' => $d['name'], 'tagline' => $d['tagline'], 'products' => $d['products'], 'website' => $d['website'],
            'email' => $d['email'], 'status' => $d['status'], 'u' => $userId];
        if ($id === null) {
            $pdo->prepare('INSERT INTO associate_brands (code, name, tagline, products, website, email, status, created_by, updated_by)
                           VALUES (:code, :name, :tagline, :products, :website, :email, :status, :u, :u2)')
                ->execute($p + ['u2' => $userId]);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE associate_brands SET code = :code, name = :name, tagline = :tagline, products = :products, website = :website,
                                  email = :email, status = :status, updated_by = :u WHERE id = :id')
                ->execute($p + ['id' => $id]);
        }
        unset(self::$byId[$id]);
        return $id;
    }

    /** @return array<string,string> the brand's own rule values (missing = follows the company setting) */
    public static function settings(int $brandId): array
    {
        $stmt = Database::connection()->prepare('SELECT setting_key, setting_value FROM associate_brand_settings WHERE brand_id = :b');
        $stmt->execute(['b' => $brandId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['setting_key']] = (string) $r['setting_value'];
        }
        return $out;
    }

    /** @param array<string, ?string> $values null or '' = remove (follow the company setting) */
    public static function saveSettings(int $brandId, array $values, int $userId): void
    {
        $pdo = Database::connection();
        $del = $pdo->prepare('DELETE FROM associate_brand_settings WHERE brand_id = :b AND setting_key = :k');
        $put = $pdo->prepare('INSERT INTO associate_brand_settings (brand_id, setting_key, setting_value, updated_by) VALUES (:b, :k, :v, :u)
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)');
        foreach ($values as $k => $v) {
            if ($v === null || trim($v) === '') {
                $del->execute(['b' => $brandId, 'k' => $k]);
            } else {
                $put->execute(['b' => $brandId, 'k' => $k, 'v' => trim($v), 'u' => $userId]);
            }
        }
    }

    /** @return list<array<string,mixed>> the brand's own ladder ([] = follows the company ladder) */
    public static function ladder(int $brandId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.id, s.from_order, s.to_order, s.level_id, l.name AS level_name, l.kind, l.value, l.is_active
               FROM associate_brand_ladder_steps s JOIN commission_levels l ON l.id = s.level_id
              WHERE s.brand_id = :b ORDER BY s.from_order'
        );
        $stmt->execute(['b' => $brandId]);
        return $stmt->fetchAll();
    }

    /** @param list<array{from:int,to:?int,level_id:int}> $steps [] = remove (follow the company ladder) */
    public static function replaceLadder(int $brandId, array $steps, int $userId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM associate_brand_ladder_steps WHERE brand_id = :b')->execute(['b' => $brandId]);
        $ins = $pdo->prepare('INSERT INTO associate_brand_ladder_steps (brand_id, from_order, to_order, level_id, updated_by) VALUES (:b, :f, :t, :l, :u)');
        foreach ($steps as $s) {
            $ins->execute(['b' => $brandId, 'f' => $s['from'], 't' => $s['to'], 'l' => $s['level_id'], 'u' => $userId]);
        }
    }

    public static function setAssociateBrand(int $associateId, ?int $brandId): void
    {
        Database::connection()->prepare('UPDATE associates SET brand_id = :b WHERE id = :id')->execute(['b' => $brandId, 'id' => $associateId]);
    }
}
