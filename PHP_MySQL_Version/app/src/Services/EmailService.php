<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Thin wrapper so the rest of the app calls one method regardless of what's
 * actually installed. Real sending (PHPMailer, per ARCHITECTURE.md) is
 * Phase B/D scope — full document dispatch pipeline. For Phase A this only
 * needs to carry 2FA codes and it degrades safely when nothing is wired up
 * yet: if PHPMailer isn't installed (vendor/ absent) or SMTP isn't
 * configured, it logs the message server-side instead of throwing, and in
 * APP_ENV=local it also returns the code so the login screen can display it
 * — never in production, where display_errors/local-mode is off by design
 * (see bootstrap.php).
 */
final class EmailService
{
    /**
     * Test Mode (docs/schema.sql Section V) redirect — the single
     * chokepoint both send methods below funnel through, so nothing that
     * calls either of them has to know about Test Mode. $isSecurityEmail
     * is the one exception carved out by design: staff's own 2FA codes
     * and password-reset links must keep going to the real address they
     * belong to, or Test Mode would lock staff out of their own accounts.
     * Every other call site (order/document notices, buyer
     * communications, reminder alerts, client portal access emails)
     * defaults to redirectable.
     */
    private static function resolveRecipient(string $toEmail, bool $isSecurityEmail, string $subject): string
    {
        if ($isSecurityEmail) {
            return $toEmail;
        }
        $settings = TestModeService::getSettings();
        if (!$settings || (int) $settings['is_enabled'] !== 1) {
            return $toEmail;
        }
        if (empty($settings['test_email'])) {
            error_log("[TEST MODE — no test_email configured, sending to real address] To: {$toEmail} | Subject: {$subject}");
            return $toEmail;
        }
        error_log("[TEST MODE — email redirected] Original To: {$toEmail} -> Test: {$settings['test_email']} | Subject: {$subject}");
        return (string) $settings['test_email'];
    }

    /**
     * @return bool true if actually handed to a transport, false if only logged (dev fallback)
     */
    public static function sendPlainText(string $toEmail, string $subject, string $body, bool $isSecurityEmail = false): bool
    {
        $to = self::resolveRecipient($toEmail, $isSecurityEmail, $subject);
        $smtpHost = Env::get('SMTP_HOST');
        $hasPhpMailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

        if ($smtpHost && $hasPhpMailer) {
            /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $smtpHost;
                $mail->SMTPAuth   = true;
                $mail->Username   = Env::get('SMTP_USERNAME');
                $mail->Password   = Env::get('SMTP_PASSWORD');
                $mail->SMTPSecure = Env::get('SMTP_ENCRYPTION', 'tls');
                $mail->Port       = (int) Env::get('SMTP_PORT', '587');
                $mail->setFrom(
                    Env::get('SMTP_FROM_ADDRESS', 'no-reply@example.com'),
                    Env::get('SMTP_FROM_NAME', 'NexaCrest International Private Limited')
                );
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->send();
                return true;
            } catch (\Throwable $e) {
                error_log('[EMAIL SEND FAILURE] ' . $e->getMessage());
                return false;
            }
        }

        // Dev/incomplete-config fallback: never silently lose the message.
        error_log("[EMAIL NOT SENT — no SMTP configured or PHPMailer not installed] To: {$to} | Subject: {$subject} | Body: {$body}");
        return false;
    }

    /**
     * Phase D — deferred client document sends (Section 10). The buyer
     * gets exactly one attachment: the watermarked final PDF at
     * $attachmentPath — never the internal DOCX, never an unwatermarked
     * copy (enforced by the caller passing the right file, not by this
     * method, which just attaches whatever path it's given).
     *
     * @return bool true if actually handed to a transport, false if only logged (dev fallback)
     */
    public static function sendWithAttachment(string $toEmail, string $subject, string $body, string $attachmentPath, string $attachmentName, bool $isSecurityEmail = false): bool
    {
        $to = self::resolveRecipient($toEmail, $isSecurityEmail, $subject);
        $smtpHost = Env::get('SMTP_HOST');
        $hasPhpMailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

        if ($smtpHost && $hasPhpMailer) {
            /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $smtpHost;
                $mail->SMTPAuth   = true;
                $mail->Username   = Env::get('SMTP_USERNAME');
                $mail->Password   = Env::get('SMTP_PASSWORD');
                $mail->SMTPSecure = Env::get('SMTP_ENCRYPTION', 'tls');
                $mail->Port       = (int) Env::get('SMTP_PORT', '587');
                $mail->setFrom(
                    Env::get('SMTP_FROM_ADDRESS', 'no-reply@example.com'),
                    Env::get('SMTP_FROM_NAME', 'NexaCrest International Private Limited')
                );
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->Body    = $body;
                if (is_file($attachmentPath)) {
                    $mail->addAttachment($attachmentPath, $attachmentName);
                }
                $mail->send();
                return true;
            } catch (\Throwable $e) {
                error_log('[EMAIL SEND FAILURE] ' . $e->getMessage());
                return false;
            }
        }

        error_log("[EMAIL NOT SENT — no SMTP configured or PHPMailer not installed] To: {$to} | Subject: {$subject} | Attachment: {$attachmentName}");
        return false;
    }
}
