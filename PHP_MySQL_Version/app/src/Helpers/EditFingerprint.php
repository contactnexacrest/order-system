<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Database;

/**
 * QA Round 6, R6-N27: two people with the same edit form open — the second
 * save silently put back what the first had just changed. The form now
 * carries a fingerprint of the values it was opened with (only the fields
 * that form edits, so unrelated activity on the record never blocks it);
 * a save is refused when the record no longer matches. Port:
 * src/helpers/editFingerprint.js.
 */
final class EditFingerprint
{
    public const FIELD = 'edit_fingerprint';

    /** @param list<string> $fields */
    public static function of(array $row, array $fields): string
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = isset($row[$field]) ? (string) $row[$field] : null;
        }
        return sha1((string) json_encode($values));
    }

    /** The hidden field, carrying a fingerprint taken from the saved record (before any typed values are shown in it). */
    public static function input(string $fingerprint): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars($fingerprint, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * The "changed since you opened it" message, or null when the record
     * still matches the form (or the form sent no fingerprint).
     *
     * @param list<string> $fields
     */
    public static function conflict(array $current, array $fields, string $entityType, int $entityId, string $what): ?string
    {
        $posted = trim((string) ($_POST[self::FIELD] ?? ''));
        if ($posted === '' || hash_equals(self::of($current, $fields), $posted)) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT u.name, a.created_at FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = :type AND a.entity_id = :id ORDER BY a.id DESC LIMIT 1'
        );
        $stmt->execute(['type' => $entityType, 'id' => $entityId]);
        $last = $stmt->fetch(\PDO::FETCH_ASSOC);
        $by = $last ? ' by ' . ($last['name'] ?: 'someone else') . ' at ' . Dates::human((string) $last['created_at']) : ' by someone else';
        return "This {$what} was changed{$by} after you opened this page. Reload the page to see the change, then make yours again. Nothing was saved.";
    }
}
