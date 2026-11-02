<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;

/**
 * Owner decision 2 Nov 2026 (D-233): when a Zoho Books sync run — scheduled,
 * Sync now, or "Sync this order" — has failures, Finance hears about it once
 * for that run: one e-mail to every active holder of ca_module_manage with an
 * e-mail address (only while business e-mail is on) and an in-app
 * notification to each of them either way. The list carries record type,
 * order and Zoho's error message — never amounts. Node twin:
 * zohoSyncFailureNotifier.js.
 */
final class ZohoSyncFailureNotifier
{
    /** @var (callable(string,string,string):bool)|null test hook, replaces MailSenderService::send */
    public static $sender = null;

    /** The last sync-log id before a run starts; failures after it belong to the run. */
    public static function mark(): int
    {
        return (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) FROM zoho_sync_log')->fetchColumn();
    }

    /** @return array{failures:int, notified:int, emailed:int, mail_off:bool} */
    public static function afterRun(int $sinceLogId, string $triggeredBy, ?int $orderId = null): array
    {
        $out = ['failures' => 0, 'notified' => 0, 'emailed' => 0, 'mail_off' => false];
        $stmt = Database::connection()->prepare(
            "SELECT l.id, l.sync_type, l.entity_type, l.entity_id, l.leg, l.message, o.buyer_inquiry_ref AS order_ref
               FROM zoho_sync_log l
               LEFT JOIN orders o ON o.id = l.entity_id AND l.entity_type IN ('order', 'order_payment_status')
              WHERE l.id > :since AND l.status = 'error'
              ORDER BY l.id"
        );
        $stmt->execute(['since' => $sinceLogId]);
        $rows = $stmt->fetchAll();
        $out['failures'] = count($rows);
        if ($rows === []) {
            return $out;
        }
        $how = ['scheduled' => 'the scheduled (hourly) sync', 'manual' => $orderId !== null ? '"Sync this order to Zoho now"' : 'Sync now'][$triggeredBy] ?? $triggeredBy . ' sync';
        $subject = 'Zoho Books sync: ' . count($rows) . ' record' . (count($rows) === 1 ? '' : 's') . ' could not be sent';
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '- ' . self::label($r) . ': ' . trim((string) $r['message']);
        }
        $body = "Zoho Books sync failures\n\n"
            . 'Run: ' . $how . ' at ' . date('d M Y H:i') . ($orderId !== null ? ' (one order only)' : '') . ".\n"
            . count($rows) . " record(s) could not be sent to Zoho Books. They stay waiting and the next sync tries them again.\n\n"
            . implode("\n", $lines)
            . "\n\nOpen Zoho Sync in the app to see the Sync Log and retry (Sync now).\n";
        $notice = $subject . ' — see Zoho Sync (Sync Log).';

        $recipients = PermissionRepository::usersWithPermission('ca_module_manage');
        $mailOn = CompanySettingsRepository::get('mail_sending_enabled') !== '0';
        $out['mail_off'] = !$mailOn;
        $emails = [];
        if ($recipients !== []) {
            $ids = implode(',', array_map('intval', array_unique($recipients)));
            $emails = Database::connection()->query("SELECT id, email FROM users WHERE is_active = 1 AND id IN ({$ids})")->fetchAll();
        }
        foreach ($emails as $u) {
            NotificationRepository::createInAppOnly((int) $u['id'], 'zoho_sync_failed', $orderId, $notice);
            $out['notified']++;
            if ($mailOn && trim((string) $u['email']) !== '') {
                $ok = self::$sender !== null
                    ? (bool) (self::$sender)((string) $u['email'], $subject, $body)
                    : MailSenderService::send((string) $u['email'], $subject, $body);
                $out['emailed'] += $ok ? 1 : 0;
            }
        }
        return $out;
    }

    private static function label(array $r): string
    {
        $type = ucfirst(str_replace('_', ' ', (string) $r['sync_type']));
        if (!empty($r['order_ref'])) {
            return $type . ' — order ' . $r['order_ref'] . (!empty($r['leg']) ? ' (' . $r['leg'] . ')' : '');
        }
        if (!empty($r['entity_type']) && !empty($r['entity_id'])) {
            return $type . ' — ' . str_replace('_', ' ', (string) $r['entity_type']) . ' #' . $r['entity_id'];
        }
        return $type;
    }
}
