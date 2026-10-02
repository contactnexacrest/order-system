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
     * @return bool true if actually handed to a transport, false if only logged (dev fallback)
     */
    public static function sendPlainText(string $toEmail, string $subject, string $body, bool $isSecurityEmail = false): bool
    {
        $to = TestModeService::resolveEmailRecipient($toEmail, $isSecurityEmail, $subject);
        if ($to === null) {
            // QA-5 TM-07: Test Mode is on and no test_email is configured —
            // never fall back to sending this to the real address.
            return false;
        }
        $to = MailRedirectService::resolveRecipient($to, $isSecurityEmail);
        $cc = MailRedirectService::ccList($isSecurityEmail);
        $smtpHost = Env::get('SMTP_HOST');
        $hasPhpMailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

        if ($smtpHost && $hasPhpMailer) {
            /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                // QA-5 EML-07: PHPMailer defaults to iso-8859-1, which
                // garbles any non-ASCII character (accented buyer names,
                // currency symbols, etc.) in both the subject and body.
                $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
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
                foreach ($cc as $ccAddress) {
                    $mail->addCC($ccAddress);
                }
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
     * Settings "Send Test Email" button (docs/schema.sql Section AS).
     * Unlike sendPlainText()/sendWithAttachments(), this never swallows a
     * failure into a logged line and a false return — the whole point of
     * this entry point is letting an admin see the *actual* SMTP error
     * (bad credentials, connection refused, TLS mismatch, …) right on the
     * settings screen while they're configuring it, the same way
     * SettingsController::testZohoEmail() calls ZohoMailService::send()
     * directly rather than through MailSenderService's silent fallback.
     * Deliberately bypasses Test Mode and Mail Redirect entirely — this
     * confirms the raw SMTP_* credentials actually work, not a simulated
     * business email, so it must go to exactly the address the admin typed.
     *
     * @throws \RuntimeException if SMTP isn't configured or PHPMailer isn't installed
     * @throws \PHPMailer\PHPMailer\Exception if the send itself fails
     */
    public static function sendTestEmail(string $toEmail, string $subject, string $body): bool
    {
        $smtpHost = Env::get('SMTP_HOST');
        if (!$smtpHost) {
            throw new \RuntimeException('SMTP_HOST is not configured in .env.');
        }
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new \RuntimeException('PHPMailer is not installed (run composer install).');
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
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
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
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
        return self::sendWithAttachments($toEmail, $subject, $body, [['path' => $attachmentPath, 'name' => $attachmentName]], $isSecurityEmail);
    }

    /**
     * Order progress chat (docs/schema.sql Section AI) can carry several
     * images/videos on one comment — this is the general form
     * sendWithAttachment() above now delegates to.
     *
     * @param array<int, array{path:string, name:string}> $attachments
     * @return bool true if actually handed to a transport, false if only logged (dev fallback)
     */
    public static function sendWithAttachments(string $toEmail, string $subject, string $body, array $attachments, bool $isSecurityEmail = false): bool
    {
        $to = TestModeService::resolveEmailRecipient($toEmail, $isSecurityEmail, $subject);
        if ($to === null) {
            // QA-5 TM-07: see sendPlainText() above.
            return false;
        }
        $to = MailRedirectService::resolveRecipient($to, $isSecurityEmail);
        return self::deliverWithAttachments($to, $subject, $body, $attachments, MailRedirectService::ccList($isSecurityEmail));
    }

    /**
     * QA-5 TM-08: MailSenderService is the one caller that needs to resolve
     * the Test Mode recipient itself, BEFORE choosing Zoho vs. this SMTP
     * fallback — Zoho's own send() call has to see the resolved (possibly
     * redirected) address too, so the gate can't live only inside
     * sendWithAttachments() above. This is that already-resolved delivery
     * path; $to here is never re-checked against Test Mode.
     *
     * @param array<int, array{path:string, name:string}> $attachments
     * @param string[] $cc
     * @return bool true if actually handed to a transport, false if only logged (dev fallback)
     */
    public static function deliverWithAttachments(string $to, string $subject, string $body, array $attachments, array $cc = []): bool
    {
        $smtpHost = Env::get('SMTP_HOST');
        $hasPhpMailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

        if ($smtpHost && $hasPhpMailer) {
            /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                // QA-5 EML-07: see sendPlainText() above.
                $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
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
                foreach ($cc as $ccAddress) {
                    $mail->addCC($ccAddress);
                }
                $mail->Subject = $subject;
                $mail->Body    = $body;
                foreach ($attachments as $att) {
                    if (is_file($att['path'])) {
                        $mail->addAttachment($att['path'], $att['name']);
                    }
                }
                $mail->send();
                return true;
            } catch (\Throwable $e) {
                error_log('[EMAIL SEND FAILURE] ' . $e->getMessage());
                return false;
            }
        }

        $names = implode(', ', array_column($attachments, 'name'));
        error_log("[EMAIL NOT SENT — no SMTP configured or PHPMailer not installed] To: {$to} | Subject: {$subject} | Attachments: {$names}");
        return false;
    }
}
