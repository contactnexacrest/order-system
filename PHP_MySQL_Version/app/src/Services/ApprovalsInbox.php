<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PermissionRepository;

/**
 * Owner decision D-310 (point 27, 11 Nov 2026; mockup approvals.png): one
 * Approvals page listing everything the signed-in person may approve —
 * prices, payment terms, documents (My Reviews), e-mails (Level-2 e-mail
 * approvals), bank details and the rest (amendments, cancellations,
 * purchases, short payments, associate registrations, associate price
 * requests, protection requests, reorder requests).
 *
 * Nothing is approved here: each row opens the exact approval screen that
 * already exists. The rows follow the same permissions and Admin ›
 * Self-approval rules as those screens, so a row is shown only to someone
 * who could act on it there. Node twin: services/approvalsInbox.js.
 */
final class ApprovalsInbox
{
    public const TABS = [
        'prices' => 'Prices',
        'payment_terms' => 'Payment terms',
        'documents' => 'Documents',
        'emails' => 'E-mails',
        'bank' => 'Bank details',
        'other' => 'Other',
    ];

    /** Holding any of these shows "Approvals" in the menu even when nothing waits (anyone else sees it once something does). */
    public const APPROVER_PERMISSIONS = [
        'manage_pricing', 'approve_payment_terms', 'approve_email_send', 'approve_documents', 'edit_locked_data', 'approve_purchases',
        'approve_short_payment', 'manage_associate_bank', 'manage_associates', 'manage_field_protection',
    ];

    /** Payment legs: where on the order page each one is cleared. */
    public const LEG_ANCHORS = [
        'advance' => 'step-5e-payment-confirm',
        'freight' => 'step-9d-freight-clear',
        'balance' => 'step-balance-clear',
        'milestone' => 'payment-overview',
    ];

