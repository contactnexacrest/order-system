<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\Dates;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderAssigneeRepository;
use App\Repositories\OrderStageRepository;

/**
 * Owner decision D-394 (20 Nov 2026): quick-quote prospects.
 *
 * A buyer asks for a quotation without filling in the Public Quotation Form.
 * Staff add the client as a prospect ("Quick quote" on + Add client: only the
 * company name, e-mail and country of destination are required) and quote.
 * The prospect expires on its own date (Settings › Quick-quote prospects,
 * default 60 days, separate from the Quotation's printed validity):
 *  - N days before (default 7) the order's owner gets ONE reminder
 *    (notification, My tasks, e-mail);
 *  - on the date, when "Automatic closing" is On, the daily job moves each of
 *    its still-active orders to "Did not go ahead" (lost, through the same
 *    OrderLostService as Mark Lost — never an order Mark Lost would refuse,
 *    never one past the quotation stage) and deactivates the client.
 * Nothing is ever deleted. "Keep for N more days" moves the date; reactivating
 * gives a fresh date; filling in every normally required detail ends the
 * prospect (it then never expires on its own). A Quotation request whose
 * e-mail matches a prospect can update that prospect instead of creating a
 * second client. Node twin: services/prospectService.js.
 */
final class ProspectService
{
    public const LOST_CATEGORY = 'quotation_expired';
    public const NOTIFICATION_TYPE = 'prospect_expiry_reminder';
    public const DEFAULT_EXPIRY_DAYS = 60;
    public const DEFAULT_REMINDER_DAYS = 7;

    /** The normally required details (D-346) — all filled = no longer a prospect. */
    public const REQUIRED_DETAILS = ['company_legal_name', 'billing_address_line1', 'billing_city', 'billing_country'];

    public const REQUIRED_MESSAGE = 'For a prospect, Company Legal Name, Email and Country of Destination are required.';

    public static function expiryDays(): int
    {
        $v = (int) (CompanySettingsRepository::get('prospect_expiry_days') ?? self::DEFAULT_EXPIRY_DAYS);
        return $v >= 7 && $v <= 365 ? $v : self::DEFAULT_EXPIRY_DAYS;
    }

    public static function reminderDays(): int
    {
        $v = (int) (CompanySettingsRepository::get('prospect_reminder_days') ?? self::DEFAULT_REMINDER_DAYS);
        return $v >= 1 && $v <= 30 ? $v : self::DEFAULT_REMINDER_DAYS;
    }

