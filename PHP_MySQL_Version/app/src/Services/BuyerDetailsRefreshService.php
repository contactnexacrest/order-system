<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderBuyerDetailRequestRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\UserRepository;

/**
 * Owner decision D-337 (12 Nov 2026, "will go with recommendation"): an order
 * keeps its own copy of the buyer's details (rule R25), but that copy now
 * follows the client where it safely can.
 *
 * Case 1 — automatic. When staff save a client (client edit form, or a PI-stage
 * confirmation accepted), every RUNNING order of that client (not cancelled,
 * lost or complete) with NOTHING ISSUED takes the client's current details at
 * once (OrderRepository::applyClientSnapshot), only when a field really differs.
 * Audited per order and field (ORDER_BUYER_DETAILS_REFRESHED); the holders of
 * approve_buyer_details_change get an FYI notification; no approval needed.
 *
 * "Issued" (hasIssuedDocument): any document of the order, of any type, that is
 * approved or sent, or superseded after having been approved or sent. A draft
 * or a document still in review is not issued; a deleted draft no longer
 * exists; a document superseded straight from draft / review (audit
 * DOCUMENT_SUPERSEDED with old value draft or in_review) does not count.
 * A superseded document without that audit row counts as issued (safe side).
 *
 * Case 2 — with approval. An order with an issued document shows the
 * differences and "Ask to update buyer details from the client" (manage_orders,
 * reason 10–500 characters). One PENDING request per order. A holder of
 * approve_buyer_details_change approves or refuses it (refusal needs a note of
 * 10+ characters); nobody decides their own request unless Admin › Self-approval
 * rules "buyer_details_change" allows it (default MD only). If the client's
 * details changed after the request (hash of the snapshot), the request LAPSES
 * and nothing is applied — also when the client is saved again while it waits.
 * Issued documents never change: they keep their stored render context and
 * files; only documents generated afterwards show the new details.
 *
 * Node twin: src/services/buyerDetailsRefreshService.js (same wording).
 */
final class BuyerDetailsRefreshService
{
    public const PERMISSION = 'approve_buyer_details_change';
    public const RULE = 'buyer_details_change';
    public const REASON_MIN = 10;
    public const REASON_MAX = 500;

    /** The order's snapshot columns, in the order they are listed, with plain names. */
    public const LABELS = [
        'company_legal_name'             => 'Company legal name',
        'billing_address'                => 'Billing address',
        'billing_address_line1'          => 'Billing address line 1',
        'billing_address_line2'          => 'Billing address line 2',
        'billing_city'                   => 'Billing city',
        'billing_postcode'               => 'Billing postcode',
        'billing_country'                => 'Billing country',
        'vat_eori_tax_no'                => 'VAT / EORI / tax no.',
        'contact_person'                 => 'Contact person',
        'client_email'                   => 'Email',
        'client_phone'                   => 'Phone',
        'country_of_destination'         => 'Country of destination',
        'consignee_same_as_buyer'        => 'Consignee same as buyer',
        'consignee_name'                 => 'Consignee name',
        'consignee_address'              => 'Consignee address',
        'consignee_address_line1'        => 'Consignee address line 1',
        'consignee_address_line2'        => 'Consignee address line 2',
        'consignee_city'                 => 'Consignee city',
        'consignee_postcode'             => 'Consignee postcode',
        'consignee_country'              => 'Consignee country',
        'consignee_vat_eori_tax_no'      => 'Consignee VAT / EORI / tax no.',
        'consignee_contact_person'       => 'Consignee contact person',
        'consignee_phone'                => 'Consignee phone',
        'consignee_email'                => 'Consignee email',
        'notify_party_same_as_consignee' => 'Notify party same as consignee',
        'notify_party'                   => 'Notify party',
        'notify_party_address_line1'     => 'Notify party address line 1',
        'notify_party_address_line2'     => 'Notify party address line 2',
        'notify_party_city'              => 'Notify party city',
        'notify_party_postcode'          => 'Notify party postcode',
        'notify_party_country'           => 'Notify party country',
        'notify_party_contact_person'    => 'Notify party contact person',
        'notify_party_phone'             => 'Notify party phone',
        'notify_party_email'             => 'Notify party email',
    ];

