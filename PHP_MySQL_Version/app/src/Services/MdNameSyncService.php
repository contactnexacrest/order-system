<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;

/**
 * D-349 (owner, 12 Nov 2026, confirm 1 + confirm 2): the Managing Director's name
 * and title kept in sync on open orders and draft documents.
 *
 * When md_name / md_title is changed in Settings › Company, the person saving
 * chooses "Save and apply" or "Save setting only". Apply changes:
 *   - open orders (status not complete / lost / cancelled) whose kept company
 *     details (frozen_snapshot_json.company) still show the old value;
 *   - documents still in Draft / In review whose kept signatory is the fallback
 *     (signatory_user_id IS NULL) and whose snapshot equals the old value.
 * On those documents the kept company block and the stored render context (what the FINAL
 * copy is re-rendered from on approval) take the new value too.
 * Approved and sent documents, and closed / lost / cancelled orders, never change.
 * A protected founder login's own name is never changed (a DB trigger refuses
 * it on purpose): the panel only says so.
 *
 * userRenamed(): Admin › Users — a non-protected signatory-eligible user's new
 * name goes onto that user's documents still in Draft / In review.
 *
 * Port: src/services/mdNameSyncService.js.
 */
final class MdNameSyncService
{
    public const CLOSED_ORDER_STATUSES = ['complete', 'lost', 'cancelled'];
    public const OPEN_DOCUMENT_STATUSES = ['draft', 'in_review'];

    /**
     * @param array<string,array{old:string,new:string}> $changes keyed md_name / md_title
     * @return array{orders:list<array{id:int,ref:string,keys:list<string>}>,documents:list<array{id:int,ref:string,keys:list<string>}>,not_changed_documents:int,not_changed_orders:int,founder_note:?string}
     */
    public static function preview(array $changes): array
    {
        $changes = self::onlyMdKeys($changes);
        $pdo = Database::connection();

        $orders = [];
        $closedOrders = 0;
        if ($changes !== []) {
            $rows = $pdo->query(
                'SELECT id, order_reference, status, frozen_snapshot_json FROM orders
                  WHERE frozen_snapshot_json IS NOT NULL ORDER BY id'
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $snap = json_decode((string) $row['frozen_snapshot_json'], true);
                $company = is_array($snap) && is_array($snap['company'] ?? null) ? $snap['company'] : null;
                if ($company === null) {
                    continue;
                }
                $keys = [];
                foreach ($changes as $key => $c) {
                    if (array_key_exists($key, $company) && (string) $company[$key] === $c['old']) {
                        $keys[] = $key;
                    }
                }
                if ($keys !== [] && in_array((string) $row['status'], self::CLOSED_ORDER_STATUSES, true)) {
                    $closedOrders++;
                } elseif ($keys !== []) {
                    $orders[] = ['id' => (int) $row['id'], 'ref' => (string) $row['order_reference'], 'keys' => $keys];
                }
            }
        }

        $documents = [];
        $notChanged = 0;
        foreach (self::fallbackDocuments($changes) as $doc) {
            if (in_array($doc['status'], self::OPEN_DOCUMENT_STATUSES, true)) {
                $documents[] = ['id' => (int) $doc['id'], 'ref' => (string) ($doc['document_reference'] ?? ('#' . $doc['id'])), 'keys' => $doc['keys']];
            } elseif (in_array($doc['status'], ['approved', 'sent'], true)) {
                $notChanged++;
            }
        }

        return [
            'orders' => $orders,
            'documents' => $documents,
            'not_changed_documents' => $notChanged,
            'not_changed_orders' => $closedOrders,
            'founder_note' => isset($changes['md_name']) ? self::founderNote($changes['md_name']['new']) : null,
        ];
    }

    /**
     * Applies the preview: order snapshots and draft / in-review fallback signatory snapshots.
     * Every change is written to the audit log (old -> new, which order, which document).
     *
     * @param array<string,array{old:string,new:string}> $changes
     * @return array{orders:int,documents:int}
     */
    public static function apply(array $changes, int $userId, string $reason, bool $orders = true, bool $documents = true): array
    {
        return Database::transaction(static fn (): array => self::applyNow($changes, $userId, $reason, $orders, $documents));
    }

