<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\FinancialYear;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaFyLockRepository;
use App\Repositories\CompanySettingsRepository;

/**
 * Owner points 5 / 8 / 9 (2 Nov 2026): "every document has a draft and an
 * approved copy — do we need both? un-useful copies eat cloud space", "old
 * CAFIN copies — keep or delete?", "give admin configuration to delete draft
 * documents". Admin → Settings → Document copies clean-up. Everything is OFF
 * by default; each rule is switched on separately.
 *
 * What a generated document leaves behind (DocumentGenerationService):
 *   generate        → draft PDF (DRAFT watermark) + optional internal DOCX
 *   approve         → final PDF (the copy that is sent); the draft stays
 *   newer approved  → a SUPERSEDED-stamped PDF; the old final stays too
 *   CAFIN           → a new PDF every time it is generated
 * Every one of those files carries file_store.linked_document_id (the
 * revision it belongs to), which is how the rules below find them.
 *
 * Rules (each only when switched on):
 *   draft       — the DRAFT copy of a revision that has since been approved
 *                 (its final copy exists), N days after approval;
 *   superseded  — every copy of a superseded revision that was NEVER sent to
 *                 the client, N days after it was superseded, except the one
 *                 SUPERSEDED-stamped copy (so the history list still opens);
 *   cafin_old   — every CAFIN copy except the latest per order, 30 days after
 *                 a newer one was made.
 *
 * Never removed, whatever is switched on: anything sent to the client
 * (file_store.sent_to_client, a sent e-mail, "counted as sent" with e-mail
 * off, a client portal download); the current copy of every
 * revision; the internal Word copy of a revision that is still current;
 * uploaded/received files (signed copies, bills, screenshots ...); any file
 * of an order with an open dispute; any document generated in a financial
 * year the CA has locked.
 *
 * Node twin: src/services/documentCopyCleanupService.js — same rules, same
 * order, same wording.
 */
final class DocumentCopyCleanupService
{
    public const KEY_DRAFT = 'doc_cleanup_draft_days';
    public const KEY_SUPERSEDED = 'doc_cleanup_superseded_days';
    public const KEY_CAFIN = 'doc_cleanup_cafin_mode';

    /** @var int[] */
    public const DRAFT_OPTIONS = [0, 30, 90, 180];
    /** @var int[] */
    public const SUPERSEDED_OPTIONS = [0, 90, 180, 365];
    /** @var string[] */
    public const CAFIN_OPTIONS = ['keep_all', 'latest_only'];
    public const CAFIN_DAYS = 30;

    /** What each rule shows as, in the preview, the audit trail and the file row. */
    public const REASONS = [
        'draft'      => 'Draft copy of an approved document',
        'superseded' => 'Copy of a superseded revision that was never sent to the client',
        'cafin_old'  => 'Older CAFIN copy (a newer one exists)',
    ];

    /** @return array{draft_days:int,superseded_days:int,cafin_mode:string} */
    public static function settings(): array
    {
        $draft = (int) (CompanySettingsRepository::get(self::KEY_DRAFT) ?? '0');
        $superseded = (int) (CompanySettingsRepository::get(self::KEY_SUPERSEDED) ?? '0');
        $cafin = (string) (CompanySettingsRepository::get(self::KEY_CAFIN) ?? 'keep_all');
        return [
            'draft_days'      => in_array($draft, self::DRAFT_OPTIONS, true) ? $draft : 0,
            'superseded_days' => in_array($superseded, self::SUPERSEDED_OPTIONS, true) ? $superseded : 0,
            'cafin_mode'      => in_array($cafin, self::CAFIN_OPTIONS, true) ? $cafin : 'keep_all',
        ];
    }

    /** @param array{draft_days:int,superseded_days:int,cafin_mode:string} $settings */
    public static function anyOn(array $settings): bool
    {
        return $settings['draft_days'] > 0 || $settings['superseded_days'] > 0 || $settings['cafin_mode'] === 'latest_only';
    }

