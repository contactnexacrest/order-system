<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\ZohoSyncLogRepository;

/**
 * D-257 Zoho (owner 5 Nov 2026: "make sure the Zoho sync is complete"): the
 * money of the new divisions goes to Zoho Books like Export's does — at the
 * scheduled sync and Sync now (CaSyncService::fullSync), or from the record's
 * own page — with the same sync log, retry, API allowance (D-239) and
 * duplicate guards:
 *
 *  - Domestic Trade, and Import & Distribution's local sale: the GST tax
 *    invoice, then each buyer receipt as a customer payment applied to it;
 *  - Import & Distribution: the supplier's bill and the customs duty bill,
 *    each payment to the supplier abroad as a vendor payment on the bill,
 *    the duty payment as a vendor payment to Customs on the duty bill, and
 *    the IGST input-credit journal;
 *  - Merchant Trade: the sale invoice and the supplier's bill, the buy leg
 *    as vendor payments and the sell leg as customer payments.
 *
 * A payment waits until its invoice or bill is in Books. Records dated
 * before the go-live date are not sent (the CA books them by hand).
 */
final class DivisionZohoSync
{
    public const TYPE_LABELS = [
        'domestic_invoice' => 'domestic GST tax invoice',
        'domestic_receipt' => 'domestic buyer receipt',
        'import_bills' => 'import (supplier + customs duty bill)',
        'import_payment' => 'payment to an import supplier',
        'import_duty_payment' => 'customs duty payment',
        'import_igst_journal' => 'import IGST credit journal',
        'merchant_deal' => 'merchant trade invoice + bill',
        'merchant_payment' => 'merchant trade payment',
    ];

    /**
     * What the next sync would send, by type (the same selectors run() pushes
     * from). With $only = [kind, id] limited to one record.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public static function pending(?string $start, ?array $only = null): array
    {
        $pdo = Database::connection();
        $since = static fn (string $col): string => $start !== null ? " AND {$col} >= " . $pdo->quote($start) : '';
        $order = $only !== null && $only[0] === 'order' ? ' AND d.order_id = ' . (int) $only[1] : ($only !== null ? ' AND 1 = 0' : '');
        $import = $only !== null && $only[0] === 'import' ? ' AND s.id = ' . (int) $only[1] : ($only !== null ? ' AND 1 = 0' : '');
        $deal = $only !== null && $only[0] === 'deal' ? ' AND m.id = ' . (int) $only[1] : ($only !== null ? ' AND 1 = 0' : '');
        $q = static fn (string $sql): array => $pdo->query($sql)->fetchAll();
        return [
            'domestic_invoice' => $q('SELECT d.order_id, o.division_id, d.tax_invoice_no FROM domestic_orders d JOIN orders o ON o.id = d.order_id
                WHERE d.tax_invoice_no IS NOT NULL AND d.zoho_invoice_id IS NULL' . $since('d.tax_invoice_date') . $order . ' ORDER BY d.order_id'),
            'domestic_receipt' => $q('SELECT p.*, o.division_id FROM domestic_payments p JOIN domestic_orders d ON d.order_id = p.order_id JOIN orders o ON o.id = p.order_id
                WHERE p.zoho_payment_id IS NULL AND d.zoho_invoice_id IS NOT NULL' . $since('p.received_on') . $order . ' ORDER BY p.id'),
            'import_bills' => $q('SELECT s.id, s.division_id, s.reference FROM import_shipments s
                WHERE s.boe_no IS NOT NULL AND s.bcd_total IS NOT NULL AND (s.zoho_bill_id IS NULL OR s.zoho_duty_bill_id IS NULL
                    OR (s.zoho_clearance_bill_id IS NULL AND s.freight_inr + s.insurance_inr + s.cha_inr + s.port_inr + s.other_inr > 0))' . $since('s.boe_date') . $import . ' ORDER BY s.id'),
            'import_payment' => $q('SELECT p.*, s.division_id, s.reference FROM import_payments p JOIN import_shipments s ON s.id = p.shipment_id
                WHERE p.zoho_payment_id IS NULL AND s.zoho_bill_id IS NOT NULL' . $since('p.paid_on') . $import . ' ORDER BY p.id'),
            'import_duty_payment' => $q('SELECT s.id, s.division_id, s.reference FROM import_shipments s
                WHERE s.duty_paid_on IS NOT NULL AND s.duty_zoho_payment_id IS NULL AND s.zoho_duty_bill_id IS NOT NULL' . $since('s.duty_paid_on') . $import . ' ORDER BY s.id'),
            'import_igst_journal' => $q('SELECT s.id, s.division_id, s.reference FROM import_shipments s
                WHERE s.igst_credit_on IS NOT NULL AND s.zoho_igst_journal_id IS NULL AND s.zoho_duty_bill_id IS NOT NULL' . $since('s.igst_credit_on') . $import . ' ORDER BY s.id'),
            'merchant_deal' => $q('SELECT m.id, m.division_id, m.reference FROM merchant_deals m
                WHERE m.sale_invoice_no IS NOT NULL AND m.invoice_date IS NOT NULL AND (m.zoho_invoice_id IS NULL OR m.zoho_bill_id IS NULL)' . $since('m.invoice_date') . $deal . ' ORDER BY m.id'),
            'merchant_payment' => $q("SELECT p.*, m.division_id, m.reference FROM merchant_payments p JOIN merchant_deals m ON m.id = p.deal_id
                WHERE p.zoho_payment_id IS NULL AND ((p.leg = 'buy' AND m.zoho_bill_id IS NOT NULL) OR (p.leg = 'sell' AND m.zoho_invoice_id IS NOT NULL))" . $since('p.moved_on') . $deal . ' ORDER BY p.id'),
        ];
    }

    /**
     * For the "Waiting for Zoho" badge: counts by type, only for the
     * divisions this request may see.
     *
     * @return array<string,int>
     */
    public static function pendingCounts(?string $start): array
    {
        $out = [];
        foreach (self::pending($start) as $type => $rows) {
            $n = count(array_filter($rows, static fn (array $r): bool => DivisionService::allows((int) $r['division_id'])));
            if ($n > 0) {
                $out[$type] = $n;
            }
        }
        return $out;
    }

