<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderCommentRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\UserRepository;

/**
 * docs/schema.sql Section AI — the order progress chat. A staff post is
 * immediately emailed to the client (attachments included, up to
 * MAX_DIRECT_ATTACH_BYTES combined — anything larger becomes a portal
 * download link instead, so a big video can never silently cause the
 * whole send to be rejected by the mail transport). A client post never
 * emails the client back; it raises an in-app notification to every user
 * with manage_orders instead.
 */
final class OrderCommentService
{
    /**
     * Combined size cap for attachments sent directly on the email itself.
     * Kept comfortably under typical SMTP/Zoho Mail attachment limits
     * (usually ~20-25MB before MIME base64 overhead inflates it further).
     */
    private const MAX_DIRECT_ATTACH_BYTES = 15 * 1024 * 1024;

    public static function postAsStaff(int $orderId, int $authorUserId, ?string $body, array $fileStoreIds): int
    {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }

        $bodyTrim = self::normalizeBody($body, $fileStoreIds);
        $commentId = OrderCommentRepository::create($orderId, 'staff', $authorUserId, null, $bodyTrim);
        foreach ($fileStoreIds as $fileId) {
            OrderCommentRepository::attachFile($commentId, $fileId);
        }

        if (!empty($order['client_email'])) {
            self::emailClient($order, $authorUserId, $commentId, $bodyTrim, $fileStoreIds);
        }

        AuditLogRepository::log($authorUserId, 'ORDER_COMMENT_POSTED', 'orders', $orderId);
        return $commentId;
    }

    public static function postAsClient(int $orderId, int $clientId, ?string $body, array $fileStoreIds): int
    {
        $bodyTrim = self::normalizeBody($body, $fileStoreIds);
        $commentId = OrderCommentRepository::create($orderId, 'client', null, $clientId, $bodyTrim);
        foreach ($fileStoreIds as $fileId) {
            OrderCommentRepository::attachFile($commentId, $fileId);
        }

        $order = OrderRepository::find($orderId);
        $orderRef = $order['order_reference'] ?? "#{$orderId}";
        foreach (PermissionRepository::usersWithPermission('manage_orders') as $userId) {
            NotificationRepository::create($userId, null, 'order_comment_from_client', $orderId, "New client message on order {$orderRef}.");
        }

        AuditLogRepository::log(null, 'ORDER_COMMENT_POSTED', 'orders', $orderId);
        return $commentId;
    }

    private static function normalizeBody(?string $body, array $fileStoreIds): ?string
    {
        $trimmed = $body !== null ? trim($body) : null;
        if (($trimmed === null || $trimmed === '') && empty($fileStoreIds)) {
            throw new \RuntimeException('Write a message or attach a file before posting.');
        }
        return $trimmed !== '' ? $trimmed : null;
    }

    private static function emailClient(array $order, int $authorUserId, int $commentId, ?string $body, array $fileStoreIds): void
    {
        $sender = UserRepository::findById($authorUserId);
        $subject = "Update on your order {$order['order_reference']} — " . (string) CompanySettingsRepository::get('legal_name');

        $greeting = 'Dear ' . ($order['contact_person'] ?: 'Sir/Madam') . ",\n\n";
        $message = $greeting . ($body ?? 'Please see the attached file(s).') . "\n\n";

        $direct = [];
        $linked = [];
        $totalSize = 0;
        foreach ($fileStoreIds as $fileId) {
            $file = FileStoreRepository::find($fileId);
            if (!$file) {
                continue;
            }
            $size = (int) $file['file_size_bytes'];
            if ($totalSize + $size <= self::MAX_DIRECT_ATTACH_BYTES) {
                $direct[] = ['path' => $file['server_path'], 'name' => $file['original_filename']];
                $totalSize += $size;
            } else {
                $linked[] = $file;
            }
        }

        if ($linked) {
            $message .= "The following file(s) are too large to attach directly to this email — view or download them securely from your order portal:\n";
            $baseUrl = rtrim(Env::get('APP_URL', ''), '/');
            foreach ($linked as $file) {
                $message .= "- {$file['original_filename']}: {$baseUrl}/client/orders/{$order['id']}/comment-attachments/{$file['id']}/download\n";
            }
            $message .= "\n";
        }

        $signature = trim((string) ($sender['email_signature'] ?? ''));
        $message .= $signature !== ''
            ? $signature
            : "Regards,\n" . ($sender['name'] ?? '') . "\n" . (string) CompanySettingsRepository::get('md_title');

        $sent = MailSenderService::send($order['client_email'], $subject, $message, $direct);
        if ($sent) {
            OrderCommentRepository::markEmailSent($commentId);
        }
    }
}