    private const FLAGS = ['consignee_same_as_buyer', 'notify_party_same_as_consignee'];

    /** Order statuses that never change automatically or by request. */
    private const CLOSED_FOR_AUTO = ['cancelled', 'lost', 'complete'];
    private const CLOSED_FOR_REQUEST = ['cancelled', 'lost'];

    public const MSG_ALREADY_WAITING = 'A request to update the buyer details is already waiting for approval.';
    public const MSG_LAPSED = 'The client\'s details changed again after this request — ask again so the approver sees the current details.';
    public const MSG_SELF = 'You asked for this change — someone else must approve it (Admin › Self-approval rules).';
    public const MSG_NOT_WAITING = 'This request is no longer waiting for approval.';
    public const MSG_NOTHING = 'The order already shows the client\'s current details — nothing to update.';

    // ---------------------------------------------------------------- comparing

    private static function norm(mixed $v): string
    {
        return $v === null ? '' : (string) $v;
    }

    /** How a value reads on screen and in messages. */
    public static function display(string $field, mixed $v): string
    {
        if (in_array($field, self::FLAGS, true)) {
            return (int) $v === 1 ? 'Yes' : 'No';
        }
        $s = trim(self::norm($v));
        return $s === '' ? '(blank)' : $s;
    }

    /**
     * The snapshot fields where the order differs from the client now.
     * @return list<array{field:string,label:string,old:?string,new:?string}>
     */
    public static function differences(array $order, array $snapshot): array
    {
        $out = [];
        foreach (self::LABELS as $field => $label) {
            $old = $order[$field] ?? null;
            $new = $snapshot[$field] ?? null;
            if (in_array($field, self::FLAGS, true) ? (int) ($old ?? 1) === (int) ($new ?? 1) : self::norm($old) === self::norm($new)) {
                continue;
            }
            $out[] = ['field' => $field, 'label' => $label, 'old' => $old === null ? null : (string) $old, 'new' => $new === null ? null : (string) $new];
        }
        return $out;
    }

    /** "Email a@b.com → x@y.com; Phone (blank) → +91-0000000000" */
    public static function changesText(array $changes): string
    {
        return implode('; ', array_map(static fn (array $c): string => $c['label'] . ': ' . self::display((string) $c['field'], $c['old']) . ' → ' . self::display((string) $c['field'], $c['new']), $changes));
    }