    public static function autoClose(): bool
    {
        return (CompanySettingsRepository::get('prospect_auto_close') ?? '1') === '1';
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    /** Today + the setting's days ('YYYY-MM-DD'). */
    public static function freshExpiry(): string
    {
        return date('Y-m-d', strtotime(self::today() . ' +' . self::expiryDays() . ' days'));
    }

    /** Days from today to the date (negative once past). */
    public static function daysLeft(string $date): int
    {
        return (int) round((strtotime(substr($date, 0, 10)) - strtotime(self::today())) / 86400);
    }

    /** True when the date is within the reminder days (shown in red). */
    public static function isUrgent(?string $date): bool
    {
        return $date !== null && $date !== '' && self::daysLeft($date) <= self::reminderDays();
    }

    /** The day the owner is reminded: the expiry date less the reminder days. */
    public static function reminderDate(string $date): string
    {
        return date('Y-m-d', strtotime(substr($date, 0, 10) . ' -' . self::reminderDays() . ' days'));
    }

    /** "in 60 days" / "today" / "tomorrow" / "3 days ago". */
    public static function relative(string $date): string
    {
        $d = self::daysLeft($date);
        return match (true) {
            $d === 0 => 'today',
            $d === 1 => 'tomorrow',
            $d > 1 => "in {$d} days",
            $d === -1 => '1 day ago',
            default => (-$d) . ' days ago',
        };
    }

    /** Clients list: "Quote expires 19 Jan 2027 (in 60 days)". */
    public static function clientExpiryLine(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        return 'Quote expires ' . Dates::short($date) . ' (' . self::relative($date) . ')';
    }

    /** Order card: "No reply yet · expires 19 Jan 2027", "… · expires in 5 days" when urgent. */
    public static function orderExpiryLine(?string $date): string
    {
        if ($date === null || $date === '') {
            return 'No reply yet';
        }
        $d = self::daysLeft($date);
        if ($d < 0) {
            return 'No reply yet · expired ' . Dates::short($date);
        }
        if (self::isUrgent($date)) {
            return 'No reply yet · expires ' . ($d === 0 ? 'today' : ($d === 1 ? 'tomorrow' : "in {$d} days"));
        }
        return 'No reply yet · expires ' . Dates::short($date);
    }

    /**
     * True when every normally required detail is really filled in. A field that
     * reads only "To be confirmed" (any capitals, a trailing full stop allowed) is
     * the placeholder staff typed before D-394 and counts as still empty (owner,
     * 20 Nov 2026, option A), so saving such a prospect keeps it a prospect.
     *
     * @param array<string,mixed> $client
     */
    public static function hasRequiredDetails(array $client): bool
    {
        foreach (self::REQUIRED_DETAILS as $field) {
            if (self::isPlaceholder((string) ($client[$field] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    /** Empty, or only the words "To be confirmed" (any capitals, optional full stop). */
    public static function isPlaceholder(string $value): bool
    {
        $v = strtolower(trim($value));
        return $v === '' || preg_match('/^to\s+be\s+confirmed\.?$/', $v) === 1;
    }

    /** The prospect form's own server check (company name, a valid e-mail, country of destination); null = fine. */
    public static function createError(array $post): ?string
    {
        foreach (['company_legal_name', 'email', 'country_of_destination'] as $field) {
            if (trim((string) ($post[$field] ?? '')) === '') {
                return self::REQUIRED_MESSAGE;
            }
        }
        return null;
    }

    /** True when the form asked for a prospect (the Quick quote box). */
    public static function requested(array $post): bool
    {
        return !empty($post['is_prospect']);
    }

    /**
     * The billing address of a prospect whose address is not complete: the parts
     * there are, or "To Be Confirmed" when there is no Line 1 — the same words
     * staff typed by hand before (documents unchanged).
     */
    public static function partialBillingAddress(array $post): string
    {
        $part = static fn (string $k): string => trim((string) ($post[$k] ?? ''));
        if ($part('billing_address_line1') === '') {
            return 'To Be Confirmed';
        }
        return \App\Helpers\ClientAddress::compose($part('billing_address_line1'), $part('billing_address_line2'), $part('billing_city'), $part('billing_postcode'), $part('billing_country'));
    }

    public static function markNew(int $clientId, int $userId): string
    {
        $expires = self::freshExpiry();
        Database::connection()->prepare(
            'UPDATE clients SET is_prospect = 1, prospect_expires_on = :d, prospect_reminded_on = NULL, prospect_expired_on = NULL, prospect_expired_reason = NULL WHERE id = :id'
        )->execute(['d' => $expires, 'id' => $clientId]);
        AuditLogRepository::log($userId, 'CLIENT_PROSPECT_CREATED', 'clients', $clientId, 'prospect_expires_on', null, $expires, 'Quick quote — the buyer has not sent their details yet (D-394).');
        return $expires;
    }

    /** Every normally required detail is filled in: no longer a prospect (it never expires on its own). */
    public static function confirm(int $clientId, ?int $userId, string $why): void
    {
        $client = ClientRepository::find($clientId);
        if ($client === null || (int) ($client['is_prospect'] ?? 0) !== 1) {
            return;
        }
        Database::connection()->prepare(
            'UPDATE clients SET is_prospect = 0, prospect_expires_on = NULL, prospect_reminded_on = NULL WHERE id = :id'
        )->execute(['id' => $clientId]);
        AuditLogRepository::log($userId, 'CLIENT_PROSPECT_CONFIRMED', 'clients', $clientId, 'is_prospect', '1', '0', $why);
    }

    public const QUOTATION_FORM_SUBJECT = 'Your quotation — please send us your company details';

    /** The e-mail with the Public Quotation Form link ("E-mail the Public Quotation Form"). */
    public static function quotationFormEmailBody(string $clientName, string $email, string $link): string
    {
        $company = (string) (CompanySettingsRepository::get('legal_name') ?? '');
        return "Dear {$clientName},\n\n"
            . "Thank you for your enquiry. So that we can prepare your documents, please fill in your company details on our quotation form:\n\n{$link}\n\n"
            . "Please use this e-mail address ({$email}) on the form, so that your details are added to your existing enquiry.\n\n"
            . "Kind regards,\n" . ($company !== '' ? $company : 'NexaCrest');
    }

    /** "Keep for N more days": from the later of today and the current date. */
    public static function extend(int $clientId, int $userId): ?string
    {
        $client = ClientRepository::find($clientId);
        if ($client === null || (int) ($client['is_prospect'] ?? 0) !== 1 || (int) $client['is_active'] !== 1) {
            return null;
        }
        $from = (string) ($client['prospect_expires_on'] ?? '');
        if ($from === '' || $from < self::today()) {
            $from = self::today();
        }
        $new = date('Y-m-d', strtotime($from . ' +' . self::expiryDays() . ' days'));
        Database::connection()->prepare('UPDATE clients SET prospect_expires_on = :d, prospect_reminded_on = NULL WHERE id = :id')
            ->execute(['d' => $new, 'id' => $clientId]);
        AuditLogRepository::log($userId, 'CLIENT_PROSPECT_EXTENDED', 'clients', $clientId, 'prospect_expires_on', $client['prospect_expires_on'] ?? null, $new, 'Keep for ' . self::expiryDays() . ' more days.');
        return $new;
    }

    /** Reactivated: a fresh date (today + the setting); the "expired" note goes. */
    public static function onReactivated(int $clientId, int $userId): string
    {
        $client = ClientRepository::find($clientId);
        $new = self::freshExpiry();
        Database::connection()->prepare(
            'UPDATE clients SET prospect_expires_on = :d, prospect_reminded_on = NULL, prospect_expired_on = NULL, prospect_expired_reason = NULL WHERE id = :id'
        )->execute(['d' => $new, 'id' => $clientId]);
        AuditLogRepository::log($userId, 'CLIENT_PROSPECT_EXTENDED', 'clients', $clientId, 'prospect_expires_on', $client['prospect_expires_on'] ?? null, $new, 'Reactivated — a fresh ' . self::expiryDays() . ' days.');
        return $new;
    }

    /** The success message after reactivating a prospect. */
    public static function reactivatedMessage(string $name, string $expires): string
    {
        return "{$name} is active again. It stays a prospect until its details are filled in; it now expires on " . Dates::short($expires)
            . ' unless an order goes ahead. To quote again, open the old order (Did not go ahead) and use Duplicate order.';
    }

    /**
     * Why the daily job must not close this order, or null when it may: Mark
     * Lost's own refusals, and never an order past the quotation stage (Stage 1
     * gate passed: the buyer accepted / sent a PO).
     *
     * @param array<string,mixed> $order
     */
    public static function closeRefusal(array $order): ?string
    {
        if ($refusal = OrderLostService::refusal($order)) {
            return $refusal;
        }
        $stage1 = OrderStageRepository::findByOrderAndStageNumber((int) $order['id'], 1);
        if ($stage1 !== null && in_array((string) $stage1['status'], ['gate_passed', 'skipped'], true)) {
            return 'The order is past the quotation stage.';
        }
        return null;
    }

    /** @return list<array<string,mixed>> the client's orders, oldest first */
    public static function orders(int $clientId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM orders WHERE client_id = :c ORDER BY created_at, id');
        $stmt->execute(['c' => $clientId]);
        return $stmt->fetchAll();
    }

    /** The first still-active order (the quotation order the client page names), else the first order. */
    public static function quotationOrder(int $clientId): ?array
    {
        $orders = self::orders($clientId);
        foreach ($orders as $o) {
            if ($o['status'] === 'active') {
                return $o;
            }
        }
        return $orders[0] ?? null;
    }

    /** @return list<int> who is reminded for an order: its assignees, else whoever created it. */
    private static function ownersOf(array $order, array $client): array
    {
        $ids = array_map(static fn (array $a): int => (int) $a['user_id'], OrderAssigneeRepository::forOrder((int) $order['id']));
        if ($ids === [] && !empty($order['created_by'])) {
            $ids = [(int) $order['created_by']];
        }
        if ($ids === [] && !empty($client['created_by'])) {
            $ids = [(int) $client['created_by']];
        }
        return array_values(array_unique($ids));
    }

    public static function reminderMessage(array $client, ?array $order, string $expires): string
    {
        $text = "Reminder: quotation for {$client['company_legal_name']} expires on " . Dates::short($expires)
            . ($order !== null ? " (order {$order['order_reference']})" : '') . '. No reply from the buyer yet.';
        $text .= self::autoClose()
            ? ' If the buyer does not go ahead, on that date the order moves to "Did not go ahead" and the client to Deactivated. Nothing is deleted, and the client can be activated again. To keep it open, use "Keep for ' . self::expiryDays() . ' more days" on the client page.'
            : ' Automatic closing is off, so nothing closes on its own. Nothing is deleted.';
        return $text;
    }

    /**
     * Daily: one reminder per prospect and expiry date, N days before it, to
     * each still-active order's owner(s). Returns how many people were notified.
     */
    public static function sendReminders(): int
    {
        $days = self::reminderDays();
        $stmt = Database::connection()->prepare(
            'SELECT * FROM clients
              WHERE is_prospect = 1 AND is_active = 1 AND prospect_expires_on IS NOT NULL AND prospect_reminded_on IS NULL
                AND prospect_expires_on <= DATE_ADD(CURDATE(), INTERVAL :d DAY)'
        );
        $stmt->execute(['d' => $days]);
        $sent = 0;
        foreach ($stmt->fetchAll() as $client) {
            $expires = (string) $client['prospect_expires_on'];
            if ($expires <= self::today() && self::autoClose()) {
                continue; // due today or past: the closing (or its "left as it is") comes instead
            }
            $orders = array_values(array_filter(self::orders((int) $client['id']), static fn (array $o): bool => $o['status'] === 'active'));
            if ($orders === []) {
                $owners = !empty($client['created_by']) ? [(int) $client['created_by']] : [];
                foreach ($owners as $uid) {
                    NotificationRepository::create($uid, null, self::NOTIFICATION_TYPE, null, self::reminderMessage($client, null, $expires));
                    $sent++;
                }
            }
            foreach ($orders as $order) {
                foreach (self::ownersOf($order, $client) as $uid) {
                    NotificationRepository::create($uid, null, self::NOTIFICATION_TYPE, (int) $order['id'], self::reminderMessage($client, $order, $expires));
                    $sent++;
                }
            }
            Database::connection()->prepare('UPDATE clients SET prospect_reminded_on = CURDATE() WHERE id = :id')->execute(['id' => $client['id']]);
            AuditLogRepository::log(null, 'CLIENT_PROSPECT_REMINDED', 'clients', (int) $client['id'], 'prospect_reminded_on', null, self::today(), 'Quotation expires on ' . Dates::short($expires) . ' (D-394).');
        }
        return $sent;
    }

    /** "Quote expired 19 Jan 2027 — no reply in 60 days". */
    public static function expiredReason(string $date, int $days): string
    {
        return 'Quote expired ' . Dates::short($date) . " — no reply in {$days} days";
    }

    /**
     * Daily, when Automatic closing is On: every active prospect whose date has
     * come. Each of its active orders is marked lost (if any one may not be, or
     * an order already went ahead, the client is left as it is); then the client
     * is deactivated. Safe to run twice: a closed client is no longer active.
     *
     * @return array{clients:int, orders:int, skipped:list<string>}
     */
    public static function expireDue(): array
    {
        $out = ['clients' => 0, 'orders' => 0, 'skipped' => []];
        if (!self::autoClose()) {
            return $out;
        }
        $days = self::expiryDays();
        $clients = Database::connection()->query(
            'SELECT * FROM clients WHERE is_prospect = 1 AND is_active = 1 AND prospect_expires_on IS NOT NULL AND prospect_expires_on <= CURDATE() ORDER BY id'
        )->fetchAll();
        foreach ($clients as $client) {
            $toClose = [];
            $blocked = null;
            foreach (self::orders((int) $client['id']) as $order) {
                if (in_array($order['status'], ['lost', 'cancelled'], true)) {
                    continue;
                }
                if ($order['status'] !== 'active') {
                    $blocked = "order {$order['order_reference']} went ahead ({$order['status']})";
                    break;
                }
                if ($why = self::closeRefusal($order)) {
                    $blocked = "order {$order['order_reference']}: {$why}";
                    break;
                }
                $toClose[] = $order;
            }
            if ($blocked !== null) {
                $out['skipped'][] = "{$client['company_legal_name']} — {$blocked}";
                continue;
            }
            $reason = "Quotation expired — no reply in {$days} days";
            foreach ($toClose as $order) {
                OrderLostService::markLost((int) $order['id'], $reason, null, self::LOST_CATEGORY);
                $out['orders']++;
            }
            $expiredReason = self::expiredReason((string) $client['prospect_expires_on'], $days);
            ClientRepository::setActive((int) $client['id'], false);
            Database::connection()->prepare('UPDATE clients SET prospect_expired_on = CURDATE(), prospect_expired_reason = :r WHERE id = :id')
                ->execute(['r' => $expiredReason, 'id' => $client['id']]);
            AuditLogRepository::log(null, 'CLIENT_DEACTIVATED', 'clients', (int) $client['id'], 'is_active', '1', '0', $expiredReason . ' (closed automatically, D-394).');
            $out['clients']++;
        }
        return $out;
    }

    /**
     * A prospect (active or deactivated) using this e-mail, case- and
     * space-insensitive; null when none.
     */
    public static function matchingProspect(?string $email): ?array
    {
        $email = trim((string) $email);
        if ($email === '') {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM clients WHERE is_prospect = 1 AND LOWER(TRIM(email)) = LOWER(:e) ORDER BY id LIMIT 1'
        );
        $stmt->execute(['e' => $email]);
        return $stmt->fetch() ?: null;
    }

    /** The client fields a Quotation request fills in => their label on the "Update this prospect" page. */
    public const INTAKE_FIELDS = [
        'company_legal_name' => 'Company Legal Name',
        'billing_address_line1' => 'Billing Address Line 1',
        'billing_address_line2' => 'Billing Address Line 2',
        'billing_city' => 'City / Town',
        'billing_postcode' => 'Postcode',
        'billing_country' => 'Billing Country',
        'billing_address' => 'Billing address (as printed)',
        'vat_eori_tax_no' => 'VAT / EORI / Tax Reg. No.',
        'contact_person' => 'Contact Person',
        'email' => 'Email',
        'phone' => 'Phone',
        'country_of_destination' => 'Country of Destination',
        'coo_type' => 'Certificate of Origin Type',
        'consignee_same_as_buyer' => 'Consignee same as buyer',
        'consignee_name' => 'Consignee Company Name',
        'consignee_address_line1' => 'Consignee Address Line 1',
        'consignee_address_line2' => 'Consignee Address Line 2',
        'consignee_city' => 'Consignee City / Town',
        'consignee_postcode' => 'Consignee Postcode',
        'consignee_country' => 'Consignee Country',
        'consignee_vat_eori_tax_no' => 'Consignee VAT / EORI / Tax Reg. No.',
        'consignee_contact_person' => 'Consignee Contact Person',
        'consignee_phone' => 'Consignee Phone',
        'consignee_email' => 'Consignee Email',
    ];

    /** @return array<string,mixed> the client values a submission sets (the same mapping as Accept & Create Client). */
    public static function valuesFromIntake(array $s): array
    {
        $same = (int) ($s['consignee_same_as_buyer'] ?? 1) === 1;
        $v = [
            'company_legal_name' => $s['company_legal_name'],
            'billing_address' => $s['billing_address'],
            'billing_address_line1' => $s['billing_address_line1'] ?? null,
            'billing_address_line2' => $s['billing_address_line2'] ?? null,
            'billing_city' => $s['billing_city'] ?? null,
            'billing_postcode' => $s['billing_postcode'] ?? null,
            'billing_country' => $s['billing_country'] ?? null,
            'vat_eori_tax_no' => $s['vat_eori_tax_no'] ?? null,
            'contact_person' => $s['contact_person'] ?? null,
            'email' => $s['email'] ?? null,
            'phone' => $s['phone'] ?? null,
            'country_of_destination' => $s['country_of_destination'] ?? null,
            'coo_type' => ($s['coo_type'] ?? '') ?: 'To Be Confirmed',
            'consignee_same_as_buyer' => $same ? 1 : 0,
        ];
        foreach (['consignee_name', 'consignee_address_line1', 'consignee_address_line2', 'consignee_city', 'consignee_postcode', 'consignee_country', 'consignee_vat_eori_tax_no', 'consignee_contact_person', 'consignee_phone', 'consignee_email'] as $f) {
            $v[$f] = $same ? null : ($s[$f] ?? null);
        }
        return $v;
    }

    /** @return list<array{field:string,label:string,old:string,new:string}> what "Update this prospect" changes */
    public static function intakeChanges(array $submission, array $client): array
    {
        $changes = [];
        foreach (self::valuesFromIntake($submission) as $field => $new) {
            $old = $client[$field] ?? null;
            if ((string) ($old ?? '') === (string) ($new ?? '')) {
                continue;
            }
            $changes[] = ['field' => $field, 'label' => self::INTAKE_FIELDS[$field] ?? $field, 'old' => (string) ($old ?? ''), 'new' => (string) ($new ?? '')];
        }
        return $changes;
    }

    /** Why "Update this prospect" cannot go ahead, or null. */
    public static function intakeUpdateRefusal(array $submission, ?array $client): ?string
    {
        if ($client === null || (int) ($client['is_prospect'] ?? 0) !== 1) {
            return 'This request no longer matches a prospect.';
        }
        if (strtolower(trim((string) $client['email'])) !== strtolower(trim((string) ($submission['email'] ?? '')))) {
            return 'This request no longer matches the prospect\'s e-mail.';
        }
        if ((int) ($client['is_data_locked'] ?? 0) === 1) {
            return 'This client\'s details are locked and cannot be updated from a request.';
        }
        if ($blocker = AssociateIntakeService::acceptBlocker($submission)) {
            return $blocker;
        }
        if ($other = ClientRepository::findOtherWithEmail($submission['email'] ?? null, (int) $client['id'])) {
            return ClientRepository::duplicateEmailMessage((string) $submission['email'], $other);
        }
        return null;
    }

    /**
     * "Update this prospect": the request's details go onto the prospect (same id,
     * Buyer Inquiry Ref and orders), the prospect badge goes, a deactivated one is
     * reactivated, and the request is marked accepted and linked to it.
     *
     * @return array{changes:int, reactivated:bool, associate:?string, refresh:array}
     */
    public static function applyIntake(array $submission, array $client, int $userId): array
    {
        $clientId = (int) $client['id'];
        $changes = self::intakeChanges($submission, $client);
        $values = self::valuesFromIntake($submission);
        $set = [];
        $params = ['id' => $clientId];
        foreach ($values as $field => $value) {
            $set[] = "{$field} = :{$field}";
            $params[$field] = $value;
        }
        Database::connection()->prepare('UPDATE clients SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        $why = "From Quotation request no. {$submission['id']} (Update this prospect, D-394).";
        foreach ($changes as $c) {
            AuditLogRepository::log($userId, 'CLIENT_UPDATED', 'clients', $clientId, $c['field'], $c['old'] !== '' ? $c['old'] : null, $c['new'] !== '' ? $c['new'] : null, $why);
        }
        Database::connection()->prepare(
            'UPDATE clients SET is_prospect = 0, prospect_expires_on = NULL, prospect_reminded_on = NULL, prospect_expired_on = NULL, prospect_expired_reason = NULL WHERE id = :id'
        )->execute(['id' => $clientId]);
        AuditLogRepository::log($userId, 'CLIENT_PROSPECT_CONFIRMED', 'clients', $clientId, 'is_prospect', '1', '0', $why);
        $reactivated = false;
        if ((int) $client['is_active'] !== 1) {
            ClientRepository::setActive($clientId, true);
            AuditLogRepository::log($userId, 'CLIENT_REACTIVATED', 'clients', $clientId, 'is_active', '0', '1', $why);
            $reactivated = true;
        }
        \App\Repositories\ClientIntakeRepository::markConverted((int) $submission['id'], $clientId, $userId);
        AuditLogRepository::log($userId, 'CLIENT_INTAKE_ACCEPTED', 'client_intake_submissions', (int) $submission['id'], 'converted_client_id', 'pending', (string) $clientId, 'Linked to the existing prospect (Update this prospect, D-394).');
        $associate = AssociateIntakeService::onAccepted($submission, $clientId, $userId);
        $refresh = BuyerDetailsRefreshService::afterClientSaved($clientId, $userId, 'quotation request');
        return ['changes' => count($changes), 'reactivated' => $reactivated, 'associate' => $associate, 'refresh' => $refresh];
    }
}
