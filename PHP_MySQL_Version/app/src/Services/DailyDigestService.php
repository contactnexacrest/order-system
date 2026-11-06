<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\DepartmentRepository;
use App\Repositories\DepartmentTaskRepository;
use App\Repositories\DocumentReviewRepository;

/**
 * Owner decision 1 Nov 2026 (C-7b, O-7): one e-mail per person per day
 * listing what waits on them — the department tasks they own or that sit
 * unowned with their department (overdue and escalated first), and the
 * documents waiting for their review. Review requests still arrive at once
 * as before (C-7c); the digest only repeats them. Nobody with nothing
 * waiting gets an e-mail. Run once a day: cron/daily_digest.php.
 */
final class DailyDigestService
{
    /** @return list<array{kind:string, text:string}> */
    public static function itemsFor(int $userId): array
    {
        $departmentIds = array_map(static fn (array $m): int => (int) $m['department_id'], DepartmentRepository::activeMembershipsForUser($userId));
        $tasks = array_values(array_filter(DepartmentTaskRepository::open(), static fn (array $t): bool =>
            (int) ($t['owner_id'] ?? 0) === $userId
            || ($t['owner_id'] === null && in_array((int) ($t['department_id'] ?? 0), $departmentIds, true))));
        usort($tasks, static fn (array $a, array $b): int => [(int) $b['escalated'], (int) $b['overdue'], (string) $a['due_on']] <=> [(int) $a['escalated'], (int) $a['overdue'], (string) $b['due_on']]);
        $items = [];
        foreach ($tasks as $t) {
            $state = (int) $t['escalated'] === 1 ? 'ESCALATED' : ((int) $t['overdue'] === 1 ? 'OVERDUE' : 'due');
            $items[] = ['kind' => 'task', 'text' => "{$t['order_reference']} — {$t['title']} ({$state} " . date('j M Y', strtotime((string) $t['due_on'])) . ')'
                . ($t['owner_id'] === null ? ' — no owner yet' : '')];
        }
        foreach (DocumentReviewRepository::pendingForReviewer($userId) as $r) {
            $items[] = ['kind' => 'review', 'text' => "{$r['document_reference']} ({$r['document_type_name']}) — waiting for your review"];
        }
        // D-260: a missing, failed or too-old backup goes to whoever manages company settings (MD / Admin).
        $roleId = Database::connection()->prepare('SELECT role_id FROM users WHERE id = :id');
        $roleId->execute(['id' => $userId]);
        $role = $roleId->fetchColumn();
        if (\App\Services\PermissionService::can($userId, $role !== false && $role !== null ? (int) $role : null, 'manage_company_settings')) {
            foreach (\App\Services\BackupStatusService::problems() as $problem) {
                $items[] = ['kind' => 'backup', 'text' => $problem];
            }
        }
        return $items;
    }

    /** "NexaCrest — your day: 3 tasks, 1 review" and the body. @return array{subject:string, body:string} */
    public static function compose(string $name, array $items, string $date): array
    {
        $tasks = array_values(array_filter($items, static fn (array $i): bool => $i['kind'] === 'task'));
        $reviews = array_values(array_filter($items, static fn (array $i): bool => $i['kind'] === 'review'));
        $count = static fn (int $n, string $one): string => $n . ' ' . $one . ($n === 1 ? '' : 's');
        $backups = array_values(array_filter($items, static fn (array $i): bool => $i['kind'] === 'backup'));
        $subject = 'NexaCrest — your day ' . date('j M Y', strtotime($date)) . ': ' . $count(count($tasks), 'task') . ', ' . $count(count($reviews), 'review')
            . ($backups !== [] ? ' — BACKUP WARNING' : '');
        $body = "Good morning {$name},\n\nThis is what waits on you today.\n";
        if ($tasks !== []) {
            $body .= "\nTasks (My tasks):\n" . implode("\n", array_map(static fn (array $i): string => '- ' . $i['text'], $tasks)) . "\n";
        }
        if ($reviews !== []) {
            $body .= "\nDocuments to review (My Reviews):\n" . implode("\n", array_map(static fn (array $i): string => '- ' . $i['text'], $reviews)) . "\n";
        }
        if ($backups !== []) {
            $body .= "\nBackups need attention (Admin › Backups):\n" . implode("\n", array_map(static fn (array $i): string => '- ' . $i['text'], $backups)) . "\n";
        }
        $body .= "\nOpen the app to act on them. You get this e-mail once a day, only when something waits on you.\n";
        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * QA Round 8, R8-N02: the line the cron job prints — plain about mail
     * being off, and about digests that could not be sent.
     * @param array{sent:int, skipped:int, failed:int, mail_off:bool} $result
     */
    public static function summary(array $result): string
    {
        if ($result['mail_off']) {
            return 'Daily digest: e-mail sending is off — nothing sent.';
        }
        return "Daily digest: {$result['sent']} sent, {$result['skipped']} with nothing waiting or already sent"
            . ($result['failed'] > 0 ? ", {$result['failed']} not sent (will be retried on the next run)" : '') . '.';
    }

    /** @var (callable(string,string,string):bool)|null tests only: replaces the real mail sender */
    private static $sender = null;

    /** Tests only: send through $sender instead of MailSenderService (null restores it). */
    public static function setSender(?callable $sender): void
    {
        self::$sender = $sender;
    }

    /**
     * Sends today's digest to every active staff member with something
     * waiting, once per day. QA Round 8, R8-N02: only a digest that really
     * left counts as sent and uses up the day — with e-mail sending off
     * nothing is claimed, and a failed send is released so the next run
     * tries again.
     * @return array{sent:int, skipped:int, failed:int, mail_off:bool}
     */
    public static function run(?string $date = null): array
    {
        $date ??= date('Y-m-d');
        if (self::$sender === null && \App\Repositories\CompanySettingsRepository::get('mail_sending_enabled') === '0') {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'mail_off' => true];
        }
        $pdo = Database::connection();
        $users = $pdo->query("SELECT id, name, email FROM users WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY id")->fetchAll();
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($users as $u) {
            $items = self::itemsFor((int) $u['id']);
            if ($items === []) {
                $skipped++;
                continue;
            }
            // Once a day per person, even if the job runs twice.
            $claim = $pdo->prepare('INSERT IGNORE INTO daily_digest_log (user_id, digest_date, items) VALUES (:u, :d, :n)');
            $claim->execute(['u' => (int) $u['id'], 'd' => $date, 'n' => count($items)]);
            if ($claim->rowCount() !== 1) {
                $skipped++;
                continue;
            }
            $mail = self::compose((string) $u['name'], $items, $date);
            $ok = self::$sender !== null
                ? (bool) (self::$sender)((string) $u['email'], $mail['subject'], $mail['body'])
                : MailSenderService::send((string) $u['email'], $mail['subject'], $mail['body']);
            if ($ok) {
                $sent++;
            } else {
                $pdo->prepare('DELETE FROM daily_digest_log WHERE user_id = :u AND digest_date = :d')->execute(['u' => (int) $u['id'], 'd' => $date]);
                $failed++;
            }
        }
        return ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed, 'mail_off' => false];
    }
}