    public static function snapshotHash(array $snapshot): string
    {
        $vals = [];
        foreach (array_keys(self::LABELS) as $field) {
            $vals[$field] = self::norm($snapshot[$field] ?? null);
        }
        return hash('sha256', (string) json_encode($vals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** See the class docblock for what "issued" means. */
    public static function hasIssuedDocument(int $orderId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM documents d
              WHERE d.order_id = :o
                AND (d.status IN ('approved', 'sent')
                     OR (d.status = 'superseded' AND NOT EXISTS (
                           SELECT 1 FROM audit_log a
                            WHERE a.action_type = 'DOCUMENT_SUPERSEDED' AND a.entity_type = 'documents'
                              AND a.entity_id = d.id AND a.old_value IN ('draft', 'in_review'))))
              LIMIT 1"
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * What the order page shows: the differences, whether a document is issued,
     * and the waiting request (if any). Null when there is nothing to show.
     * @return ?array{changes:list<array<string,mixed>>, issued:bool, pending:?array}
     */
    public static function noticeFor(array $order): ?array
    {
        $pending = OrderBuyerDetailRequestRepository::pendingForOrder((int) $order['id']);
        if (in_array((string) $order['status'], self::CLOSED_FOR_REQUEST, true) && $pending === null) {
            return null;
        }
        $changes = self::differences($order, OrderRepository::clientSnapshot((int) $order['client_id']));
        if ($changes === [] && $pending === null) {
            return null;
        }
        if ($pending !== null) {
            $pending['changes'] = json_decode((string) $pending['changes_json'], true) ?: [];
        }
        return ['changes' => $changes, 'issued' => self::hasIssuedDocument((int) $order['id']), 'pending' => $pending];
    }

    // ---------------------------------------------------------------- applying

    /**
     * Copies the client's details onto the order and audits each changed field.
     * @return list<array<string,mixed>> the fields that changed
     */
    private static function apply(array $order, array $snapshot, ?int $userId, string $source): array
    {
        $changes = self::differences($order, $snapshot);
        if ($changes === []) {
            return [];
        }
        OrderRepository::applyClientSnapshot((int) $order['id'], (int) $order['client_id']);
        foreach ($changes as $c) {
            AuditLogRepository::log($userId, 'ORDER_BUYER_DETAILS_REFRESHED', 'orders', (int) $order['id'], (string) $c['field'], $c['old'], $c['new'], 'Source: ' . $source);
        }
        return $changes;
    }

    private static function userName(?int $userId): string
    {
        return $userId !== null ? (string) (UserRepository::findById($userId)['name'] ?? 'someone') : 'someone';
    }

    /** @return list<int> holders of approve_buyer_details_change, without $exceptUserId */
    private static function approvers(?int $exceptUserId): array
    {
        return array_values(array_filter(array_map('intval', PermissionRepository::usersWithPermission(self::PERMISSION)), static fn (int $id): bool => $id !== $exceptUserId));
    }

    /**
     * Case 1, plus lapsing waiting requests: called right after a client is saved.
     * @return array{refreshed:list<string>, lapsed:list<string>} order references
     */
    public static function afterClientSaved(int $clientId, ?int $userId, string $source): array
    {
        $snapshot = OrderRepository::clientSnapshot($clientId);
        $hash = self::snapshotHash($snapshot);
        $lapsed = [];
        foreach (OrderBuyerDetailRequestRepository::pendingForClient($clientId) as $r) {
            if ((string) $r['client_snapshot_hash'] !== $hash && self::lapse($r, $userId, 'the client was saved again (' . $source . ')')) {
                $lapsed[] = (string) $r['order_reference'];
            }
        }

        $stmt = Database::connection()->prepare(
            "SELECT id FROM orders WHERE client_id = :c AND status NOT IN ('cancelled', 'lost', 'complete') ORDER BY id"
        );
        $stmt->execute(['c' => $clientId]);
        $refreshed = [];
        $who = self::userName($userId);
        $clientName = (string) ($snapshot['company_legal_name'] ?? (ClientRepository::find($clientId)['company_legal_name'] ?? ''));
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $orderId) {
            $order = OrderRepository::find((int) $orderId);
            if ($order === null || in_array((string) $order['status'], self::CLOSED_FOR_AUTO, true) || self::hasIssuedDocument((int) $orderId)) {
                continue;
            }
            $changes = self::apply($order, $snapshot, $userId, $source);
            if ($changes === []) {
                continue;
            }
            $refreshed[] = (string) $order['order_reference'];
            $message = 'Buyer details of ' . $clientName . ' changed by ' . $who . ' — also updated on draft order ' . $order['order_reference'] . ': ' . self::changesText($changes);
            foreach (self::approvers($userId) as $approverId) {
                NotificationRepository::create($approverId, null, 'buyer_details_refreshed', (int) $orderId, $message);
            }
        }
        return ['refreshed' => $refreshed, 'lapsed' => $lapsed];
    }

    /** The flash sentence for the client page ('' when no order changed). */
    public static function flashText(array $result): string
    {
        $text = '';
        if ($result['refreshed'] !== []) {
            $text .= ' Buyer details also updated on draft order(s): ' . implode(', ', $result['refreshed']) . '.';
        }
        if ($result['lapsed'] !== []) {
            $text .= ' A waiting request to update the buyer details lapsed on order(s) ' . implode(', ', $result['lapsed']) . ' — ask again so the approver sees the current details.';
        }
        return $text;
    }

    /**
     * "Update buyer details from the client" on an order with nothing issued (no approval needed).
     * @return array{error:?string, changes:list<array<string,mixed>>}
     */
    public static function refreshOrder(int $orderId, int $userId): array
    {
        $order = OrderRepository::find($orderId);
        if ($order === null || in_array((string) $order['status'], self::CLOSED_FOR_AUTO, true)) {
            return ['error' => 'The buyer details of this order can no longer be updated.', 'changes' => []];
        }
        if (self::hasIssuedDocument($orderId)) {
            return ['error' => 'This order already has an issued document — ask for approval to update the buyer details.', 'changes' => []];
        }
        $snapshot = OrderRepository::clientSnapshot((int) $order['client_id']);
        $changes = self::apply($order, $snapshot, $userId, 'order page (nothing issued yet)');
        if ($changes === []) {
            return ['error' => self::MSG_NOTHING, 'changes' => []];
        }
        $message = 'Buyer details of ' . ($snapshot['company_legal_name'] ?? '') . ' updated by ' . self::userName($userId) . ' on draft order ' . $order['order_reference'] . ': ' . self::changesText($changes);
        foreach (self::approvers($userId) as $approverId) {
            NotificationRepository::create($approverId, null, 'buyer_details_refreshed', $orderId, $message);
        }
        return ['error' => null, 'changes' => $changes];
    }

    // ---------------------------------------------------------------- requests

    public static function reasonError(string $reason, string $what = 'reason'): ?string
    {
        $len = mb_strlen(trim($reason));
        if ($len < self::REASON_MIN || $len > self::REASON_MAX) {
            return 'Give the ' . $what . ' (' . self::REASON_MIN . ' to ' . self::REASON_MAX . ' characters).';
        }
        return null;
    }

    /** Case 2: ask to copy the client's details onto an order with an issued document. Returns the error or null. */
    public static function request(int $orderId, int $userId, string $reason): ?string
    {
        $reason = trim($reason);
        if (($e = self::reasonError($reason)) !== null) {
            return $e;
        }
        $order = OrderRepository::find($orderId);
        if ($order === null || in_array((string) $order['status'], self::CLOSED_FOR_REQUEST, true)) {
            return 'The buyer details of this order can no longer be updated.';
        }
        if (OrderBuyerDetailRequestRepository::pendingForOrder($orderId) !== null) {
            return self::MSG_ALREADY_WAITING;
        }
        $snapshot = OrderRepository::clientSnapshot((int) $order['client_id']);
        $changes = self::differences($order, $snapshot);
        if ($changes === []) {
            return self::MSG_NOTHING;
        }
        if (!self::hasIssuedDocument($orderId)) {
            return 'Nothing has been issued on this order yet — use "Update buyer details from the client" (no approval needed).';
        }
        $id = OrderBuyerDetailRequestRepository::create($orderId, $userId, $reason, (string) json_encode($changes, JSON_UNESCAPED_UNICODE), self::snapshotHash($snapshot));
        if ($id === 0) {
            return self::MSG_ALREADY_WAITING;
        }
        AuditLogRepository::log($userId, 'BUYER_DETAILS_CHANGE_REQUESTED', 'orders', $orderId, 'buyer_details', null, self::changesText($changes), 'Request #' . $id . ' — ' . $reason);
        $message = 'Approval needed: update the buyer details of order ' . $order['order_reference'] . ' (' . ($snapshot['company_legal_name'] ?? '') . ') from the client — asked by '
            . self::userName($userId) . ': ' . self::changesText($changes) . '. Reason: ' . $reason;
        foreach (self::approvers($userId) as $approverId) {
            NotificationRepository::create($approverId, null, 'buyer_details_change_requested', $orderId, $message);
        }
        return null;
    }

    /** Whether this person may decide this request (permission is checked by the route). */
    public static function selfDecisionRefused(array $request, int $userId): bool
    {
        return $request['requested_by'] !== null && (int) $request['requested_by'] === $userId && !SelfApprovalRules::allowed(self::RULE, $userId);
    }

    private static function loadPending(int $requestId, int $orderId): array
    {
        $r = OrderBuyerDetailRequestRepository::find($requestId);
        if ($r === null || (int) $r['order_id'] !== $orderId || $r['status'] !== 'PENDING') {
            return [null, self::MSG_NOT_WAITING];
        }
        return [$r, null];
    }

    private static function lapse(array $r, ?int $userId, string $why): bool
    {
        if (!OrderBuyerDetailRequestRepository::decide((int) $r['id'], 'LAPSED', $userId, 'Lapsed — ' . $why)) {
            return false;
        }
        $order = OrderRepository::find((int) $r['order_id']);
        AuditLogRepository::log($userId, 'BUYER_DETAILS_CHANGE_LAPSED', 'orders', (int) $r['order_id'], 'buyer_details', null, null, 'Request #' . $r['id'] . ' lapsed — ' . $why . '; nothing applied');
        if ($r['requested_by'] !== null) {
            NotificationRepository::create((int) $r['requested_by'], null, 'buyer_details_change_decided', (int) $r['order_id'],
                'Your request to update the buyer details of order ' . ($order['order_reference'] ?? '') . ' lapsed — the client\'s details changed again after it. Ask again so the approver sees the current details.');
        }
        return true;
    }

    /** Approve: applies the client's details, unless they changed since the request (then LAPSED). Returns the error or null. */
    public static function approve(int $requestId, int $orderId, int $userId, string $note): ?string
    {
        [$r, $err] = self::loadPending($requestId, $orderId);
        if ($r === null) {
            return $err;
        }
        if (self::selfDecisionRefused($r, $userId)) {
            return self::MSG_SELF;
        }
        $note = trim($note);
        if (mb_strlen($note) > self::REASON_MAX) {
            return 'Keep the note to ' . self::REASON_MAX . ' characters.';
        }
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return self::MSG_NOT_WAITING;
        }
        $snapshot = OrderRepository::clientSnapshot((int) $order['client_id']);
        if (self::snapshotHash($snapshot) !== (string) $r['client_snapshot_hash']) {
            self::lapse($r, $userId, 'the client\'s details changed after the request');
            return self::MSG_LAPSED;
        }
        if (!OrderBuyerDetailRequestRepository::decide($requestId, 'APPROVED', $userId, $note !== '' ? $note : null)) {
            return self::MSG_NOT_WAITING;
        }
        $changes = self::apply($order, $snapshot, $userId, 'approved request #' . $requestId);
        AuditLogRepository::log($userId, 'BUYER_DETAILS_CHANGE_APPROVED', 'orders', $orderId, 'buyer_details', null, self::changesText($changes), 'Request #' . $requestId . ($note !== '' ? ' — ' . $note : ''));
        if ($r['requested_by'] !== null) {
            NotificationRepository::create((int) $r['requested_by'], null, 'buyer_details_change_decided', $orderId,
                'Your request to update the buyer details of order ' . $order['order_reference'] . ' was approved by ' . self::userName($userId) . ' — documents generated from now on show the new details.');
        }
        return null;
    }

    /** Refuse with a note (10–500 characters). Returns the error or null. */
    public static function refuse(int $requestId, int $orderId, int $userId, string $note): ?string
    {
        [$r, $err] = self::loadPending($requestId, $orderId);
        if ($r === null) {
            return $err;
        }
        if (self::selfDecisionRefused($r, $userId)) {
            return self::MSG_SELF;
        }
        $note = trim($note);
        if (($e = self::reasonError($note, 'note saying why it is refused')) !== null) {
            return $e;
        }
        if (!OrderBuyerDetailRequestRepository::decide($requestId, 'REFUSED', $userId, $note)) {
            return self::MSG_NOT_WAITING;
        }
        $order = OrderRepository::find($orderId);
        AuditLogRepository::log($userId, 'BUYER_DETAILS_CHANGE_REFUSED', 'orders', $orderId, 'buyer_details', null, null, 'Request #' . $requestId . ' — ' . $note);
        if ($r['requested_by'] !== null) {
            NotificationRepository::create((int) $r['requested_by'], null, 'buyer_details_change_decided', $orderId,
                'Your request to update the buyer details of order ' . ($order['order_reference'] ?? '') . ' was refused by ' . self::userName($userId) . ': ' . $note);
        }
        return null;
    }
}
