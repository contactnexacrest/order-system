<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use App\Repositories\AssociateEventRepository;
use App\Repositories\AssociateInvitationRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\IncentiveRepository;

/**
 * Owner decision 1 Nov 2026 ("AS all as recommended"): the associate's
 * portal access, the link between a client and the associate who brought it
 * (with its 12-month lock and the buyer's own confirmation), and the notices
 * and e-mails that keep the associate informed.
 */
final class AssociateService
{
    public static function protectionMonths(): int
    {
        // D-245 P3: a buyer registration lasts this long; with no order from the buyer by then it lapses.
        $v = (int) (IncentiveRepository::settings()['associate_protection_months'] ?? 6);
        return $v > 0 ? $v : 6;
    }

    /**
     * QA Round 8 (R8-N01): what the associate is told about where payments
     * go while new bank details are pending or were not accepted — the
     * verified account only when there is one in use today.
     */
    public static function bankPaymentsSentence(int $associateId): string
    {
        $usable = \App\Repositories\AssociateBankRepository::usable($associateId);
        return $usable !== null
            ? 'Payments keep going to your verified account (••••' . $usable['account_last4'] . ').'
            : 'No payment can be made until new bank details are verified.';
    }

    /** A reason typed by staff, without the full stops and spaces at its end (the e-mail adds its own). */
    public static function cleanReason(string $reason): string
    {
        return rtrim(trim($reason), ". \t\n\r\0\x0B");
    }

    public static function bankWaitDays(): int
    {
        $v = IncentiveRepository::settings()['associate_bank_wait_days'] ?? '7';
        return max(0, (int) $v);
    }

    public static function tdsPct(): float
    {
        return (float) (IncentiveRepository::settings()['associate_tds_pct'] ?? 2.0);
    }

    /** A notice in the associate's portal and, where useful, an e-mail. */
    public static function notify(array $associate, string $event, string $message, ?int $clientId = null, ?int $orderId = null, ?int $invitationId = null, ?int $userId = null, bool $email = true): void
    {
        // D-261: amounts (and the commission %) only where the associate's access level shows them.
        if (!empty($associate['id'])) {
            $message = AssociateAccess::maskNotice($associate, $message);
        }
        AssociateEventRepository::add((int) $associate['id'], $event, $message, $clientId, $orderId, $invitationId, $userId, true);
        if ($email && !empty($associate['email'])) {
            EmailService::sendPlainText((string) $associate['email'], 'NexaCrest associate portal — ' . self::subjectFor($event),
                "Hello {$associate['name']},\n\n{$message}\n\nSee the details in your associate portal: " . rtrim((string) Env::get('APP_URL', ''), '/') . "/associate\n\nNexaCrest International Private Limited");
        }
    }

    private static function subjectFor(string $event): string
    {
        return [
            'client_accepted' => 'your client is registered', 'client_not_accepted' => 'a registration was not accepted',
            'direct_contact' => 'your client contacted us', 'order_created' => 'new order from your client',
            'commission_earned' => 'commission earned', 'payout_paid' => 'commission paid', 'payout_held' => 'commission on hold',
            'clawback' => 'commission adjustment', 'bank_changed' => 'your bank details changed', 'bank_rejected' => 'your new bank details were not accepted', 'link_changed' => 'a client link changed',
            'lock_ending' => 'protection ending soon', 'registration_lapsed' => 'a buyer registration lapsed', 'agreement_ending' => 'agreement ending soon', 'portal_invite' => 'your portal login link',
            'document_accepted' => 'your document was accepted', 'document_rejected' => 'please upload a document again', 'code_changed' => 'your personal code changed', 'price_ready' => 'your price is ready',
        ][$event] ?? 'update';
    }