    /** @var array<string, list<array<string,mixed>>> per-request cache: the sidebar, dashboard and order bar ask once */
    private static array $cache = [];

    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Every item this person may approve now, oldest first.
     *
     * @return list<array<string,mixed>> each: key, tab, what, tone, since, order_id, order_ref, line_no, who, details, url, button, bar
     */
    public static function items(int $userId, ?int $roleId): array
    {
        $cacheKey = $userId . '|' . ($roleId ?? '');
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }
        $can = static fn (string $perm): bool => PermissionService::can($userId, $roleId, $perm);
        $items = [];
        if ($can('manage_pricing')) {
            $items = array_merge($items, self::prices($userId, $roleId));
        }
        if ($can('approve_payment_terms')) {
            $items = array_merge($items, self::paymentTerms($userId, $roleId));
        }
        $items = array_merge($items, self::documents($userId));
        if ($can('approve_email_send')) {
            $items = array_merge($items, self::emails($userId));
        }
        $items = array_merge($items, self::bank($userId, $can));
        $items = array_merge($items, self::other($userId, $roleId, $can));
        usort($items, static fn (array $a, array $b): int => [(string) $a['since'], $a['key']] <=> [(string) $b['since'], $b['key']]);
        return self::$cache[$cacheKey] = $items;
    }

    /** @return array<string,int> 'all' plus one count per tab */
    public static function counts(array $items): array
    {
        $counts = ['all' => count($items)] + array_fill_keys(array_keys(self::TABS), 0);
        foreach ($items as $item) {
            $counts[$item['tab']]++;
        }
        return $counts;
    }

    /** The menu badge: never lets a failing query break the page around it (the Approvals page itself still shows the error). */
    public static function countFor(int $userId, ?int $roleId): int
    {
        try {
            return count(self::items($userId, $roleId));
        } catch (\Throwable $e) {
            error_log('[ApprovalsInbox] ' . $e->getMessage());
            return 0;
        }
    }

    public static function isApprover(int $userId, ?int $roleId): bool
    {
        foreach (self::APPROVER_PERMISSIONS as $perm) {
            if (PermissionService::can($userId, $roleId, $perm)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,mixed>> the items of one order (the yellow bar at the top of the order page) */
    public static function forOrder(int $userId, ?int $roleId, int $orderId): array
    {
        return array_values(array_filter(self::items($userId, $roleId), static fn (array $i): bool => (int) ($i['order_id'] ?? 0) === $orderId));
    }

    /**
     * What this person asked for on the order that someone else must approve:
     * "Line 2 — sent to the MD for approval on 11 Nov, 10:42 — waiting."
     *
     * @return list<string>
     */
    public static function waitingOnOthers(int $userId, ?int $roleId, int $orderId): array
    {
        $mine = array_column(self::forOrder($userId, $roleId, $orderId), 'key');
        $pdo = Database::connection();
        $out = [];
        $rows = $pdo->prepare('SELECT id, line_no, price_approval_requested_at FROM order_products
                                WHERE order_id = :o AND is_active = 1 AND price_approval_requested_at IS NOT NULL AND price_approval_requested_by = :u ORDER BY line_no');
        $rows->execute(['o' => $orderId, 'u' => $userId]);
        foreach ($rows->fetchAll() as $r) {
            if (!in_array('price:' . $r['id'], $mine, true)) {
                $out[] = 'Line ' . (int) $r['line_no'] . ' — sent to the MD for approval on ' . self::when((string) $r['price_approval_requested_at']) . ' — waiting.';
            }
        }
        $queries = [
            'cancellation' => ["SELECT id, requested_at AS at FROM order_cancellations WHERE order_id = :o AND status = 'pending' AND requested_by = :u", 'Cancellation request'],
            'amendment' => ["SELECT id, created_at AS at FROM amendments WHERE order_id = :o AND status = 'pending' AND created_by = :u", 'Amendment'],
            'email' => ["SELECT id, created_at AS at FROM email_log WHERE order_id = :o AND status = 'pending_approval' AND requested_by = :u", 'E-mail'],
            'short' => ['SELECT id, requested_at AS at FROM short_payment_requests WHERE order_id = :o AND requested_by = :u', 'Short payment'],
        ];
        foreach ($queries as $kind => [$sql, $label]) {
            $stmt = $pdo->prepare($sql . ' ORDER BY id');
            $stmt->execute(['o' => $orderId, 'u' => $userId]);
            foreach ($stmt->fetchAll() as $r) {
                if ($kind === 'short' && !self::shortRequestOpen((int) $r['id'])) {
                    continue;
                }
                if (!in_array($kind . ':' . $r['id'], $mine, true)) {
                    $out[] = $label . ' — sent for approval on ' . self::when((string) $r['at']) . ' — waiting.';
                }
            }
        }
        return $out;
    }

    /** "Today 10:42", "Yesterday 16:05", "9 Nov", "9 Nov 2025" — when an item started waiting. */
    public static function sinceLabel(string $since, string $today): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $since, $m)) {
            return $since;
        }
        $date = "{$m[1]}-{$m[2]}-{$m[3]}";
        $time = isset($m[4]) && $m[4] !== '' ? " {$m[4]}:{$m[5]}" : '';
        if ($date === $today) {
            return 'Today' . $time;
        }
        if ($date === date('Y-m-d', strtotime($today . ' -1 day'))) {
            return 'Yesterday' . $time;
        }
        $label = (int) $m[3] . ' ' . date('M', mktime(0, 0, 0, (int) $m[2], 1));
        return $m[1] === substr($today, 0, 4) ? $label : $label . ' ' . $m[1];
    }

    /** "11 Nov, 10:42" — for the requester's "waiting" note. */
    public static function when(string $at): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $at, $m)) {
            return $at;
        }
        return (int) $m[3] . ' ' . date('M', mktime(0, 0, 0, (int) $m[2], 1)) . (isset($m[4]) && $m[4] !== '' ? ", {$m[4]}:{$m[5]}" : '');
    }

    // ---------------------------------------------------------------- the kinds

    /** @return list<array<string,mixed>> */
    private static function prices(int $userId, ?int $roleId): array
    {
        [$vis, $params] = OrderAccessService::visibilitySqlFor($userId, $roleId, 'o');
        $stmt = Database::connection()->prepare(
            "SELECT op.*, o.order_reference, u.name AS requested_by_name
               FROM order_products op
               JOIN orders o ON o.id = op.order_id
               LEFT JOIN users u ON u.id = op.price_approval_requested_by
              WHERE op.price_approval_requested_at IS NOT NULL AND op.is_active = 1 AND o.status NOT IN ('cancelled', 'lost'){$vis}
              ORDER BY op.price_approval_requested_at, op.id"
        );
        $stmt->execute($params);
        $out = [];
        $contexts = [];
        foreach ($stmt->fetchAll() as $line) {
            // Admin › Self-approval rules: a price this person set themselves (rule price_discount).
            if ((int) ($line['price_set_by'] ?? 0) === $userId && !MakerCheckerGuard::selfApprovalAllowed('price_discount', $userId)) {
                continue;
            }
            $orderId = (int) $line['order_id'];
            $no = (int) $line['line_no'];
            if (!isset($contexts[$orderId])) {
                $order = OrderRepository::find($orderId);
                $contexts[$orderId] = $order !== null ? OrderLinePricing::context($order) : null;
            }
            $check = $contexts[$orderId] !== null ? OrderLinePricing::checkLine($line, $contexts[$orderId]) : ['status' => null, 'figures' => null];
            $currency = 'USD';
            $unit = trim((string) ($line['unit'] ?? ''));
            $price = $line['unit_price'] !== null ? $currency . ' ' . number_format((float) $line['unit_price'], 2) . ($unit !== '' ? ' / ' . $unit : '') : 'no price';
            $f = $check['figures'] ?? null;
            if ($check['status'] === OrderLinePricing::NO_PRICE) {
                $what = 'Price — no product price';
                $details = 'Product has no price set' . ($line['unit_price'] !== null ? ' · asked ' . $price : '');
                $bar = "line {$no} — its product has no price set" . ($line['unit_price'] !== null ? " (asked {$price})" : '') . '. The Quotation is held until it is approved.';
            } else {
                $what = 'Price below lowest';
                $lowest = $f !== null ? $currency . ' ' . number_format((float) $f['floor'], 2) . (!empty($f['unit']) ? ' / ' . $f['unit'] : '') : null;
                $details = $price . ($f !== null ? ' · lowest ' . number_format((float) $f['floor'], 2) . ' · minimum ' . number_format((float) $f['minimum'], 2) : '');
                $bar = "line {$no} — {$price} is below the lowest price" . ($lowest !== null ? " ({$lowest})" : '') . '. The Quotation is held until it is approved.';
            }
            $out[] = [
                'key' => 'price:' . $line['id'], 'tab' => 'prices', 'what' => $what, 'tone' => 'red',
                'since' => (string) $line['price_approval_requested_at'], 'order_id' => $orderId, 'order_ref' => (string) $line['order_reference'], 'line_no' => $no,
                'who' => 'line ' . $no . (!empty($line['requested_by_name']) ? ' · asked by ' . $line['requested_by_name'] : ''),
                'details' => $details, 'url' => "/orders/{$orderId}/products/{$line['id']}/price-approval", 'button' => 'Review and approve',
                'bar' => $bar, 'bar_button' => "Review and approve line {$no}",
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function paymentTerms(int $userId, ?int $roleId): array
    {
        [$vis, $params] = OrderAccessService::visibilitySqlFor($userId, $roleId, 'o');
        $stmt = Database::connection()->prepare(
            "SELECT o.id, o.order_reference, o.created_by, o.created_at, COALESCE(o.preset_name, pp.preset_name) AS preset_name
               FROM orders o
               JOIN payment_presets pp ON pp.id = o.payment_preset_id
              WHERE COALESCE(o.payment_terms_requires_md_approval, pp.requires_md_approval) = 1
                AND o.payment_terms_approved_at IS NULL AND o.status NOT IN ('cancelled', 'lost'){$vis}
              ORDER BY o.created_at, o.id"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $o) {
            if ((int) ($o['created_by'] ?? 0) === $userId && !MakerCheckerGuard::paymentTermsSelfApprovalAllowed($userId)) {
                continue;
            }
            $out[] = [
                'key' => 'terms:' . $o['id'], 'tab' => 'payment_terms', 'what' => 'Payment terms', 'tone' => 'amber',
                'since' => (string) $o['created_at'], 'order_id' => (int) $o['id'], 'order_ref' => (string) $o['order_reference'], 'line_no' => null,
                'who' => '', 'details' => (string) $o['preset_name'],
                'url' => '/orders/' . (int) $o['id'] . '#step-1-quotation-prepare', 'button' => 'Approve terms',
                'bar' => 'the payment terms ("' . $o['preset_name'] . '") — the Quotation cannot be generated or sent until they are approved.', 'bar_button' => 'Approve the payment terms',
            ];
        }
        return $out;
    }

    /** My Reviews: the documents waiting on this reviewer (as /reviews). @return list<array<string,mixed>> */
    private static function documents(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT dr.id, dr.assigned_at, d.order_id, d.document_reference, d.revision_number, dt.code, dt.name AS type_name, o.order_reference
               FROM document_reviews dr
               JOIN documents d ON d.id = dr.document_id
               JOIN document_types dt ON dt.id = d.document_type_id
               LEFT JOIN orders o ON o.id = d.order_id
              WHERE dr.reviewer_id = :u AND dr.status = 'pending' AND d.status != 'superseded'
              ORDER BY dr.assigned_at, dr.id"
        );
        $stmt->execute(['u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'key' => 'review:' . $r['id'], 'tab' => 'documents', 'what' => 'Document review', 'tone' => 'amber',
                'since' => (string) $r['assigned_at'], 'order_id' => $r['order_id'] !== null ? (int) $r['order_id'] : null,
                'order_ref' => (string) ($r['order_reference'] ?? ''), 'line_no' => null, 'who' => (string) $r['type_name'],
                'details' => $r['code'] . ' ' . ($r['document_reference'] ?? '—') . ' · Rev.' . str_pad((string) (int) $r['revision_number'], 2, '0', STR_PAD_LEFT),
                'url' => '/reviews#review-' . (int) $r['id'], 'button' => 'Review document',
                'bar' => 'the ' . $r['type_name'] . ' (' . ($r['document_reference'] ?? $r['code']) . ') waits for your review.', 'bar_button' => 'Review the document',
            ];
        }
        return $out;
    }

    /** Email Approvals: sends waiting for Level-2 approval (as /email-approvals). @return list<array<string,mixed>> */
    private static function emails(int $userId): array
    {
        $rows = Database::connection()->query(
            "SELECT el.id, el.order_id, el.recipient_email, el.subject, el.requested_by, el.created_at, o.order_reference, dt.name AS document_type_name
               FROM email_log el
               LEFT JOIN orders o ON o.id = el.order_id
               LEFT JOIN documents d ON d.id = el.document_id
               LEFT JOIN document_types dt ON dt.id = d.document_type_id
              WHERE el.status = 'pending_approval'" . DivisionService::orderIdSql('el.order_id', true) . '
              ORDER BY el.created_at, el.id'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            if ($r['requested_by'] !== null && (int) $r['requested_by'] === $userId && !MakerCheckerGuard::selfApprovalAllowed('email_send', $userId)) {
                continue;
            }
            $out[] = [
                'key' => 'email:' . $r['id'], 'tab' => 'emails', 'what' => 'E-mail (Level 2)', 'tone' => 'amber',
                'since' => (string) $r['created_at'], 'order_id' => $r['order_id'] !== null ? (int) $r['order_id'] : null,
                'order_ref' => (string) ($r['order_reference'] ?? ''), 'line_no' => null, 'who' => (string) ($r['document_type_name'] ?? ''),
                'details' => 'to ' . $r['recipient_email'] . ' · ' . $r['subject'],
                'url' => '/email-approvals#email-' . (int) $r['id'], 'button' => 'Review e-mail',
                'bar' => 'an e-mail to ' . $r['recipient_email'] . ' waits for your Level-2 approval.', 'bar_button' => 'Review the e-mail',
            ];
        }
        return $out;
    }

    /** Bank details waiting to be verified (associates, suppliers, logistics partners). @return list<array<string,mixed>> */
    private static function bank(int $userId, callable $can): array
    {
        $out = [];
        $two = BankDetailsCheck::people() === 'two';
        $selfOk = BankDetailsCheck::maySelfVerify($userId);
        $pdo = Database::connection();
        if ($can('manage_associate_bank') && DivisionService::moduleEnabled('associates')) {
            $rows = $pdo->query(
                "SELECT b.id, b.associate_id, b.bank_name, b.account_last4, b.requested_by_user, b.first_checked_by, b.requested_at, b.confirmed_at, a.name
                   FROM associate_bank_accounts b JOIN associates a ON a.id = b.associate_id
                  WHERE b.status = 'pending'" . DivisionService::sql('a.division_id') . ' ORDER BY b.id'
            )->fetchAll();
            foreach ($rows as $b) {
                $item = self::bankItem('associate_bank:' . $b['id'], $b, $userId, $two, $selfOk, (int) ($b['requested_by_user'] ?? 0),
                    'Associate · ' . $b['name'], '/associates/' . (int) $b['associate_id'] . '#bank');
                if ($item !== null) {
                    $out[] = $item;
                }
            }
        }
        $vendors = [];
        if ($can('manage_suppliers')) {
            $vendors[] = ['supplier', 'JOIN suppliers v ON v.id = c.vendor_id', 'v.supplier_legal_name', 'Supplier', '/suppliers/%d/edit#bank-changes'];
        }
        if ($can('manage_logistics_partners')) {
            $vendors[] = ['logistics_partner', 'JOIN logistics_partners v ON v.id = c.vendor_id', 'v.partner_name', 'Logistics partner', '/logistics-partners/%d/edit#bank-changes'];
        }
        foreach ($vendors as [$kind, $join, $nameCol, $label, $url]) {
            $stmt = $pdo->prepare(
                "SELECT c.id, c.vendor_id, c.bank_name, c.account_last4, c.entered_by, c.first_checked_by, c.entered_at AS requested_at, c.confirmed_at, {$nameCol} AS name
                   FROM vendor_bank_changes c {$join}
                  WHERE c.vendor_kind = :k AND c.status = 'pending' ORDER BY c.id"
            );
            $stmt->execute(['k' => $kind]);
            foreach ($stmt->fetchAll() as $c) {
                $item = self::bankItem('vendor_bank:' . $c['id'], $c, $userId, $two, $selfOk, (int) ($c['entered_by'] ?? 0), $label . ' · ' . $c['name'], sprintf($url, (int) $c['vendor_id']));
                if ($item !== null) {
                    $out[] = $item;
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private static function bankItem(string $key, array $b, int $userId, bool $two, bool $selfOk, int $enteredBy, string $who, string $url): ?array
    {
        // D-293: someone other than the enterer verifies (MD/ED with the "bank_details" rule may, with a reason);
        // with two people, the first checker does not also do the second check.
        if ($enteredBy === $userId && !$selfOk) {
            return null;
        }
        if ($two && (int) ($b['first_checked_by'] ?? 0) === $userId) {
            return null;
        }
        $second = $two && !empty($b['first_checked_by']);
        return [
            'key' => $key, 'tab' => 'bank', 'what' => $second ? 'Bank details — 2nd check' : ($two ? 'Bank details — 1st check' : 'Bank details — verify'), 'tone' => 'amber',
            'since' => (string) ($b['confirmed_at'] ?? $b['requested_at']), 'order_id' => null, 'order_ref' => '', 'line_no' => null, 'who' => $who,
            'details' => trim((string) ($b['bank_name'] ?? '')) . ' ••••' . ($b['account_last4'] ?? '') . ($second ? ' · first check done' : ' · confirmed by the account holder'),
            'url' => $url, 'button' => 'Verify', 'bar' => '', 'bar_button' => '',
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function other(int $userId, ?int $roleId, callable $can): array
    {
        $pdo = Database::connection();
        [$vis, $params] = OrderAccessService::visibilitySqlFor($userId, $roleId, 'o');
        $out = [];

        if ($can('approve_documents')) {
            $stmt = $pdo->prepare("SELECT a.id, a.order_id, a.amendment_reference, a.reason, a.created_by, a.created_at, o.order_reference
                                     FROM amendments a JOIN orders o ON o.id = a.order_id WHERE a.status = 'pending'{$vis} ORDER BY a.created_at, a.id");
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $a) {
                if ($a['created_by'] !== null && (int) $a['created_by'] === $userId && !MakerCheckerGuard::selfApprovalAllowed('amendment', $userId)) {
                    continue;
                }
                $out[] = self::row('amendment:' . $a['id'], 'Amendment', (string) $a['created_at'], (int) $a['order_id'], (string) $a['order_reference'], (string) $a['amendment_reference'],
                    self::short((string) $a['reason']), '/orders/' . (int) $a['order_id'] . '/amendments', 'Review amendment',
                    'amendment ' . $a['amendment_reference'] . ' waits for MD approval.');
            }
        }

        if ($can('edit_locked_data')) {
            $stmt = $pdo->prepare("SELECT c.id, c.order_id, c.reason, c.requested_by, c.requested_at, o.order_reference, u.name AS requested_by_name
                                     FROM order_cancellations c JOIN orders o ON o.id = c.order_id LEFT JOIN users u ON u.id = c.requested_by
                                    WHERE c.status = 'pending'{$vis} ORDER BY c.requested_at, c.id");
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $c) {
                if ($c['requested_by'] !== null && (int) $c['requested_by'] === $userId && !MakerCheckerGuard::selfApprovalAllowed('cancellation', $userId)) {
                    continue;
                }
                $out[] = self::row('cancellation:' . $c['id'], 'Cancellation request', (string) $c['requested_at'], (int) $c['order_id'], (string) $c['order_reference'],
                    !empty($c['requested_by_name']) ? 'asked by ' . $c['requested_by_name'] : '', self::short((string) $c['reason']),
                    '/orders/' . (int) $c['order_id'] . '#cancellation-request', 'Review cancellation', 'a request to cancel this order.');
            }
        }

        if ($can('approve_purchases') && DivisionService::moduleEnabled('supplier_purchase')) {
            $stmt = $pdo->prepare("SELECT pr.*, o.order_reference, q.unit_price_inr, q.gst_percent, s.supplier_legal_name
                                     FROM purchase_requirements pr JOIN orders o ON o.id = pr.order_id
                                     LEFT JOIN purchase_quotes q ON q.id = pr.awarded_quote_id LEFT JOIN suppliers s ON s.id = q.supplier_id
                                    WHERE pr.status = 'awarded' AND pr.approval_needed = 1 AND pr.awarded_quote_id IS NOT NULL{$vis} ORDER BY pr.awarded_at, pr.id");
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $value = PurchasingService::awardValue(['unit_price_inr' => $r['unit_price_inr'], 'gst_percent' => $r['gst_percent']], (float) $r['quantity']);
                if (PurchasingService::approvalRefusal($userId, $roleId, $r, $value) !== null) {
                    continue;
                }
                $out[] = self::row('purchase:' . $r['id'], 'Purchase approval', (string) $r['awarded_at'], (int) $r['order_id'], (string) $r['order_reference'], 'PR-' . (int) $r['id'],
                    (string) $r['supplier_legal_name'] . ' · ₹' . number_format($value, 2) . ' · ' . self::short((string) $r['description']),
                    '/procurement/' . (int) $r['id'], 'Review purchase', 'purchase PR-' . (int) $r['id'] . ' (' . $r['supplier_legal_name'] . ') waits for approval.');
            }
        }

        if ($can('approve_short_payment')) {
            foreach (self::shortRequests($vis, $params) as $s) {
                if ((int) $s['requested_by'] === $userId) {
                    continue; // asked because they could not clear it themselves
                }
                if (PartPaymentService::recordedBy((int) $s['order_id'], (string) $s['leg'], (int) $s['milestone_id'] > 0 ? (int) $s['milestone_id'] : null, $userId)
                    && !MakerCheckerGuard::selfApprovalAllowed('short_payment', $userId)) {
                    continue;
                }
                $label = PartPaymentService::label((string) $s['leg'], (int) $s['milestone_id'] > 0 ? ['seq' => $s['milestone_seq']] : null);
                $out[] = self::row('short:' . $s['id'], 'Short payment', (string) $s['requested_at'], (int) $s['order_id'], (string) $s['order_reference'],
                    !empty($s['requested_by_name']) ? 'asked by ' . $s['requested_by_name'] : '',
                    $label . ': ' . PartPaymentService::money((string) $s['currency_code'], (float) $s['owed']) . ' still owed — clear it short with a reason, or wait for the rest',
                    '/orders/' . (int) $s['order_id'] . '#' . (self::LEG_ANCHORS[(string) $s['leg']] ?? 'payment-overview'), 'Review payment',
                    strtolower($label) . ' is short by ' . PartPaymentService::money((string) $s['currency_code'], (float) $s['owed']) . ' — clear it short with a reason, or wait for the rest.');
            }
        }

        if ($can('manage_associates') && DivisionService::moduleEnabled('associates')) {
            foreach ($pdo->query("SELECT id, name, country, based_in, created_at FROM associate_registrations WHERE status = 'pending' ORDER BY created_at, id")->fetchAll() as $r) {
                $out[] = self::row('registration:' . $r['id'], 'Associate registration', (string) $r['created_at'], null, '', 'Associate · ' . $r['name'],
                    'Based in ' . ($r['based_in'] === 'india' ? 'India' : 'abroad') . (!empty($r['country']) ? ' · ' . $r['country'] : ''),
                    '/associates/registrations', 'Review registration', '');
            }
        }

        if ($can('manage_pricing') && DivisionService::moduleEnabled('associates')) {
            $rows = $pdo->query("SELECT r.id, r.number, r.requested_at, r.selection_text, p.name AS product_name, a.name AS associate_name
                                   FROM price_requests r JOIN catalog_products p ON p.id = r.product_id JOIN associates a ON a.id = r.associate_id
                                  WHERE r.status = 'pending'" . DivisionService::sql('r.division_id') . ' ORDER BY r.requested_at, r.id')->fetchAll();
            foreach ($rows as $r) {
                $out[] = self::row('price_request:' . $r['id'], 'Associate price request', (string) $r['requested_at'], null, '', 'Associate · ' . $r['associate_name'],
                    $r['number'] . ' · ' . $r['product_name'] . (trim((string) $r['selection_text']) !== '' ? ' · ' . $r['selection_text'] : ''),
                    '/associates/price-requests?show=pending', 'Give a price', '');
            }
        }

        if ($can('manage_field_protection')) {
            $isSuper = SuperAdminService::isEffective($userId);
            $rows = $pdo->query("SELECT f.id, f.record_label, f.table_name, f.requested_action, f.reason, f.requested_by, f.created_at, u.name AS requested_by_name
                                   FROM field_protection_requests f LEFT JOIN users u ON u.id = f.requested_by WHERE f.status = 'pending' ORDER BY f.created_at, f.id")->fetchAll();
            foreach ($rows as $f) {
                if ((int) $f['requested_by'] === $userId && !$isSuper) {
                    continue;
                }
                $out[] = self::row('protection:' . $f['id'], $f['requested_action'] === 'lock' ? 'Protect a field' : 'Unprotect a field', (string) $f['created_at'], null, '',
                    !empty($f['requested_by_name']) ? 'asked by ' . $f['requested_by_name'] : '', $f['record_label'] . ' · ' . self::short((string) $f['reason']),
                    '/admin/field-protection', 'Review request', '');
            }
        }

        if ($can('manage_orders')) {
            $stmt = $pdo->prepare("SELECT rr.id, rr.source_order_id, rr.submitted_at, rr.notes, o.order_reference
                                     FROM order_reorder_requests rr JOIN orders o ON o.id = rr.source_order_id
                                    WHERE rr.status = 'pending'{$vis} ORDER BY rr.submitted_at, rr.id");
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                $out[] = self::row('reorder:' . $r['id'], 'Reorder request', (string) $r['submitted_at'], null, (string) $r['order_reference'], 'from the buyer',
                    self::short((string) ($r['notes'] ?? '')), '/reorder-requests/' . (int) $r['id'], 'Review reorder', '');
            }
        }
        return $out;
    }

    /** @return array<string,mixed> one row of the Other tab */
    private static function row(string $key, string $what, string $since, ?int $orderId, string $orderRef, string $who, string $details, string $url, string $button, string $bar): array
    {
        return ['key' => $key, 'tab' => 'other', 'what' => $what, 'tone' => 'amber', 'since' => $since, 'order_id' => $orderId, 'order_ref' => $orderRef,
            'line_no' => null, 'who' => $who, 'details' => $details, 'url' => $url, 'button' => $button,
            'bar' => $bar, 'bar_button' => $bar !== '' ? $button : ''];
    }

    private static function short(string $text, int $max = 90): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    // ------------------------------------------------- short payment requests

    /**
     * D-310: a payment that came in short waits for a manager with "Approve
     * short payment". Until now the person clearing it was only refused; the
     * refusal now also files the request (one per payment leg, refreshed when
     * asked again), and every manager who may clear it short is told once.
     */
    public static function recordShortPaymentRequest(int $orderId, string $leg, ?array $milestone, int $userId, float $owed, string $currency): void
    {
        $milestoneId = $milestone !== null ? (int) $milestone['id'] : 0;
        $pdo = Database::connection();
        $existing = $pdo->prepare('SELECT id FROM short_payment_requests WHERE order_id = :o AND leg = :l AND milestone_id = :m');
        $existing->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestoneId]);
        $id = $existing->fetchColumn();
        if ($id !== false && self::shortRequestOpen((int) $id)) {
            $pdo->prepare('UPDATE short_payment_requests SET owed_amount = :a WHERE id = :id')->execute(['a' => round($owed, 2), 'id' => (int) $id]);
            return;
        }
        $pdo->prepare('INSERT INTO short_payment_requests (order_id, leg, milestone_id, owed_amount, currency_code, requested_by, requested_at)
                       VALUES (:o, :l, :m, :a, :c, :u, NOW())
                       ON DUPLICATE KEY UPDATE owed_amount = VALUES(owed_amount), currency_code = VALUES(currency_code), requested_by = VALUES(requested_by), requested_at = NOW()')
            ->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestoneId, 'a' => round($owed, 2), 'c' => $currency !== '' ? $currency : null, 'u' => $userId]);
        $order = OrderRepository::find($orderId);
        $label = PartPaymentService::label($leg, $milestone);
        $message = 'Order ' . ($order['order_reference'] ?? "#{$orderId}") . ': ' . $label . ' is short by ' . PartPaymentService::money($currency, $owed)
            . ' and waits for your approval to clear it short (Approvals).';
        foreach (PermissionRepository::usersWithPermission('approve_short_payment') as $managerId) {
            if ((int) $managerId !== $userId) {
                NotificationRepository::create((int) $managerId, null, 'short_payment_approval', $orderId, mb_substr($message, 0, 500));
            }
        }
        \App\Repositories\AuditLogRepository::log($userId, 'SHORT_PAYMENT_APPROVAL_REQUESTED', 'orders', $orderId, $leg . ($milestone !== null ? ':' . ($milestone['seq'] ?? '') : ''), null, (string) round($owed, 2), $message);
        self::resetCache();
    }

    /** Open: the leg is still short by more than the tolerance and nobody has cleared it short since it was asked. */
    public static function shortRequestOpen(int $requestId): bool
    {
        $stmt = Database::connection()->prepare('SELECT r.*, o.status AS order_status FROM short_payment_requests r JOIN orders o ON o.id = r.order_id WHERE r.id = :id');
        $stmt->execute(['id' => $requestId]);
        $r = $stmt->fetch();
        if (!$r || in_array($r['order_status'], ['cancelled', 'lost'], true)) {
            return false;
        }
        return self::shortOwed($r) !== null;
    }

    /** @return float|null what is still owed, or null when the request is answered */
    private static function shortOwed(array $r): ?float
    {
        $milestone = null;
        if ((int) $r['milestone_id'] > 0) {
            $milestone = \App\Repositories\PaymentMilestoneRepository::find((int) $r['milestone_id']);
            if ($milestone === null) {
                return null;
            }
        }
        $s = PartPaymentService::summary((int) $r['order_id'], (string) $r['leg'], $milestone);
        if ($s['owed'] === null || $s['owed'] <= PartPaymentService::TOLERANCE) {
            return null;
        }
        if ($s['shortfall'] !== null && (string) $s['shortfall']['approved_at'] >= (string) $r['requested_at']) {
            return null;
        }
        return (float) $s['owed'];
    }

    /**
     * @param array<string,int> $params
     * @return list<array<string,mixed>> the open short payment requests of the orders this person may see
     */
    private static function shortRequests(string $vis, array $params): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT r.*, o.order_reference, o.status AS order_status, u.name AS requested_by_name, m.seq AS milestone_seq
               FROM short_payment_requests r JOIN orders o ON o.id = r.order_id
               LEFT JOIN users u ON u.id = r.requested_by
               LEFT JOIN order_payment_milestones m ON m.id = r.milestone_id
              WHERE o.status NOT IN ('cancelled', 'lost'){$vis} ORDER BY r.requested_at, r.id"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $owed = self::shortOwed($r);
            if ($owed !== null) {
                $out[] = $r + ['owed' => $owed];
            }
        }
        return $out;
    }
}
