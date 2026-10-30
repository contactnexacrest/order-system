<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Database;

/**
 * QA Round 6, R6-N04: an action on a record that does not exist (a stale
 * page, a typed URL) used to report success and write an audit row for it.
 * Each such action now checks first. Port: src/helpers/recordCheck.js.
 */
final class RecordCheck
{
    /** @param array<string, int|string|null> $where extra column => value conditions */
    public static function exists(string $table, int $id, array $where = []): bool
    {
        $sql = "SELECT 1 FROM {$table} WHERE id = :id";
        $params = ['id' => $id];
        foreach ($where as $column => $value) {
            $sql .= " AND {$column} = :w_{$column}";
            $params["w_{$column}"] = $value;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /** True when the query (SELECT 1 ... WHERE ...) finds a row. */
    public static function found(string $sql, array $params): bool
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /** Sets the "no longer exists" message and redirects when the record is missing; true when it was. */
    public static function missing(string $table, int $id, string $redirect, string $what, array $where = []): bool
    {
        if (self::exists($table, $id, $where)) {
            return false;
        }
        Flash::set('error', "That {$what} was not found — it may have been removed already. Nothing was changed.");
        header("Location: {$redirect}");
        return true;
    }
}