    /** Sends (or resends) the set-password link and switches the portal on; makes the personal code if there is none. */
    public static function invitePortal(int $associateId, int $userId): string
    {
        $a = AssociateRepository::find($associateId);
        if ($a === null || empty($a['email'])) {
            throw new \RuntimeException('Enter the associate\'s e-mail first — it is their portal login.');
        }
        AssociateRepository::setFields($associateId, ['portal_enabled' => 1]);
        if (empty($a['personal_code'])) {
            AssociateCodeService::issue($associateId, (string) $a['name']);
        }
        $raw = AssociatePortalService::issueToken($associateId, 'set_password', 72 * 60);
        $link = rtrim((string) Env::get('APP_URL', ''), '/') . '/associate/set-password/' . $raw;
        EmailService::sendPlainText((string) $a['email'], 'Your NexaCrest associate portal',
            "Hello {$a['name']},\n\nNexaCrest has opened your associate portal. There you can see the clients you introduced, their orders and stages, your commission and payments, and invite new clients.\n\n"
            . "Set your password within 72 hours:\n{$link}\n\nYour login e-mail: {$a['email']}\n\nNexaCrest International Private Limited");
        AuditLogRepository::log($userId, 'ASSOCIATE_PORTAL_INVITED', 'associates', $associateId, null, null, (string) $a['email']);
        AssociateEventRepository::add($associateId, 'portal_invite', 'Portal login link sent to ' . $a['email'] . '.', null, null, null, $userId, false);
        return $link;
    }

    /**
     * Links a client to the associate who brought it: the buyer's own
     * registration (through a link or the personal code) or a staff link.
     * The 12-month lock starts now.
     */
    public static function linkClient(int $clientId, int $associateId, string $source, ?int $invitationId, ?string $registeredOn, ?int $userId): void
    {
        $lockUntil = date('Y-m-d', strtotime('+' . self::protectionMonths() . ' months'));
        Database::connection()->prepare('UPDATE clients SET associate_id = :a, associate_registered_on = :d, associate_locked_until = :l, associate_source = :s, associate_invitation_id = :i WHERE id = :id')
            ->execute(['a' => $associateId, 'd' => $registeredOn ?? date('Y-m-d'), 'l' => $lockUntil, 's' => $source, 'i' => $invitationId, 'id' => $clientId]);
    }

    /**
     * Owner decision 1 Nov 2026 (guarantee 3): only the MD changes or removes
     * a client's associate, with a written reason; the associate is told at once.
     */
    public static function canChangeLink(array $user): bool
    {
        if (SuperAdminService::isEffective((int) $user['id'])) {
            return true;
        }
        foreach (GrantGuard::ownRoleIds($user) as $roleId) {
            if ((\App\Repositories\RoleRepository::find($roleId)['name'] ?? '') === 'Managing Director') {
                return true;
            }
        }
        return false;
    }

    /**
     * Called when an order is created: links the order to the client's associate and
     * tells them. D-245 P3: the commission level shown is provisional — it is fixed
     * on the order when its Proforma Invoice is issued (AssociateCommission::fixLevelAtPi).
     */
    public static function onOrderCreated(int $orderId): void
    {
        $order = Database::connection()->prepare('SELECT o.id, o.client_id, o.order_reference, o.status, o.is_test_data, o.is_sample_data, c.associate_id, c.company_legal_name FROM orders o JOIN clients c ON c.id = o.client_id WHERE o.id = :id');
        $order->execute(['id' => $orderId]);
        $o = $order->fetch();
        if (!$o || empty($o['associate_id'])) {
            return;
        }
        $a = AssociateRepository::find((int) $o['associate_id']);
        if ($a === null) {
            return;
        }
        [$level] = (int) ($a['no_commission'] ?? 0) === 1 ? [null] : AssociateCommission::levelForAssociate($a, $orderId);
        Database::connection()->prepare('UPDATE orders SET associate_id = :a, associate_rate_pct = :r, associate_rate_source = :s WHERE id = :id')
            ->execute(['a' => $a['id'], 'r' => $level !== null && $level['kind'] === 'pct' ? $level['value'] : null,
                's' => $level === null ? 'none' : (($a['commission_mode'] ?? 'ladder') === 'fixed' ? 'fixed' : 'ladder'), 'id' => $orderId]);
        if ((int) $o['is_test_data'] === 1 || (int) $o['is_sample_data'] === 1) {
            return;
        }
        self::notify($a, 'order_created', "New order {$o['order_reference']} from your client {$o['company_legal_name']}. "
            . ($level === null ? 'No commission applies to it.' : 'Your commission level for now: ' . AssociateCommission::levelLabel($level) . ' — fixed on the order when its Proforma Invoice is issued.'), (int) $o['client_id'], $orderId);
    }

