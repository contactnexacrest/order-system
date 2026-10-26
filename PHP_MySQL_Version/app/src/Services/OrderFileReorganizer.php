<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use App\Helpers\OrderStorage;

/**
 * Moves an order's existing files into the step-named folders of
 * App\Helpers\OrderStorage (owner request 2026-10-26) and updates
 * file_store.server_path — the one place every order file's location is
 * recorded (documents, revisions, uploads all point at a file_store row by
 * id, never at a path). Run from cron/reorganize_order_files.php: a dry run
 * by default, listing every planned move; --apply performs them.
 *
 * Where a file belongs:
 *  - a generated document (or an earlier revision of one) goes to the folder
 *    of its own document type — fixing files that landed under whatever
 *    stage the order happened to be at when they were generated;
 *  - an uploaded file is placed by the folder it was uploaded to (buyer_po/,
 *    shipping/, ...), mapped to its step folder;
 *  - anything already in the new layout is left alone, and anything not
 *    recognised is reported and left where it is.
 */
final class OrderFileReorganizer
{
    /** Old upload folder (first segment after the order folder) => [area, sub-folder]. */
    private const LEGACY_UPLOAD_FOLDERS = [
        'buyer_po'               => ['buyer_po', 'received'],
        'supplier_po'            => ['supplier_po', 'received'],
        'shipping'               => ['packing_bl', 'received'],
        'packing'                => ['packing_bl', 'received'],
        'lc'                     => ['lc', null],
        'annexure'               => ['annexure', null],
        'additional_documents'   => ['additional_documents', null],
        'client_payment_reports' => ['payments', null],
        'comment_attachments'    => ['order_updates', null],
        'internal-ca'            => ['financials', 'generated'],
        'bl-endorsement'         => ['closure', 'generated'],
    ];

