<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderComplaintReplyRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\UserRepository;

/**
 * docs/schema.sql Section BX — a Complaint / Process Issue. Modeled on
 * OrderCommentService: a client-raised complaint or reply notifies
 * every manage_complaints/respond_to_complaints holder in-app (nobody
 * at NexaCrest gets emailed for their own staff's actions); a staff
 * reply is emailed directly to the client, the same as a staff order
 * comment, since the client has no in-app inbox of their own.
 */
final class OrderComplaintService
{
    private const STAFF_PERMISSIONS = ['manage_complaints', 'respond_to_complaints'];

    public static function raiseAsClient(int $orderId, int $clientId, string $description): int
    {
        $complaintId = \App\Repositories\OrderComplaintRepository::create($orderId, $clientId, $description);
        self::notifyStaff($orderId, 'complaint_raised', 'New process complaint on order %s.');
        AuditLogRepository::log(null, 'COMPLAINT_RAISED', 'order_complaints', $complaintId, null, null, $description, 'Raised by the client via the client portal.');
        return $complaintId;
    }

    public static function raiseAsStaff(int $orderId, int $clientId, int $authorUserId, string $description): int
    {
        $complaintId = \App\Repositories\OrderComplaintRepository::create($orderId, $clientId, $description);
        AuditLogRepository::log($authorUserId, 'COMPLAINT_RAISED', 'order_complaints', $complaintId, null, null, $description, 'Logged by staff on the client\'s behalf.');
        return $complaintId;
    }

    public static function replyAsStaff(int $complaintId, int $authorUserId, string $body): int
    {
        $replyId = OrderComplaintReplyRepository::createAsStaff($complaintId, $authorUserId, $body);
        self::emailClient($complaintId, $authorUserId, $body);
        AuditLogRepository::log($authorUserId, 'COMPLAINT_REPLY_POSTED', 'order_complaints', $complaintId, null, null, $body);
        return $replyId;
    }

    public static function replyAsClient(int $complaintId, int $clientId, string $body): int
    {
        $replyId = OrderComplaintReplyRepository::createAsClient($complaintId, $clientId, $body);
        $complaint = \App\Repositories\OrderComplaintRepository::find($complaintId);
        if ($complaint) {
            self::notifyStaff((int) $complaint['order_id'], 'complaint_reply_from_client', 'New client reply on a complaint for order %s.');
        }
        AuditLogRepository::log(null, 'COMPLAINT_REPLY_POSTED', 'order_complaints', $complaintId, null, null, $body, 'Replied by the client via the client portal.');
        return $replyId;
    }

    public static function updateStatus(int $complaintId, string $status, int $actingUserId): void
    {
        $complaint = \App\Repositories\OrderComplaintRepository::find($complaintId);
        \App\Repositories\OrderComplaintRepository::updateStatus($complaintId, $status);
        AuditLogRepository::log($actingUserId, 'COMPLAINT_STATUS_CHANGED', 'order_complaints', $complaintId, 'status', $complaint['status'] ?? null, $status);
    }

    private static function notifyStaff(int $orderId, string $type, string $messageTemplate): void
    {
        $order = OrderRepository::find($orderId);
        $orderRef = $order['order_reference'] ?? "#{$orderId}";
        $message = sprintf($messageTemplate, $orderRef);
        $notified = [];
        foreach (self::STAFF_PERMISSIONS as $permission) {
            foreach (PermissionRepository::usersWithPermission($permission) as $userId) {
                if (isset($notified[$userId])) {
                    continue;
                }
                $notified[$userId] = true;
                NotificationRepository::create($userId, null, $type, $orderId, $message);
            }
        }
    }

    private static function emailClient(int $complaintId, int $authorUserId, string $body): void
    {
        $complaint = \App\Repositories\OrderComplaintRepository::find($complaintId);
        if (!$complaint) {
            return;
        }
        $order = OrderRepository::find((int) $complaint['order_id']);
        if (!$order || empty($order['client_email'])) {
            return;
        }
        $sender = UserRepository::findById($authorUserId);
        $subject = "Update on your complaint for order {$order['order_reference']} — " . (string) \App\Repositories\CompanySettingsRepository::get('legal_name');
        $greeting = 'Dear ' . ($order['contact_person'] ?: 'Sir/Madam') . ",\n\n";
        $signature = trim((string) ($sender['email_signature'] ?? ''));
        $message = $greeting . $body . "\n\n" . ($signature !== ''
            ? $signature
            : "Regards,\n" . ($sender['name'] ?? '') . "\n" . (string) \App\Repositories\CompanySettingsRepository::get('md_title'));

        \App\Services\MailSenderService::send($order['client_email'], $subject, $message, []);
    }
}