    /**
     * After an associate payout is paid: the claw-back lines due for the same
     * associate (up to this month) are settled by deduction, and the associate
     * is told the amount, TDS and bank reference (owner rule D-212).
     */
    public static function onPayoutPaid(int $payoutId, int $userId): void
    {
        $p = IncentiveRepository::findPayout($payoutId);
        if ($p === null || $p['payee_kind'] !== 'associate') {
            return;
        }
        Database::connection()->prepare(
            "UPDATE incentive_payouts SET status = 'paid', paid_on = :d, paid_inr = amount_inr, paid_by = :u, paid_via = :via, paid_reference = :ref
              WHERE associate_id = :a AND payee_kind = 'associate' AND clawback_of_payout_id IS NOT NULL AND status = 'due' AND incentive_month <= :m"
        )->execute(['d' => $p['paid_on'], 'u' => $userId, 'via' => $p['paid_via'], 'ref' => 'Deducted from payout no. ' . $payoutId, 'a' => $p['associate_id'], 'm' => $p['incentive_month']]);
        $a = AssociateRepository::find((int) $p['associate_id']);
        $order = \App\Repositories\OrderRepository::find((int) $p['order_id']);
        if ($a !== null) {
            // Owner decision 2 Nov 2026 (D-235): gross, TDS (section and rate) and net.
            self::notify($a, 'payout_paid', 'Commission paid for order ' . ($order['order_reference'] ?? $p['order_id']) . ': INR ' . number_format((float) $p['paid_inr'], 2)
                . ((float) ($p['tds_inr'] ?? 0) > 0 ? ' (gross INR ' . number_format((float) $p['paid_inr'] + (float) $p['tds_inr'], 2) . ', TDS deducted INR ' . number_format((float) $p['tds_inr'], 2)
                    . (!empty($p['tds_section']) ? ' u/s ' . $p['tds_section'] : '') . (!empty($p['tds_rate']) ? ' at ' . AssociateTds::pct((float) $p['tds_rate']) : '') . ')' : '')
                . ((float) ($p['gst_inr'] ?? 0) > 0 ? ' + GST INR ' . number_format((float) $p['gst_inr'], 2) . ' on your tax invoice' : '')
                . ' on ' . \App\Helpers\Dates::short((string) $p['paid_on']) . (!empty($p['paid_reference']) ? ', bank reference ' . $p['paid_reference'] : '') . '.', null, (int) $p['order_id'], null, $userId);
        }
    }

    /** Daily: protection and agreement reminders (30 and 7 days), expired links. */
    public static function dailyReminders(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        AssociateInvitationRepository::expireOld();
        // D-245 P3: registrations past their period with no order from the buyer lapse (unlinked, associate told).
        $sent = AssociateCommission::lapseRegistrations($today);
        foreach ([30, 7] as $days) {
            $day = date('Y-m-d', strtotime("{$today} +{$days} days"));
            $stmt = Database::connection()->prepare(
                "SELECT c.id, c.company_legal_name, c.associate_id, c.associate_locked_until FROM clients c
                  WHERE c.associate_id IS NOT NULL AND c.associate_locked_until = :d
                    AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.client_id = c.id AND o.status NOT IN ('cancelled', 'lost'))"
            );
            $stmt->execute(['d' => $day]);
            foreach ($stmt->fetchAll() as $c) {
                $a = AssociateRepository::find((int) $c['associate_id']);
                if ($a !== null && !AssociateEventRepository::hasEvent((int) $a['id'], "lock_ending_{$days}", (int) $c['id'], null)) {
                    self::notify($a, 'lock_ending', "Your protection for {$c['company_legal_name']} ends on " . \App\Helpers\Dates::short((string) $c['associate_locked_until']) . " ({$days} days) and the client has not ordered yet. Ask your relationship manager if you need an extension.", (int) $c['id']);
                    AssociateEventRepository::add((int) $a['id'], "lock_ending_{$days}", "Reminder sent ({$days} days).", (int) $c['id'], null, null, null, false);
                    $sent++;
                }
            }
            $stmt = Database::connection()->prepare("SELECT * FROM associates WHERE agreement_ends_on = :d AND status = 'active'");
            $stmt->execute(['d' => $day]);
            foreach ($stmt->fetchAll() as $a) {
                if (!AssociateEventRepository::hasEvent((int) $a['id'], "agreement_ending_{$days}", null, null)) {
                    self::notify($a, 'agreement_ending', "Your associate agreement ends on " . \App\Helpers\Dates::short($day) . " ({$days} days). Your relationship manager will contact you about renewal.");
                    AssociateEventRepository::add((int) $a['id'], "agreement_ending_{$days}", "Reminder sent ({$days} days).", null, null, null, null, false);
                    $sent++;
                }
            }
        }
        return $sent;
    }
}
