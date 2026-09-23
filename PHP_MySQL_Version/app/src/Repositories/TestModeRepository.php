<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Config\Env;
use PDO;

/**
 * Test Mode — a global, system-wide switch distinct from the Sample Data
 * Playground (SampleDataRepository). See docs/schema.sql Section V for the
 * full rationale. This repository owns the settings row, the "any test
 * data present" check that gates the Disable/Delete buttons, and the one
 * hard-delete routine — modeled directly on SampleDataRepository::clearAll()
 * (same FK-safe ordering, same cycle-breaking technique for
 * documents <-> file_store), extended to cover the extra order/client-
 * linked tables that didn't exist yet when that routine was written
 * (order_buyer_po_documents, order_supplier_po_documents, client_logins,
 * client_password_reset_tokens, client_intake_submissions).
 */
final class TestModeRepository
{
    public static function getSettings(): ?array
    {
        $row = Database::connection()->query('SELECT * FROM test_mode_settings WHERE id = 1')->fetch();
        return $row ?: null;
    }

    public static function setEnabled(bool $enabled, ?int $userId): void
    {
        if ($enabled) {
            Database::connection()->prepare(
                'UPDATE test_mode_settings
                 SET is_enabled = 1, enabled_at = CURRENT_TIMESTAMP, enabled_by = :user_id, disabled_at = NULL
                 WHERE id = 1'
            )->execute(['user_id' => $userId]);
        } else {
            Database::connection()->exec(
                'UPDATE test_mode_settings SET is_enabled = 0, disabled_at = CURRENT_TIMESTAMP WHERE id = 1'
            );
        }
    }

    public static function setTestEmail(string $email): void
    {
        Database::connection()->prepare('UPDATE test_mode_settings SET test_email = :email WHERE id = 1')
            ->execute(['email' => $email]);
    }

    /** @return array{clients:int, orders:int, suppliers:int} */
    public static function testDataCounts(): array
    {
        $pdo = Database::connection();
        return [
            'clients' => (int) $pdo->query('SELECT COUNT(*) AS c FROM clients WHERE is_test_data = 1')->fetch()['c'],
            'orders' => (int) $pdo->query('SELECT COUNT(*) AS c FROM orders WHERE is_test_data = 1')->fetch()['c'],
            'suppliers' => (int) $pdo->query('SELECT COUNT(*) AS c FROM suppliers WHERE is_test_data = 1')->fetch()['c'],
        ];
    }

    public static function hasTestData(): bool
    {
        $counts = self::testDataCounts();
        return $counts['clients'] > 0 || $counts['orders'] > 0 || $counts['suppliers'] > 0;
    }

    /**
     * Hard-deletes every test-flagged row and its generated files. Returns
     * a small report (counts) for the flash message. Safe to call when
     * nothing is loaded — every step is a no-op on empty id sets. See
     * SampleDataRepository::clearAll()'s docblock for why deletion order
     * matters (no ON DELETE CASCADE anywhere in this schema, and a genuine
     * documents <-> file_store reference cycle).
     */
    public static function clearAllTestData(): array
    {
        $pdo = Database::connection();

        $clientIds = self::intColumn($pdo, 'SELECT id FROM clients WHERE is_test_data = 1');
        $orderIds  = self::intColumn($pdo, 'SELECT id FROM orders WHERE is_test_data = 1');

        if (!$clientIds && !$orderIds) {
            $pdo->exec('DELETE FROM suppliers WHERE is_test_data = 1');
            return ['clients' => 0, 'orders' => 0, 'files' => 0];
        }

        // Captured BEFORE the transaction deletes these clients — the
        // storage directory is keyed by client_unique_number, unqueryable
        // once gone.
        $storageDirs = self::testClientStorageDirs($clientIds);

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

            // --- Test suppliers (order_supplier_po rows pointing at them
            // are already gone via the order-scoped delete loop above) ---
            $pdo->exec('DELETE FROM suppliers WHERE is_test_data = 1');

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
        // before the clients were deleted — never fatal if it can't, since
        // the DB side is already fully committed.
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
    private static function testClientStorageDirs(array $clientIds): array
    {
        if (!$clientIds) {
            return [];
        }
        $pdo = Database::connection();
        $numbers = $pdo->query('SELECT client_unique_number FROM clients WHERE id IN (' . implode(',', $clientIds) . ')')
            ->fetchAll(PDO::FETCH_COLUMN);
        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
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

    /** Used by the audit-log writer to skip test-data actions (docs/schema.sql Section V). */
    public static function isTestEntity(?string $entityType, ?int $entityId): bool
    {
        if (!$entityId) {
            return false;
        }
        $pdo = Database::connection();
        switch ($entityType) {
            case 'clients':
                return self::boolRow($pdo, 'SELECT is_test_data AS v FROM clients WHERE id = :id', ['id' => $entityId]);
            case 'orders':
                return self::boolRow($pdo, 'SELECT is_test_data AS v FROM orders WHERE id = :id', ['id' => $entityId]);
            case 'documents':
                return self::boolRow(
                    $pdo,
                    'SELECT o.is_test_data AS v FROM documents d JOIN orders o ON o.id = d.order_id WHERE d.id = :id',
                    ['id' => $entityId]
                );
            case 'amendments':
                return self::boolRow(
                    $pdo,
                    'SELECT o.is_test_data AS v FROM amendments a JOIN orders o ON o.id = a.order_id WHERE a.id = :id',
                    ['id' => $entityId]
                );
            case 'disputes':
                return self::boolRow(
                    $pdo,
                    'SELECT o.is_test_data AS v FROM disputes di JOIN orders o ON o.id = di.order_id WHERE di.id = :id',
                    ['id' => $entityId]
                );
            case 'email_log':
                return self::boolRow(
                    $pdo,
                    'SELECT o.is_test_data AS v FROM email_log e JOIN orders o ON o.id = e.order_id WHERE e.id = :id',
                    ['id' => $entityId]
                );
            default:
                return false;
        }
    }

    private static function boolRow(PDO $pdo, string $sql, array $params): bool
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row && (int) $row['v'] === 1;
    }
}