    /**
     * Sends what is waiting. Every record is logged on its own; one failing
     * never stops the others (a spent allowance stops the run, D-239).
     *
     * @param array{0:string,1:int}|null $only ['order'|'import'|'deal', id] for one record's "Send to Zoho Books"
     * @return array{sent:int, failed:int, skipped:int}
     */
    public static function run(string $triggeredBy, ?int $userId, ?array $only = null): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (!ZohoBooksService::isEnabled()) {
            if ($only === null) {
                // QA ZOH-008: the same reason as every other row of the run (server switch off / Books not enabled).
                ZohoSyncLogRepository::log('division_sync', null, null, null, 'skipped', null, CaSyncService::offReason() . ' — no Domestic Trade, Import & Distribution or Merchant Trade records sent.', $triggeredBy, $userId);
            }
            $result['skipped'] = 1;
            return $result;
        }
        $start = ZohoBooksService::syncStartDate();
        if ($start === null && ZohoGuard::isProduction()) {
            ZohoSyncLogRepository::log('division_sync', null, null, null, 'error', null, 'No go-live date is set (Settings → Zoho Books → "Sync records dated from"), so nothing was sent.', $triggeredBy, $userId);
            $result['failed'] = 1;
            return $result;
        }
        $pdo = Database::connection();
        $log = static function (string $type, string $entity, int $id, string $status, ?string $ref, string $message) use ($triggeredBy, $userId): void {
            ZohoSyncLogRepository::log($type, $entity, $id, null, $status, $ref, $message, $triggeredBy, $userId);
        };
        $step = static function (string $type, string $entity, int $id, callable $push, callable $message) use (&$result, $log): void {
            try {
                $ref = $push();
                $log($type, $entity, $id, 'success', $ref, $message($ref));
                $result['sent']++;
            } catch (\Throwable $e) {
                ZohoSyncStop::rethrow($e, $result);
                $log($type, $entity, $id, 'error', null, $e->getMessage());
                $result['failed']++;
            }
        };
        $applied = static fn (array $r): string => $r['applied'] > 0 ? ', applied ' . number_format($r['applied'], 2, '.', '') : ', held unapplied (nothing left to pay on it in Books)';

        // The invoices and bills first, so the payments find them in the same run.
        foreach (self::pending($start, $only)['domestic_invoice'] as $r) {
            $step('domestic_invoice', 'order', (int) $r['order_id'], static fn (): string => ZohoBooksService::pushDomesticInvoice((int) $r['order_id']),
                static fn (string $id): string => "Sent GST tax invoice {$r['tax_invoice_no']} as Zoho Books invoice (marked sent).");
        }
        foreach (self::pending($start, $only)['import_bills'] as $r) {
            $step('import_bills', 'import_shipment', (int) $r['id'], static fn (): string => implode(' / ', ZohoBooksService::pushImport((int) $r['id'])),
                static fn (string $ids): string => "Sent import {$r['reference']}: the supplier's bill, the customs duty bill and the clearance charges bill, whichever were not in Books yet ({$ids}).");
        }
        foreach (self::pending($start, $only)['merchant_deal'] as $r) {
            $step('merchant_deal', 'merchant_deal', (int) $r['id'], static fn (): string => implode(' / ', ZohoBooksService::pushMerchantDeal((int) $r['id'])),
                static fn (string $ids): string => "Sent merchant trade deal {$r['reference']}: the sale invoice (marked sent) and the supplier's bill ({$ids}).");
        }

