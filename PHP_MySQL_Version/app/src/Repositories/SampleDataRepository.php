<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Phase E follow-up — Sample Data Playground (see SampleDataService for the
 * load side). This repository owns the ONE hard-delete routine in an
 * otherwise soft-delete-only codebase (see file_store.is_active and every
 * other table's convention). It is deliberately scoped to rows flagged
 * is_sample_data = 1 on clients/orders and everything that hangs off them —
 * it can never touch a real client or order, because it only ever selects
 * by that flag, never by name or id range.
 *
 * Deletion order matters: nothing here has ON DELETE CASCADE (checked —
 * see docs/schema.sql, no FK in the whole schema declares one), and two
 * pairs of tables reference each other in a genuine cycle
 * (documents <-> file_store, via docx_file_id/pdf_file_id and
 * linked_document_id). Those FK columns are nulled out first so both sides
 * can then be deleted in either order. Everything else is deleted
 * strictly children-before-parents, including the tables the "cover all"
 * sample-data expansion actually exercises (order_buyer_po_documents,
 * order_supplier_po_documents keyed off order_supplier_po.id, and — since
 * client portal provisioning is now part of the sample walkthrough —
 * client_logins and client_password_reset_tokens, plus nulling
 * client_intake_submissions.converted_client_id). This mirrors
 * TestModeRepository::clearAllTestData(), which already covered all of
 * these. audit_log is deliberately left alone — entity_id there is NOT a
 * real foreign key (no constraint in the schema), so historical log rows
 * referencing a since-cleared sample client/order remain valid, harmless
 * history rather than orphaned references.
 */
final class SampleDataRepository
{
    public static function isLoaded(): bool
    {
        $stmt = Database::connection()->query('SELECT COUNT(*) AS c FROM clients WHERE is_sample_data = 1');
        return ((int) $stmt->fetch()['c']) > 0;
    }

    /** @return array<int, array<string,mixed>> sample clients with their orders, for the playground screen. */
    public static function summary(): array
    {
        $clients = Database::connection()
            ->query('SELECT * FROM clients WHERE is_sample_data = 1 ORDER BY id')
            ->fetchAll();

        $stmt = Database::connection()->prepare(
            'SELECT o.*, sm.stage_name AS current_stage_name
             FROM orders o
             LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
             WHERE o.client_id = :client_id AND o.is_sample_data = 1
             ORDER BY o.id'
        );

        foreach ($clients as &$client) {
            $stmt->execute(['client_id' => $client['id']]);
            $client['orders'] = $stmt->fetchAll();
        }
        unset($client);

        return $clients;
    }

    /**
     * Hard-deletes every sample-flagged row and its generated files.
     * Returns a small report (counts) for the flash message. Safe to call
     * when nothing is loaded — every step is a no-op on empty id sets.
     */
    public static function clearAll(): array
    {
        $pdo = Database::connection();

        $clientIds = self::intColumn($pdo, 'SELECT id FROM clients WHERE is_sample_data = 1');
        $orderIds  = self::intColumn($pdo, 'SELECT id FROM orders WHERE is_sample_data = 1');

        if (!$clientIds && !$orderIds) {
            return ['clients' => 0, 'orders' => 0, 'files' => 0];
        }

        // Captured BEFORE the transaction deletes these clients — the
        // storage directory is keyed by client_unique_number, which won't
        // be queryable once the row is gone.
        $storageDirs = self::sampleClientStorageDirs($clientIds);

        $documentIds = $orderIds ? self::intColumn($pdo, self::inQuery('SELECT id FROM documents WHERE order_id IN (%s)', $orderIds)) : [];
        $amendmentIds = $orderIds ? self::intColumn($pdo, self::inQuery('SELECT id FROM amendments WHERE order_id IN (%s)', $orderIds)) : [];
        $disputeIds = $orderIds ? self::intColumn($pdo, self::inQuery('SELECT id FROM disputes WHERE order_id IN (%s)', $orderIds)) : [];
        $annexureProductIds = $orderIds ? self::intColumn($pdo, self::inQuery('SELECT id FROM order_annexure_products WHERE order_id IN (%s)', $orderIds)) : [];
        $supplierPoIds = $orderIds ? self::intColumn($pdo, self::inQuery('SELECT id FROM order_supplier_po WHERE order_id IN (%s)', $orderIds)) : [];
        $clientLoginIds = $clientIds ? self::intColumn($pdo, self::inQuery('SELECT id FROM client_logins WHERE client_id IN (%s)', $clientIds)) : [];

        // file_store rows belong to the order and/or the client directly.
        $fileRows = [];
        if ($orderIds || $clientIds) {
            $clauses = [];
            if ($orderIds) { $clauses[] = 'order_id IN (' . implode(',', $orderIds) . ')'; }
            if ($clientIds) { $clauses[] = 'client_id IN (' . implode(',', $clientIds) . ')'; }
            $fileRows = $pdo->query('SELECT id, server_path FROM file_store WHERE ' . implode(' OR ', $clauses))->fetchAll();
        }
        $fileIds = array_map(static fn($r) => (int) $r['id'], $fileRows);

        $pdo->beginTransaction();
        try {
            // --- Leaf/child rows first ---
            if ($annexureProductIds) {
                $pdo->exec(self::inQuery('DELETE FROM order_annexure_images WHERE order_annexure_product_id IN (%s)', $annexureProductIds));
            }
            if ($orderIds) {
                $pdo->exec(self::inQuery('DELETE FROM order_annexure_products WHERE order_id IN (%s)', $orderIds));
                $pdo->exec(self::inQuery('DELETE FROM order_buyer_po_documents WHERE order_id IN (%s)', $orderIds));
            }
            if ($supplierPoIds) {
                $pdo->exec(self::inQuery('DELETE FROM order_supplier_po_documents WHERE order_supplier_po_id IN (%s)', $supplierPoIds));
            }
            if ($disputeIds) {
                $pdo->exec(self::inQuery('DELETE FROM dispute_documents WHERE dispute_id IN (%s)', $disputeIds));
            }
            if ($orderIds) {
                $pdo->exec(self::inQuery('DELETE FROM disputes WHERE order_id IN (%s)', $orderIds));
            }
            if ($documentIds) {
                $pdo->exec(self::inQuery('DELETE FROM document_revisions WHERE document_id IN (%s)', $documentIds));
                $pdo->exec(self::inQuery('DELETE FROM document_reviews WHERE document_id IN (%s)', $documentIds));
                $pdo->exec(self::inQuery('DELETE FROM document_cross_verifications WHERE document_id IN (%s)', $documentIds));
            }
            if ($orderIds) {
                $pdo->exec(self::inQuery('DELETE FROM email_log WHERE order_id IN (%s)', $orderIds));
                $pdo->exec(self::inQuery('DELETE FROM notifications WHERE related_order_id IN (%s)', $orderIds));
            }
            if ($clientIds) {
                $pdo->exec(self::inQuery('DELETE FROM client_password_reset_tokens WHERE client_id IN (%s)', $clientIds));
                $pdo->exec(self::inQuery('UPDATE client_intake_submissions SET converted_client_id = NULL WHERE converted_client_id IN (%s)', $clientIds));
            }
            if ($clientLoginIds) {
                $pdo->exec(self::inQuery('DELETE FROM client_logins WHERE id IN (%s)', $clientLoginIds));
            }

            // --- Break the documents <-> file_store cycle before deleting either ---
            if ($documentIds) {
                $pdo->exec(self::inQuery('UPDATE documents SET docx_file_id = NULL, pdf_file_id = NULL WHERE id IN (%s)', $documentIds));
            }
            if ($fileIds) {
                $pdo->exec(self::inQuery('UPDATE file_store SET linked_document_id = NULL WHERE id IN (%s)', $fileIds));
            }

            // --- Null every other file/document pointer held by rows we're about to delete ---
            if ($amendmentIds) {
                $pdo->exec(self::inQuery('UPDATE amendments SET signed_copy_file_id = NULL, document_id = NULL WHERE id IN (%s)', $amendmentIds));
            }
            if ($orderIds) {
                $pdo->exec(self::inQuery('UPDATE orders SET active_amendment_id = NULL WHERE id IN (%s)', $orderIds));
                $pdo->exec(self::inQuery('UPDATE order_freight SET fdn_document_id = NULL WHERE order_id IN (%s)', $orderIds));
                $pdo->exec(self::inQuery('UPDATE order_packing SET fumigation_cert_file_id = NULL, buyer_approval_file_id = NULL WHERE order_id IN (%s)', $orderIds));
                $pdo->exec(self::inQuery('UPDATE order_shipping SET draft_bl_file_id = NULL WHERE order_id IN (%s)', $orderIds));
            }

            // --- Now safe to delete documents and amendments ---
            if ($documentIds) {
                $pdo->exec(self::inQuery('DELETE FROM documents WHERE id IN (%s)', $documentIds));
            }
            if ($amendmentIds) {
                $pdo->exec(self::inQuery('DELETE FROM amendments WHERE id IN (%s)', $amendmentIds));
            }

            // --- file_store rows themselves (pointers all cleared above) ---
            if ($fileIds) {
                $pdo->exec(self::inQuery('DELETE FROM file_store WHERE id IN (%s)', $fileIds));
            }

            // --- Remaining order-scoped 1:1 / 1:many tables ---
            if ($orderIds) {
                foreach ([
                    'order_stages', 'order_products', 'order_payment_status', 'order_production',
                    'order_supplier_po', 'order_packing', 'order_crates', 'order_freight', 'order_shipping',
                    'pi_intake_submissions',
                ] as $table) {
                    $pdo->exec(self::inQuery("DELETE FROM {$table} WHERE order_id IN (%s)", $orderIds));
                }
            }

            // --- Orders, then clients ---
            if ($orderIds) {
                $pdo->exec(self::inQuery('DELETE FROM orders WHERE id IN (%s)', $orderIds));
            }
            if ($clientIds) {
                $pdo->exec(self::inQuery('DELETE FROM clients WHERE id IN (%s)', $clientIds));
            }

            // --- Sample suppliers (Task #17 — a Stage-5+ sample order needs
            // one; order_supplier_po rows pointing at it are already gone
            // via the order-scoped delete loop above, so this is safe here) ---
            $pdo->exec('DELETE FROM suppliers WHERE is_sample_data = 1');

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Physical files — deleted only after the DB transaction committed,
        // so a failed clear never leaves file_store rows pointing at
        // already-unlinked files.
        $deletedFiles = 0;
        foreach ($fileRows as $row) {
            if ($row['server_path'] && is_file($row['server_path']) && @unlink($row['server_path'])) {
                $deletedFiles++;
            }
        }

        // Best-effort cleanup of the per-client storage directories
        // (storage/clients/{client_unique_number}/...) captured above,
        // before the clients were deleted — never fatal if it can't,
        // since the DB side is already fully committed.
        foreach ($storageDirs as $dir) {
            self::rrmdirIfEmpty($dir);
        }

        return ['clients' => count($clientIds), 'orders' => count($orderIds), 'files' => $deletedFiles];
    }

    /** @param array<int,int> $ids */
    private static function inQuery(string $template, array $ids): string
    {
        return sprintf($template, implode(',', $ids));
    }

    /** @return array<int,int> */
    private static function intColumn(PDO $pdo, string $sql): array
    {
        return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<int,int> $clientIds @return array<int,string> */
    private static function sampleClientStorageDirs(array $clientIds): array
    {
        if (!$clientIds) {
            return [];
        }
        $pdo = Database::connection();
        $numbers = $pdo->query('SELECT client_unique_number FROM clients WHERE id IN (' . implode(',', $clientIds) . ')')
            ->fetchAll(PDO::FETCH_COLUMN);
        $storageBase = rtrim(\App\Config\Env::get('STORAGE_BASE_PATH', ''), '/');
        $dirs = [];
        foreach ($numbers as $number) {
            $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $number);
            $dirs[] = "{$storageBase}/clients/{$safe}";
        }
        return $dirs;
    }

    private static function rrmdirIfEmpty(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        // Remove files/subfolders bottom-up (all sample-generated — safe),
        // then the directory itself.
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