    /**
     * @return array<int, array{file_id:int, from:string, to:?string, note:string}> every order file and what would happen to it
     */
    public static function plan(): array
    {
        $pdo = Database::connection();
        $docTypeByFile = [];
        $sql = 'SELECT f.file_id, dt.code FROM (
                    SELECT pdf_file_id AS file_id, document_type_id FROM documents WHERE pdf_file_id IS NOT NULL
                    UNION ALL SELECT docx_file_id, document_type_id FROM documents WHERE docx_file_id IS NOT NULL
                    UNION ALL SELECT r.pdf_file_id, d.document_type_id FROM document_revisions r JOIN documents d ON d.id = r.document_id WHERE r.pdf_file_id IS NOT NULL
                    UNION ALL SELECT r.docx_file_id, d.document_type_id FROM document_revisions r JOIN documents d ON d.id = r.document_id WHERE r.docx_file_id IS NOT NULL
                ) f JOIN document_types dt ON dt.id = f.document_type_id';
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $docTypeByFile[(int) $row['file_id']] = (string) $row['code'];
        }

        $base = rtrim(Env::storageBasePath(), '/');
        $rows = $pdo->query(
            'SELECT fs.id, fs.server_path, fs.file_origin, fs.original_filename, c.client_unique_number, o.order_reference
               FROM file_store fs
               JOIN orders o ON o.id = fs.order_id
               JOIN clients c ON c.id = o.client_id
              ORDER BY fs.id'
        )->fetchAll();

        $plan = [];
        foreach ($rows as $row) {
            $fileId = (int) $row['id'];
            $from = (string) $row['server_path'];
            $order = ['client_unique_number' => $row['client_unique_number'], 'order_reference' => $row['order_reference']];
            [$folder, $note] = self::targetFolder($from, $order, $docTypeByFile[$fileId] ?? self::typeFromGeneratedFilename($row));
            if ($folder === null) {
                $plan[] = ['file_id' => $fileId, 'from' => $from, 'to' => null, 'note' => $note];
                continue;
            }
            // Stays under the storage root it already lives in.
            $root = preg_match('#^(.*)/clients/#', $from, $rm) ? $rm[1] : $base;
            $to = $root . '/' . $folder . '/' . basename($from);
            $plan[] = ['file_id' => $fileId, 'from' => $from, 'to' => $to === $from ? null : $to, 'note' => $to === $from ? 'already in place' : $note];
        }
        return $plan;
    }

    /**
     * An older render no longer referenced by its document (replaced by a
     * later regeneration) still says what it is: generated files are named
     * "<TYPE CODE> <reference> Rev.N.pdf".
     *
     * @param array<string,mixed> $row
     */
    private static function typeFromGeneratedFilename(array $row): ?string
    {
        if (!str_starts_with((string) ($row['file_origin'] ?? ''), 'GENERATED')) {
            return null;
        }
        $code = strtok((string) $row['original_filename'], ' ');
        return $code !== false && isset(OrderStorage::DOCUMENT_TYPE_AREAS[$code]) ? $code : null;
    }

    /**
     * @param array<string,string> $order
     * @return array{0: ?string, 1: string} [target folder relative to storage, reason] — folder null = leave it
     */
    public static function targetFolder(string $path, array $order, ?string $documentTypeCode): array
    {
        if ($documentTypeCode !== null && $documentTypeCode !== 'AMD') {
            return [OrderStorage::forGeneratedDocument($order, $documentTypeCode), "generated {$documentTypeCode}"];
        }
        if (!preg_match('#/clients/[^/]+/[^/]+/(.+)/[^/]+$#', $path, $m)) {
            return [null, 'not under an order folder — left in place'];
        }
        $relative = $m[1];
        $parts = explode('/', $relative);
        $first = $parts[0];

        if ($first === 'amendments' && count($parts) >= 3) {
            $sub = $parts[2] === 'signed' ? 'received' : $parts[2];
            return [OrderStorage::folder($order, 'amendments', $parts[1] . '/' . $sub), 'amendment file'];
        }
        if ($first === 'disputes' && count($parts) >= 2) {
            return [OrderStorage::folder($order, 'disputes', $parts[1]), 'dispute file'];
        }
        if (isset(self::LEGACY_UPLOAD_FOLDERS[$first])) {
            [$area, $sub] = self::LEGACY_UPLOAD_FOLDERS[$first];
            return [OrderStorage::folder($order, $area, $sub), "{$first}/ upload"];
        }
        if (in_array(implode('/', array_slice($parts, 0, 1)), array_map(static fn (string $f): string => explode('/', $f)[0], OrderStorage::AREAS), true)) {
            // Already in the new layout (possibly under an old client/order
            // folder name) — keep its place within the order.
            return [OrderStorage::orderRoot($order) . '/' . $relative, 'new layout'];
        }
        return [null, "unrecognised folder '{$relative}' — left in place"];
    }

    /**
     * Performs the planned moves. A file missing from disk is reported and
     * its path left unchanged; every successful move updates its row at once,
     * so an interrupted run can simply be run again.
     *
     * @param array<int, array{file_id:int, from:string, to:?string, note:string}> $plan
     * @return array{moved:int, missing:int, failed:int, errors:string[]}
     */
    public static function apply(array $plan): array
    {
        $result = ['moved' => 0, 'missing' => 0, 'failed' => 0, 'errors' => []];
        $update = Database::connection()->prepare('UPDATE file_store SET server_path = :path WHERE id = :id');
        foreach ($plan as $item) {
            if ($item['to'] === null) {
                continue;
            }
            if (!is_file($item['from'])) {
                $result['missing']++;
                $result['errors'][] = "file #{$item['file_id']} missing on disk: {$item['from']}";
                continue;
            }
            $dir = dirname($item['to']);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $result['failed']++;
                $result['errors'][] = "file #{$item['file_id']}: cannot create {$dir}";
                continue;
            }
            if (!rename($item['from'], $item['to'])) {
                $result['failed']++;
                $result['errors'][] = "file #{$item['file_id']}: cannot move to {$item['to']}";
                continue;
            }
            $update->execute(['path' => $item['to'], 'id' => $item['file_id']]);
            $result['moved']++;
            self::removeEmptyParents(dirname($item['from']));
        }
        return $result;
    }

    /** Tidies away the old folders a move left empty, stopping at the order folder. */
    private static function removeEmptyParents(string $dir): void
    {
        while (preg_match('#/clients/[^/]+/[^/]+/.+#', $dir) && is_dir($dir) && count((array) scandir($dir)) === 2) {
            if (!@rmdir($dir)) {
                return;
            }
            $dir = dirname($dir);
        }
    }
}