        foreach (self::pending($start, $only)['domestic_receipt'] as $p) {
            $step('domestic_receipt', 'order', (int) $p['order_id'], static function () use ($p, $pdo, $applied, &$note): string {
                $r = ZohoBooksService::pushDomesticReceipt($p);
                $pdo->prepare('UPDATE domestic_payments SET zoho_payment_id = :z WHERE id = :id AND zoho_payment_id IS NULL')->execute(['z' => $r['payment_id'], 'id' => $p['id']]);
                $note = $applied($r);
                return $r['payment_id'];
            }, static function () use ($p, &$note): string {
                return "Sent receipt {$p['bank_reference']} (INR " . number_format((float) $p['amount_inr'], 2, '.', '') . ") as Zoho Books customer payment{$note}. {$p['description']}";
            });
        }
        foreach (self::pending($start, $only)['import_payment'] as $p) {
            $step('import_payment', 'import_shipment', (int) $p['shipment_id'], static function () use ($p, $pdo, $applied, &$note): string {
                $r = ZohoBooksService::pushImportPayment($p);
                $pdo->prepare('UPDATE import_payments SET zoho_payment_id = :z WHERE id = :id AND zoho_payment_id IS NULL')->execute(['z' => $r['payment_id'], 'id' => $p['id']]);
                $note = $applied($r);
                return $r['payment_id'];
            }, static function () use ($p, &$note): string {
                return "Sent payment {$p['bank_reference']} ({$p['amount_fc']} at {$p['fx_rate']}) as Zoho Books vendor payment{$note}. {$p['description']}";
            });
        }
        foreach (self::pending($start, $only)['import_duty_payment'] as $r) {
            $step('import_duty_payment', 'import_shipment', (int) $r['id'], static function () use ($r, $pdo, $applied, &$note): string {
                $x = ZohoBooksService::pushImportDutyPayment((int) $r['id']);
                $pdo->prepare('UPDATE import_shipments SET duty_zoho_payment_id = :z WHERE id = :id AND duty_zoho_payment_id IS NULL')->execute(['z' => $x['payment_id'], 'id' => $r['id']]);
                $note = $applied($x);
                return $x['payment_id'];
            }, static function () use ($r, &$note): string {
                return "Sent the customs duty payment of import {$r['reference']} as Zoho Books vendor payment to Customs{$note}.";
            });
        }
        foreach (self::pending($start, $only)['import_igst_journal'] as $r) {
            $step('import_igst_journal', 'import_shipment', (int) $r['id'], static function () use ($r, $pdo): string {
                $id = ZohoBooksService::pushImportIgstJournal((int) $r['id']);
                $pdo->prepare('UPDATE import_shipments SET zoho_igst_journal_id = :z WHERE id = :id AND zoho_igst_journal_id IS NULL')->execute(['z' => $id, 'id' => $r['id']]);
                return $id;
            }, static fn (): string => "Sent the IGST input-credit journal of import {$r['reference']}.");
        }
        foreach (self::pending($start, $only)['merchant_payment'] as $p) {
            $step('merchant_payment', 'merchant_deal', (int) $p['deal_id'], static function () use ($p, $pdo, $applied, &$note): string {
                $r = ZohoBooksService::pushMerchantPayment($p);
                $pdo->prepare('UPDATE merchant_payments SET zoho_payment_id = :z WHERE id = :id AND zoho_payment_id IS NULL')->execute(['z' => $r['payment_id'], 'id' => $p['id']]);
                $note = $applied($r);
                return $r['payment_id'];
            }, static function () use ($p, &$note): string {
                $what = $p['leg'] === 'buy' ? 'vendor payment (buy leg)' : 'customer payment (sell leg)';
                return "Sent payment {$p['bank_reference']} ({$p['amount_fc']} at {$p['fx_rate']}) as Zoho Books {$what}{$note}. {$p['description']}";
            });
        }
        return $result;
    }

    /**
     * The record page's "Send to Zoho Books": everything of this record that
     * is waiting, now (the same pushes and log as the sync).
     *
     * @return array{0:?string, 1:string} [error, success message]
     */
    public static function sendNow(string $kind, int $id, int $userId): array
    {
        if (!ZohoBooksService::isEnabled()) {
            return ['Zoho Books is not connected (Settings → Zoho Books) — nothing was sent.', ''];
        }
        try {
            // QA batch 4, ZOH-1: never two syncs at once.
            $r = ZohoSyncLock::run(fn (): array => self::run('manual', $userId, [$kind, $id]), static fn (): ?array => null, 'manual', $userId);
            if ($r === null) {
                return ['Zoho Books: ' . ZohoSyncLock::BUSY, ''];
            }
        } catch (ZohoSyncStop $stop) {
            ZohoSyncLogRepository::log('division_sync', null, null, null, 'error', null, $stop->getMessage(), 'manual', $userId);
            return ['Zoho Books: ' . $stop->getMessage(), ''];
        }
        if ($r['failed'] > 0) {
            return ["Zoho Books: {$r['sent']} record(s) sent, {$r['failed']} failed — see the Zoho Sync Log for why.", ''];
        }
        return [null, $r['sent'] > 0 ? "{$r['sent']} record(s) sent to Zoho Books (invoice / bills and their payments)." : 'Nothing of this record was waiting for Zoho Books.'];
    }

    /**
     * The "App vs Zoho" check for one Domestic Trade order, import or
     * merchant trade deal (reads only).
     *
     * @return array{rows: list<array<string,?string>>, counts: array<string,int>}
     */
    public static function check(string $kind, int $id): array
    {
        $pdo = Database::connection();
        $records = [];
        if ($kind === 'order') {
            $dom = \App\Repositories\DomesticOrderRepository::find($id);
            $order = \App\Repositories\OrderRepository::find($id);
            if ($dom === null || $order === null) {
                throw new \RuntimeException("Order #{$id} not found.");
            }
            $contact = $pdo->query('SELECT zoho_contact_id_inr FROM clients WHERE id = ' . (int) $order['client_id'])->fetchColumn() ?: null;
            if (!empty($dom['tax_invoice_no'])) {
                $records[] = ['group' => 'Sales', 'item' => "GST tax invoice {$dom['tax_invoice_no']}", 'kind' => 'invoice', 'number' => (string) $dom['tax_invoice_no'], 'zoho_id' => $dom['zoho_invoice_id'], 'amount' => (float) $dom['invoice_total'], 'unit' => 'INR', 'vendor' => false, 'contact' => $contact];
            }
            foreach (\App\Repositories\DomesticOrderRepository::payments($id) as $p) {
                $records[] = ['group' => 'Receipts', 'item' => "Receipt {$p['bank_reference']} on {$p['received_on']}", 'kind' => 'payment', 'number' => '', 'zoho_id' => $p['zoho_payment_id'] ?? null, 'amount' => (float) $p['amount_inr'], 'unit' => 'INR', 'vendor' => false, 'contact' => $contact];
            }
        } elseif ($kind === 'import') {
            $s = \App\Repositories\ImportRepository::find($id);
            if ($s === null) {
                throw new \RuntimeException("Import #{$id} not found.");
            }
            $supplier = ZohoBooksService::contactIdByName((string) $s['supplier_name'], 'vendor');
            $customs = ZohoBooksService::contactIdByName(ZohoGuard::isProduction() ? 'Indian Customs' : ZohoGuard::TEST_PREFIX . 'Indian Customs', 'vendor');
            $billTotal = 0.0;
            foreach (\App\Repositories\ImportRepository::lines($id) as $l) {
                $billTotal += round((float) $l['quantity'] * (float) $l['unit_price_fc'], 2);
            }
            $records[] = ['group' => 'Purchase', 'item' => "Supplier's bill {$s['reference']}", 'kind' => 'bill', 'number' => (string) $s['reference'], 'zoho_id' => $s['zoho_bill_id'], 'amount' => round($billTotal, 2), 'unit' => (string) $s['currency_code'], 'vendor' => true, 'contact' => $supplier];
            if (!empty($s['boe_no']) && $s['bcd_total'] !== null) {
                $records[] = ['group' => 'Customs', 'item' => "Customs duty bill BE-{$s['boe_no']}", 'kind' => 'bill', 'number' => 'BE-' . $s['boe_no'], 'zoho_id' => $s['zoho_duty_bill_id'], 'amount' => round((float) $s['bcd_total'] + (float) $s['sws_total'] + (float) $s['igst_total'], 2), 'unit' => 'INR', 'vendor' => true, 'contact' => $customs];
            }
            if (ZohoBooksService::importClearanceTotal($s) > 0 && !empty($s['boe_no'])) {
                $cha = ZohoBooksService::contactIdByName(trim((string) $s['cha_name']) !== '' ? (string) $s['cha_name'] : 'Import clearance agent', 'vendor');
                $records[] = ['group' => 'Customs', 'item' => "Clearance charges bill CLR-{$s['boe_no']}", 'kind' => 'bill', 'number' => 'CLR-' . $s['boe_no'], 'zoho_id' => $s['zoho_clearance_bill_id'] ?? null, 'amount' => ZohoBooksService::importClearanceTotal($s), 'unit' => 'INR', 'vendor' => true, 'contact' => $cha];
            }
            foreach (\App\Repositories\ImportRepository::payments($id) as $p) {
                $records[] = ['group' => 'Payments to the supplier', 'item' => ucfirst((string) $p['kind']) . " payment {$p['bank_reference']} on {$p['paid_on']}", 'kind' => 'payment', 'number' => '', 'zoho_id' => $p['zoho_payment_id'] ?? null, 'amount' => (float) $p['amount_fc'], 'unit' => (string) $s['currency_code'], 'vendor' => true, 'contact' => $supplier];
            }
            if (!empty($s['duty_paid_on'])) {
                $records[] = ['group' => 'Customs', 'item' => "Duty payment {$s['duty_bank_reference']} on {$s['duty_paid_on']}", 'kind' => 'payment', 'number' => '', 'zoho_id' => $s['duty_zoho_payment_id'], 'amount' => round((float) $s['bcd_total'] + (float) $s['sws_total'] + (float) $s['igst_total'], 2), 'unit' => 'INR', 'vendor' => true, 'contact' => $customs];
            }
            if (!empty($s['igst_credit_on'])) {
                $records[] = ['group' => 'Customs', 'item' => "IGST input-credit journal on {$s['igst_credit_on']}", 'kind' => 'journal', 'number' => '', 'zoho_id' => $s['zoho_igst_journal_id'], 'amount' => round((float) $s['igst_total'], 2), 'unit' => 'INR', 'vendor' => false, 'contact' => null];
            }
        } else {
            $d = \App\Repositories\MerchantTradeRepository::find($id);
            if ($d === null) {
                throw new \RuntimeException("Deal #{$id} not found.");
            }
            $buyer = ZohoBooksService::contactIdByName((string) $d['buyer_name'], 'customer');
            $supplier = ZohoBooksService::contactIdByName((string) $d['supplier_name'], 'vendor');
            $sell = 0.0;
            $buy = 0.0;
            foreach (\App\Repositories\MerchantTradeRepository::lines($id) as $l) {
                $sell += round((float) $l['quantity'] * (float) $l['sell_price_fc'], 2);
                $buy += round((float) $l['quantity'] * (float) $l['buy_price_fc'], 2);
            }
            if (!empty($d['sale_invoice_no'])) {
                $records[] = ['group' => 'Sale', 'item' => "Sale invoice {$d['sale_invoice_no']}", 'kind' => 'invoice', 'number' => (string) $d['sale_invoice_no'], 'zoho_id' => $d['zoho_invoice_id'], 'amount' => round($sell, 2), 'unit' => (string) $d['sale_currency'], 'vendor' => false, 'contact' => $buyer];
                $records[] = ['group' => 'Purchase', 'item' => "Supplier's bill {$d['po_no']}", 'kind' => 'bill', 'number' => (string) $d['po_no'], 'zoho_id' => $d['zoho_bill_id'], 'amount' => round($buy, 2), 'unit' => (string) $d['purchase_currency'], 'vendor' => true, 'contact' => $supplier];
            }
            foreach (\App\Repositories\MerchantTradeRepository::payments($id) as $p) {
                $isBuy = $p['leg'] === 'buy';
                $records[] = ['group' => $isBuy ? 'Buy leg' : 'Sell leg', 'item' => ($isBuy ? 'Paid ' : 'Received ') . "{$p['bank_reference']} on {$p['moved_on']}", 'kind' => 'payment', 'number' => '', 'zoho_id' => $p['zoho_payment_id'] ?? null, 'amount' => (float) $p['amount_fc'], 'unit' => (string) ($isBuy ? $d['purchase_currency'] : $d['sale_currency']), 'vendor' => $isBuy, 'contact' => $isBuy ? $supplier : $buyer];
            }
        }
        $rows = ZohoBooksService::matchRecords($records);
        $counts = [];
        foreach ($rows as $r) {
            $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        }
        return ['rows' => $rows, 'counts' => $counts];
    }
}