    private static function applyNow(array $changes, int $userId, string $reason, bool $orders, bool $documents): array
    {
        $changes = self::onlyMdKeys($changes);
        $plan = self::preview($changes);
        $plan['orders'] = $orders ? $plan['orders'] : [];
        $plan['documents'] = $documents ? $plan['documents'] : [];
        $pdo = Database::connection();
        $columns = ['md_name' => 'signatory_name_snapshot', 'md_title' => 'signatory_designation_snapshot'];

        foreach ($plan['orders'] as $o) {
            $snap = OrderSnapshotService::snapshot($o['id']);
            if ($snap === null) {
                continue;
            }
            foreach ($o['keys'] as $key) {
                $snap['company'][$key] = $changes[$key]['new'];
            }
            $pdo->prepare('UPDATE orders SET frozen_snapshot_json = :j WHERE id = :id')
                ->execute(['j' => json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $o['id']]);
            foreach ($o['keys'] as $key) {
                AuditLogRepository::log($userId, 'ORDER_MD_DETAILS_SYNCED', 'orders', $o['id'], 'frozen_snapshot_json.company.' . $key,
                    $changes[$key]['old'], $changes[$key]['new'], self::reason($reason, 'Settings › Company'));
            }
        }

        foreach ($plan['documents'] as $d) {
            foreach ($d['keys'] as $key) {
                $col = $columns[$key];
                $pdo->prepare(
                    "UPDATE documents SET {$col} = :new
                      WHERE id = :id AND signatory_user_id IS NULL AND status IN ('draft', 'in_review') AND {$col} = :old"
                )->execute(['new' => $changes[$key]['new'], 'old' => $changes[$key]['old'], 'id' => $d['id']]);
                AuditLogRepository::log($userId, 'DOCUMENT_SIGNATORY_SNAPSHOT_SYNCED', 'documents', $d['id'], $col,
                    $changes[$key]['old'], $changes[$key]['new'], self::reason($reason, 'Settings › Company'));
            }
            self::syncDocumentCompanySnapshot($d['id'], $d['keys'], $changes);
            $edits = [];
            foreach ($d['keys'] as $key) {
                $edits[] = [['company', $key], $changes[$key]['old'], $changes[$key]['new']];
                $edits[] = [['signatory', $key === 'md_name' ? 'name' : 'designation'], $changes[$key]['old'], $changes[$key]['new']];
            }
            self::syncRenderContext($d['id'], $edits);
        }

        return ['orders' => count($plan['orders']), 'documents' => count($plan['documents'])];
    }

    /**
     * Admin › Users: a signatory-eligible (non-protected) user's name changed — their
     * documents still in Draft / In review take the new name. Approved and sent never change.
     */
    public static function userRenamed(int $targetUserId, string $oldName, string $newName, int $actorId): int
    {
        if ($oldName === $newName) {
            return 0;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT is_signatory_eligible, is_protected_account FROM users WHERE id = :id');
        $stmt->execute(['id' => $targetUserId]);
        $u = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$u || (int) $u['is_protected_account'] === 1 || (int) $u['is_signatory_eligible'] !== 1) {
            return 0;
        }
        $docs = $pdo->prepare(
            "SELECT id, signatory_name_snapshot FROM documents
              WHERE signatory_user_id = :u AND status IN ('draft', 'in_review')
                AND (signatory_name_snapshot IS NULL OR signatory_name_snapshot <> :new)
              ORDER BY id"
        );
        $docs->execute(['u' => $targetUserId, 'new' => $newName]);
        $n = 0;
        foreach ($docs->fetchAll(\PDO::FETCH_ASSOC) as $d) {
            $pdo->prepare("UPDATE documents SET signatory_name_snapshot = :new WHERE id = :id AND status IN ('draft', 'in_review')")
                ->execute(['new' => $newName, 'id' => (int) $d['id']]);
            self::syncRenderContext((int) $d['id'], [[['signatory', 'name'], $oldName, $newName]]);
            AuditLogRepository::log($actorId, 'DOCUMENT_SIGNATORY_SNAPSHOT_SYNCED', 'documents', (int) $d['id'], 'signatory_name_snapshot',
                $d['signatory_name_snapshot'] !== null ? (string) $d['signatory_name_snapshot'] : null, $newName,
                'Signatory renamed in Admin › Users (user #' . $targetUserId . ')');
            $n++;
        }
        return $n;
    }

