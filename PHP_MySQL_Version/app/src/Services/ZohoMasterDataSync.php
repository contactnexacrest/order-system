<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\ZohoSyncLogRepository;

/**
 * Owner decision 2 Nov 2026 (D-238, "Zoho clients/products yes"): clients
 * and the products of the Products menu kept in Zoho Books on their own,
 * each behind its own switch (Settings → Zoho Books: zoho_sync_clients,
 * zoho_sync_products — both off by default).
 *
 * One way, app → Books: a record is pushed when it is new or changed in the
 * app (a hash of what was last sent), so an edit made in Books is put back
 * the next time the app's record changes — or for every record at once with
 * "Re-push all" on the Zoho Sync page. A client or product no longer active
 * (or a product deleted) is marked inactive in Books, never deleted.
 * Runs inside the hourly sync and Sync now (not "Sync this order").
 *
 * Clients: only once the client's currency is known (the currency of its
 * latest order) — a Books customer has one currency for good.
 * Products: rate 0 — the catalogue holds sourcing costs only, never a
 * selling price, and no cost is ever sent to Books.
 */
final class ZohoMasterDataSync
{
    public static function clientsOn(): bool
    {
        return (string) CompanySettingsRepository::get('zoho_sync_clients') === '1';
    }

    public static function productsOn(): bool
    {
        return (string) CompanySettingsRepository::get('zoho_sync_products') === '1';
    }