    /**
     * Every file the given settings would remove right now, oldest first.
     * The same list drives the page's preview and the run itself.
     *
     * @param array{draft_days:int,superseded_days:int,cafin_mode:string}|null $settings
     * @return array<int, array<string,mixed>>
     */
    public static function candidates(?array $settings = null): array
    {
        $settings ??= self::settings();
        $pdo = Database::connection();
        $byFile = [];

        // Guards shared by every rule (aliases: fs = the file, d = its revision).
        $guards = "fs.file_origin = 'GENERATED_AUTO' AND fs.is_active = 1 AND fs.sent_to_client = 0
                   AND NOT EXISTS (SELECT 1 FROM disputes dp WHERE dp.order_id = d.order_id AND dp.status <> 'Resolved' AND dp.resolved_at IS NULL)";
        $select = "SELECT fs.id AS file_id, fs.original_filename, fs.file_size_bytes, fs.server_path, fs.uploaded_at,
                          d.id AS document_id, d.order_id, d.document_reference, d.revision_number, d.generated_at, dt.code AS document_type_code
                     FROM file_store fs
                     JOIN documents d ON d.id = fs.linked_document_id
                     JOIN document_types dt ON dt.id = d.document_type_id";
        // A revision counts as sent to the client however it went: a sent e-mail, "counted as sent"
        // on approval with e-mail off, or any of its files flagged sent (portal download included).
        $neverSent = "NOT EXISTS (SELECT 1 FROM email_log el WHERE el.document_id = d.id AND el.status = 'sent')
                      AND NOT EXISTS (SELECT 1 FROM audit_log al WHERE al.entity_type = 'documents' AND al.entity_id = d.id
                                       AND al.action_type = 'DOCUMENT_MARKED_SENT_MAIL_DISABLED')
                      AND NOT EXISTS (SELECT 1 FROM file_store s2 WHERE s2.linked_document_id = d.id AND s2.sent_to_client = 1)";

        if ($settings['draft_days'] > 0) {
            // The draft = the first PDF made for this revision; it is no longer the revision's
            // current copy (approval made the final copy); counted from when the current copy was made.
            $stmt = $pdo->prepare("{$select}
                 JOIN file_store cur ON cur.id = d.pdf_file_id
                WHERE {$guards}
                  AND dt.code <> 'CAFIN'
                  AND d.status IN ('approved', 'sent', 'superseded')
                  AND fs.id <> d.pdf_file_id
                  AND fs.mime_type = 'application/pdf'
                  AND fs.original_filename NOT LIKE '%(SUPERSEDED)%'
                  AND fs.id = (SELECT MIN(f2.id) FROM file_store f2
                                WHERE f2.linked_document_id = d.id AND f2.file_origin = 'GENERATED_AUTO' AND f2.mime_type = 'application/pdf')
                  AND cur.uploaded_at <= DATE_SUB(NOW(), INTERVAL :days DAY)");
            $stmt->execute(['days' => $settings['draft_days']]);
            foreach ($stmt->fetchAll() as $row) {
                $byFile[(int) $row['file_id']] ??= $row + ['rule' => 'draft'];
            }
        }

        if ($settings['superseded_days'] > 0) {
            // Every copy of a never-sent superseded revision except its SUPERSEDED-stamped current copy,
            // counted from when it was superseded (that copy's date).
            $stmt = $pdo->prepare("{$select}
                 JOIN file_store cur ON cur.id = d.pdf_file_id
                WHERE {$guards}
                  AND dt.code <> 'CAFIN'
                  AND d.status = 'superseded'
                  AND fs.id <> d.pdf_file_id
                  AND {$neverSent}
                  AND cur.uploaded_at <= DATE_SUB(NOW(), INTERVAL :days DAY)");
            $stmt->execute(['days' => $settings['superseded_days']]);
            foreach ($stmt->fetchAll() as $row) {
                $byFile[(int) $row['file_id']] ??= $row + ['rule' => 'superseded'];
            }
        }

        if ($settings['cafin_mode'] === 'latest_only') {
            // Every copy of a CAFIN that is not the order's latest, once a newer CAFIN is 30 days old.
            $stmt = $pdo->prepare("{$select}
                WHERE {$guards}
                  AND dt.code = 'CAFIN'
                  AND EXISTS (SELECT 1 FROM documents n
                               WHERE n.order_id = d.order_id AND n.document_type_id = d.document_type_id
                                 AND n.id > d.id AND n.generated_at <= DATE_SUB(NOW(), INTERVAL :days DAY))");
            $stmt->execute(['days' => self::CAFIN_DAYS]);
            foreach ($stmt->fetchAll() as $row) {
                $byFile[(int) $row['file_id']] ??= $row + ['rule' => 'cafin_old'];
            }
        }

        // Never touch a document generated in a financial year the CA has locked.
        $locked = CaFyLockRepository::lockedYears();
        $rows = array_values(array_filter($byFile, static function (array $r) use ($locked): bool {
            return $locked === [] || !in_array(FinancialYear::label((string) $r['generated_at']), $locked, true);
        }));
        usort($rows, static fn (array $a, array $b): int => (int) $a['file_id'] <=> (int) $b['file_id']);
        foreach ($rows as &$r) {
            $r['reason'] = self::REASONS[$r['rule']];
        }
        return $rows;
    }

    /**
     * Totals for the page: per rule, what the current settings would remove, and — for a rule
     * that is off — what it would remove if switched on at its first option.
     *
     * @return array<string, array{files:int,bytes:int,hypothetical:bool}>
     */
    public static function estimate(?array $settings = null): array
    {
        $settings ??= self::settings();
        $probe = [
            'draft'      => ['draft_days' => $settings['draft_days'] ?: self::DRAFT_OPTIONS[1], 'superseded_days' => 0, 'cafin_mode' => 'keep_all'],
            'superseded' => ['draft_days' => 0, 'superseded_days' => $settings['superseded_days'] ?: self::SUPERSEDED_OPTIONS[1], 'cafin_mode' => 'keep_all'],
            'cafin_old'  => ['draft_days' => 0, 'superseded_days' => 0, 'cafin_mode' => 'latest_only'],
        ];
        $isOn = [
            'draft' => $settings['draft_days'] > 0,
            'superseded' => $settings['superseded_days'] > 0,
            'cafin_old' => $settings['cafin_mode'] === 'latest_only',
        ];
        $out = [];
        foreach ($probe as $rule => $s) {
            $rows = self::candidates($s);
            $out[$rule] = [
                'files' => count($rows),
                'bytes' => array_sum(array_map(static fn (array $r): int => (int) $r['file_size_bytes'], $rows)),
                'hypothetical' => !$isOn[$rule],
            ];
        }
        return $out;
    }

    /**
     * Removes every candidate: the file itself, and the row is marked inactive with when and why.
     * One DOCUMENT_COPY_REMOVED audit row per file plus one DOCUMENT_COPY_CLEANUP_RUN summary.
     *
     * @return array{files:int,bytes:int,missing:int,skipped_off:bool}
     */
    public static function run(?int $userId, string $trigger): array
    {
        $settings = self::settings();
        if (!self::anyOn($settings)) {
            return ['files' => 0, 'bytes' => 0, 'missing' => 0, 'skipped_off' => true];
        }
        $pdo = Database::connection();
        $files = 0;
        $bytes = 0;
        $missing = 0;
        foreach (self::candidates($settings) as $row) {
            $path = (string) $row['server_path'];
            if ($path !== '' && is_file($path)) {
                if (!@unlink($path)) {
                    continue; // could not delete the file — leave the row as it is, try again next run
                }
            } else {
                $missing++;
            }
            $pdo->prepare('UPDATE file_store SET is_active = 0, removed_at = NOW(), removed_reason = :reason WHERE id = :id AND is_active = 1')
                ->execute(['reason' => $row['reason'], 'id' => (int) $row['file_id']]);
            $size = (int) $row['file_size_bytes'];
            AuditLogRepository::log(
                $userId,
                'DOCUMENT_COPY_REMOVED',
                'file_store',
                (int) $row['file_id'],
                'file',
                (string) $row['original_filename'],
                null,
                sprintf('%s — %s %s (revision %d), %s', $row['reason'], $row['document_type_code'],
                    $row['document_reference'] ?? '—', (int) $row['revision_number'], self::formatBytes($size))
            );
            $files++;
            $bytes += $size;
        }
        AuditLogRepository::log(
            $userId,
            'DOCUMENT_COPY_CLEANUP_RUN',
            'company_settings',
            null,
            'document_cleanup',
            null,
            sprintf('%d file(s), %s', $files, self::formatBytes($bytes)),
            sprintf('%s run — drafts: %s; superseded never sent: %s; old CAFIN: %s',
                $trigger === 'scheduled' ? 'Daily' : 'Manual',
                $settings['draft_days'] > 0 ? "after {$settings['draft_days']} days" : 'off',
                $settings['superseded_days'] > 0 ? "after {$settings['superseded_days']} days" : 'off',
                $settings['cafin_mode'] === 'latest_only' ? 'latest only' : 'keep all')
        );
        return ['files' => $files, 'bytes' => $bytes, 'missing' => $missing, 'skipped_off' => false];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return number_format($bytes / 1024, 0) . ' KB';
    }
}