    /** The md_name / md_title entries of a settings change set, as plain strings. */
    public static function onlyMdKeys(array $changes): array
    {
        $out = [];
        foreach (['md_name', 'md_title'] as $key) {
            if (isset($changes[$key]) && (string) ($changes[$key]['old'] ?? '') !== (string) ($changes[$key]['new'] ?? '')) {
                $out[$key] = ['old' => (string) ($changes[$key]['old'] ?? ''), 'new' => (string) ($changes[$key]['new'] ?? '')];
            }
        }
        return $out;
    }

    /** Info line when the default signatory is a protected founder login whose name differs from the new md_name. */
    public static function founderNote(string $newMdName): ?string
    {
        $row = Database::connection()->query(
            'SELECT u.name, u.is_protected_account FROM company_default_signatory cds JOIN users u ON u.id = cds.user_id WHERE cds.id = 1'
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$row || (int) $row['is_protected_account'] !== 1 || (string) $row['name'] === $newMdName) {
            return null;
        }
        return 'The name printed under the signature comes from the founder login, which the app cannot change on purpose. '
            . 'Change it with the founder SQL block in the README (First-time setup).';
    }

    /** @return list<array{id:int,document_reference:?string,status:string,keys:list<string>}> */
    private static function fallbackDocuments(array $changes): array
    {
        $columns = ['md_name' => 'signatory_name_snapshot', 'md_title' => 'signatory_designation_snapshot'];
        $byId = [];
        foreach ($changes as $key => $c) {
            $col = $columns[$key];
            $stmt = Database::connection()->prepare(
                "SELECT id, document_reference, status FROM documents
                  WHERE signatory_user_id IS NULL AND {$col} = :old AND status IN ('draft', 'in_review', 'approved', 'sent')
                  ORDER BY id"
            );
            $stmt->execute(['old' => $c['old']]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['id'];
                $byId[$id] ??= ['id' => $id, 'document_reference' => $row['document_reference'], 'status' => (string) $row['status'], 'keys' => []];
                $byId[$id]['keys'][] = $key;
            }
        }
        ksort($byId);
        return array_values($byId);
    }

    /** The same document's own kept company block (company_snapshot_json), where it still shows the old value. */
    private static function syncDocumentCompanySnapshot(int $documentId, array $keys, array $changes): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT company_snapshot_json FROM documents WHERE id = :id');
        $stmt->execute(['id' => $documentId]);
        $raw = $stmt->fetchColumn();
        $company = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($company)) {
            return;
        }
        $changed = false;
        foreach ($keys as $key) {
            if (array_key_exists($key, $company) && (string) $company[$key] === $changes[$key]['old']) {
                $company[$key] = $changes[$key]['new'];
                $changed = true;
            }
        }
        if ($changed) {
            $pdo->prepare('UPDATE documents SET company_snapshot_json = :j WHERE id = :id')
                ->execute(['j' => json_encode($company, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $documentId]);
        }
    }

    /**
     * The stored render context of a Draft / In-review document (documents.render_context_json,
     * what the FINAL copy is re-rendered from on approval): the given text fields, where they
     * still show the old value. Images stay as their stored references.
     *
     * @param list<array{0:list<string>,1:string,2:string}> $edits [path, old, new]
     */
    private static function syncRenderContext(int $documentId, array $edits): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT render_context_json FROM documents WHERE id = :id AND status IN ('draft', 'in_review')");
        $stmt->execute(['id' => $documentId]);
        $raw = $stmt->fetchColumn();
        $context = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($context)) {
            return;
        }
        $changed = false;
        foreach ($edits as [[$block, $field], $old, $new]) {
            if (is_array($context[$block] ?? null) && array_key_exists($field, $context[$block]) && (string) $context[$block][$field] === $old) {
                $context[$block][$field] = $new;
                $changed = true;
            }
        }
        if ($changed) {
            $json = json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
            if ($json !== false) {
                $pdo->prepare('UPDATE documents SET render_context_json = :c WHERE id = :id')->execute(['c' => $json, 'id' => $documentId]);
            }
        }
    }

    private static function reason(string $reason, string $where): string
    {
        $text = 'D-349 apply from ' . $where . ($reason !== '' ? ': ' . $reason : '');
        return mb_substr($text, 0, 500);
    }
}