    /** @return array{clients:int, products:int, inactivated:int, failed:int, skipped:int} */
    public static function run(string $triggeredBy, ?int $userId): array
    {
        $result = ['clients' => 0, 'products' => 0, 'inactivated' => 0, 'failed' => 0, 'skipped' => 0];
        if (!self::clientsOn() && !self::productsOn()) {
            return $result;
        }
        if (!ZohoBooksService::isEnabled()) {
            $result['skipped'] = 1;
            return $result;
        }
        if (self::clientsOn()) {
            foreach (self::clientsToPush() as $c) {
                self::pushClient($c, $triggeredBy, $userId, $result);
            }
        }
        if (self::productsOn()) {
            foreach (self::productsToPush() as $p) {
                self::pushProduct($p, $triggeredBy, $userId, $result);
            }
            foreach (self::deletedProducts() as $t) {
                try {
                    ZohoBooksService::markInactive('item', (string) $t['zoho_id']);
                    Database::connection()->prepare('DELETE FROM zoho_pending_inactivations WHERE id = :id')->execute(['id' => $t['id']]);
                    ZohoSyncLogRepository::log('product', 'catalog_product', null, null, 'success', (string) $t['zoho_id'], "Marked Zoho Books item \"{$t['name']}\" inactive: the product was deleted from the Products menu.", $triggeredBy, $userId);
                    $result['inactivated']++;
                } catch (\Throwable $e) {
                    ZohoSyncStop::rethrow($e); // D-239: a spent allowance / rate limit stops the whole run
                    ZohoSyncLogRepository::log('product', 'catalog_product', null, null, 'error', (string) $t['zoho_id'], $e->getMessage(), $triggeredBy, $userId);
                    $result['failed']++;
                }
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $c @param array<string,int> $result */
    private static function pushClient(array $c, string $triggeredBy, ?int $userId, array &$result): void
    {
        $id = (int) $c['id'];
        $zohoId = $c['zoho_contact_id'] !== null && $c['zoho_contact_id'] !== '' ? (string) $c['zoho_contact_id'] : null;
        try {
            if ((int) $c['is_active'] !== 1) {
                ZohoBooksService::markInactive('contact', (string) $zohoId);
                self::markClient($id, $zohoId, $c['_hash']);
                ZohoSyncLogRepository::log('client', 'client', $id, null, 'success', $zohoId, "Marked Zoho Books customer \"{$c['company_legal_name']}\" inactive: the client is no longer active in the app.", $triggeredBy, $userId);
                $result['inactivated']++;
                return;
            }
            $r = ZohoBooksService::pushCustomerContact($c, $zohoId, (string) $c['order_currency']);
            self::markClient($id, $r['contact_id'], $c['_hash']);
            $verb = ['created' => 'Created', 'linked' => 'Linked the existing', 'updated' => 'Updated'][$r['action']];
            ZohoSyncLogRepository::log('client', 'client', $id, null, 'success', $r['contact_id'], "{$verb} Zoho Books customer \"{$c['company_legal_name']}\" from the client record.", $triggeredBy, $userId);
            $result['clients']++;
        } catch (\Throwable $e) {
            ZohoSyncStop::rethrow($e); // D-239: a spent allowance / rate limit stops the whole run
            ZohoSyncLogRepository::log('client', 'client', $id, null, 'error', $zohoId, "Client \"{$c['company_legal_name']}\": " . $e->getMessage(), $triggeredBy, $userId);
            $result['failed']++;
        }
    }

    /** @param array<string,mixed> $p @param array<string,int> $result */
    private static function pushProduct(array $p, string $triggeredBy, ?int $userId, array &$result): void
    {
        $id = (int) $p['id'];
        $zohoId = $p['zoho_item_id'] !== null && $p['zoho_item_id'] !== '' ? (string) $p['zoho_item_id'] : null;
        try {
            if ((int) $p['is_active'] !== 1) {
                ZohoBooksService::markInactive('item', (string) $zohoId);
                self::markProduct($id, $zohoId, $p['_hash']);
                ZohoSyncLogRepository::log('product', 'catalog_product', $id, null, 'success', $zohoId, "Marked Zoho Books item \"{$p['name']}\" inactive: the product is no longer active in the Products menu.", $triggeredBy, $userId);
                $result['inactivated']++;
                return;
            }
            $r = ZohoBooksService::pushItem($p, $zohoId);
            self::markProduct($id, $r['item_id'], $p['_hash']);
            $verb = ['created' => 'Created', 'linked' => 'Linked the existing', 'updated' => 'Updated'][$r['action']];
            ZohoSyncLogRepository::log('product', 'catalog_product', $id, null, 'success', $r['item_id'], "{$verb} Zoho Books item \"{$p['name']}\" from the Products menu.", $triggeredBy, $userId);
            $result['products']++;
        } catch (\Throwable $e) {
            ZohoSyncStop::rethrow($e); // D-239: a spent allowance / rate limit stops the whole run
            ZohoSyncLogRepository::log('product', 'catalog_product', $id, null, 'error', $zohoId, "Product \"{$p['name']}\": " . $e->getMessage(), $triggeredBy, $userId);
            $result['failed']++;
        }
    }

    /**
     * Clients whose Books customer is missing or out of date: active ones with
     * a known currency, and inactive ones already in Books.
     *
     * @return list<array<string,mixed>>
     */
    public static function clientsToPush(): array
    {
        $rows = Database::connection()->query(
            "SELECT c.*, (SELECT o.currency_code FROM orders o WHERE o.client_id = c.id AND o.currency_code IS NOT NULL ORDER BY o.id DESC LIMIT 1) AS order_currency
               FROM clients c
              WHERE COALESCE(c.is_sample_data, 0) = 0 AND c.duplicate_of_client_id IS NULL
              ORDER BY c.id"
        )->fetchAll();
        $out = [];
        foreach ($rows as $c) {
            if (!ZohoGuard::isProduction() && !str_starts_with((string) $c['company_legal_name'], ZohoGuard::TEST_PREFIX)) {
                continue; // test organisation: only TEST- customers
            }
            $active = (int) $c['is_active'] === 1;
            if ($active && ($c['order_currency'] === null || $c['order_currency'] === '')) {
                continue; // waits for its first order to set the currency
            }
            if (!$active && ($c['zoho_contact_id'] ?? null) === null) {
                continue; // never in Books — nothing to switch off
            }
            $c['_hash'] = self::clientHash($c);
            if (($c['zoho_synced_hash'] ?? null) !== $c['_hash'] || ($active && ($c['zoho_contact_id'] ?? null) === null)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function productsToPush(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT * FROM catalog_products ORDER BY id')->fetchAll() as $p) {
            if (!ZohoGuard::isProduction() && !str_starts_with((string) $p['name'], ZohoGuard::TEST_PREFIX)) {
                continue;
            }
            $active = (int) $p['is_active'] === 1;
            if (!$active && ($p['zoho_item_id'] ?? null) === null) {
                continue;
            }
            $p['_hash'] = self::productHash($p);
            if (($p['zoho_synced_hash'] ?? null) !== $p['_hash'] || ($active && ($p['zoho_item_id'] ?? null) === null)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** @return list<array{id:int, zoho_id:string, name:string}> products deleted from the Products menu while in Books */
    public static function deletedProducts(): array
    {
        return Database::connection()->query("SELECT id, zoho_id, name FROM zoho_pending_inactivations WHERE kind = 'item' ORDER BY id")->fetchAll();
    }

    /** Called just before a product is deleted: remember its Books item so the next sync marks it inactive. */
    public static function rememberDeletedProduct(array $product): void
    {
        if (empty($product['zoho_item_id'])) {
            return;
        }
        Database::connection()->prepare("INSERT INTO zoho_pending_inactivations (kind, zoho_id, name) VALUES ('item', :z, :n)")
            ->execute(['z' => (string) $product['zoho_item_id'], 'n' => mb_substr((string) $product['name'], 0, 255)]);
    }

    /** "Re-push all": every client and product is sent again at the next sync (puts back edits made in Books). */
    public static function forgetHashes(): void
    {
        $pdo = Database::connection();
        $pdo->exec('UPDATE clients SET zoho_synced_hash = NULL');
        $pdo->exec('UPDATE catalog_products SET zoho_synced_hash = NULL');
    }

    /** @return array{client:int, product:int} what the next sync would send, per switch that is on */
    public static function pendingCounts(): array
    {
        return [
            'client' => self::clientsOn() ? count(self::clientsToPush()) : 0,
            'product' => self::productsOn() ? count(self::productsToPush()) + count(self::deletedProducts()) : 0,
        ];
    }

    public static function clientHash(array $c): string
    {
        return hash('sha256', (string) json_encode([
            ZohoBooksService::customerPayload($c, false), (int) $c['is_active'],
        ]));
    }

    public static function productHash(array $p): string
    {
        return hash('sha256', (string) json_encode([ZohoBooksService::itemPayload($p, true), (int) $p['is_active']]));
    }

    private static function markClient(int $id, ?string $zohoId, string $hash): void
    {
        Database::connection()->prepare('UPDATE clients SET zoho_contact_id = COALESCE(:z, zoho_contact_id), zoho_synced_hash = :h, zoho_synced_at = NOW() WHERE id = :id')
            ->execute(['z' => $zohoId, 'h' => $hash, 'id' => $id]);
    }

    private static function markProduct(int $id, ?string $zohoId, string $hash): void
    {
        Database::connection()->prepare('UPDATE catalog_products SET zoho_item_id = COALESCE(:z, zoho_item_id), zoho_synced_hash = :h, zoho_synced_at = NOW() WHERE id = :id')
            ->execute(['z' => $zohoId, 'h' => $hash, 'id' => $id]);
    }
}
